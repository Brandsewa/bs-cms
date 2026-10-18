import { describe, expect, it, vi } from 'vitest';
import { renderUI, screen, waitFor } from './test-utils';
import { userEvent } from './test-utils';
import { runAxe } from './axe';
import { Combobox } from '../src/components/combobox';

const fruit = [
  { value: 'apple', label: 'Apple' },
  { value: 'banana', label: 'Banana' },
  { value: 'cherry', label: 'Cherry' },
];

describe('Combobox (static options)', () => {
  it('searches, selects, and shows the selected label', async () => {
    const onChange = vi.fn();
    const user = userEvent.setup();
    renderUI(
      <Combobox options={fruit} value={null} onChange={onChange} aria-label="Fruit" />
    );
    await user.click(screen.getByRole('combobox', { name: 'Fruit' }));
    const listbox = await screen.findByRole('listbox');
    expect(listbox).toBeInTheDocument();

    await user.type(screen.getByPlaceholderText('Search…'), 'ban');
    await user.click(screen.getByRole('option', { name: 'Banana' }));
    expect(onChange).toHaveBeenCalledWith('banana');
    await waitFor(() => expect(screen.queryByRole('listbox')).not.toBeInTheDocument());
  });

  it('shows the selected label on the trigger and clears with the clear button', async () => {
    const onChange = vi.fn();
    const user = userEvent.setup();
    renderUI(
      <Combobox options={fruit} value="cherry" onChange={onChange} aria-label="Fruit" />
    );
    expect(screen.getByRole('combobox', { name: 'Fruit' })).toHaveTextContent('Cherry');
    await user.click(screen.getByRole('button', { name: 'Clear selection' }));
    expect(onChange).toHaveBeenCalledWith(null);
  });

  it('shows the empty message when nothing matches', async () => {
    const user = userEvent.setup();
    renderUI(
      <Combobox options={fruit} value={null} onChange={vi.fn()} aria-label="Fruit" />
    );
    await user.click(screen.getByRole('combobox', { name: 'Fruit' }));
    await user.type(screen.getByPlaceholderText('Search…'), 'zzz');
    expect(await screen.findByText('No options found.')).toBeInTheDocument();
  });

  it('is inert when disabled', async () => {
    renderUI(
      <Combobox options={fruit} value={null} onChange={vi.fn()} disabled aria-label="Fruit" />
    );
    await userEvent.click(screen.getByRole('combobox', { name: 'Fruit' }));
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
  });

  it('passes axe while open', async () => {
    const user = userEvent.setup();
    renderUI(
      <Combobox options={fruit} value="apple" onChange={vi.fn()} aria-label="Fruit" />
    );
    await user.click(screen.getByRole('combobox', { name: 'Fruit' }));
    await screen.findByRole('listbox');
    const results = await runAxe(document.body);
    expect(results.violations).toEqual([]);
  });
});

describe('Combobox (async search)', () => {
  it('debounces search and selects async results', async () => {
    const calls: string[] = [];
    const onSearch = vi.fn(async (query: string) => {
      calls.push(query);
      return [{ value: 'user-1', label: `User for ${query}` }];
    });
    const onChange = vi.fn();
    const user = userEvent.setup();
    renderUI(
      <Combobox onSearch={onSearch} value={null} onChange={onChange} aria-label="Assignee" />
    );
    await user.click(screen.getByRole('combobox', { name: 'Assignee' }));
    // Both keystrokes land within the 250 ms debounce window: one call, for
    // the final query only.
    await user.type(screen.getByPlaceholderText('Search…'), 'ab');
    await waitFor(
      () => expect(screen.getByText('User for ab')).toBeInTheDocument(),
      { timeout: 2000 }
    );
    expect(calls).toEqual(['ab']);
    await user.click(screen.getByText('User for ab'));
    expect(onChange).toHaveBeenCalledWith('user-1');
  });

  it('discards stale responses that arrive out of order', async () => {
    let resolveSlow: ((value: { value: string; label: string }[]) => void) | undefined;
    const onSearch = vi.fn((query: string) => {
      if (query === 'x') {
        return new Promise<{ value: string; label: string }[]>((resolve) => {
          resolveSlow = resolve;
        });
      }
      return Promise.resolve([{ value: 'fast', label: 'Fast result' }]);
    });
    const user = userEvent.setup();
    renderUI(
      <Combobox onSearch={onSearch} value={null} onChange={vi.fn()} aria-label="Assignee" />
    );
    await user.click(screen.getByRole('combobox', { name: 'Assignee' }));
    await user.type(screen.getByPlaceholderText('Search…'), 'x');
    await waitFor(() => expect(onSearch).toHaveBeenCalledWith('x'));
    // Typing more starts a new search that wins; the slow 'x' response is stale.
    await user.type(screen.getByPlaceholderText('Search…'), 'y');
    expect(await screen.findByText('Fast result')).toBeInTheDocument();
    resolveSlow?.([{ value: 'stale', label: 'STALE RESULT' }]);
    await new Promise((resolve) => setTimeout(resolve, 20));
    expect(screen.queryByText('STALE RESULT')).not.toBeInTheDocument();
    expect(screen.getByText('Fast result')).toBeInTheDocument();
  });

  it('shows a loading indicator while searching', async () => {
    let resolveSearch: ((options: { value: string; label: string }[]) => void) | undefined;
    const onSearch = vi.fn(
      (): Promise<{ value: string; label: string }[]> =>
        new Promise((resolve) => {
          resolveSearch = resolve;
        })
    );
    const user = userEvent.setup();
    renderUI(
      <Combobox onSearch={onSearch} value={null} onChange={vi.fn()} aria-label="Assignee" />
    );
    await user.click(screen.getByRole('combobox', { name: 'Assignee' }));
    await user.type(screen.getByPlaceholderText('Search…'), 'a');
    expect(await screen.findByText('Searching…')).toBeInTheDocument();
    // Wait for the debounced call before resolving, or the resolve is a no-op.
    await waitFor(() => expect(onSearch).toHaveBeenCalledWith('a'));
    resolveSearch?.([]);
    await waitFor(() => expect(screen.queryByText('Searching…')).not.toBeInTheDocument());
  });
});

describe('Combobox (create option)', () => {
  it('offers creation when no exact match exists and reports the new option', async () => {
    const onCreate = vi.fn(() => ({ value: 'durian', label: 'Durian' }));
    const onChange = vi.fn();
    const user = userEvent.setup();
    renderUI(
      <Combobox
        options={fruit}
        value={null}
        onChange={onChange}
        allowCreate
        onCreate={onCreate}
        aria-label="Fruit"
      />
    );
    await user.click(screen.getByRole('combobox', { name: 'Fruit' }));
    // An exact match to an existing option hides the create action.
    await user.type(screen.getByPlaceholderText('Search…'), 'Apple');
    expect(screen.queryByText('Create "Apple"')).not.toBeInTheDocument();
    // Case-insensitive exact match also hides it.
    await user.clear(screen.getByPlaceholderText('Search…'));
    await user.type(screen.getByPlaceholderText('Search…'), 'apple');
    expect(screen.queryByText('Create "apple"')).not.toBeInTheDocument();
    // A query with no exact match offers creation.
    await user.clear(screen.getByPlaceholderText('Search…'));
    await user.type(screen.getByPlaceholderText('Search…'), 'durian');
    expect(await screen.findByText('Create "durian"')).toBeInTheDocument();
    await user.click(screen.getByText('Create "durian"'));
    expect(onCreate).toHaveBeenCalledWith('durian');
    expect(onChange).toHaveBeenCalledWith('durian');
  });
});
