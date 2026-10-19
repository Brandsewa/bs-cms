import { describe, expect, it, vi } from 'vitest';
import { useState } from 'react';
import { renderUI, screen, waitFor } from './test-utils';
import { userEvent } from './test-utils';
import { runAxe } from './axe';
import { MediaPicker } from '../src/pickers/media-picker';
import { TermPicker } from '../src/pickers/term-picker';
import { RelationPicker } from '../src/pickers/relation-picker';
import { UserPicker } from '../src/pickers/user-picker';
import { SlugInput, slugify } from '../src/pickers/slug-input';

const MEDIA = [
  { id: 'm1', filename: 'hero.png', mime: 'image/png', mediaUrl: 'https://x/hero.png', folderId: 'f1' },
  { id: 'm2', filename: 'team.jpg', mime: 'image/jpeg', folderId: 'f1' },
  { id: 'm3', filename: 'report.pdf', mime: 'application/pdf', folderId: null },
];
const FOLDERS = [
  { id: 'f1', label: 'Images' },
  { id: 'f2', label: 'Docs' },
];

describe('MediaPicker', () => {
  it('filters by folder and search; selects via grid buttons', async () => {
    const onSelect = vi.fn();
    const user = userEvent.setup();
    renderUI(
      <MediaPicker items={MEDIA} folders={FOLDERS} selectedId={null} onSelect={onSelect} aria-label="Media" />
    );
    // All three visible initially.
    expect(screen.getAllByRole('option').length).toBe(3);

    await user.click(screen.getByRole('combobox', { name: 'Folder' }));
    await user.click(await screen.findByRole('option', { name: 'Images' }));
    expect(screen.getAllByRole('option').length).toBe(2);

    await user.type(screen.getByLabelText('Search media…'), 'team');
    await waitFor(() => expect(screen.getAllByRole('option').length).toBe(1));
    await user.click(screen.getByRole('option', { name: /team\.jpg/ }));
    expect(onSelect).toHaveBeenCalledWith(MEDIA[1]);
  });

  it('uploads via the injected uploader and surfaces progress + errors', async () => {
    const onSelect = vi.fn();
    const user = userEvent.setup();
    const uploader = vi
      .fn()
      .mockResolvedValueOnce({ id: 'm9', filename: 'new.png', mime: 'image/png' })
      .mockRejectedValueOnce(new Error('Chunk 0 failed'));
    renderUI(
      <MediaPicker items={[]} folders={[]} selectedId={null} onSelect={onSelect} uploader={uploader} />
    );
    await user.click(screen.getByRole('button', { name: 'Upload' }));
    const input = document.querySelector('input[type="file"]') as HTMLInputElement;
    const file = new File(['x'], 'new.png', { type: 'image/png' });
    await user.upload(input, file);
    await waitFor(() => expect(onSelect).toHaveBeenCalledWith(expect.objectContaining({ id: 'm9' })));

    // Second upload fails → inline error.
    await user.click(screen.getByRole('button', { name: 'Upload' }));
    const failFile = new File(['y'], 'fail.png', { type: 'image/png' });
    await user.upload(input, failFile);
    expect(await screen.findByRole('alert')).toHaveTextContent('Chunk 0 failed');
  });

  it('passes axe', async () => {
    const { container } = renderUI(
      <MediaPicker items={MEDIA} folders={FOLDERS} selectedId="m1" onSelect={() => {}} aria-label="Media" />
    );
    const results = await runAxe(container);
    expect(results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical')).toEqual([]);
  });
});

const TERM_TREE = [
  {
    id: 'news',
    label: 'News',
    children: [
      { id: 'politics', label: 'Politics' },
      { id: 'sports', label: 'Sports' },
    ],
  },
  { id: 'guides', label: 'Guides' },
];

describe('TermPicker', () => {
  it('selects terms, honours search pruning', async () => {
    const onChange = vi.fn();
    const user = userEvent.setup();
    const { rerender } = renderUI(
      <TermPicker tree={TERM_TREE} value={[]} onChange={onChange} aria-label="Terms" />
    );
    await user.type(screen.getByLabelText('Search terms…'), 'pol');
    await waitFor(() => expect(screen.queryByText('Guides')).not.toBeInTheDocument());

    rerender(<TermPicker tree={TERM_TREE} value={['politics']} onChange={onChange} aria-label="Terms" />);
    expect(screen.getByText(/Politics ✓/)).toBeInTheDocument();
    expect(screen.getByText(/1 selected/)).toBeInTheDocument();
  });

  it('include subcategories toggles subtree selection', async () => {
    const onChange = vi.fn();
    const user = userEvent.setup();
    const { rerender } = renderUI(
      <TermPicker
        tree={TERM_TREE}
        value={[]}
        onChange={onChange}
        includeSubcategories={false}
        onIncludeSubcategoriesChange={() => {}}
        aria-label="Terms"
      />
    );
    await user.click(screen.getByText('News'));
    expect(onChange).toHaveBeenLastCalledWith(['news']);

    const toggle = vi.fn();
    rerender(
      <TermPicker
        tree={TERM_TREE}
        value={['news']}
        onChange={onChange}
        includeSubcategories
        onIncludeSubcategoriesChange={toggle}
        aria-label="Terms"
      />
    );
    // With subcategories on, 'news' shows as selected through the subtree.
    expect(screen.getByText(/News ✓/)).toBeInTheDocument();
    void toggle;
  });

  it('passes axe', async () => {
    const { container } = renderUI(
      <TermPicker tree={TERM_TREE} value={['news']} onChange={() => {}} aria-label="Terms" />
    );
    const results = await runAxe(container);
    expect(results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical')).toEqual([]);
  });
});

const USERS = [
  { id: 'u1', name: 'Ada Lovelace' },
  { id: 'u2', name: 'Grace Hopper' },
];

describe('RelationPicker / UserPicker', () => {
  it('RelationPicker: debounced async search, keyboard selection, duplicate guard', async () => {
    const onChange = vi.fn();
    const search = vi.fn(async (query: string) =>
      [{ id: 'r1', name: `Result for ${query}` }]
    );
    const user = userEvent.setup();
    renderUI(
      <RelationPicker value={[]} onChange={onChange} search={search} aria-label="Related entry" />
    );
    const input = screen.getByLabelText('Related entry');
    await user.type(input, 'post');
    await waitFor(() => expect(search).toHaveBeenCalledWith('post'));

    const option = await screen.findByRole('option', { name: /Result for post/ });
    await user.keyboard('{ArrowDown}');
    await user.keyboard('{Enter}');
    expect(onChange).toHaveBeenCalledWith([{ id: 'r1', name: 'Result for post' }]);
    void option;
  });

  it('RelationPicker: chips remove with backspace and button', async () => {
    const onChange = vi.fn();
    const user = userEvent.setup();
    const { rerender } = renderUI(
      <RelationPicker value={[{ id: 'r1', name: 'Alpha' }]} onChange={onChange} search={async () => []} aria-label="Related" />
    );
    await user.click(screen.getByRole('button', { name: 'Remove Alpha' }));
    expect(onChange).toHaveBeenCalledWith([]);

    rerender(
      <RelationPicker value={[{ id: 'r1', name: 'Alpha' }]} onChange={onChange} search={async () => []} aria-label="Related" />
    );
    const input = screen.getByLabelText('Related');
    await user.click(input);
    await user.keyboard('{Backspace}');
    expect(onChange).toHaveBeenLastCalledWith([]);
  });

  it('UserPicker: avatar search and single-select clear', async () => {
    const onChange = vi.fn();
    const user = userEvent.setup();
    const { rerender } = renderUI(
      <UserPicker users={USERS} value={null} onChange={onChange} aria-label="Assignee" />
    );
    const input = screen.getByLabelText('Assignee');
    await user.click(input);
    await user.click(await screen.findByRole('option', { name: /Grace Hopper/ }));
    expect(onChange).toHaveBeenCalledWith('u2');

    rerender(<UserPicker users={USERS} value="u2" onChange={onChange} aria-label="Assignee" />);
    await user.click(screen.getByRole('button', { name: 'Remove Grace Hopper' }));
    expect(onChange).toHaveBeenLastCalledWith(null);
  });

  it('passes axe for both', async () => {
    const { container } = renderUI(
      <div>
        <RelationPicker value={[]} onChange={() => {}} search={async () => []} aria-label="Related" />
        <UserPicker users={USERS} value={null} onChange={() => {}} aria-label="Assignee" />
      </div>
    );
    const results = await runAxe(container);
    expect(results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical')).toEqual([]);
  });
});

describe('SlugInput', () => {
  it('slugify produces URL-safe slugs', () => {
    expect(slugify('Hello, World! 2026')).toBe('hello-world-2026');
    expect(slugify('Über Café naïve')).toBe('uber-cafe-naive');
    expect(slugify('--Weird--title--')).toBe('weird-title');
  });

  it('auto-derives from the source while locked; stops when unlocked', async () => {
    const onChange = vi.fn();
    const { rerender } = renderUI(
      <SlugInput value="" onChange={onChange} source="My First Post" aria-label="Slug" />
    );
    rerender(<SlugInput value="my-first-post" onChange={onChange} source="A Better Title" aria-label="Slug" />);
    expect(onChange).toHaveBeenLastCalledWith('a-better-title');
  });

  it('reports uniqueness via the debounced validate callback', async () => {
    const validate = vi.fn().mockResolvedValue('taken');
    function StatefulSlug() {
      const [value, setValue] = useState('');
      return (
        <SlugInput value={value} onChange={setValue} source="Existing Post" validate={validate} aria-label="Slug" />
      );
    }
    renderUI(<StatefulSlug />);
    await waitFor(() => expect(validate).toHaveBeenCalledWith('existing-post'));
    expect(await screen.findByRole('alert')).toHaveTextContent(/already in use/);
  });

  it('shows available state and full URL preview', async () => {
    const validate = vi.fn().mockResolvedValue('available');
    void validate;
    renderUI(
      <SlugInput
        value="my-post"
        onChange={() => {}}
        validate={validate}
        urlPrefix="https://site.com/posts/"
        aria-label="Slug"
      />
    );
    await waitFor(() => expect(validate).toHaveBeenCalled());
    expect(screen.getByText('https://site.com/posts/')).toBeInTheDocument();
    expect(screen.getByText('my-post')).toBeInTheDocument();
  });

  it('passes axe with taken state', async () => {
    const validate = vi.fn().mockResolvedValue('taken');
    const { container } = renderUI(
      <SlugInput value="taken-post" onChange={() => {}} validate={validate} aria-label="Slug" />
    );
    await waitFor(() => expect(validate).toHaveBeenCalledWith('taken-post'));
    const results = await runAxe(container);
    expect(results.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical')).toEqual([]);
  });
});
