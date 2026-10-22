import { useState, useEffect, useCallback } from 'react';
import {
  Button,
  Card,
  CardHeader,
  CardContent,
  Label,
  Input,
  Textarea,
  Separator,
  StatusBadge,
  type EntryStatus,
  SlugInput,
  Select,
  SelectTrigger,
  SelectValue,
  SelectContent,
  SelectItem,
  DateTimePicker,
  Dialog,
  DialogContent,
  DialogHeader,
  DialogTitle,
  DialogFooter,
  Badge,
  Spinner,
} from '@bs/ui';
import {
  Calendar,
  Clock,
  Globe,
  ShieldAlert,
  Send,
  Eye,
  History,
  Image as ImageIcon,
  Tag as TagIcon,
  User as UserIcon,
  RefreshCw,
  Plus,
} from 'lucide-react';
import { apiClient } from '../lib/api';
import { MediaPickerModal, type MediaItem } from '../media/media-picker-modal';

export interface PublishPanelProps {
  status: 'draft' | 'published' | 'in_review' | 'scheduled' | 'archived';
  onStatusChange: (status: 'draft' | 'published' | 'in_review' | 'scheduled' | 'archived') => void;
  version?: number;
  publishedAt?: string | null;
  scheduledAt?: string | null;
  onPublishedAtChange?: (date: string | null) => void;
  onScheduledAtChange?: (date: string | null) => void;
  onSave: (targetStatus?: 'draft' | 'published' | 'in_review') => void;
  saving?: boolean;
  canPublish?: boolean;
  onPreview?: () => void;
  onViewOnSite?: () => void;
  onSubmitForReview?: () => void;
}

export function PublishPanel({
  status,
  onStatusChange,
  version = 1,
  publishedAt,
  scheduledAt,
  onPublishedAtChange,
  onScheduledAtChange,
  onSave,
  saving = false,
  canPublish = true,
  onPreview,
  onViewOnSite,
  onSubmitForReview,
}: PublishPanelProps) {
  return (
    <Card className="border-border bg-card">
      <CardHeader className="p-4 pb-2 border-b border-border">
        <div className="flex items-center justify-between">
          <span className="text-xs font-semibold uppercase tracking-wider text-foreground-lighter">
            Publishing
          </span>
          <StatusBadge status={status as EntryStatus} />
        </div>
      </CardHeader>
      <CardContent className="p-4 space-y-4">
        {/* Status selection */}
        <div className="space-y-1.5">
          <Label htmlFor="entry-status-select" className="text-xs font-medium text-foreground-light">
            Status
          </Label>
          <Select
            value={status}
            onValueChange={(val) => onStatusChange(val as 'draft' | 'published' | 'in_review' | 'scheduled' | 'archived')}
          >
            <SelectTrigger id="entry-status-select" className="w-full text-xs">
              <SelectValue placeholder="Select status" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="draft">Draft</SelectItem>
              <SelectItem value="in_review">In review</SelectItem>
              <SelectItem value="published">Published</SelectItem>
              <SelectItem value="scheduled">Scheduled</SelectItem>
              <SelectItem value="archived">Archived</SelectItem>
            </SelectContent>
          </Select>
        </div>

        {/* Schedule / Published date */}
        {status === 'scheduled' ? (
          <div className="space-y-1.5">
            <Label className="text-xs font-medium text-foreground-light flex items-center gap-1.5">
              <Calendar className="h-3.5 w-3.5 text-muted-foreground" />
              Schedule Release
            </Label>
            <DateTimePicker
              value={scheduledAt ? new Date(scheduledAt) : undefined}
              onChange={(date) => onScheduledAtChange?.(date ? date.toISOString() : null)}
              className="text-xs w-full"
            />
          </div>
        ) : null}

        {status === 'published' ? (
          <div className="space-y-1.5">
            <Label className="text-xs font-medium text-foreground-light flex items-center gap-1.5">
              <Clock className="h-3.5 w-3.5 text-muted-foreground" />
              Published At
            </Label>
            <DateTimePicker
              value={publishedAt ? new Date(publishedAt) : undefined}
              onChange={(date) => onPublishedAtChange?.(date ? date.toISOString() : null)}
              className="text-xs w-full"
            />
          </div>
        ) : null}

        <div className="text-[11px] text-muted-foreground flex justify-between">
          <span>Snapshot version:</span>
          <span className="font-mono font-medium text-foreground">v{version}</span>
        </div>

        <Separator className="bg-border" />

        {/* Preview and View on site buttons */}
        <div className="flex gap-2">
          {onPreview && (
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={onPreview}
              className="flex-1 text-xs"
            >
              <Eye className="h-3.5 w-3.5 mr-1 text-muted-foreground" />
              Preview
            </Button>
          )}
          {onViewOnSite && (
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={onViewOnSite}
              className="flex-1 text-xs"
            >
              <Globe className="h-3.5 w-3.5 mr-1 text-muted-foreground" />
              View Site
            </Button>
          )}
        </div>

        {/* Action Buttons */}
        <div className="space-y-2 pt-1">
          <Button
            type="button"
            variant="outline"
            className="w-full text-xs"
            disabled={saving}
            onClick={() => onSave('draft')}
          >
            {saving ? 'Saving...' : 'Save Draft'}
          </Button>

          {canPublish ? (
            <Button
              type="button"
              className="w-full text-xs font-medium"
              disabled={saving}
              onClick={() => onSave('published')}
            >
              {saving ? 'Publishing...' : status === 'published' ? 'Update & Keep Published' : 'Publish Now'}
            </Button>
          ) : (
            <Button
              type="button"
              className="w-full text-xs font-medium"
              disabled={saving}
              onClick={() => {
                onStatusChange('in_review');
                onSave('in_review');
                onSubmitForReview?.();
              }}
            >
              <Send className="h-3.5 w-3.5 mr-1.5" />
              Submit for Review
            </Button>
          )}
        </div>
      </CardContent>
    </Card>
  );
}

