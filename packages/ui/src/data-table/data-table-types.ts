import type { ReactNode } from 'react';
import type { FieldType, ReferencedItem, StatusValue } from './types';

export interface DataTableColumn<T> {
  id: string;
  header: string;
  /** Raw value used for sorting, searching and export. */
  accessor: (row: T) => unknown;
  /** Custom cell renderer; defaults to the fieldType renderer. */
  cell?: (row: T) => ReactNode;
  fieldType: FieldType;
  sortable?: boolean;
  /** Included in the search box (default false). */
  searchable?: boolean;
  /** Right-aligned (numbers). */
  align?: 'left' | 'right';
  width?: number;
  minWidth?: number;
  /** Default false — set true on the title column (sticky left). */
  sticky?: boolean;
  /** Hidden until enabled via the Columns menu. */
  defaultHidden?: boolean;
  /** Column cannot be hidden via the menu (e.g. selection, title). */
  enableHiding?: boolean;
}

export interface BulkFailure<T> {
  row: T;
  reason: string;
}

export interface BulkActionOutcome<T> {
  succeeded: T[];
  failed: Array<BulkFailure<T>>;
}

export interface DataTableBulkAction<T> {
  id: string;
  label: string;
  icon?: React.ComponentType<{ className?: string }>;
  destructive?: boolean;
  /**
   * Confirmation: 'confirm' shows a plain confirm dialog; type-to-confirm
   * requires typing a phrase derived from the rows (bulk delete of >10
   * items or published items, docs/02-ui-spec.md 3.1).
   */
  confirm?:
    | 'confirm'
    | { type: 'type-to-confirm'; phrase: (rows: T[]) => string; body?: string }
    | 'none';
  /** Permission gate: hidden when it returns false. */
  hidden?: (rows: T[]) => boolean;
  /** Per-item execution; partial failures are reported and retriable. */
  run: (rows: T[]) => Promise<BulkActionOutcome<T>>;
}

export type FilterType =
  | 'text'
  | 'number'
  | 'date'
  | 'enum'
  | 'boolean'
  | 'relation'
  | 'taxonomy'
  | 'status';

export interface DataTableFilter {
  id: string;
  label: string;
  type: FilterType;
  columnId: string;
  /** enum/status/relation/taxonomy options. */
  options?: Array<{ value: string; label: string }>;
  /** taxonomy filters gain an "include subcategories" toggle. */
  withSubcategories?: boolean;
}

export type FilterValue =
  | { type: 'text'; contains: string }
  | { type: 'number'; min?: number; max?: number }
  | { type: 'date'; from?: Date; to?: Date }
  | { type: 'enum'; values: string[] }
  | { type: 'boolean'; value: boolean }
  | { type: 'relation'; ids: string[]; includeSubcategories?: boolean };

export interface ActiveFilter {
  filterId: string;
  value: FilterValue;
}

export interface SavedView {
  id: string;
  label: string;
  /** Serialized state: filters + sort + columns + density. */
  state: {
    filters: ActiveFilter[];
    sorting: Array<{ id: string; desc: boolean }>;
    columnVisibility: Record<string, boolean>;
    density: Density;
  };
  /** Role keys the view is shared with (permission to share comes from API). */
  sharedWithRoles?: string[];
  /** Marks views persisted on the server (saved_views table). */
  onServer?: boolean;
}

export interface StatusTab {
  id: string;
  label: string;
  count?: number;
}

export type Density = 'compact' | 'default' | 'comfortable';

export const DENSITY_ROW_HEIGHT: Record<Density, string> = {
  compact: 'var(--density-row-compact)',
  default: 'var(--density-row-default)',
  comfortable: 'var(--density-row-comfortable)',
};

export interface RowMenuItem<T> {
  id: string;
  label: string;
  destructive?: boolean;
  hidden?: (row: T) => boolean;
  onSelect: (row: T) => void;
}

export interface DataTableError {
  message?: string;
  requestId?: string;
}

export interface CursorInfo {
  next?: string | null;
  previous?: string | null;
}

export type { FieldType, ReferencedItem, StatusValue };
