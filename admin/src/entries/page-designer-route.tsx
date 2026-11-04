import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate } from '@tanstack/react-router';
import { ArrowLeft, Eye, Loader2, XCircle } from 'lucide-react';
import { apiClient } from '../lib/api';
import { Button, ConfirmationModal, PageContainer, Spinner } from '@bs/ui';
import { problemToast, successToast } from '@bs/ui';
import { PuckPageEditor } from './puck-page-editor';
import { extractEntryContent, staticPreviewOrigin, toContentPayload } from './puck-content';
import type { PuckData } from '@bs/page-builder';

/**
 * Full-screen visual designer for pages: owns the `content` field end to end.
 * The entry editor renders a read-only preview and hands off here via
 * Customize; this route is the only place that saves Puck data, so the two
 * save flows can never clobber each other with stale state.
 */

type EntryStatus = 'draft' | 'published' | 'in_review' | 'scheduled' | 'archived';

interface LoadedEntry {
  title: string;
  slug: string;
  status: EntryStatus;
  content: unknown;
  version: number;
}

function statusLabel(status: EntryStatus): string {
  switch (status) {
    case 'published':
      return 'Published';
    case 'in_review':
      return 'In review';
    case 'scheduled':
      return 'Scheduled';
    case 'archived':
      return 'Archived';
    default:
      return 'Draft';
  }
}