// -------------------------------------------------------------
// Revisions Panel
// -------------------------------------------------------------
export interface RevisionItem {
  id: string;
  version_number: number;
  created_at: string;
  author_name?: string;
  summary?: string;
}

export interface RevisionsPanelProps {
  collectionHandle: string;
  entryId?: string;
  currentVersion?: number;
  onRestoreVersion: (versionId: string) => void;
}

export function RevisionsPanel({
  collectionHandle,
  entryId,
  currentVersion,
  onRestoreVersion,
}: RevisionsPanelProps) {
  const [open, setOpen] = useState(false);
  const [loading, setLoading] = useState(false);
  const [revisions, setRevisions] = useState<RevisionItem[]>([]);
  const [selectedRevision, setSelectedRevision] = useState<RevisionItem | null>(null);

  const fetchVersions = async () => {
    if (!entryId || entryId === 'new') return;
    setLoading(true);
    try {
      const res = await apiClient.request<RevisionItem[] | { data: RevisionItem[] }>(
        'GET',
        `/collections/${collectionHandle}/entries/${entryId}/versions`
      );
      if (res.ok && res.data) {
        const list = Array.isArray(res.data) ? res.data : (res.data as { data?: RevisionItem[] })?.data || [];
        setRevisions(list);
      }
    } catch {
      // Ignored
    } finally {
      setLoading(false);
    }
  };

  const handleOpen = () => {
    setOpen(true);
    fetchVersions();
  };

  return (
    <>
      <Card className="border-border bg-card">
        <CardHeader className="p-4 pb-2 border-b border-border flex flex-row items-center justify-between">
          <span className="text-xs font-semibold uppercase tracking-wider text-foreground-lighter flex items-center gap-1.5">
            <History className="h-3.5 w-3.5 text-muted-foreground" />
            Revision History
          </span>
          <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={handleOpen}
            disabled={!entryId || entryId === 'new'}
            className="text-xs h-7 px-2"
          >
            Browse
          </Button>
        </CardHeader>
        <CardContent className="p-4 text-xs text-muted-foreground">
          Current snapshot v{currentVersion || 1}. Track changes and restore past revisions.
        </CardContent>
      </Card>

      <Dialog open={open} onOpenChange={setOpen}>
        <DialogContent className="max-w-xl bg-background">
          <DialogHeader>
            <DialogTitle className="text-sm font-semibold flex items-center justify-between">
              <span>Entry Version History</span>
              <Button size="sm" variant="outline" onClick={fetchVersions} disabled={loading} className="h-7 text-xs">
                <RefreshCw className="h-3 w-3 mr-1" />
                Refresh
              </Button>
            </DialogTitle>
          </DialogHeader>
          <div className="py-2 max-h-[350px] overflow-y-auto space-y-2">
            {loading ? (
              <div className="flex justify-center p-6">
                <Spinner className="h-5 w-5 text-primary" />
              </div>
            ) : revisions.length === 0 ? (
              <p className="text-xs text-muted-foreground text-center py-6">No previous versions found.</p>
            ) : (
              revisions.map((rev) => (
                <div
                  key={rev.id}
                  onClick={() => setSelectedRevision(rev)}
                  className={`p-2.5 rounded-md border text-xs cursor-pointer flex items-center justify-between transition-colors ${
                    selectedRevision?.id === rev.id ? 'border-primary bg-primary/5' : 'border-border hover:bg-surface-100'
                  }`}
                >
                  <div>
                    <div className="font-semibold text-foreground flex items-center gap-2">
                      <span>Version {rev.version_number}</span>
                      {rev.version_number === currentVersion && (
                        <Badge variant="secondary" className="text-[10px] py-0">Current</Badge>
                      )}
                    </div>
                    <p className="text-[11px] text-muted-foreground">
                      {new Date(rev.created_at).toLocaleString()} • {rev.author_name || 'System / Author'}
                    </p>
                  </div>
                  <Button
                    size="sm"
                    variant="outline"
                    onClick={(e) => {
                      e.stopPropagation();
                      onRestoreVersion(rev.id);
                      setOpen(false);
                    }}
                    className="h-7 text-xs"
                  >
                    Restore
                  </Button>
                </div>
              ))
            )}
          </div>
          <DialogFooter>
            <Button size="sm" variant="outline" onClick={() => setOpen(false)} className="text-xs">
              Close
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  );
}

