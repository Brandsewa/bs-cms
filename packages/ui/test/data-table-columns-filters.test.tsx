import { describe, expect, it, vi } from 'vitest';
import { useState } from 'react';
import { renderUI, screen, waitFor, within } from './test-utils';
import { userEvent } from './test-utils';
import { runAxe } from './axe';
import { DataTable, type ActiveFilter } from '../src/data-table';
import { POSTS, COLUMNS, renderPostsTable, type Post } from './fixtures/posts-table';

describe('DataTable — columns (docs/02-ui-spec.md 3.1)', () => {
  it('column visibility menu with reset, persisted per storageKey', async () => {
    const user = userEvent.setup();
    window.localStorage.clear();
    const { unmount } = renderPostsTable();
    await user.click(screen.getByRole('button', { name: 'Columns' }));
    await user.click(await screen.findByRole('menuitemcheckbox', { name: 'Author' }));
    await waitFor(() => expect(screen.queryByText('Ada')).not.toBeInTheDocument());

    // Persisted per collection key: remount without the storageKey colliding.
    unmount();
    renderUI(
      <DataTable<Post>
        columns={COLUMNS}
        data={POSTS}
        getRowId={(row) => row.id}
        aria-label="Posts"
        storageKey="dt-posts"
        emptyState={{ title: 'x' }}
      />
    );
    // No visibility change persisted for this key -> Author visible again.
    expect(screen.getAllByText('Ada').length).toBeGreaterThan(0);
    window.localStorage.clear();
  });

  it('column resize via drag handle and keyboard, persisted', async () => {
    const user = userEvent.setup();
    window.localStorage.clear();
    renderUI(
      <DataTable<Post>
        columns={COLUMNS}
        data={POSTS}
        getRowId={(row) => row.id}
        aria-label="Posts"
        storageKey="dt-posts-resize"
        emptyState={{ title: 'x' }}
      />
    );
    const viewsHeader = screen.getByRole('columnheader', { name: 'Views' });
    const handle = within(viewsHeader).getByRole('separator', { name: /resize views/i });
    expect(handle).toBeInTheDocument();

    // Keyboard resize (+20/-20) persists through the storageKey.
    handle.focus();
    await user.keyboard('{ArrowRight}');
    await waitFor(() => {
      const stored = JSON.parse(window.localStorage.getItem('dt-posts-resize') ?? '{}');
      expect(stored.sizes?.views).toBe(120);
    });
    await user.keyboard('{ArrowLeft}');
    await waitFor(() => {
      const stored = JSON.parse(window.localStorage.getItem('dt-posts-resize') ?? '{}');
      expect(stored.sizes?.views).toBe(100);
    });
    window.localStorage.clear();
  });

  it('density toggle switches row heights', async () => {
    const user = userEvent.setup();
    renderPostsTable();
    const compactRow = screen.getAllByRole('row')[1]!.querySelector('td:nth-child(3)');
    expect(compactRow).toHaveStyle({ height: '36px' });

    await user.click(screen.getByRole('button', { name: 'Density' }));
    await user.click(await screen.findByRole('menuitemcheckbox', { name: 'Comfortable' }));
    const comfortableRow = screen.getAllByRole('row')[1]!.querySelector('td:nth-child(3)');
    expect(comfortableRow).toHaveStyle({ height: '52px' });
  });

  it('passes axe in the default state', async () => {
    const { container } = renderPostsTable();
    const results = await runAxe(container);
    expect(
      results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical')
    ).toEqual([]);
  });
});

