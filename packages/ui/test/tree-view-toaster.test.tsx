import { afterEach, describe, expect, it, vi } from 'vitest';
import { renderUI, screen, waitFor } from './test-utils';
import { userEvent } from './test-utils';
import { runAxe } from './axe';
import { TreeView, type TreeNode } from '../src/components/tree-view';
import { Toaster, successToast, errorToast, toast } from '../src/components/toaster';
import { Toaster as SonnerToaster } from 'sonner';

const tree: TreeNode[] = [
  {
    id: 'news',
    label: 'News',
    children: [
      { id: 'politics', label: 'Politics' },
      {
        id: 'sports',
        label: 'Sports',
        children: [{ id: 'football', label: 'Football' }],
      },
    ],
  },
  { id: 'guides', label: 'Guides', disabled: true },
  { id: 'releases', label: 'Releases' },
];

describe('TreeView', () => {
  it('selects a node on click and marks it aria-selected', async () => {
    const onSelect = vi.fn();
    const user = userEvent.setup();
    renderUI(<TreeView nodes={tree} onSelect={onSelect} aria-label="Sections" />);
    await user.click(screen.getByRole('treeitem', { name: 'Releases' }));
    expect(onSelect).toHaveBeenCalledWith('releases');
    expect(screen.getByRole('treeitem', { name: 'Releases' })).toHaveAttribute(
      'aria-selected',
      'true'
    );
  });

  it('expands and collapses branches', async () => {
    const user = userEvent.setup();
    renderUI(<TreeView nodes={tree} aria-label="Sections" />);
    const news = screen.getByRole('treeitem', { name: 'News' });
    expect(news).toHaveAttribute('aria-expanded', 'false');
    expect(screen.queryByRole('treeitem', { name: 'Politics' })).not.toBeInTheDocument();

    await user.click(news);
    expect(news).toHaveAttribute('aria-expanded', 'true');
    expect(screen.getByRole('treeitem', { name: 'Politics' })).toBeInTheDocument();

    await user.click(news);
    expect(news).toHaveAttribute('aria-expanded', 'false');
  });

  it('moves focus with arrow keys and selects with Enter', async () => {
    const onSelect = vi.fn();
    const user = userEvent.setup();
    renderUI(<TreeView nodes={tree} onSelect={onSelect} aria-label="Sections" />);
    const first = screen.getByRole('treeitem', { name: 'News' });
    first.focus();
    await user.keyboard('{ArrowDown}');
    expect(screen.getByRole('treeitem', { name: 'Guides' })).toHaveFocus();
    await user.keyboard('{ArrowDown}');
    await user.keyboard('{Enter}');
    expect(onSelect).toHaveBeenCalledWith('releases');
    await user.keyboard('{ArrowUp}');
    expect(screen.getByRole('treeitem', { name: 'Guides' })).toHaveFocus();
    await user.keyboard('{Home}');
    expect(screen.getByRole('treeitem', { name: 'News' })).toHaveFocus();
    await user.keyboard('{End}');
    expect(screen.getByRole('treeitem', { name: 'Releases' })).toHaveFocus();
  });

  it('Right expands, Left collapses, then Left moves to the parent', async () => {
    const user = userEvent.setup();
    renderUI(
      <TreeView nodes={tree} defaultExpandedIds={['news']} aria-label="Sections" />
    );
    const news = screen.getByRole('treeitem', { name: 'News' });
    news.focus();
    // Right on an expanded branch moves to the first child.
    await user.keyboard('{ArrowRight}');
    expect(screen.getByRole('treeitem', { name: 'Politics' })).toHaveFocus();
    // Left from a child without children moves to the parent.
    await user.keyboard('{ArrowLeft}');
    expect(news).toHaveFocus();
    // Left on an expanded branch collapses it.
    await user.keyboard('{ArrowLeft}');
    expect(news).toHaveAttribute('aria-expanded', 'false');
  });

  it('expands ancestors of the selected node by default', () => {
    renderUI(
      <TreeView nodes={tree} selectedId="football" aria-label="Sections" />
    );
    expect(screen.getByRole('treeitem', { name: 'Football' })).toBeInTheDocument();
    expect(screen.getByRole('treeitem', { name: 'Football' })).toHaveAttribute(
      'aria-selected',
      'true'
    );
  });

  it('does not select disabled nodes', async () => {
    const onSelect = vi.fn();
    const user = userEvent.setup();
    renderUI(<TreeView nodes={tree} onSelect={onSelect} aria-label="Sections" />);
    await user.click(screen.getByRole('treeitem', { name: 'Guides' }));
    expect(onSelect).not.toHaveBeenCalled();
  });

  it('shows an empty message without nodes', () => {
    renderUI(<TreeView nodes={[]} aria-label="Empty" />);
    expect(screen.getByText('No items.')).toBeInTheDocument();
  });

  it('passes axe', async () => {
    const { container } = renderUI(
      <TreeView nodes={tree} defaultExpandedIds={['news']} aria-label="Sections" />
    );
    const results = await runAxe(container);
    expect(results.violations).toEqual([]);
  });
});

describe('Toaster', () => {
  afterEach(() => {
    vi.useRealTimers();
  });

  function ToastProbe() {
    return (
      <div>
        <Toaster />
        <button type="button" onClick={() => successToast('Entry published')}>
          success
        </button>
        <button
          type="button"
          onClick={() =>
            errorToast('Save failed', {
              action: { label: 'Retry', onClick: () => {} },
            })
          }
        >
          error
        </button>
        <button
          type="button"
          onClick={() =>
            toast('Entry moved to trash', {
              action: { label: 'Undo', onClick: () => {} },
            })
          }
        >
          undo
        </button>
      </div>
    );
  }

  it('renders success and action toasts', async () => {
    const user = userEvent.setup();
    renderUI(<ToastProbe />);
    await user.click(screen.getByRole('button', { name: 'success' }));
    expect((await screen.findAllByText('Entry published')).length).toBeGreaterThan(0);
    await user.click(screen.getByRole('button', { name: 'undo' }));
    expect((await screen.findAllByText('Entry moved to trash')).length).toBeGreaterThan(0);
    expect(screen.getByRole('button', { name: 'Undo' })).toBeInTheDocument();
  });

  it('keeps error toasts until dismissed (duration Infinity contract)', () => {
    const spy = vi.spyOn(toast, 'error');
    errorToast('Save failed');
    // duration Infinity = never auto-dismissed by sonner.
    expect(spy).toHaveBeenCalledWith('Save failed', expect.objectContaining({ duration: Infinity }));
    spy.mockRestore();
  });

  it('marks an error toast removed via its close button', async () => {
    const user = userEvent.setup();
    renderUI(<ToastProbe />);
    await user.click(screen.getByRole('button', { name: 'error' }));
    await screen.findAllByText('Save failed');
    await user.click(screen.getAllByRole('button', { name: /close toast/i })[0]!);
    // jsdom fires no CSS animation events, so sonner's exit animation never
    // completes here; data-removed is its real dismissal state and the
    // unmount follows in a real browser.
    await waitFor(() =>
      expect(
        screen.getAllByRole('listitem').some((item) => item.getAttribute('data-removed') === 'true')
      ).toBe(true)
    );
  });

  it('renders the toaster with axe-clean content', async () => {
    const user = userEvent.setup();
    renderUI(<ToastProbe />);
    await user.click(screen.getByRole('button', { name: 'success' }));
    await screen.findAllByText('Entry published');
    const results = await runAxe(document.body);
    expect(results.violations).toEqual([]);
    void SonnerToaster;
  });
});