// -------------------------------------------------------------
// Taxonomy Panel (Categories / Tags Picker with Inline Create)
// -------------------------------------------------------------
export interface TaxonomyTerm {
  id: string;
  name: string;
  slug: string;
  parent_id?: string | null;
}

export interface TaxonomyPanelProps {
  taxonomyHandle?: string;
  taxonomyTitle?: string;
  selectedTermIds: string[];
  onChange: (ids: string[]) => void;
}

export function TaxonomyPanel({
  taxonomyHandle = 'categories',
  taxonomyTitle = 'Categories',
  selectedTermIds,
  onChange,
}: TaxonomyPanelProps) {
  const [terms, setTerms] = useState<TaxonomyTerm[]>([]);
  const [loading, setLoading] = useState(false);
  const [newTermName, setNewTermName] = useState('');
  const [creating, setCreating] = useState(false);

  const loadTerms = useCallback(async () => {
    setLoading(true);
    try {
      const res = await apiClient.request<TaxonomyTerm[] | { data: TaxonomyTerm[] }>(
        'GET',
        `/taxonomies/${taxonomyHandle}/terms`
      );
      if (res.ok && res.data) {
        const list = Array.isArray(res.data) ? res.data : (res.data as { data?: TaxonomyTerm[] })?.data || [];
        setTerms(list);
      }
    } catch {
      // Ignored
    } finally {
      setLoading(false);
    }
  }, [taxonomyHandle]);

  useEffect(() => {
    loadTerms();
  }, [loadTerms]);

  const handleToggleTerm = (id: string) => {
    if (selectedTermIds.includes(id)) {
      onChange(selectedTermIds.filter((t) => t !== id));
    } else {
      onChange([...selectedTermIds, id]);
    }
  };

  const handleCreateTerm = async () => {
    if (!newTermName.trim()) return;
    setCreating(true);
    try {
      const res = await apiClient.request<TaxonomyTerm>(
        'POST',
        `/taxonomies/${taxonomyHandle}/terms`,
        {
          body: {
            name: newTermName.trim(),
            slug: newTermName.toLowerCase().replace(/[^a-z0-9]+/g, '-'),
          },
        }
      );
      if (res.ok && res.data) {
        setTerms((prev) => [...prev, res.data!]);
        onChange([...selectedTermIds, res.data.id]);
        setNewTermName('');
      }
    } catch {
      // Ignored
    } finally {
      setCreating(false);
    }
  };

  return (
    <Card className="border-border bg-card">
      <CardHeader className="p-4 pb-2 border-b border-border flex flex-row items-center justify-between">
        <span className="text-xs font-semibold uppercase tracking-wider text-foreground-lighter flex items-center gap-1.5">
          <TagIcon className="h-3.5 w-3.5 text-muted-foreground" />
          {taxonomyTitle}
        </span>
      </CardHeader>
      <CardContent className="p-4 space-y-3">
        <div className="max-h-36 overflow-y-auto space-y-1.5 pr-1">
          {terms.map((t) => {
            const isChecked = selectedTermIds.includes(t.id);
            return (
              <label
                key={t.id}
                className="flex items-center gap-2 text-xs text-foreground cursor-pointer hover:bg-surface-100 p-1 rounded"
              >
                <input
                  type="checkbox"
                  checked={isChecked}
                  onChange={() => handleToggleTerm(t.id)}
                  className="rounded border-border"
                />
                <span className="truncate">{t.name}</span>
              </label>
            );
          })}
          {terms.length === 0 && !loading && (
            <p className="text-xs text-muted-foreground italic">No terms defined.</p>
          )}
        </div>

        {/* Inline Create Term */}
        <div className="flex gap-1.5 pt-1">
          <Input
            value={newTermName}
            onChange={(e) => setNewTermName(e.target.value)}
            placeholder={`Add new ${taxonomyTitle.toLowerCase()}...`}
            className="text-xs h-7"
            onKeyDown={(e) => {
              if (e.key === 'Enter') {
                e.preventDefault();
                handleCreateTerm();
              }
            }}
          />
          <Button
            size="sm"
            variant="outline"
            onClick={handleCreateTerm}
            disabled={creating || !newTermName.trim()}
            className="text-xs h-7 px-2"
            aria-label={`Add ${taxonomyTitle.toLowerCase()}`}
          >
            <Plus className="h-3 w-3" />
          </Button>
        </div>
      </CardContent>
    </Card>
  );
}

