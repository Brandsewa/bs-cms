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
} from '@bs/ui';
import { Calendar, Clock, Globe, ShieldAlert } from 'lucide-react';

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
          <select
            id="entry-status-select"
            value={status}
            onChange={(e) => onStatusChange(e.target.value as any)}
            className="w-full rounded-md border border-border bg-surface-100 px-2.5 py-1.5 text-xs text-foreground focus:border-ring focus:outline-none focus:ring-1 focus:ring-ring"
          >
            <option value="draft">Draft</option>
            <option value="in_review">In review</option>
            <option value="published">Published</option>
            <option value="scheduled">Scheduled</option>
            <option value="archived">Archived</option>
          </select>
        </div>

        {/* Schedule / Published date */}
        {status === 'scheduled' ? (
          <div className="space-y-1.5">
            <Label htmlFor="entry-scheduled-at" className="text-xs font-medium text-foreground-light flex items-center gap-1.5">
              <Calendar className="h-3.5 w-3.5 text-muted-foreground" />
              Schedule Release
            </Label>
            <Input
              id="entry-scheduled-at"
              type="datetime-local"
              value={scheduledAt || ''}
              onChange={(e) => onScheduledAtChange?.(e.target.value)}
              className="text-xs"
            />
          </div>
        ) : null}

        {status === 'published' ? (
          <div className="space-y-1.5">
            <Label htmlFor="entry-published-at" className="text-xs font-medium text-foreground-light flex items-center gap-1.5">
              <Clock className="h-3.5 w-3.5 text-muted-foreground" />
              Published At
            </Label>
            <Input
              id="entry-published-at"
              type="datetime-local"
              value={publishedAt || ''}
              onChange={(e) => onPublishedAtChange?.(e.target.value)}
              className="text-xs"
            />
          </div>
        ) : null}

        {/* Concurrency version indicator */}
        <div className="flex items-center justify-between text-xs text-muted-foreground pt-1">
          <span>Revision version</span>
          <span className="font-mono text-foreground-lighter">v{version}</span>
        </div>

        <Separator />

        {/* Action buttons */}
        <div className="flex flex-col gap-2 pt-1">
          {canPublish && status !== 'published' ? (
            <Button
              type="button"
              variant="default"
              size="sm"
              disabled={saving}
              onClick={() => {
                onStatusChange('published');
                onSave('published');
              }}
              className="w-full justify-center"
            >
              {saving ? 'Publishing...' : 'Publish'}
            </Button>
          ) : null}

          <Button
            type="button"
            variant={status === 'published' ? 'default' : 'secondary'}
            size="sm"
            disabled={saving}
            onClick={() => onSave()}
            className="w-full justify-center"
          >
            {saving ? 'Saving...' : 'Save changes'}
          </Button>

          {status !== 'draft' ? (
            <Button
              type="button"
              variant="ghost"
              size="sm"
              disabled={saving}
              onClick={() => {
                onStatusChange('draft');
                onSave('draft');
              }}
              className="w-full justify-center text-xs text-muted-foreground"
            >
              Revert to Draft
            </Button>
          ) : null}
        </div>
      </CardContent>
    </Card>
  );
}

export interface SeoPanelProps {
  slug: string;
  onSlugChange: (slug: string) => void;
  title: string;
  metaTitle: string;
  onMetaTitleChange: (v: string) => void;
  metaDescription: string;
  onMetaDescriptionChange: (v: string) => void;
  urlPrefix?: string;
}

