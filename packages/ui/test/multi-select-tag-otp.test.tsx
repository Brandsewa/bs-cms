import { describe, expect, it, vi } from 'vitest';
import { renderUI, screen } from './test-utils';
import { userEvent } from './test-utils';
import { runAxe } from './axe';
import { MultiSelect } from '../src/components/multi-select';
import { TagInput } from '../src/components/tag-input';
import { InputOTP } from '../src/components/input-otp';

const categories = [
  { value: '1', label: 'News' },
  { value: '2', label: 'Guides' },
  { value: '3', label: 'Releases' },
];

describe('MultiSelect', () => {
  it('selects multiple values, shows chips and removes via chip button', async () => {
    const onChange = vi.fn();
    const user = userEvent.setup();
    const { rerender } = renderUI(
      <MultiSelect options={categories} value={['1']} onChange={onChange} aria-label="Categories" />
    );
    expect(screen.getByRole('button', { name: 'Remove News' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Categories' })).toHaveTextContent('1 selected');
    await user.click(screen.getByRole('button', { name: 'Categories' }));
    await user.click(await screen.findByRole('option', { name: 'Guides' }));
    expect(onChange).toHaveBeenCalledWith(['1', '2']);

    rerender(<MultiSelect options={categories} value={['1', '2']} onChange={onChange} aria-label="Categories" />);
    await user.click(screen.getByRole('button', { name: 'Remove News' }));
    expect(onChange).toHaveBeenCalledWith(['2']);
  });

  it('select all sets every option, clear all empties the selection', async () => {
    const onChange = vi.fn();
    const user = userEvent.setup();
    renderUI(
      <MultiSelect options={categories} value={['1']} onChange={onChange} aria-label="Categories" />
    );
    await user.click(screen.getByRole('button', { name: 'Categories' }));
    await user.click(await screen.findByRole('option', { name: 'Select all' }));
    expect(onChange).toHaveBeenCalledWith(['1', '2', '3']);

    onChange.mockClear();
    renderUI(
      <MultiSelect options={categories} value={['1', '2', '3']} onChange={onChange} aria-label="All picked" />
    );
    await user.click(screen.getByRole('button', { name: 'All picked' }));
    await user.click(await screen.findByRole('option', { name: 'Clear all' }));
    expect(onChange).toHaveBeenCalledWith([]);
  });

  it('overflows chips into a +N badge', () => {
    renderUI(
      <MultiSelect
        options={categories}
        value={['1', '2', '3']}
        onChange={vi.fn()}
        maxChips={1}
        aria-label="Overflowing"
      />
    );
    expect(screen.getByText('News')).toBeInTheDocument();
    expect(screen.getByText('+2 more')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Overflowing' })).toHaveTextContent(
      '3 selected'
    );
  });

  it('passes axe while open with chips', async () => {
    const user = userEvent.setup();
    renderUI(
      <MultiSelect options={categories} value={['1']} onChange={vi.fn()} aria-label="Categories" />
    );
    await user.click(screen.getByRole('button', { name: 'Categories' }));
    await screen.findByRole('option', { name: 'Guides' });
    const results = await runAxe(document.body);
    expect(results.violations).toEqual([]);
  });
});

describe('TagInput', () => {
  it('adds a tag on Enter, clears the draft, and removes via chip', async () => {
    const onChange = vi.fn();
    const user = userEvent.setup();
    const { rerender } = renderUI(<TagInput value={[]} onChange={onChange} aria-label="Tags" />);
    const input = screen.getByLabelText('Tags');
    await user.type(input, 'seo{Enter}');
    expect(onChange).toHaveBeenCalledWith(['seo']);

    rerender(<TagInput value={['seo']} onChange={onChange} aria-label="Tags" />);
    await user.click(screen.getByRole('button', { name: 'Remove seo' }));
    expect(onChange).toHaveBeenCalledWith([]);
  });

  it('rejects duplicates with an inline message', async () => {
    const onChange = vi.fn();
    const user = userEvent.setup();
    renderUI(<TagInput value={['seo']} onChange={onChange} aria-label="Tags" />);
    const input = screen.getByLabelText('Tags');
    await user.type(input, 'SEO{Enter}');
    expect(screen.getByRole('alert')).toHaveTextContent('"SEO" has already been added.');
    expect(onChange).not.toHaveBeenCalled();
  });

  it('rejects invalid tags via the validate callback', async () => {
    const onChange = vi.fn();
    const user = userEvent.setup();
    renderUI(
      <TagInput
        value={[]}
        onChange={onChange}
        aria-label="Tags"
        validate={(tag) => (tag === 'bad' ? 'Not allowed' : null)}
      />
    );
    await user.type(screen.getByLabelText('Tags'), 'bad{Enter}');
    expect(screen.getByRole('alert')).toHaveTextContent('Not allowed');
    expect(onChange).not.toHaveBeenCalled();
  });

  it('removes the last tag with Backspace on an empty field', async () => {
    const onChange = vi.fn();
    const user = userEvent.setup();
    renderUI(<TagInput value={['a', 'b']} onChange={onChange} aria-label="Tags" />);
    const input = screen.getByLabelText('Tags');
    await user.click(input);
    await user.keyboard('{Backspace}');
    expect(onChange).toHaveBeenCalledWith(['a']);
  });

  it('clears the error when typing again', async () => {
    const onChange = vi.fn();
    const user = userEvent.setup();
    renderUI(<TagInput value={['seo']} onChange={onChange} aria-label="Tags" />);
    const input = screen.getByLabelText('Tags');
    await user.type(input, 'seo{Enter}');
    expect(screen.getByRole('alert')).toBeInTheDocument();
    await user.type(input, 'x');
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
  });

  it('passes axe with tags and an error', async () => {
    const user = userEvent.setup();
    const { container } = renderUI(<TagInput value={['seo']} onChange={vi.fn()} aria-label="Tags" />);
    const input = screen.getByLabelText('Tags');
    await user.type(input, 'seo{Enter}');
    const results = await runAxe(container);
    expect(results.violations).toEqual([]);
  });
});

describe('InputOTP', () => {
  it('advances to the next cell while typing', async () => {
    const onChange = vi.fn();
    const user = userEvent.setup();
    renderUI(<InputOTP value="" onChange={onChange} aria-label="Verification code" />);
    const cells = screen.getAllByRole('textbox', { name: /Verification code/ });
    await user.type(cells[0]!, '1');
    expect(onChange).toHaveBeenCalledWith('1');
    expect(cells[1]).toHaveFocus();
  });

  it('accepts a pasted code across all cells', async () => {
    const onChange = vi.fn();
    const user = userEvent.setup();
    renderUI(<InputOTP value="" onChange={onChange} aria-label="Verification code" />);
    const first = screen.getAllByRole('textbox', { name: /Verification code/ })[0]!;
    await user.click(first);
    await user.paste('123456');
    expect(onChange).toHaveBeenCalledWith('123456');
  });

  it('moves back with Backspace and left arrow', async () => {
    const user = userEvent.setup();
    renderUI(<InputOTP value="123456" onChange={vi.fn()} aria-label="Verification code" />);
    const cells = screen.getAllByRole('textbox', { name: /Verification code/ });
    await user.click(cells[3]!);
    await user.keyboard('{ArrowLeft}');
    expect(cells[2]).toHaveFocus();
    await user.keyboard('{Backspace}');
    // Backspace on a filled cell clears it and stays.
    expect(document.activeElement).toBe(cells[2]);
  });

  it('ignores non-digits', async () => {
    const onChange = vi.fn();
    const user = userEvent.setup();
    renderUI(<InputOTP value="" onChange={onChange} aria-label="Verification code" />);
    await user.type(screen.getAllByLabelText(/Verification code/)[0]!, 'x');
    expect(onChange).not.toHaveBeenCalled();
  });

  it('is inert when disabled', async () => {
    const onChange = vi.fn();
    const user = userEvent.setup();
    renderUI(<InputOTP value="" onChange={onChange} disabled aria-label="Verification code" />);
    const cells = screen.getAllByRole('textbox', { name: /Verification code/ });
    await user.type(cells[0]!, '1');
    expect(onChange).not.toHaveBeenCalled();
  });

  it('passes axe', async () => {
    const { container } = renderUI(
      <InputOTP value="12" onChange={vi.fn()} aria-label="Verification code" />
    );
    const results = await runAxe(container);
    expect(results.violations).toEqual([]);
  });
});