// -------------------------------------------------------------
// Author Panel
// -------------------------------------------------------------
export interface AuthorUser {
  id: string;
  name: string;
  email: string;
}

export interface AuthorPanelProps {
  authorId?: string | null;
  onAuthorChange: (id: string | null) => void;
}

export function AuthorPanel({ authorId, onAuthorChange }: AuthorPanelProps) {
  const [users, setUsers] = useState<AuthorUser[]>([]);

  useEffect(() => {
    async function loadUsers() {
      try {
        const res = await apiClient.request<AuthorUser[] | { data: AuthorUser[] }>('GET', '/users');
        if (res.ok && res.data) {
          const raw = res.data as { items?: AuthorUser[]; data?: { items?: AuthorUser[] } | AuthorUser[] };
          const list: AuthorUser[] = Array.isArray(res.data)
            ? (res.data as AuthorUser[])
            : Array.isArray(raw.items)
              ? raw.items
              : raw.data && typeof raw.data === 'object' && Array.isArray((raw.data as { items?: AuthorUser[] }).items)
                ? ((raw.data as { items: AuthorUser[] }).items)
                : Array.isArray(raw.data)
                  ? (raw.data as AuthorUser[])
                  : [];
          setUsers(list);
        }
      } catch {
        // Ignored
      }
    }
    loadUsers();
  }, []);

  return (
    <Card className="border-border bg-card">
      <CardHeader className="p-4 pb-2 border-b border-border">
        <span className="text-xs font-semibold uppercase tracking-wider text-foreground-lighter flex items-center gap-1.5">
          <UserIcon className="h-3.5 w-3.5 text-muted-foreground" />
          Author
        </span>
      </CardHeader>
      <CardContent className="p-4 space-y-2">
        <Select
          value={authorId || 'none'}
          onValueChange={(val) => onAuthorChange(val === 'none' ? null : val)}
        >
          <SelectTrigger className="w-full text-xs" aria-label="Author">
            <SelectValue placeholder="Assign author" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="none">Current User (Default)</SelectItem>
            {users.map((u) => (
              <SelectItem key={u.id} value={u.id}>
                {u.name || u.email}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </CardContent>
    </Card>
  );
}

// -------------------------------------------------------------
// Featured Image Panel
// -------------------------------------------------------------
export interface FeaturedImagePanelProps {
  featuredImage?: MediaItem | null;
  onSelect: (item: MediaItem | null) => void;
}

export function FeaturedImagePanel({ featuredImage, onSelect }: FeaturedImagePanelProps) {
  const [pickerOpen, setPickerOpen] = useState(false);

  return (
    <Card className="border-border bg-card">
      <CardHeader className="p-4 pb-2 border-b border-border flex flex-row items-center justify-between">
        <span className="text-xs font-semibold uppercase tracking-wider text-foreground-lighter flex items-center gap-1.5">
          <ImageIcon className="h-3.5 w-3.5 text-muted-foreground" />
          Featured Image
        </span>
      </CardHeader>
      <CardContent className="p-4 space-y-3">
        {featuredImage ? (
          <div className="space-y-2">
            <div className="relative aspect-video rounded-md overflow-hidden border border-border bg-surface-100">
              <img
                src={featuredImage.url || `/api/v1/public/media/${featuredImage.id}/file`}
                alt={featuredImage.alt || 'Featured preview'}
                className="w-full h-full object-cover"
              />
            </div>
            <div className="flex gap-2">
              <Button
                size="sm"
                variant="outline"
                onClick={() => setPickerOpen(true)}
                className="flex-1 text-xs h-7"
              >
                Replace
              </Button>
              <Button
                size="sm"
                variant="ghost"
                onClick={() => onSelect(null)}
                className="text-xs h-7 text-destructive"
              >
                Remove
              </Button>
            </div>
          </div>
        ) : (
          <Button
            type="button"
            variant="outline"
            onClick={() => setPickerOpen(true)}
            className="w-full border-dashed text-xs py-6 flex flex-col gap-1 items-center justify-center text-muted-foreground"
          >
            <ImageIcon className="h-5 w-5" />
            <span>Select Featured Image</span>
          </Button>
        )}

        <MediaPickerModal
          open={pickerOpen}
          onClose={() => setPickerOpen(false)}
          onSelect={(img) => {
            onSelect(img);
            setPickerOpen(false);
          }}
        />
      </CardContent>
    </Card>
  );
}

// -------------------------------------------------------------
// SeoPanel & ConflictDialog
// -------------------------------------------------------------
export interface SeoPanelProps {
  slug: string;
  onSlugChange: (slug: string) => void;
  title?: string;
  metaTitle: string;
  onMetaTitleChange: (val: string) => void;
  metaDescription: string;
  onMetaDescriptionChange: (val: string) => void;
  urlPrefix?: string;
}

export function SeoPanel({
  slug,
  onSlugChange,
  title = '',
  metaTitle,
  onMetaTitleChange,
  metaDescription,
  onMetaDescriptionChange,
  urlPrefix = 'https://example.com/',
}: SeoPanelProps) {
  const displayTitle = metaTitle || title || 'Untitled Entry';
  const displayDesc = metaDescription || 'No description preview available.';

  return (
    <Card className="border-border bg-card">
      <CardHeader className="p-4 pb-2 border-b border-border">
        <div className="flex items-center justify-between">
          <span className="text-xs font-semibold uppercase tracking-wider text-foreground-lighter flex items-center gap-1.5">
            <Globe className="h-3.5 w-3.5 text-muted-foreground" />
            SEO & Discoverability
          </span>
        </div>
      </CardHeader>
      <CardContent className="p-4 space-y-4">
        <div className="space-y-1.5">
          <Label className="text-xs font-medium text-foreground-light">URL Slug</Label>
          <SlugInput
            value={slug}
            onChange={onSlugChange}
            source={title}
            className="text-xs"
            aria-label="URL slug"
          />
        </div>

        <div className="space-y-1.5">
          <div className="flex justify-between items-center">
            <Label htmlFor="seo-meta-title" className="text-xs font-medium text-foreground-light">Meta Title</Label>
            <span className="text-[10px] text-muted-foreground font-mono">{metaTitle.length}/60</span>
          </div>
          <Input
            id="seo-meta-title"
            value={metaTitle}
            onChange={(e) => onMetaTitleChange(e.target.value)}
            placeholder={title || 'Leave blank to use main title'}
            maxLength={70}
            className="text-xs"
          />
        </div>

        <div className="space-y-1.5">
          <div className="flex justify-between items-center">
            <Label htmlFor="seo-meta-desc" className="text-xs font-medium text-foreground-light">Meta Description</Label>
            <span className="text-[10px] text-muted-foreground font-mono">{metaDescription.length}/160</span>
          </div>
          <Textarea
            id="seo-meta-desc"
            value={metaDescription}
            onChange={(e) => onMetaDescriptionChange(e.target.value)}
            placeholder="Search snippet description..."
            rows={2}
            maxLength={180}
            className="text-xs resize-none"
          />
        </div>

        <Separator className="bg-border" />

        <div className="space-y-1">
          <span className="text-[11px] font-semibold text-foreground-lighter uppercase tracking-wider">
            SERP Snippet Preview
          </span>
          <div className="rounded-md border border-border bg-surface-100 p-2.5 text-xs font-sans space-y-0.5">
            <p className="text-[11px] text-muted-foreground truncate">{urlPrefix}{slug || 'slug'}</p>
            <p className="text-xs font-medium text-primary hover:underline line-clamp-1">{displayTitle}</p>
            <p className="text-[11px] text-foreground-muted line-clamp-2">{displayDesc}</p>
          </div>
        </div>
      </CardContent>
    </Card>
  );
}

export interface ConflictDialogProps {
  open: boolean;
  message?: string;
  onClose: () => void;
  onReload: () => void;
  onOverwrite: () => void;
}

export function ConflictDialog({
  open,
  message,
  onClose,
  onReload,
  onOverwrite,
}: ConflictDialogProps) {
  return (
    <Dialog open={open} onOpenChange={(val) => !val && onClose()}>
      <DialogContent className="max-w-md bg-background">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2 text-destructive text-sm font-semibold">
            <ShieldAlert className="h-4 w-4" />
            Save Conflict (409)
          </DialogTitle>
        </DialogHeader>
        <div className="py-2 text-xs text-foreground space-y-2">
          <p>
            {message ||
              'This document has been updated by another user or session since you loaded it.'}
          </p>
          <p className="text-muted-foreground">
            Reloading will discard your unsaved changes and bring in the latest published version.
            Overwriting will force your changes on top of the newer revision.
          </p>
        </div>
        <DialogFooter className="gap-2 sm:gap-2">
          <Button variant="outline" size="sm" onClick={onClose} className="text-xs">
            Cancel
          </Button>
          <Button variant="outline" size="sm" onClick={onReload} className="text-xs">
            Reload Latest
          </Button>
          <Button variant="destructive" size="sm" onClick={onOverwrite} className="text-xs">
            Overwrite
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
