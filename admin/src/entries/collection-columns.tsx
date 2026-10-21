import type { DataTableColumn } from '@bs/ui';
import type { EntryItem, CollectionSchema } from './types';

/**
 * Builds DataTable columns based on collection schema and standard content fields.
 * Includes title (with slug), status, categories, tags, author, dates, and SEO score badge.
 */
export function buildCollectionColumns(
  collection: CollectionSchema | null
): Array<DataTableColumn<EntryItem>> {
  const isPages = collection?.handle === 'pages';

  const columns: Array<DataTableColumn<EntryItem>> = [
    {
      id: 'title',
      header: 'Title',
      fieldType: 'text',
      accessor: (row: EntryItem) => row.title,
      searchable: true,
      sortable: true,
      sticky: true,
      enableHiding: false,
      width: 280,
      cell: (row: EntryItem) => {
        return (
          <div className="flex flex-col min-w-0 py-0.5">
            <span className="font-medium text-foreground truncate">{row.title || 'Untitled'}</span>
            <span className="text-xs text-muted-foreground truncate">/{row.slug || ''}</span>
          </div>
        );
      },
    },
    {
      id: 'status',
      header: 'Status',
      fieldType: 'status',
      accessor: (row: EntryItem) => row.status || 'draft',
      sortable: true,
      width: 120,
    },
  ];

  // For posts, add categories and tags
  if (!isPages) {
    columns.push(
      {
        id: 'categories',
        header: 'Categories',
        fieldType: 'taxonomy',
        accessor: (row: EntryItem) => {
          if (!row.categories) return [];
          if (Array.isArray(row.categories)) {
            return row.categories.map((c: any) =>
              typeof c === 'string'
                ? { id: c, label: c }
                : { id: c.id ?? c.name, label: c.label ?? c.name ?? c.id }
            );
          }
          return [];
        },
        width: 140,
      },
      {
        id: 'tags',
        header: 'Tags',
        fieldType: 'tags',
        accessor: (row: EntryItem) => {
          if (!row.tags) return [];
          if (Array.isArray(row.tags)) {
            return row.tags.map((t: any) =>
              typeof t === 'string'
                ? { id: t, label: t }
                : { id: t.id ?? t.name, label: t.label ?? t.name ?? t.id }
            );
          }
          return [];
        },
        width: 140,
      }
    );
  }

  // Author column
  columns.push({
    id: 'author',
    header: 'Author',
    fieldType: 'user',
    accessor: (row: EntryItem) => {
      if (row.authors && Array.isArray(row.authors) && row.authors.length > 0) {
        return { id: row.authors[0]?.name ?? 'Author', label: row.authors[0]?.name ?? 'Author' };
      }
      return { id: row.author_id ?? 'Unknown', label: row.author_id ?? 'Unknown' };
    },
    width: 130,
  });

  // Published / Updated date columns
  columns.push(
    {
      id: 'published_at',
      header: 'Published',
      fieldType: 'date',
      accessor: (row: EntryItem) => (row.published_at ? new Date(row.published_at) : null),
      sortable: true,
      width: 130,
    },
    {
      id: 'updated_at',
      header: 'Updated',
      fieldType: 'date',
      accessor: (row: EntryItem) => (row.updated_at ? new Date(row.updated_at) : null),
      sortable: true,
      width: 130,
    }
  );

  return columns;
}
