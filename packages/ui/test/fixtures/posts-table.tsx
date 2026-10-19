import { fireEvent, renderUI, screen, userEvent, within } from '../test-utils';
import { DataTable, type DataTableColumn } from '../../src/data-table';

/**
 * Shared Posts fixture for the DataTable acceptance suites. Lives in a
 * non-`.test` module: importing a `.test` file would register its suites
 * again in every importer.
 */

export interface Post {
  id: string;
  title: string;
  status: 'draft' | 'published' | 'in_review';
  author: string;
  views: number;
  updatedAt: Date;
  tags: string[];
  slug: string;
}

export const POSTS: Post[] = Array.from({ length: 28 }, (_, index) => ({
  id: `p${index + 1}`,
  title: `Post ${index + 1}`,
  status: index % 3 === 0 ? 'draft' : index % 3 === 1 ? 'published' : 'in_review',
  author: index % 2 === 0 ? 'Ada' : 'Grace',
  views: (index + 1) * 7,
  updatedAt: new Date(2026, 8, (index % 28) + 1, 10, 0),
  tags: ['seo', 'launch', 'news'].slice(0, (index % 3) + 1),
  slug: `post-${index + 1}`,
}));

export const COLUMNS: Array<DataTableColumn<Post>> = [
  {
    id: 'title',
    header: 'Title',
    fieldType: 'text',
    accessor: (row: Post) => row.title,
    searchable: true,
    sticky: true,
    enableHiding: false,
    width: 200,
  },
  {
    id: 'status',
    header: 'Status',
    fieldType: 'status',
    accessor: (row: Post) => row.status,
    width: 120,
  },
  {
    id: 'author',
    header: 'Author',
    fieldType: 'user',
    accessor: (row: Post) => ({ id: row.author, label: row.author }),
    width: 120,
  },
  {
    id: 'views',
    header: 'Views',
    fieldType: 'number',
    accessor: (row: Post) => row.views,
    align: 'right',
    width: 100,
  },
  {
    id: 'updatedAt',
    header: 'Updated',
    fieldType: 'date',
    accessor: (row: Post) => row.updatedAt,
    width: 140,
  },
  {
    id: 'tags',
    header: 'Tags',
    fieldType: 'tags',
    accessor: (row: Post) => row.tags.map((tag) => ({ id: tag, label: tag })),
    width: 140,
  },
];

const DEFAULT_BULK: Parameters<typeof DataTable<Post>>[0]['bulkActions'] = [
  { id: 'publish', label: 'Publish', confirm: 'none', run: async (rows) => ({ succeeded: rows, failed: [] }) },
];

export function renderPostsTable(overrides?: {
  bulkActions?: Parameters<typeof DataTable<Post>>[0]['bulkActions'];
  state?: Parameters<typeof DataTable<Post>>[0]['state'];
  onStateChange?: Parameters<typeof DataTable<Post>>[0]['onStateChange'];
  rowHref?: (row: Post) => string;
  onRowOpen?: (row: Post) => void;
}) {
  return renderUI(
    <DataTable<Post>
      columns={COLUMNS}
      data={POSTS}
      getRowId={(row) => row.id}
      aria-label="Posts table"
      state={overrides?.state}
      onStateChange={overrides?.onStateChange}
      bulkActions={overrides?.bulkActions ?? DEFAULT_BULK}
      rowHref={overrides?.rowHref}
      onRowOpen={overrides?.onRowOpen}
      emptyState={{
        title: 'No entries yet',
        action: <button type="button">New post</button>,
      }}
    />
  );
}

export async function clickRowCheckbox(index: number, shift = false): Promise<void> {
  const rows = screen.getAllByRole('row');
  const checkbox = within(rows[index]!).getByRole('checkbox');
  if (shift) {
    // A synthetic event carries shiftKey reliably (modifier state cannot be
    // shared across userEvent instances). preventDefault stops Radix's own
    // single-row toggle so the DataTable's range handler owns the click.
    fireEvent.click(checkbox, { shiftKey: true });
  } else {
    await userEvent.click(checkbox);
  }
}
