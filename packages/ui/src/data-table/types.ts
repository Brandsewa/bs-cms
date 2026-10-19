import type { ReactNode } from 'react';

/**
 * Cell renderers per field type (docs/02-ui-spec.md 3.1): text truncates
 * with tooltip, status badge, relative date with exact tooltip, boolean
 * icon, right-aligned tabular number, relation chips with +N overflow,
 * taxonomy chips with colour, media thumbnail, user avatar + name, URL
 * link, tags.
 */

export type FieldType =
  | 'text'
  | 'status'
  | 'date'
  | 'datetime'
  | 'boolean'
  | 'number'
  | 'relation'
  | 'taxonomy'
  | 'media'
  | 'user'
  | 'url'
  | 'tags';

export interface StatusValue {
  key: 'draft' | 'in_review' | 'scheduled' | 'published' | 'archived' | 'trashed' | 'ai_created';
  label?: string;
}

export interface ReferencedItem {
  id: string;
  label: string;
  colour?: string;
  mediaUrl?: string;
}

export interface CellRendererContext {
  /** Renders a RelativeTime tooltip for date cells. */
  renderRelative?: (date: Date) => ReactNode;
}

export function truncateMiddle(value: string, max = 40): string {
  if (value.length <= max) return value;
  return `${value.slice(0, max - 1)}…`;
}
