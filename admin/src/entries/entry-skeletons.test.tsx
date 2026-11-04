import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import {
  CollectionsGridSkeleton,
  PagesTreeSkeleton,
  EntryEditorSkeleton,
  PageDesignerSkeleton,
} from './entry-skeletons';

describe('Entry Skeletons', () => {
  it('renders CollectionsGridSkeleton with role status and 6 cards', () => {
    const { container } = render(<CollectionsGridSkeleton />);
    expect(screen.getByRole('status', { name: 'Loading collections' })).not.toBeNull();
    expect(container.querySelectorAll('[data-testid="collections-grid-skeleton"] > div')).toHaveLength(6);
  });

  it('renders PagesTreeSkeleton with role status and tree rows', () => {
    render(<PagesTreeSkeleton />);
    expect(screen.getByRole('status', { name: 'Loading pages tree' })).not.toBeNull();
  });

  it('renders EntryEditorSkeleton for pages with URL slug and customize button skeletons', () => {
    const { container } = render(<EntryEditorSkeleton collectionHandle="pages" />);
    expect(screen.getByRole('status', { name: 'Loading pages editor' })).not.toBeNull();
    expect(container.querySelector('[data-testid="entry-editor-skeleton"]')).not.toBeNull();
  });

  it('renders EntryEditorSkeleton for posts with rich text editor frame skeleton', () => {
    const { container } = render(<EntryEditorSkeleton collectionHandle="posts" />);
    expect(screen.getByRole('status', { name: 'Loading posts editor' })).not.toBeNull();
    expect(container.querySelector('[data-testid="entry-editor-skeleton"]')).not.toBeNull();
  });

  it('renders EntryEditorSkeleton for authors with biography and avatar skeletons', () => {
    const { container } = render(<EntryEditorSkeleton collectionHandle="authors" />);
    expect(screen.getByRole('status', { name: 'Loading authors editor' })).not.toBeNull();
    expect(container.querySelector('[data-testid="entry-editor-skeleton"]')).not.toBeNull();
  });

  it('renders PageDesignerSkeleton with 3-pane builder layout', () => {
    const { container } = render(<PageDesignerSkeleton />);
    expect(screen.getByRole('status', { name: 'Loading page builder' })).not.toBeNull();
    expect(container.querySelector('[data-testid="page-designer-skeleton"]')).not.toBeNull();
  });
});