export function PageDesignerRoute({ collectionHandle, entryId }: { collectionHandle: string; entryId: string }) {
  const navigate = useNavigate();

  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [entry, setEntry] = useState<LoadedEntry | null>(null);

  const [saving, setSaving] = useState<'draft' | 'publish' | null>(null);
  const [conflict, setConflict] = useState<string | null>(null);
  const [exitConfirmOpen, setExitConfirmOpen] = useState(false);
  const [reloadKey, setReloadKey] = useState(0);

  const initialSnapshotRef = useRef<string | null>(null);
  const contentRef = useRef<PuckData | null>(null);
  const versionRef = useRef<number>(1);
  const [isDirty, setIsDirty] = useState(false);

  const exitToEditor = useCallback(() => {
    navigate({ to: '/entries/$collection/$id', params: { collection: collectionHandle, id: entryId } });
  }, [navigate, collectionHandle, entryId]);

  // 1. Load the saved entry
  useEffect(() => {
    let mounted = true;
    const load = async () => {
      try {
        setLoading(true);
        const res = await apiClient.request<Record<string, unknown>>('GET', `/collections/${collectionHandle}/entries/${entryId}`);
        if (!mounted) return;
        if (res.ok && res.data) {
          const item = res.data;
          const itemData = (item.data || {}) as Record<string, unknown>;
          const rawContent = extractEntryContent(item, itemData);
          const version = typeof item.version === 'number' ? item.version : 1;
          const loaded: LoadedEntry = {
            title: String(item.title || 'Untitled page'),
            slug: String(item.slug || ''),
            status: (item.status as EntryStatus) || 'draft',
            content: rawContent,
            version,
          };
          setEntry(loaded);
          versionRef.current = version;
          initialSnapshotRef.current = JSON.stringify(rawContent ?? null);
          contentRef.current = null;
          setIsDirty(false);
          setLoadError(null);
        } else {
          setLoadError('This page could not be loaded.');
        }
      } catch {
        if (mounted) setLoadError('This page could not be loaded.');
      } finally {
        if (mounted) setLoading(false);
      }
    };
    load();
    return () => {
      mounted = false;
    };
  }, [collectionHandle, entryId]);

  // 2. Dirty tracking: any Puck change vs. the loaded snapshot
  const handleChange = useCallback((next: PuckData) => {
    contentRef.current = next;
    setIsDirty(JSON.stringify(next) !== initialSnapshotRef.current);
  }, []);

  // 3. Unsaved-changes browser guard
  useEffect(() => {
    const handler = (e: BeforeUnloadEvent) => {
      if (isDirty) {
        e.preventDefault();
        e.returnValue = '';
      }
    };
    window.addEventListener('beforeunload', handler);
    return () => window.removeEventListener('beforeunload', handler);
  }, [isDirty]);

  // 4. Save: draft keeps work-in-progress; publish goes live
  const handleSave = useCallback(
    async (target: 'draft' | 'publish') => {
      if (!entry || saving) return;
      setSaving(target);
      setConflict(null);
      try {
        const payload: Record<string, unknown> = {
          content: toContentPayload(contentRef.current),
          status: target === 'draft' ? 'draft' : 'published',
        };
        const res = await apiClient.request<Record<string, unknown>>(
          'PATCH',
          `/collections/${collectionHandle}/entries/${entryId}`,
          { body: payload, headers: { 'If-Match': `"${versionRef.current}"` } },
        );
        if (res.ok && res.data) {
          const nextVersion = typeof res.data.version === 'number' ? res.data.version : versionRef.current + 1;
          versionRef.current = nextVersion;
          initialSnapshotRef.current = JSON.stringify(contentRef.current ?? null);
          setEntry((prev) => (prev ? { ...prev, status: (payload.status as EntryStatus), version: nextVersion } : prev));
          setIsDirty(false);
          successToast(target === 'draft' ? 'Draft saved.' : 'Page published.');
        } else if (res.status === 409) {
          setConflict('This page was saved elsewhere while you were editing. Reload to get the latest version (your current edits will be discarded).');
        } else {
          const errObj = (res as { problem?: unknown; error?: { message?: string } }).problem
            || (res as { error?: { message?: string } }).error;
          problemToast(errObj || 'Failed to save the page.', { fallbackMessage: 'Failed to save the page.' });
        }
      } catch (err: unknown) {
        if ((err as { status?: number }).status === 409) {
          setConflict('This page was saved elsewhere while you were editing. Reload to get the latest version (your current edits will be discarded).');
        } else {
          problemToast(err || 'Error occurred while saving the page.', { fallbackMessage: 'Error occurred while saving the page.' });
        }
      } finally {
        setSaving(null);
      }
    },
    [entry, saving, collectionHandle, entryId],
  );

  const reloadAfterConflict = useCallback(() => {
    // Discard local edits and refetch the saved entry (see the reloadKey effect).
    setConflict(null);
    setIsDirty(false);
    contentRef.current = null;
    setReloadKey((k) => k + 1);
  }, []);

  useEffect(() => {
    if (reloadKey === 0) return;
    setLoading(true);
    const run = async () => {
      const res = await apiClient.request<Record<string, unknown>>('GET', `/collections/${collectionHandle}/entries/${entryId}`);
      if (res.ok && res.data) {
        const item = res.data;
        const itemData = (item.data || {}) as Record<string, unknown>;
        const rawContent = extractEntryContent(item, itemData);
        const version = typeof item.version === 'number' ? item.version : 1;
        setEntry({
          title: String(item.title || 'Untitled page'),
          slug: String(item.slug || ''),
          status: (item.status as EntryStatus) || 'draft',
          content: rawContent,
          version,
        });
        versionRef.current = version;
        initialSnapshotRef.current = JSON.stringify(rawContent ?? null);
        contentRef.current = null;
        setIsDirty(false);
      }
      setLoading(false);
    };
    run();
  }, [reloadKey, collectionHandle, entryId]);

  // 5. Preview: the API returns a relative preview_url for the static site's
  // draft-preview page. The window is opened synchronously in the click
  // handler — after the awaited token request the browser's popup blocker
  // eats a fresh window.open.
  const handlePreview = useCallback(async () => {
    // If dirty and currently a draft, auto-save draft first so preview reflects latest Puck edits
    if (isDirty && contentRef.current && entry?.status === 'draft') {
      await handleSave('draft');
    }

    const win = window.open('about:blank', '_blank');
    const openUrl = (url: string) => {
      if (win) win.location.href = url;
      else window.open(url, '_blank');
    };
    const origin = staticPreviewOrigin();
    const s = entry?.slug || '';
    const fallbackUrl = entry ? `${origin}${s === 'home' || s === 'index' ? '/' : `/${s}`}` : null;
    try {
      const res = await apiClient.request<{ token?: string; preview_url?: string }>('POST', '/preview/token', {
        body: { collection: collectionHandle, id: entryId },
      });
      const previewPath =
        (res.ok && res.data?.preview_url) ||
        (res.ok && res.data?.token
          ? `/preview/?collection=${encodeURIComponent(collectionHandle)}&id=${encodeURIComponent(entryId)}&token=${encodeURIComponent(res.data.token)}`
          : null);
      if (previewPath) {
        openUrl(origin + previewPath);
      } else if (fallbackUrl) {
        openUrl(fallbackUrl);
      } else if (win) {
        win.close();
      }
    } catch {
      if (fallbackUrl) {
        openUrl(fallbackUrl);
      } else if (win) {
        win.close();
      }
    }
  }, [collectionHandle, entryId, entry, isDirty, handleSave]);

  // 6. Ctrl+S saves the draft
  useEffect(() => {
    const handler = (e: KeyboardEvent) => {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
        e.preventDefault();
        handleSave('draft');
      }
    };
    window.addEventListener('keydown', handler);
    return () => window.removeEventListener('keydown', handler);
  }, [handleSave]);

  const handleExit = useCallback(() => {
    if (isDirty) {
      setExitConfirmOpen(true);
      return;
    }
    exitToEditor();
  }, [isDirty, exitToEditor]);

  const header = useMemo(() => {
    if (!entry) return () => <></>;
    const Header = () => (
      <div
        className="relative flex h-12 items-center justify-between gap-3 border-b border-border bg-card px-3"
        data-testid="designer-header"
      >
        <div className="flex min-w-0 items-center gap-2">
          <Button variant="ghost" size="sm" onClick={handleExit} data-testid="designer-exit">
            <ArrowLeft className="mr-1 h-4 w-4" />
            Exit
          </Button>
          <div className="mx-1 h-5 w-px bg-border" aria-hidden="true" />
          <span className="truncate text-sm font-semibold text-foreground">{entry.title}</span>
          <span
            className={`inline-flex shrink-0 items-center rounded px-1.5 py-0.5 text-[10px] font-medium ${
              entry.status === 'published'
                ? 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400'
                : 'bg-amber-500/10 text-amber-600 dark:text-amber-400'
            }`}
          >
            {statusLabel(entry.status)}
          </span>
          {isDirty && (
            <span className="inline-flex shrink-0 items-center rounded bg-primary/10 px-1.5 py-0.5 text-[10px] font-medium text-primary">
              Unsaved changes
            </span>
          )}
        </div>

        {conflict && (
          <div className="absolute left-1/2 top-full z-50 flex w-[min(680px,90vw)] -translate-x-1/2 items-center gap-2 rounded-md border border-destructive/30 bg-destructive/10 p-2 text-xs text-destructive"
            role="alert"
            data-testid="designer-conflict"
          >
            <XCircle className="h-4 w-4 shrink-0" />
            <span className="min-w-0 flex-1">{conflict}</span>
            <Button size="sm" variant="outline" className="h-7 text-xs" onClick={reloadAfterConflict}>
              Discard & reload
            </Button>
          </div>
        )}

        <div className="flex shrink-0 items-center gap-2">
          <Button variant="outline" size="sm" onClick={handlePreview} data-testid="designer-preview">
            <Eye className="mr-1 h-3.5 w-3.5" />
            Preview
          </Button>
          <Button variant="outline" size="sm" onClick={() => handleSave('draft')} disabled={saving !== null} data-testid="designer-save-draft">
            {saving === 'draft' ? <Loader2 className="mr-1 h-3.5 w-3.5 animate-spin" /> : null}
            Save as Draft
          </Button>
          <Button size="sm" onClick={() => handleSave('publish')} disabled={saving !== null} data-testid="designer-publish">
            {saving === 'publish' ? <Loader2 className="mr-1 h-3.5 w-3.5 animate-spin" /> : null}
            {entry.status === 'published' ? 'Update & Keep Published' : 'Publish'}
          </Button>
        </div>
      </div>
    );
    Header.displayName = 'DesignerHeader';
    return Header;
  }, [entry, isDirty, conflict, saving, handleExit, handlePreview, handleSave, reloadAfterConflict]);

  // Load / error states
  if (loading && !entry) {
    return (
      <div className="flex h-screen items-center justify-center bg-background">
        <div className="flex flex-col items-center gap-3">
          <Spinner className="h-8 w-8 text-primary" />
          <p className="text-13 text-muted-foreground">Loading page builder…</p>
        </div>
      </div>
    );
  }

  if (loadError || !entry) {
    return (
      <div className="flex h-screen flex-col items-center justify-center gap-4 bg-background">
        <PageContainer width="small">
          <div className="space-y-3 text-center">
            <h1 className="text-lg font-semibold text-foreground">Page builder unavailable</h1>
            <p className="text-sm text-muted-foreground">{loadError || 'This page could not be loaded.'}</p>
            <Button variant="outline" size="sm" onClick={exitToEditor}>
              <ArrowLeft className="mr-1 h-3.5 w-3.5" />
              Back to page
            </Button>
          </div>
        </PageContainer>
      </div>
    );
  }

  return (
    <div className="h-screen w-full overflow-hidden bg-background">
      {/* The designer owns the viewport: no admin chrome, single Puck surface */}
      <PuckPageEditor
        value={entry.content}
        onChange={handleChange}
        header={header}
        height="100vh"
      />
      <ConfirmationModal
        open={exitConfirmOpen}
        onOpenChange={setExitConfirmOpen}
        title="Discard unsaved changes?"
        body="Your content edits in the builder have not been saved. Exiting now will discard them."
        confirmLabel="Discard & exit"
        destructive
        onConfirm={() => {
          setExitConfirmOpen(false);
          exitToEditor();
        }}
      />
    </div>
  );
}
