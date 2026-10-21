import { useState } from 'react';
import {
  Button,
  Input,
  Label,
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@bs/ui';
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
    } catch (err: unknown) {
      const errMsg = err instanceof Error ? err.message : 'Failed to update entry';
      setError(errMsg);
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
        <Select
          value={status}
          onValueChange={(val) => setStatus(val as EntryItem['status'])}
        >
          <SelectTrigger id="quick-edit-status">
            <SelectValue placeholder="Select status" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="draft">Draft</SelectItem>
            <SelectItem value="in_review">In Review</SelectItem>
            <SelectItem value="published">Published</SelectItem>
            <SelectItem value="archived">Archived</SelectItem>
          </SelectContent>
        </Select>
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
