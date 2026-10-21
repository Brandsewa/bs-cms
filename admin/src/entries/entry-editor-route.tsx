import { useEffect, useState, useCallback, useRef } from 'react';
import { useNavigate } from '@tanstack/react-router';
import {
  PageContainer,
  Button,
  Card,
  CardContent,
  Input,
  Label,
  Textarea,
  Switch,
  Spinner,
  ForbiddenState,
  NotFoundState,
  errorToast,
  successToast,
} from '@bs/ui';
import { ArrowLeft, Save, AlertTriangle } from 'lucide-react';
import { apiClient } from '../lib/api';
import { useSession } from '../auth/session-context';
import { TiptapArticleEditor } from './tiptap-article-editor';
import { PublishPanel, SeoPanel, ConflictDialog } from './entry-panels';
import type { CollectionSchema, EntryItem } from './types';

export interface EntryEditorRouteProps {
  collectionHandle: string;
  entryId?: string; // If undefined, creation mode
}

export function EntryEditorRoute({
  collectionHandle = 'posts',
  entryId,
}: EntryEditorRouteProps) {
  const isNew = !entryId || entryId === 'new';
  const navigate = useNavigate();
  const { session, hasPermission } = useSession();

  // Permissions
  const isOwner =
    session?.user?.roles?.some((r) => (typeof r === 'string' ? r === 'owner' : r.handle === 'owner')) ?? false;
  const canPublish = isOwner || hasPermission('collection', 'publish');
  const canSave = isOwner || (isNew ? hasPermission('collection', 'create') : hasPermission('collection', 'update'));

  // Schema state
  const [schema, setSchema] = useState<CollectionSchema | null>(null);
  const [loadingSchema, setLoadingSchema] = useState(true);
  const [schemaStatusCode, setSchemaStatusCode] = useState<number | null>(null);

  // Form State
  const [title, setTitle] = useState('');
  const [slug, setSlug] = useState('');
  const [status, setStatus] = useState<'draft' | 'published' | 'in_review' | 'scheduled' | 'archived'>('draft');
  const [bodyContent, setBodyContent] = useState<unknown>(null);
  const [excerpt, setExcerpt] = useState('');
  const [metaTitle, setMetaTitle] = useState('');
  const [metaDescription, setMetaDescription] = useState('');
  const [isFeatured, setIsFeatured] = useState(false);
  const [publishedAt, setPublishedAt] = useState<string | null>(null);
  const [scheduledAt, setScheduledAt] = useState<string | null>(null);
  const [version, setVersion] = useState<number>(1);

  // Dirty state tracking for unsaved-changes guard
  const initialSnapshotRef = useRef<string>('');
  const [isDirty, setIsDirty] = useState(false);

  // Loading & Saving status
  const [loadingEntry, setLoadingEntry] = useState(!isNew);
  const [entryStatusCode, setEntryStatusCode] = useState<number | null>(null);
  const [saving, setSaving] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  // Optimistic concurrency conflict dialog
  const [conflictModalOpen, setConflictModalOpen] = useState(false);
  const [conflictDetails, setConflictDetails] = useState('');

  // Helper: Strip trailing empty paragraphs from document AST before saving
  const cleanDocument = (doc: unknown): unknown => {
    if (!doc || typeof doc !== 'object') return doc;
    const docObj = doc as { type?: string; content?: Array<{ type?: string; content?: unknown[]; text?: string }> };
    if (docObj.type !== 'doc' || !Array.isArray(docObj.content)) return doc;

    const content = [...docObj.content];
    while (content.length > 0) {
      const last = content[content.length - 1];
      if (
        last &&
        last.type === 'paragraph' &&
        (!last.content || last.content.length === 0 || last.content.every((c) => !c.text || !c.text.trim()))
      ) {
        content.pop();
      } else {
        break;
      }
    }
    return { ...docObj, content };
  };

  // 1. Fetch collection schema
  useEffect(() => {
    let mounted = true;
    async function loadSchema() {
      try {
        const res = await apiClient.request<CollectionSchema>('GET', `/collections/${collectionHandle}`);
        if (mounted) {
          setSchemaStatusCode(res.status);
          if (res.ok && res.data) {
            setSchema(res.data);
          }
        }
      } catch (err) {
        console.error('Failed to load schema:', err);
      } finally {
        if (mounted) setLoadingSchema(false);
      }
    }
    loadSchema();
    return () => {
      mounted = false;
    };
  }, [collectionHandle]);

  // 2. Fetch existing entry if editing
  const loadEntry = useCallback(async () => {
    if (isNew) return;
    setLoadingEntry(true);
    setErrorMessage(null);
    try {
      const res = await apiClient.request<EntryItem>('GET', `/collections/${collectionHandle}/entries/${entryId}`);
      setEntryStatusCode(res.status);
      if (res.ok && res.data) {
        const item = res.data;
        setTitle(item.title || '');
        setSlug(item.slug || '');
        setStatus(item.status || 'draft');
        setBodyContent(item.body || null);
        setExcerpt(item.excerpt || '');
        setMetaTitle(item.seo?.title || '');
        setMetaDescription(item.seo?.description || '');
        setIsFeatured(Boolean(item.is_featured));
        setPublishedAt(item.published_at || null);
        setScheduledAt(item.scheduled_at || null);
        setVersion(typeof item.version === 'number' ? item.version : 1);

        initialSnapshotRef.current = JSON.stringify({
          title: item.title || '',
          slug: item.slug || '',
          status: item.status || 'draft',
          body: item.body || null,
          excerpt: item.excerpt || '',
          metaTitle: item.seo?.title || '',
          metaDescription: item.seo?.description || '',
          isFeatured: Boolean(item.is_featured),
        });
        setIsDirty(false);
      } else {
        const problemMsg = !res.ok ? res.problem?.detail : undefined;
        setErrorMessage(problemMsg || 'Could not load entry details');
      }
    } catch (err: unknown) {
      const msg = err instanceof Error ? err.message : 'Network error while loading entry';
      setErrorMessage(msg);
    } finally {
      setLoadingEntry(false);
    }
  }, [collectionHandle, entryId, isNew]);

  useEffect(() => {
    loadEntry();
  }, [loadEntry]);

  // Track dirty state
  useEffect(() => {
    const current = JSON.stringify({
      title,
      slug,
      status,
      body: bodyContent,
      excerpt,
      metaTitle,
      metaDescription,
      isFeatured,
    });
    if (initialSnapshotRef.current) {
      setIsDirty(current !== initialSnapshotRef.current);
    } else if (title || slug || excerpt || bodyContent) {
      setIsDirty(true);
    }
  }, [title, slug, status, bodyContent, excerpt, metaTitle, metaDescription, isFeatured]);

  // Unsaved changes beforeunload guard
  useEffect(() => {
    const handleBeforeUnload = (e: BeforeUnloadEvent) => {
      if (isDirty) {
        e.preventDefault();
        e.returnValue = '';
      }
    };
    window.addEventListener('beforeunload', handleBeforeUnload);
    return () => window.removeEventListener('beforeunload', handleBeforeUnload);
  }, [isDirty]);

  // Save handler with If-Match concurrency headers
  const handleSave = useCallback(
    async (overrideStatus?: 'draft' | 'published' | 'in_review', forceVersion?: number) => {
      if (!canSave) {
        errorToast('You do not have permission to save this entry.');
        return;
      }

      if (!title.trim()) {
        errorToast('Entry title is required.');
        return;
      }

      setSaving(true);
      setErrorMessage(null);

      const targetStatus = overrideStatus || status;
      const cleanedBody = cleanDocument(bodyContent);

      const payload: Record<string, unknown> = {
        title: title.trim(),
        slug: slug.trim(),
        status: targetStatus,
        body: cleanedBody,
        excerpt: excerpt.trim(),
        is_featured: isFeatured,
        seo: {
          title: metaTitle.trim() || undefined,
          description: metaDescription.trim() || undefined,
        },
      };

      if (publishedAt) payload.published_at = publishedAt;
      if (scheduledAt) payload.scheduled_at = scheduledAt;

      try {
        if (isNew) {
          // Create (POST)
          const res = await apiClient.request<EntryItem>('POST', `/collections/${collectionHandle}/entries`, {
            body: payload,
          });

          if (res.ok && res.data) {
            successToast('Entry created successfully.');
            setIsDirty(false);
            // Navigate with router to edit route
            navigate({
              to: '/entries/$collection/$id',
              params: { collection: collectionHandle, id: res.data.id },
              replace: true,
            });
          } else {
            const detail = res.ok === false ? res.problem?.detail || 'Failed to create entry.' : 'Failed to create entry.';
            setErrorMessage(detail);
            errorToast(detail);
          }
        } else {
          // Update (PUT) with If-Match version
          const expectedVersion = forceVersion !== undefined ? forceVersion : version;
          const res = await apiClient.request<EntryItem>('PUT', `/collections/${collectionHandle}/entries/${entryId}`, {
            body: payload,
            ifMatch: expectedVersion ? String(expectedVersion) : undefined,
          });

          if (res.ok && res.data) {
            successToast('Changes saved successfully.');
            setVersion(typeof res.data.version === 'number' ? res.data.version : expectedVersion + 1);
            setStatus(res.data.status);
            setIsDirty(false);
            initialSnapshotRef.current = JSON.stringify({
              title: payload.title,
              slug: payload.slug,
              status: payload.status,
              body: cleanedBody,
              excerpt: payload.excerpt,
              metaTitle,
              metaDescription,
              isFeatured,
            });
          } else if (res.status === 409) {
            // HTTP 409 Conflict: another user/agent touched this entry
            const conflictMsg = !res.ok ? res.problem?.detail : undefined;
            setConflictDetails(conflictMsg || 'Entry was modified concurrently.');
            setConflictModalOpen(true);
          } else {
            const detail = !res.ok ? res.problem?.detail || 'Failed to update entry.' : 'Failed to update entry.';
            setErrorMessage(detail);
            errorToast(detail);
          }
        }
      } catch (err: unknown) {
        const msg = err instanceof Error ? err.message : 'Network error while saving';
        setErrorMessage(msg);
        errorToast('Network error while saving entry.');
      } finally {
        setSaving(false);
      }
    },
    [
      canSave,
      title,
      slug,
      status,
      bodyContent,
      excerpt,
      isFeatured,
      metaTitle,
      metaDescription,
      publishedAt,
      scheduledAt,
      isNew,
      collectionHandle,
      navigate,
      version,
      entryId,
    ]
  );

  // Hotkey listener: Ctrl+S and custom bs:save-draft event
  useEffect(() => {
    const handleSaveDraftEvent = () => {
      if (canSave && !saving) {
        handleSave();
      }
    };

    const handleKeyDown = (e: KeyboardEvent) => {
      if ((e.ctrlKey || e.metaKey) && e.key === 's') {
        e.preventDefault();
        handleSaveDraftEvent();
      }
    };

    window.addEventListener('bs:save-draft', handleSaveDraftEvent);
    window.addEventListener('keydown', handleKeyDown);
    return () => {
      window.removeEventListener('bs:save-draft', handleSaveDraftEvent);
      window.removeEventListener('keydown', handleKeyDown);
    };
  }, [canSave, saving, handleSave]);

  const collectionLabel = schema?.label_singular || (collectionHandle === 'pages' ? 'Page' : 'Post');

  // 403 Forbidden State
  if (schemaStatusCode === 403 || entryStatusCode === 403) {
    return (
      <div className="flex min-h-[400px] items-center justify-center p-6">
        <ForbiddenState
          message="You do not have permission to access or edit entries in this collection."
          onBack={() => {
            if (window.history.length > 1) {
              window.history.back();
            } else {
              window.location.href = '/admin';
            }
          }}
          onHome={() => {
            window.location.href = '/admin';
          }}
        />
      </div>
    );
  }

  // 404 Not Found State (Unknown collection or entry not found)
  if (
    (!loadingSchema && (!schema || schemaStatusCode === 404)) ||
    (!loadingEntry && !isNew && entryStatusCode === 404)
  ) {
    return (
      <div className="flex min-h-[400px] items-center justify-center p-6">
        <NotFoundState
          message={
            !schema || schemaStatusCode === 404
              ? `Collection '${collectionHandle}' was not found.`
              : `Entry '${entryId}' was not found in collection '${collectionHandle}'.`
          }
          onBack={() => {
            if (window.history.length > 1) {
              window.history.back();
            } else {
              window.location.href = '/admin';
            }
          }}
          onHome={() => {
            window.location.href = '/admin';
          }}
        />
      </div>
    );
  }

  if (loadingSchema || loadingEntry) {
    return (
      <PageContainer data-testid="entry-editor-loading" className="flex h-96 items-center justify-center">
        <div className="flex flex-col items-center gap-2 text-muted-foreground">
          <Spinner className="h-6 w-6" />
          <span className="text-xs">Loading {collectionLabel.toLowerCase()} editor...</span>
        </div>
      </PageContainer>
    );
  }

  return (
    <PageContainer data-testid="entry-editor-page" className="space-y-6 pb-12">
      {/* Header bar */}
      <div className="flex flex-wrap items-center justify-between gap-4 border-b border-border pb-4">
        <div className="flex items-center gap-3">
          <a
            href={`/admin/entries/${collectionHandle}`}
            onClick={(e) => {
              e.preventDefault();
              navigate({
                to: '/entries/$collection',
                params: { collection: collectionHandle },
              });
            }}
            aria-label={`Back to ${schema?.label_plural || 'Entries'}`}
            className="inline-flex h-8 w-8 items-center justify-center rounded-md border border-border bg-card text-muted-foreground transition-colors hover:bg-surface-200 hover:text-foreground"
          >
            <ArrowLeft className="h-4 w-4" />
          </a>
          <div>
            <h1 className="text-lg font-semibold tracking-tight text-foreground">
              {isNew ? `Create ${collectionLabel}` : `Edit ${collectionLabel}`}
            </h1>
            <p className="text-xs text-muted-foreground">
              {schema?.label_plural || 'Content'} / {slug || 'untitled'}
            </p>
          </div>
        </div>

        <div className="flex items-center gap-2">
          <Button
            type="button"
            variant="secondary"
            size="sm"
            onClick={() => handleSave()}
            disabled={saving || !canSave}
            className="flex items-center gap-1.5"
          >
            <Save className="h-3.5 w-3.5" />
            <span>{saving ? 'Saving...' : 'Save Draft'}</span>
          </Button>

          {canPublish ? (
            <Button
              type="button"
              variant="default"
              size="sm"
              onClick={() => handleSave('published')}
              disabled={saving || !canSave}
            >
              Publish
            </Button>
          ) : null}
        </div>
      </div>

      {/* Error alert if any */}
      {errorMessage ? (
        <div className="flex items-center gap-2 rounded-md border border-destructive/30 bg-destructive/10 p-3 text-xs text-destructive-text">
          <AlertTriangle className="h-4 w-4 shrink-0" />
          <span>{errorMessage}</span>
        </div>
      ) : null}

      {/* Main 2-column layout: Form Body (left 8 cols) & Panels (right 4 cols) */}
      <div className="grid grid-cols-1 gap-6 lg:grid-cols-12">
        {/* Left Column: Title & Tiptap Rich Text Story */}
        <div className="space-y-6 lg:col-span-8">
          <Card className="border-border bg-card">
            <CardContent className="p-6 space-y-4">
              <div className="space-y-1.5">
                <Label htmlFor="entry-title-input" className="text-xs font-medium text-foreground-light">
                  Title <span className="text-destructive">*</span>
                </Label>
                <Input
                  id="entry-title-input"
                  value={title}
                  onChange={(e) => setTitle(e.target.value)}
                  placeholder="Enter title..."
                  className="text-base font-medium"
                  required
                />
              </div>

              {/* Tiptap Article Editor */}
              <div className="space-y-1.5 pt-2">
                <Label className="text-xs font-medium text-foreground-light">
                  Article Body (Tiptap Rich Text)
                </Label>
                <TiptapArticleEditor
                  value={bodyContent}
                  onChange={(json) => setBodyContent(json)}
                  placeholder="Tell your story or craft documentation with headings, directives, and blocks..."
                />
              </div>

              {/* Excerpt */}
              <div className="space-y-1.5 pt-2">
                <Label htmlFor="entry-excerpt-input" className="text-xs font-medium text-foreground-light">
                  Excerpt / Summary
                </Label>
                <Textarea
                  id="entry-excerpt-input"
                  value={excerpt}
                  onChange={(e) => setExcerpt(e.target.value)}
                  placeholder="Short excerpt for archives and listing cards..."
                  rows={3}
                  className="text-xs resize-none"
                />
              </div>

              {/* Featured toggle */}
              <div className="flex items-center justify-between rounded-md border border-border bg-surface-100 p-3">
                <div className="space-y-0.5">
                  <Label htmlFor="entry-featured-switch" className="text-xs font-medium text-foreground">
                    Featured Item
                  </Label>
                  <p className="text-[11px] text-muted-foreground">
                    Pin this article to heroes and top featured sections.
                  </p>
                </div>
                <Switch
                  id="entry-featured-switch"
                  checked={isFeatured}
                  onCheckedChange={setIsFeatured}
                />
              </div>
            </CardContent>
          </Card>
        </div>

        {/* Right Column: Publishing, Slug, SEO Panels */}
        <div className="space-y-6 lg:col-span-4">
          <PublishPanel
            status={status}
            onStatusChange={setStatus}
            version={version}
            publishedAt={publishedAt}
            scheduledAt={scheduledAt}
            onPublishedAtChange={setPublishedAt}
            onScheduledAtChange={setScheduledAt}
            onSave={handleSave}
            saving={saving}
            canPublish={canPublish}
          />

          <SeoPanel
            slug={slug}
            onSlugChange={setSlug}
            title={title}
            metaTitle={metaTitle}
            onMetaTitleChange={setMetaTitle}
            metaDescription={metaDescription}
            onMetaDescriptionChange={setMetaDescription}
            urlPrefix={`https://example.com/${collectionHandle}/`}
          />
        </div>
      </div>

      {/* 409 Conflict Dialog */}
      <ConflictDialog
        open={conflictModalOpen}
        message={conflictDetails}
        onClose={() => setConflictModalOpen(false)}
        onReload={() => {
          setConflictModalOpen(false);
          loadEntry();
        }}
        onOverwrite={() => {
          setConflictModalOpen(false);
          // Force overwrite without version requirement
          handleSave(undefined, undefined);
        }}
      />
    </PageContainer>
  );
}
