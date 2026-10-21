/**
 * Types and interfaces for Content Entries and Collection List screens.
 */

export interface CollectionSchema {
  id: string;
  handle: string;
  kind: 'collection' | 'singleton';
  label_singular: string;
  label_plural: string;
  description?: string | null;
  icon?: string | null;
  table_name: string;
  is_system?: boolean;
  has_public_pages?: boolean;
  route_pattern?: string | null;
  list_route_pattern?: string | null;
  title_field?: string;
  slug_source?: string;
  default_sort?: string;
  features?: {
    drafts?: boolean;
    versions?: boolean;
    soft_delete?: boolean;
    hierarchy?: boolean;
    manual_order?: boolean;
    scheduling?: boolean;
    seo?: boolean;
  };
  fields?: Record<string, CollectionFieldDefinition>;
}

export interface CollectionFieldDefinition {
  id: string;
  collection_id: string;
  handle: string;
  label: string;
  type: string;
  config?: Record<string, unknown> | null;
  required?: boolean;
  is_unique?: boolean;
  is_filterable?: boolean;
  is_sortable?: boolean;
  is_searchable?: boolean;
  show_in_list?: boolean;
  default_value?: unknown;
  help_text?: string | null;
  placeholder?: string | null;
  position?: number;
  width?: string;
}

export interface EntryDerivedMeta {
  excerpt?: string;
  reading_time?: number;
  word_count?: number;
  outline?: unknown[];
  media_ids?: string[];
  internal_links?: string[];
}

export interface EntryItem {
  id: string;
  title: string;
  slug: string;
  status: 'draft' | 'published' | 'in_review' | 'scheduled' | 'archived';
  locale?: string;
  parent_id?: string | null;
  position?: number;
  author_id?: string | null;
  published_at?: string | null;
  scheduled_at?: string | null;
  created_at?: string;
  updated_at?: string;
  deleted_at?: string | null;
  featured_image?: string | null;
  categories?: Array<{ id: string; label: string; name?: string }> | string[] | null;
  tags?: Array<{ id: string; label: string; name?: string }> | string[] | null;
  authors?: Array<{ id: string; name: string }> | null;
  seo?: {
    title?: string;
    description?: string;
    score?: number;
  } | null;
  body?: unknown;
  excerpt?: string | null;
  _derived?: EntryDerivedMeta;
  [key: string]: unknown;
}

export interface EntriesListResponse {
  data: EntryItem[];
  meta: {
    pagination: {
      total: number;
      page: number;
      size: number;
      pages: number;
      has_more: boolean;
      next_cursor?: string | null;
    };
  };
}

export interface BulkOperationResponse {
  success: boolean;
  operation: string;
  processed: number;
  failed: number;
  total: number;
  items: Array<{
    id: string;
    status: 'success' | 'failed';
    error?: string;
  }>;
}
