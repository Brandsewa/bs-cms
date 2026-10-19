import { afterEach, describe, expect, it, vi } from 'vitest';
import { renderUI, screen, waitFor } from './test-utils';
import { userEvent } from './test-utils';
import { DataTable } from '../src/data-table';
import { COLUMNS, POSTS, type Post } from './data-table-selection.test';

class FakeResizeObserver {
  static instances: Array<{ ro: FakeResizeObserver; el: Element }> = [];
  private cb: ResizeObserverCallback;
  constructor(cb: ResizeObserverCallback) {
    this.cb = cb;
  }
  observe(el: Element): void {
    FakeResizeObserver.instances.push({ ro: this, el });
  }
  unobserve(): void {}
  disconnect(): void {}
  // jsdom has no layout: synthesise the notification with a fake viewport.
  notify(width = 800, height = 400): void {
    this.cb(
      [
        {
          contentRect: { width, height, x: 0, y: 0, top: 0, left: 0, bottom: height, right: width },
          target: {} as Element,
          borderBoxSize: [],
          contentBoxSize: [],
          devicePixelContentBoxSize: [],
        } as unknown as ResizeObserverEntry,
      ],
      this
    );
  }
}

describe('DataTable — virtualisation and responsive cards (docs/02-ui-spec.md 3.1)', () => {
  afterEach(() => {
    vi.unstubAllGlobals();
    window.localStorage.clear();
  });

  function ManyRows({ count = 250, threshold = 200 }: { count?: number; threshold?: number }) {
    const rows: Post[] = Array.from({ length: count }, (_, index) => ({
      ...POSTS[index % POSTS.length]!,
      id: `many-${index}`,
      title: `Row ${index + 1}`,
    }));
    return (
      <DataTable<Post>
        columns={COLUMNS}
        data={rows}
        getRowId={(row) => row.id}
        aria-label="Many rows"
        state={{ pagination: { pageIndex: 0, pageSize: 100 } }}
        virtualizeThreshold={threshold}
        emptyState={{ title: 'x' }}
      />
    );
  }

  it('virtualises rendering above the threshold: only visible rows mount', async () => {
    // Explicit threshold: client pages cap at 100 rows, so the >200-rows
    // default engages only in server modes; the mechanism is identical.
    vi.stubGlobal('ResizeObserver', FakeResizeObserver);
    const { container } = renderUI(<ManyRows count={250} threshold={50} />);

    // jsdom has no layout: fake the scroller's viewport and notify the
    // virtualizer's ResizeObserver.
    const scroller = container.querySelector('div.overflow-auto') as HTMLElement;
    Object.defineProperty(scroller, 'clientHeight', { value: 400, configurable: true });
    scroller.getBoundingClientRect = () =>
      ({ width: 800, height: 400, top: 0, left: 0, bottom: 400, right: 800, x: 0, y: 0 }) as DOMRect;
    for (const instance of FakeResizeObserver.instances) instance.ro.notify();
    await waitFor(() => expect(screen.getByText('Row 1')).toBeInTheDocument());

    // 100 rows on the page but far fewer than 100 in the DOM.
    const mountedRows = container.querySelectorAll('tbody tr[data-row-id]').length;
    expect(mountedRows).toBeGreaterThan(3);
    expect(mountedRows).toBeLessThan(30);

    // Scroll near the end: late rows mount, early rows unmount.
    Object.defineProperty(scroller, 'scrollTop', { value: 90 * 36, configurable: true });
    scroller.dispatchEvent(new Event('scroll'));
    await waitFor(() => expect(screen.getByText('Row 95')).toBeInTheDocument());
    expect(screen.queryByText('Row 1')).not.toBeInTheDocument();
  });

  it('renders all rows below the threshold (no virtualisation)', () => {
    const { container } = renderUI(<ManyRows count={120} threshold={200} />);
    // 120 rows, threshold 200 → all rendered.
    expect(container.querySelectorAll('tbody tr[data-row-id]').length).toBe(100); // page size cap
  });

  it('responsive card mode: checkbox selection, row menu, no table at narrow width', async () => {
    const onRowOpen = vi.fn();
    // Replace on window directly: useMediaQuery reads window.matchMedia.
    Object.defineProperty(window, 'matchMedia', {
      writable: true,
      configurable: true,
      value: vi.fn().mockImplementation((query: string) => ({
        matches: query.includes('max-width: 767px'),
        media: query,
        onchange: null,
        addListener: () => {},
        removeListener: () => {},
        addEventListener: (_: string, listener: (event: { matches: boolean }) => void) => {
          listener({ matches: true });
        },
        removeEventListener: () => {},
        dispatchEvent: () => false,
      })),
    });

    const user = userEvent.setup();
    renderUI(
      <DataTable<Post>
        columns={COLUMNS}
        data={POSTS.slice(0, 5)}
        getRowId={(row) => row.id}
        aria-label="Posts"
        rowHref={(row) => `/posts/${row.slug}`}
        onRowOpen={onRowOpen}
        rowMenu={() => [{ id: 'open', label: 'Open', onSelect: () => {} }]}
        emptyState={{ title: 'x' }}
      />
    );

    // No <table>: cards replace it.
    expect(document.querySelector('table')).not.toBeInTheDocument();
    expect(screen.getAllByText(/Post \d/).length).toBeGreaterThan(0);

    // Checkbox selects.
    const checkboxes = screen.getAllByRole('checkbox');
    await user.click(checkboxes[0]!);
    expect(screen.getByText(/1 selected/)).toBeInTheDocument();

    // Card link opens via the screen callback.
    await user.click(screen.getAllByText(/Post \d/)[0]!);
    expect(onRowOpen).toHaveBeenCalled();

    // Row menu renders on cards.
    expect(screen.getAllByRole('button', { name: /row actions/i }).length).toBe(5);
  });

  it('manual order: drag events reorder through onReorder', async () => {
    const onReorder = vi.fn();
    renderUI(
      <DataTable<Post>
        columns={COLUMNS}
        data={POSTS.slice(0, 3)}
        getRowId={(row) => row.id}
        aria-label="Posts"
        manualOrder={{ onReorder }}
        emptyState={{ title: 'x' }}
      />
    );
    const handles = screen.getAllByRole('button', { name: 'Manual order' });
    expect(handles.length).toBe(3);

    // HTML5 drag: fire dragstart on row 0's handle and drop on row 2's.
    const dragEvent0 = new Event('dragstart', { bubbles: true, cancelable: true });
    handles[0]!.dispatchEvent(dragEvent0);
    const overEvent = new Event('dragover', { bubbles: true, cancelable: true });
    handles[2]!.dispatchEvent(overEvent);
    const dropEvent = new Event('drop', { bubbles: true, cancelable: true });
    handles[2]!.dispatchEvent(dropEvent);
    const endEvent = new Event('dragend', { bubbles: true });
    handles[2]!.dispatchEvent(endEvent);

    // Moving item 0 to index 2.
    expect(onReorder).toHaveBeenCalledWith(['p2', 'p3', 'p1']);
  });
});
