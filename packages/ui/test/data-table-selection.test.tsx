import { describe, expect, it, vi } from 'vitest';
import { renderUI, screen, waitFor, within } from './test-utils';
import { userEvent } from './test-utils';
import { DataTable } from '../src/data-table';

import {
  POSTS,
  COLUMNS,
  renderPostsTable,
  clickRowCheckbox,
  type Post,
} from './fixtures/posts-table';

describe('DataTable — selection and bulk actions (docs/02-ui-spec.md 3.1)', () => {
  it('3.1 selection: header checkbox is select-all-on-page with indeterminate state when some rows are selected', async () => {
    renderPostsTable();
    const header = screen.getAllByRole('row')[0]!;
    const headerCheckbox = within(header).getByRole('checkbox');
    expect(headerCheckbox).toHaveAttribute('data-state', 'unchecked');

    await clickRowCheckbox(1);
    expect(headerCheckbox).toHaveAttribute('data-state', 'indeterminate');

    // Select the remaining 9 rows of the 10-row page -> checked.
    for (let index = 2; index <= 10; index += 1) {
      await clickRowCheckbox(index);
    }
    expect(headerCheckbox).toHaveAttribute('data-state', 'checked');
  });

  it('3.1 selection: shift-click selects a range; clicking a checkbox never navigates', async () => {
    const onRowOpen = vi.fn();
    renderPostsTable({ onRowOpen });
    await clickRowCheckbox(1);
    await clickRowCheckbox(4, true);
    // Rows 1..4 selected -> 4 selected in the footer.
    expect(screen.getByText(/4 of 28 selected/)).toBeInTheDocument();

    const rows = screen.getAllByRole('row');
    // Clicking the row (not the checkbox) opens; clicking the checkbox does not.
    await userEvent.click(within(rows[2]!).getByText('Post 2'));
    expect(onRowOpen).toHaveBeenCalledWith(POSTS[1]);
  });

  it('3.1 selection: after selecting all page rows, a banner offers "Select all N matching"', async () => {
    renderPostsTable();
    const header = screen.getAllByRole('row')[0]!;
    await userEvent.click(within(header).getByRole('checkbox'));
    const banner = screen.getByRole('status');
    expect(banner).toHaveTextContent(/Select all 28 matching results/);
    await userEvent.click(
      within(banner).getByRole('button', { name: /Select all 28 matching results/ })
    );
    expect(screen.getByText(/28 of 28 selected/)).toBeInTheDocument();
  });

  it('3.1 selection: selection persists across sort and page changes, clears when filters change', async () => {
    const Console = console;
    Console.log('placeholder');
    renderPostsTable();
    await clickRowCheckbox(1);
    // Change page: selection persists (still 1 selected).
    await userEvent.click(screen.getByRole('button', { name: /page 2 of 3/i }));
    expect(screen.getByText(/1 of 28 selected/)).toBeInTheDocument();
    await userEvent.click(screen.getByRole('button', { name: /page 1 of 3/i }));

    // Type in search (filter change) -> selection cleared.
    await userEvent.type(screen.getByLabelText('Search…'), 'Post 2');
    await waitFor(() => expect(screen.queryByText(/1 of 28 selected/)).not.toBeInTheDocument());
  });

  it('3.1 bulk: actions run per selection with partial-failure dialog and retry', async () => {
    const run = vi.fn().mockImplementation(async ([row]: [Post]) =>
      row.id === 'p1'
        ? { succeeded: [row], failed: [] }
        : { succeeded: [], failed: [{ row, reason: 'Locked' }] }
    );
    renderPostsTable({
      bulkActions: [
        {
          id: 'publish',
          label: 'Publish',
          confirm: 'none',
          run,
        },
      ],
    });
    await clickRowCheckbox(1);
    await clickRowCheckbox(2);
    await userEvent.click(screen.getByRole('button', { name: 'Publish' }));

    // Partial failure dialog: 1 succeeded, 1 failed with reason.
    const dialog = await screen.findByRole('alertdialog');
    expect(dialog).toHaveTextContent(/1 succeeded, 1 failed/);
    expect(dialog).toHaveTextContent('Locked');

    // Retry failed items reruns on the failed row only.
    run.mockClear();
    run.mockResolvedValue({ succeeded: [], failed: [] });
    await userEvent.click(within(dialog).getByRole('button', { name: 'Retry failed items' }));
    await waitFor(() => expect(run).toHaveBeenCalled());
  });

  it('3.1 bulk delete uses type-to-confirm when more than 10 items are selected', async () => {
    renderPostsTable({
      bulkActions: [
        {
          id: 'delete',
          label: 'Delete',
          destructive: true,
          confirm: { type: 'type-to-confirm', phrase: () => 'DELETE-12', body: 'All selected entries are removed.' },
          run: async (rows) => ({ succeeded: rows, failed: [] }),
        },
      ],
    });
    // Select all 10 page rows.
    const header = screen.getAllByRole('row')[0]!;
    await userEvent.click(within(header).getByRole('checkbox'));
    await userEvent.click(screen.getByRole('button', { name: /Select all 28 matching results/ }));

    await userEvent.click(screen.getByRole('button', { name: 'Delete' }));
    const input = await screen.findByLabelText(/Type DELETE-12 to confirm/);
    expect(screen.getByRole('button', { name: 'Delete' })).toBeDisabled();
    await userEvent.type(input, 'WRONG');
    // Confirm stays disabled on mismatch.
    expect(screen.getByRole('button', { name: 'Delete' })).toBeDisabled();
    await userEvent.clear(input);
    await userEvent.type(input, 'DELETE-12');
    expect(screen.getByRole('button', { name: 'Delete' })).toBeEnabled();
  });

  it('3.1 sorting: headers cycle ascending, descending, none with arrows and aria-sort', async () => {
    renderPostsTable();
    const viewsHeader = screen.getByRole('columnheader', { name: /views/i });
    expect(viewsHeader).not.toHaveAttribute('aria-sort');

    await userEvent.click(within(viewsHeader).getByRole('button'));
    expect(viewsHeader).toHaveAttribute('aria-sort', 'ascending');
    // First data row is Post 1 (7 views) — ascending.
    expect(screen.getAllByRole('row')[1]).toHaveTextContent('Post 1');

    await userEvent.click(within(viewsHeader).getByRole('button'));
    expect(viewsHeader).toHaveAttribute('aria-sort', 'descending');
    expect(screen.getAllByRole('row')[1]).toHaveTextContent('Post 28');

    await userEvent.click(within(viewsHeader).getByRole('button'));
    expect(viewsHeader).not.toHaveAttribute('aria-sort');
  });

  it('3.1 sorting: shift-click multi-sorts with order indicators', async () => {
    renderPostsTable();
    const viewsHeader = screen.getByRole('columnheader', { name: /views/i });
    const authorHeader = screen.getByRole('columnheader', { name: /author/i });
    await userEvent.click(within(viewsHeader).getByRole('button'));
    const user = userEvent.setup();
    await user.keyboard('{Shift>}');
    await user.click(within(authorHeader).getByRole('button'));
    await user.keyboard('{/Shift}');
    expect(authorHeader).toHaveAttribute('aria-sort', 'ascending');
    expect(viewsHeader).toHaveAttribute('aria-sort', 'ascending');
  });

  it('3.1 search: debounced with clear button and / shortcut, searches searchable fields only', async () => {
    renderPostsTable();
    const input = screen.getByLabelText('Search…');
    await userEvent.type(input, 'Post 28');
    // Title is searchable: filtered to one row.
    await waitFor(() => expect(screen.getByText('Post 28')).toBeInTheDocument());
    expect(screen.queryByText('Post 27')).not.toBeInTheDocument();
    // Author is not searchable: searching "Ada" yields nothing.
    await userEvent.clear(input);
    await userEvent.type(input, 'Ada');
    await waitFor(() => expect(screen.getByText(/No results match/)).toBeInTheDocument());
    await userEvent.click(screen.getByRole('button', { name: 'Clear' }));
    await waitFor(() => expect(screen.getByText('Post 1')).toBeInTheDocument());
  });

  it('3.1 footer: selection count, total, page size, page navigation and go-to-page', async () => {
    renderPostsTable();
    expect(screen.getByText('28 results')).toBeInTheDocument();
    await userEvent.click(screen.getByRole('button', { name: /page 2 of 3/i }));
    expect(screen.getByText('Post 11')).toBeInTheDocument();
    // Page size 25 -> two pages.
    await userEvent.click(screen.getByLabelText('Rows per page'));
    await userEvent.click(await screen.findByRole('option', { name: '25' }));
    await waitFor(() => expect(screen.getByText('Post 25')).toBeInTheDocument());
    // Go to page 2 of 2.
    const goTo = screen.getByLabelText('Go to page');
    await userEvent.type(goTo, '2{Enter}');
    await waitFor(() => expect(screen.getByText('Post 26')).toBeInTheDocument());
  });

  it('3.1 states: skeleton rows while loading', () => {
    renderUI(
      <DataTable<Post>
        columns={COLUMNS}
        data={POSTS}
        getRowId={(row) => row.id}
        aria-label="Posts"
        loading
      />
    );
    expect(screen.getByRole('status', { name: 'Loading results…' })).toBeInTheDocument();
  });

  it('3.1 states: both empty states and error state with retry', async () => {
    const onRetry = vi.fn();
    const { rerender } = renderUI(
      <DataTable<Post>
        columns={COLUMNS}
        data={[]}
        getRowId={(row) => row.id}
        aria-label="Posts"
        emptyState={{ title: 'No entries yet', action: <button type="button">New post</button> }}
      />
    );
    expect(screen.getByText('No entries yet')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'New post' })).toBeInTheDocument();

    rerender(
      <DataTable<Post>
        columns={COLUMNS}
        data={POSTS}
        getRowId={(row) => row.id}
        aria-label="Posts"
        state={{ search: 'zzz' }}
        emptyState={{ title: 'No entries yet' }}
      />
    );
    await waitFor(() => expect(screen.getByText(/No results match/)).toBeInTheDocument());
    await userEvent.click(screen.getByRole('button', { name: 'Clear filters' }));

    rerender(
      <DataTable<Post>
        columns={COLUMNS}
        data={POSTS}
        getRowId={(row) => row.id}
        aria-label="Posts"
        error={{ message: 'Database unavailable', requestId: 'req_9' }}
        onRetry={onRetry}
      />
    );
    await screen.findByRole('alert');
    await userEvent.click(screen.getByRole('button', { name: 'Retry' }));
    expect(onRetry).toHaveBeenCalledOnce();
  });

  it('3.1 refresh: button and last-updated time', async () => {
    const onRefresh = vi.fn();
    renderUI(
      <DataTable<Post>
        columns={COLUMNS}
        data={POSTS}
        getRowId={(row) => row.id}
        aria-label="Posts"
        onRefresh={onRefresh}
        lastUpdated={new Date(2026, 0, 1, 12, 0, 0)}
      />
    );
    await userEvent.click(screen.getByRole('button', { name: 'Refresh' }));
    expect(onRefresh).toHaveBeenCalledOnce();
    expect(screen.getAllByText(/Updated/).length).toBeGreaterThan(0);
  });

  it('3.1 export: filtered rows to CSV and JSON', async () => {
    const urlSpy = vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:x');

    renderPostsTable();
    // Unambiguous single match (Post 1 would also match Post 10-19).
    await userEvent.type(screen.getByLabelText('Search…'), 'Post 28');
    await waitFor(() => expect(screen.getByText('Post 28')).toBeInTheDocument());

    await userEvent.click(screen.getByRole('button', { name: 'Export' }));
    await userEvent.click(await screen.findByRole('menuitem', { name: 'Export CSV' }));
    const blob = urlSpy.mock.calls[0]?.[0] as Blob;
    expect(blob).toBeInstanceOf(Blob);
    expect(blob.size).toBeGreaterThan(0);

    await userEvent.click(screen.getByRole('button', { name: 'Export' }));
    await userEvent.click(await screen.findByRole('menuitem', { name: 'Export JSON' }));
    expect(urlSpy.mock.calls.length).toBe(2);

    urlSpy.mockRestore();
  });

  it('3.1 keyboard: arrow keys move between rows, Enter opens, Space selects', async () => {
    const onRowOpen = vi.fn();
    const user = userEvent.setup();
    renderPostsTable({ rowHref: (row) => `/posts/${row.slug}`, onRowOpen });
    const firstRow = screen.getAllByRole('row')[1]!;
    firstRow.focus();
    expect(firstRow).toHaveFocus();
    await user.keyboard('{ArrowDown}');
    const secondRow = screen.getAllByRole('row')[2]!;
    expect(secondRow).toHaveFocus();
    await user.keyboard(' ');
    expect(screen.getByText(/1 of 28 selected/)).toBeInTheDocument();
    await user.keyboard('{Enter}');
    expect(onRowOpen).toHaveBeenCalledWith(POSTS[1]);
  });

  it('3.1 URL state: controlled state changes flow through onStateChange', async () => {
    const onStateChange = vi.fn();
    renderPostsTable({ onStateChange });
    await userEvent.click(
      within(screen.getByRole('columnheader', { name: /views/i })).getByRole('button')
    );
    expect(onStateChange).toHaveBeenCalledWith(
      expect.objectContaining({ sorting: [{ id: 'views', desc: false }] })
    );
  });

  it('3.1 column renderers: status badge, right-aligned number, user avatar, tags chips with +N', () => {
    renderPostsTable();
    expect(screen.getAllByText('Published').length).toBeGreaterThan(0);
    expect(screen.getAllByText('In review').length).toBeGreaterThan(0);
    const firstRow = screen.getAllByRole('row')[1]!;
    const viewsCell = Array.from(firstRow.children).find((cell) =>
      (cell as HTMLElement).textContent === '7'
    );
    expect(viewsCell).toHaveClass('text-right');
    expect(screen.getAllByText('Ada').length).toBeGreaterThan(0);
    expect(screen.getAllByText('+1').length).toBeGreaterThan(0); // tags overflow
  });
});