export function SeoPanel({
  slug,
  onSlugChange,
  title,
  metaTitle,
  onMetaTitleChange,
  metaDescription,
  onMetaDescriptionChange,
  urlPrefix = 'https://example.com/posts/',
}: SeoPanelProps) {
  const metaTitleCount = metaTitle.length;
  const metaDescCount = metaDescription.length;

  return (
    <Card className="border-border bg-card">
      <CardHeader className="p-4 pb-2 border-b border-border">
        <div className="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wider text-foreground-lighter">
          <Globe className="h-3.5 w-3.5" />
          <span>SEO & Metadata</span>
        </div>
      </CardHeader>
      <CardContent className="p-4 space-y-4">
        {/* Slug input with auto-derive */}
        <div className="space-y-1.5">
          <Label className="text-xs font-medium text-foreground-light">URL Slug</Label>
          <SlugInput
            value={slug}
            source={title}
            onChange={onSlugChange}
            urlPrefix={urlPrefix}
            aria-label="URL slug"
          />
        </div>

        {/* Meta Title */}
        <div className="space-y-1.5">
          <div className="flex items-center justify-between">
            <Label htmlFor="seo-meta-title" className="text-xs font-medium text-foreground-light">
              Meta Title
            </Label>
            <span
              className={`text-xs ${
                metaTitleCount > 60 ? 'text-warning' : 'text-muted-foreground'
              }`}
            >
              {metaTitleCount} / 60
            </span>
          </div>
          <Input
            id="seo-meta-title"
            value={metaTitle}
            onChange={(e) => onMetaTitleChange(e.target.value)}
            placeholder={title || 'Search engine title'}
            maxLength={70}
            className="text-xs"
          />
        </div>

        {/* Meta Description */}
        <div className="space-y-1.5">
          <div className="flex items-center justify-between">
            <Label htmlFor="seo-meta-desc" className="text-xs font-medium text-foreground-light">
              Meta Description
            </Label>
            <span
              className={`text-xs ${
                metaDescCount > 160 ? 'text-warning' : 'text-muted-foreground'
              }`}
            >
              {metaDescCount} / 160
            </span>
          </div>
          <Textarea
            id="seo-meta-desc"
            value={metaDescription}
            onChange={(e) => onMetaDescriptionChange(e.target.value)}
            placeholder="Brief summary shown in search results..."
            rows={3}
            maxLength={180}
            className="text-xs resize-none"
          />
        </div>

        {/* SERP Search Result Preview */}
        <div className="rounded-md border border-border bg-surface-100 p-3 text-left">
          <div className="text-[11px] text-muted-foreground truncate font-mono">
            {urlPrefix}{slug || 'post-slug'}
          </div>
          <div className="text-xs font-medium text-primary hover:underline cursor-pointer truncate mt-0.5">
            {metaTitle || title || 'Post Title Preview'}
          </div>
          <div className="text-[11px] text-foreground-light line-clamp-2 mt-0.5">
            {metaDescription || 'Add a meta description to see how your article appears in Google search engine result snippets.'}
          </div>
        </div>
      </CardContent>
    </Card>
  );
}

export interface ConflictDialogProps {
  open: boolean;
  onClose: () => void;
  onOverwrite: () => void;
  onReload: () => void;
  message?: string;
}

export function ConflictDialog({
  open,
  onClose,
  onOverwrite,
  onReload,
  message,
}: ConflictDialogProps) {
  if (!open) return null;

  return (
    <div
      role="dialog"
      aria-modal="true"
      aria-labelledby="conflict-dialog-title"
      className="fixed inset-0 z-50 flex items-center justify-center bg-black/60 p-4"
    >
      <div className="w-full max-w-md rounded-lg border border-border bg-card p-6 shadow-xl space-y-4">
        <div className="flex items-center gap-2.5 text-warning">
          <ShieldAlert className="h-5 w-5 shrink-0" />
          <h2 id="conflict-dialog-title" className="text-base font-semibold text-foreground">
            Version Conflict (HTTP 409)
          </h2>
        </div>
        <p className="text-xs text-muted-foreground leading-relaxed">
          {message ||
            'This entry was modified by another editor or agent while you were editing. Overwriting will replace their version, or you can reload to inspect the newest version.'}
        </p>
        <div className="flex items-center justify-end gap-2 pt-2">
          <Button type="button" variant="ghost" size="sm" onClick={onClose}>
            Cancel
          </Button>
          <Button type="button" variant="secondary" size="sm" onClick={onReload}>
            Reload Newest
          </Button>
          <Button type="button" variant="destructive" size="sm" onClick={onOverwrite}>
            Overwrite (Force)
          </Button>
        </div>
      </div>
    </div>
  );
}
