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
  problemToast,
} from '@bs/ui';
import { ArrowLeft, Save, AlertTriangle } from 'lucide-react';
import { apiClient } from '../lib/api';
import { useSession } from '../auth/session-context';
import { TiptapArticleEditor } from './tiptap-article-editor';
import { BlocksFieldEditor, type BlockInstance } from './blocks-field-editor';
import {
  PublishPanel,
  SeoPanel,
  ConflictDialog,
  RevisionsPanel,
  TaxonomyPanel,
  AuthorPanel,
  FeaturedImagePanel,
} from './entry-panels';
import type { MediaItem } from '../media/media-picker-modal';
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
  const [blocksContent, setBlocksContent] = useState<BlockInstance[]>([]);
  const [excerpt, setExcerpt] = useState('');
  const [metaTitle, setMetaTitle] = useState('');
  const [metaDescription, setMetaDescription] = useState('');
  const [isFeatured, setIsFeatured] = useState(false);
  const [featuredImage, setFeaturedImage] = useState<MediaItem | null>(null);
  const [categoryIds, setCategoryIds] = useState<string[]>([]);
  const [authorId, setAuthorId] = useState<string | null>(null);
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
        (!last.content ||
          last.content.length === 0 ||
          last.content.every((c: any) => !c.text || !c.text.trim()))
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
        setLoadingSchema(true);
        const res = await apiClient.request<CollectionSchema>('GET', `/collections/${collectionHandle}`);
        if (!mounted) return;

        if (res.ok && res.data) {
          setSchema(res.data);
          setSchemaStatusCode(200);
        } else {
          setSchemaStatusCode(res.status || 500);
          if (res.status === 403) {
            setErrorMessage('You do not have permission to access this collection.');
          } else if (res.status === 404) {
            setErrorMessage(`Collection '${collectionHandle}' does not exist.`);
          }
        }
      } catch (err: any) {
        if (!mounted) return;
        setSchemaStatusCode(err?.status || 500);
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
    try {
      setLoadingEntry(true);
      const res = await apiClient.request<EntryItem>('GET', `/collections/${collectionHandle}/entries/${entryId}`);
      if (res.ok && res.data) {
        const item = res.data;
        const itemData = (item.data || {}) as Record<string, any>;
        setTitle(item.title || '');
        setSlug(item.slug || '');
        setStatus((item.status as any) || 'draft');
        setBodyContent(item.body || itemData.body || null);
        const rawBlocks = Array.isArray(item.blocks) ? item.blocks : Array.isArray(itemData.blocks) ? itemData.blocks : [];
        const normalizedBlocks: BlockInstance[] = rawBlocks.map((b: any, idx: number) => {
          if (b && typeof b === 'object') {
            const blockType = b.type || b.block_id || 'hero';
            const { id: _id, type: _t, block_id: _bid, ...dataFields } = b;
            return {
              id: b.id || `blk_${Date.now()}_${idx}`,
              type: blockType,
              data: b.data && typeof b.data === 'object' ? b.data : dataFields,
            };
          }
          return { id: `blk_${Date.now()}_${idx}`, type: 'text', data: {} };
        });
        setBlocksContent(normalizedBlocks);
        setExcerpt(item.excerpt || itemData.excerpt || '');
        setMetaTitle(item.seo?.title || itemData.seo?.title || '');
        setMetaDescription(item.seo?.description || itemData.seo?.description || '');
        setIsFeatured(Boolean(item.is_featured));
        setPublishedAt(item.published_at || null);
        setScheduledAt(item.scheduled_at || null);
        setVersion(typeof item.version === 'number' ? item.version : 1);
        const resolvedAuthorId = item.author_id || (itemData as any).author_id || null; if (resolvedAuthorId) setAuthorId(resolvedAuthorId);
        { const cats = (item as Record<string, unknown>).categories ?? item.category_ids; if (Array.isArray(cats)) setCategoryIds(cats as string[]); }
        if (item.featured_image) setFeaturedImage(item.featured_image as unknown as MediaItem);

        const snapshot = JSON.stringify({
          title: item.title || '',
          slug: item.slug || '',
          status: item.status || 'draft',
          body: item.body || itemData.body || null,
          excerpt: item.excerpt || itemData.excerpt || '',
          metaTitle: item.seo?.title || itemData.seo?.title || '',
          metaDescription: item.seo?.description || itemData.seo?.description || '',
          isFeatured: Boolean(item.is_featured),
          categoryIds: (((item as Record<string, unknown>).categories ?? item.category_ids) as string[] | undefined) || [],
          authorId: item.author_id || null,
        });
        initialSnapshotRef.current = snapshot;
        setIsDirty(false);
        setEntryStatusCode(200);
      } else {
        setEntryStatusCode(res.status || 500);
      }
    } catch (err: any) {
      setEntryStatusCode(err?.status || 500);
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
      categoryIds,
      authorId,
    });
    if (initialSnapshotRef.current) {
      setIsDirty(current !== initialSnapshotRef.current);
    } else if (title || slug || excerpt || bodyContent) {
      setIsDirty(true);
    }
  }, [title, slug, status, bodyContent, excerpt, metaTitle, metaDescription, isFeatured, categoryIds, authorId]);

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
  const hasField = useCallback(
    (fieldHandle: string) => {
      if (!schema?.fields) return true; // optimistic default while loading schema
      return Boolean((schema.fields as Record<string, unknown>)[fieldHandle]);
    },
    [schema]
  );

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
        author_id: authorId,
        seo: {
          title: metaTitle.trim() || undefined,
          description: metaDescription.trim() || undefined,
        },
      };
      if (hasField('body')) payload.body = cleanedBody;
      if (hasField('blocks')) {
        payload.blocks = blocksContent.map((blk) => ({
          type: blk.type,
          ...blk.data,
        }));
      }
      if (hasField('excerpt')) payload.excerpt = excerpt.trim();
      if (hasField('is_featured')) payload.is_featured = isFeatured;
      if (hasField('categories')) payload.categories = categoryIds;
      if (hasField('featured_image')) payload.featured_image = featuredImage?.id || null;

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
            });
          } else {
            const errObj = (res as any).problem || (res as any).error;
            problemToast(errObj || 'Failed to create entry.', { fallbackMessage: 'Failed to create entry.' });
            const msg = errObj?.detail || errObj?.message || 'Failed to create entry.';
            setErrorMessage(msg);
          }
        } else {
          // Update (PATCH) with If-Match concurrency header
          const headers: Record<string, string> = {};
          const versionToMatch = forceVersion !== undefined ? forceVersion : version;
          if (versionToMatch) {
            headers['If-Match'] = `"${versionToMatch}"`;
          }

          const res = await apiClient.request<EntryItem>(
            'PATCH',
            `/collections/${collectionHandle}/entries/${entryId}`,
            {
              body: payload,
              headers,
            }
          );

          if (res.ok && res.data) {
            successToast('Entry saved successfully.');
            setVersion(typeof res.data.version === 'number' ? res.data.version : version + 1);
            setStatus(targetStatus);
            const snapshot = JSON.stringify({
              title: title.trim(),
              slug: slug.trim(),
              status: targetStatus,
              body: cleanedBody,
              excerpt: excerpt.trim(),
              metaTitle: metaTitle.trim(),
              metaDescription: metaDescription.trim(),
              isFeatured,
              categoryIds,
              authorId,
            });
            initialSnapshotRef.current = snapshot;
            setIsDirty(false);
          } else if (res.status === 409) {
            // Optimistic concurrency collision
            const conflictMsg = !res.ok ? (res as any).error?.message : undefined;
            setConflictDetails(
              conflictMsg ||
                'Another user or session saved a newer revision of this entry. Please review conflicts.'
            );
            setConflictModalOpen(true);
          } else {
            const errObj = (res as any).problem || (res as any).error;
            problemToast(errObj || 'Failed to update entry.', { fallbackMessage: 'Failed to update entry.' });
            const msg = errObj?.detail || errObj?.message || 'Failed to update entry.';
            setErrorMessage(msg);
          }
        }
      } catch (err: any) {
        if (err?.status === 409) {
          setConflictDetails('A concurrency conflict occurred. The entry was modified elsewhere.');
          setConflictModalOpen(true);
        } else {
          problemToast(err || 'Error occurred while saving entry.', { fallbackMessage: 'Error occurred while saving entry.' });
          setErrorMessage(err?.detail || err?.message || 'Error occurred while saving entry.');
        }
      } finally {
        setSaving(false);
      }
    },
    [
      canSave,
      title,
      status,
      bodyContent,
      slug,
      excerpt,
      isFeatured,
      authorId,
      categoryIds,
      featuredImage,
      metaTitle,
      metaDescription,
      publishedAt,
      scheduledAt,
      isNew,
      collectionHandle,
      entryId,
      version,
      navigate,
    ]
  );

  // Restore revision handler
  const handleRestoreVersion = async (versionId: string) => {
    try {
      const res = await apiClient.request<EntryItem>(
        'POST',
        `/collections/${collectionHandle}/entries/${entryId}/versions/${versionId}/restore`
      );
      if (res.ok) {
        successToast('Restored past version snapshot.');
        loadEntry();
      } else {
        errorToast('Failed to restore selected version.');
      }
    } catch {
      errorToast('Failed to restore selected version.');
    }
  };

  // Preview token creation & launch
  const handlePreview = async () => {
    try {
      const res = await apiClient.request<{ token: string; url?: string }>('POST', '/preview/token', {
        body: {
          collection: collectionHandle,
          id: entryId || 'draft',
        },
      });

      if (res.ok && res.data?.token) {
        const previewUrl = res.data.url || `http://localhost:4321/preview?token=${res.data.token}`;
        window.open(previewUrl, '_blank');
      } else {
        // Fallback preview
        window.open(`http://localhost:4321/${collectionHandle}/${slug || 'draft'}`, '_blank');
      }
    } catch {
      window.open(`http://localhost:4321/${collectionHandle}/${slug || 'draft'}`, '_blank');
    }
  };

  const handleViewOnSite = () => {
    window.open(`https://example.com/${collectionHandle}/${slug || ''}`, '_blank');
  };

  // Hotkey listener: Ctrl+S and custom bs:save-draft event
  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
        e.preventDefault();
        handleSave();
      }
    };

    const handleSaveDraftEvent = () => {
      handleSave();
    };

    window.addEventListener('keydown', handleKeyDown);
    window.addEventListener('bs:save-draft', handleSaveDraftEvent);
    return () => {
      window.removeEventListener('keydown', handleKeyDown);
      window.removeEventListener('bs:save-draft', handleSaveDraftEvent);
    };
  }, [handleSave]);

  // Loading skeleton
  if (loadingSchema || loadingEntry) {
    return (
      <PageContainer width="full">
        <div className="flex items-center justify-center min-h-[300px]">
          <Spinner className="h-8 w-8 text-primary" />
        </div>
      </PageContainer>
    );
  }

  const collectionLabel = schema?.label_singular || (collectionHandle === 'pages' ? 'Page' : 'Post');

  // 403 Forbidden State
  if (schemaStatusCode === 403 || entryStatusCode === 403) {
    return (
      <div className="flex min-h-[400px] items-center justify-center p-6">
        <ForbiddenState
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

  return (
    <PageContainer width="full" className="space-y-6">
      {/* Top Breadcrumb & Navigation Bar */}
      <div className="flex items-center justify-between border-b border-border pb-4">
        <div className="flex items-center gap-3">
          <Button
            variant="ghost"
            size="sm"
            onClick={() => navigate({ to: '/entries/$collection', params: { collection: collectionHandle } })}
            className="h-8 w-8 p-0"
            aria-label="Back to entries"
          >
            <ArrowLeft className="h-4 w-4" />
          </Button>
          <div>
            <div className="flex items-center gap-2">
              <span className="text-xs text-muted-foreground uppercase tracking-wider font-semibold">
                {collectionLabel}
              </span>
              <span className="text-xs text-muted-foreground">/</span>
              <span className="text-sm font-semibold text-foreground">
                {isNew ? `New ${collectionLabel}` : title || 'Untitled Entry'}
              </span>
              {isDirty && (
                <span className="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-medium bg-amber-500/10 text-amber-600 dark:text-amber-400">
                  Unsaved changes
                </span>
              )}
            </div>
          </div>
        </div>

        {/* Global Save Button in Header */}
        <div className="flex items-center gap-2">
          <Button
            size="sm"
            onClick={() => handleSave()}
            disabled={saving || !canSave}
            className="text-xs"
          >
            {saving ? (
              <Spinner className="h-3.5 w-3.5 mr-1" />
            ) : (
              <Save className="h-3.5 w-3.5 mr-1" />
            )}
            Save
          </Button>
        </div>
      </div>

      {errorMessage && (
        <div className="flex items-center gap-2 rounded-md bg-destructive/10 p-3 text-xs text-destructive border border-destructive/20">
          <AlertTriangle className="h-4 w-4 shrink-0" />
          <span>{errorMessage}</span>
        </div>
      )}

      {/* Main Form Layout */}
      <div className="grid grid-cols-1 gap-6 lg:grid-cols-12">
        {/* Left / Center Column: Title & Body Editor */}
        <div className="space-y-6 lg:col-span-8">
          <Card className="border-border bg-card">
            <CardContent className="p-6 space-y-4">
              {/* Main Title Field */}
              <div className="space-y-1.5">
                <Label htmlFor="entry-title-input" className="text-xs font-semibold uppercase tracking-wider text-foreground-lighter">
                  {collectionLabel} Title
                </Label>
                <Input
                  id="entry-title-input"
                  value={title}
                  onChange={(e) => setTitle(e.target.value)}
                  placeholder={`Enter ${collectionLabel.toLowerCase()} title...`}
                  className="text-lg font-semibold h-11"
                  autoFocus={isNew}
                />
              </div>

              {/* Content Editor: Blocks for Pages or Tiptap for Posts */}
              {collectionHandle === 'pages' ? (
                <div className="space-y-1.5 pt-2">
                  <BlocksFieldEditor
                    value={blocksContent}
                    onChange={setBlocksContent}
                    disabled={!canSave}
                  />
                </div>
              ) : (
                <div className="space-y-1.5 pt-2">
                  <Label className="text-xs font-semibold uppercase tracking-wider text-foreground-lighter">
                    Content Body
                  </Label>
                  <TiptapArticleEditor
                    value={bodyContent}
                    onChange={(newJson) => setBodyContent(newJson)}
                    placeholder="Start writing story or type / for directives..."
                    disabled={!canSave}
                  />
                </div>
              )}

              {/* Excerpt */}
              {hasField('excerpt') && (
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
              )}

              {/* Featured toggle */}
              {hasField('is_featured') && (
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
              )}
            </CardContent>
          </Card>
        </div>

        {/* Right Column: Publishing, Slug, Taxonomies, Revisions, Author, Featured Image */}
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
            onPreview={handlePreview}
            onViewOnSite={handleViewOnSite}
            onSubmitForReview={() => successToast('Submitted entry for review.')}
          />

          {hasField('featured_image') && (
            <FeaturedImagePanel
              featuredImage={featuredImage}
              onSelect={setFeaturedImage}
            />
          )}

          {(hasField('author_id') || hasField('authors')) && (
            <AuthorPanel
              authorId={authorId}
              onAuthorChange={setAuthorId}
            />
          )}

          {hasField('categories') && (
            <TaxonomyPanel
              taxonomyHandle="categories"
              taxonomyTitle="Categories"
              selectedTermIds={categoryIds}
              onChange={setCategoryIds}
            />
          )}

          <RevisionsPanel
            collectionHandle={collectionHandle}
            entryId={entryId}
            currentVersion={version}
            onRestoreVersion={handleRestoreVersion}
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
