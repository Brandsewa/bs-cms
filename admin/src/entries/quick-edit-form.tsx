import { useState } from 'react';
import { Button, Input, Label } from '@bs/ui';
import type { EntryItem } from './types';

interface QuickEditFormProps {
  entry: EntryItem;
  onSave: (patch: { title: string; slug: string; status: EntryItem['status'] }) => Promise<void>;
  onClose?: () => void;
}

export function QuickEditForm({ entry, onSave, onClose }: QuickEditFormProps) {
  const [title, setTitle] = useState(entry.title || '');
  const [slug, setSlug] = useState(entry.slug || '');
  const [status, setStatus] = useState<EntryItem['status']>(entry.status || 'draft');
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setError(null);
    setIsSubmitting(true);
    try {
      await onSave({ title, slug, status });
      onClose?.();
    } catch (err: any) {
      setError(err?.message || 'Failed to update entry');
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <form onSubmit={handleSubmit} className="space-y-4 py-2">
      {error && (
        <div className="rounded-md bg-destructive/10 p-2 text-xs text-destructive">
          {error}
        </div>
      )}
      <div className="space-y-1.5">
        <Label htmlFor="quick-edit-title">Title</Label>
        <Input
          id="quick-edit-title"
          value={title}
          onChange={(e) => setTitle(e.target.value)}
          required
        />
      </div>

      <div className="space-y-1.5">
        <Label htmlFor="quick-edit-slug">Slug</Label>
        <Input
          id="quick-edit-slug"
          value={slug}
          onChange={(e) => setSlug(e.target.value)}
          required
        />
      </div>

      <div className="space-y-1.5">
        <Label htmlFor="quick-edit-status">Status</Label>
        <select
          id="quick-edit-status"
          value={status}
          onChange={(e) => setStatus(e.target.value as EntryItem['status'])}
          className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-xs transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
        >
          <option value="draft">Draft</option>
          <option value="in_review">In Review</option>
          <option value="published">Published</option>
          <option value="archived">Archived</option>
        </select>
      </div>

      <div className="flex justify-end gap-2 pt-2">
        <Button
          type="submit"
          size="sm"
          disabled={isSubmitting}
        >
          {isSubmitting ? 'Saving...' : 'Save changes'}
        </Button>
      </div>
    </form>
  );
}