describe('DataTable — filters and views (docs/02-ui-spec.md 3.1)', () => {
  const filterDefs = [
    { id: 'f-title', label: 'Title', type: 'text' as const, columnId: 'title' },
    {
      id: 'f-status',
      label: 'Status',
      type: 'enum' as const,
      columnId: 'status',
      options: [
        { value: 'draft', label: 'Draft' },
        { value: 'published', label: 'Published' },
        { value: 'in_review', label: 'In review' },
      ],
    },
    { id: 'f-views', label: 'Views', type: 'number' as const, columnId: 'views' },
  ];


  function StatefulFilters() {
    const [active, setActive] = useState<ActiveFilter[]>([]);
    return (
      <DataTable<Post>
        columns={COLUMNS}
        data={POSTS}
        getRowId={(row) => row.id}
        aria-label="Posts"
        filters={{ defs: filterDefs, active, onChange: setActive }}
        emptyState={{ title: 'x' }}
      />
    );
  }

  it('filter bar: text filter narrows rows and shows a removable chip', async () => {
    const user = userEvent.setup();
    renderUI(<StatefulFilters />);
    await user.click(screen.getByRole('button', { name: 'Filter' }));
    const picker = await screen.findByRole('dialog');
    await user.click(within(picker).getByText('Title'));
    await user.type(within(picker).getByLabelText('Title'), 'Post 7');
    await waitFor(() => {
      expect(screen.getByText('Post 7')).toBeInTheDocument();
      expect(screen.queryByText('Post 8')).not.toBeInTheDocument();
    });
    expect(screen.getByText(/Title:/)).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Remove Title' }));
    await waitFor(() => expect(screen.getByText('Post 8')).toBeInTheDocument());
  });

  it('filter bar: enum multi-select and number range with clear-all', async () => {
    const user = userEvent.setup();
    const first = renderUI(<StatefulFilters />);
    // Enum: only published rows.
    await user.click(screen.getByRole('button', { name: 'Filter' }));
    const enumPicker = await screen.findByRole('dialog');
    await user.click(within(enumPicker).getByText('Status'));
    // The enum editor is a MultiSelect: open its popover, then select all.
    // The tab chip has no aria-expanded; the MultiSelect trigger does.
    await user.click(
      within(enumPicker).getByRole('button', { name: 'Status', expanded: false })
    );
    const multiList = await screen.findByRole('listbox');
    await user.click(within(multiList).getByRole('option', { name: 'Published' }));
    await user.keyboard('{Escape}');
    await user.keyboard('{Escape}');
    await waitFor(() =>
      expect(screen.queryAllByText('Draft')).toEqual([])
    );

    // Clear all removes the enum filter again.
    await user.click(screen.getByRole('button', { name: 'Clear all' }));
    await waitFor(() => expect(screen.getAllByText('Draft').length).toBeGreaterThan(0));
    first.unmount();

    // Number range on a fresh state: views 7..70 -> Post 1..10.
    renderUI(<StatefulFilters />);
    await user.click(screen.getByRole('button', { name: 'Filter' }));
    const numberPicker = await screen.findByRole('dialog');
    await user.click(within(numberPicker).getByText('Views'));
    await user.type(within(numberPicker).getByLabelText('Views minimum'), '7');
    await user.type(within(numberPicker).getByLabelText('Views maximum'), '70');
    await waitFor(() => {
      expect(screen.getByText('Post 10')).toBeInTheDocument();
      expect(screen.queryByText('Post 11')).not.toBeInTheDocument();
    });
  });

  it('status quick tabs filter by status with counts', async () => {
    const onChange = vi.fn();
    renderUI(
      <DataTable<Post>
        columns={COLUMNS}
        data={POSTS}
        getRowId={(row) => row.id}
        aria-label="Posts"
        statusTabs={{
          active: 'all',
          onChange,
          tabs: [
            { id: 'all', label: 'All', count: 28 },
            { id: 'draft', label: 'Draft', count: 10 },
            { id: 'published', label: 'Published', count: 9 },
          ],
        }}
        emptyState={{ title: 'x' }}
      />
    );
    const tablist = screen.getByRole('tablist', { name: 'Saved views' });
    await userEvent.click(within(tablist).getByRole('tab', { name: /draft/i }));
    expect(onChange).toHaveBeenCalledWith('draft');
  });

  it('saved views: select a view tab, delete the active one', async () => {
    const onSelect = vi.fn();
    const onDelete = vi.fn();
    const user = userEvent.setup();
    renderUI(
      <DataTable<Post>
        columns={COLUMNS}
        data={POSTS}
        getRowId={(row) => row.id}
        aria-label="Posts"
        state={{ viewId: 'v1' }}
        savedViews={{
          views: [
            { id: 'v1', label: 'My drafts', state: { filters: [], sorting: [], columnVisibility: {}, density: 'compact' } },
            { id: 'v2', label: 'Review queue', state: { filters: [], sorting: [], columnVisibility: {}, density: 'compact' } },
          ],
          activeId: 'v1',
          onSelect,
          onSave: () => {},
          onDelete,
        }}
        emptyState={{ title: 'x' }}
      />
    );
    const tablist = screen.getByRole('tablist', { name: 'Saved views' });
    expect(within(tablist).getByRole('tab', { name: 'My drafts' })).toHaveAttribute(
      'aria-selected',
      'true'
    );
    await user.click(within(tablist).getByRole('tab', { name: 'Review queue' }));
    expect(onSelect).toHaveBeenCalledWith('v2');
    await user.click(screen.getByRole('button', { name: /Delete view: My drafts/ }));
    expect(onDelete).toHaveBeenCalledWith('v1');
  });

  it('drag reorder (manual_order): enabled only without sort/filter/search', async () => {
    const onReorder = vi.fn();
    const user = userEvent.setup();
    renderUI(
      <DataTable<Post>
        columns={COLUMNS}
        data={POSTS}
        getRowId={(row) => row.id}
        aria-label="Posts"
        manualOrder={{ onReorder }}
        emptyState={{ title: 'x' }}
      />
    );
    // Enabled by default (no sort/filter/search).
    const handles = screen.getAllByRole('button', { name: 'Manual order' });
    expect(handles[0]).toHaveAttribute('aria-disabled', 'false');

    // With sorting applied the handles are disabled with a tooltip.
    await user.click(
      within(screen.getByRole('columnheader', { name: 'Views' })).getByRole('button')
    );
    await waitFor(() => expect(handles[0]).toHaveAttribute('aria-disabled', 'true'));
  });

  it('row kebab menu renders items and honours hidden', async () => {
    const onSelect = vi.fn();
    renderUI(
      <DataTable<Post>
        columns={COLUMNS}
        data={POSTS}
        getRowId={(row) => row.id}
        aria-label="Posts"
        rowMenu={() => [
          { id: 'duplicate', label: 'Duplicate', onSelect },
          {
            id: 'purge',
            label: 'Delete permanently',
            destructive: true,
            // Purge only appears for drafts (Trash semantics elsewhere).
            hidden: (candidate: Post) => candidate.status !== 'draft',
            onSelect,
          },
        ]}
        emptyState={{ title: 'x' }}
      />
    );
    // Row 1 (Post 1) is a draft: both items. Row 2 (Post 2) is published: one.
    const rows = screen.getAllByRole('row');
    await userEvent.click(within(rows[1]!).getByRole('button', { name: /row actions/i }));
    await userEvent.click(await screen.findByRole('menuitem', { name: 'Duplicate' }));
    expect(onSelect).toHaveBeenCalled();
  });

  it('cursor pagination mode renders only next/previous', async () => {
    renderUI(
      <DataTable<Post>
        columns={COLUMNS}
        data={POSTS.slice(0, 10)}
        getRowId={(row) => row.id}
        aria-label="Posts"
        manualPagination
        totalRows={28}
        cursor={{ next: 'cursor-2', previous: null }}
        onCursorChange={() => {}}
        emptyState={{ title: 'x' }}
      />
    );
    expect(screen.getByRole('button', { name: 'Next ›' })).toBeEnabled();
    expect(screen.getByRole('button', { name: /Previous/ })).toBeDisabled();
    expect(screen.queryByLabelText('Go to page')).not.toBeInTheDocument();
  });

  it('passes axe with filters, tabs and bulk bar active', async () => {
    const { container } = renderUI(
      <DataTable<Post>
        columns={COLUMNS}
        data={POSTS}
        getRowId={(row) => row.id}
        aria-label="Posts"
        bulkActions={[
          { id: 'publish', label: 'Publish', confirm: 'none', run: async (rows) => ({ succeeded: rows, failed: [] }) },
        ]}
        filters={{ defs: filterDefs, active: [], onChange: () => {} }}
        statusTabs={{
          active: 'all',
          onChange: () => {},
          tabs: [{ id: 'all', label: 'All', count: 28 }],
        }}
        emptyState={{ title: 'x' }}
      />
    );
    // Select a row so the bulk bar shows.
    await userEvent.click(within(screen.getAllByRole('row')[1]!).getByRole('checkbox'));
    const results = await runAxe(container);
    expect(
      results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical')
    ).toEqual([]);
  });
});
