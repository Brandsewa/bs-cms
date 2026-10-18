import { describe, expect, it, vi } from 'vitest';
import { renderUI, screen, waitFor } from './test-utils';
import { userEvent } from './test-utils';
import { runAxe } from './axe';
import {
  Select,
  SelectContent,
  SelectGroup,
  SelectItem,
  SelectLabel,
  SelectTrigger,
  SelectValue,
} from '../src/components/select';

function BasicSelect(props: { onValueChange?: (value: string) => void; disabled?: boolean }) {
  return (
    <Select onValueChange={props.onValueChange} disabled={props.disabled}>
      <SelectTrigger aria-label="Status" className="w-40">
        <SelectValue placeholder="Choose status" />
      </SelectTrigger>
      <SelectContent>
        <SelectGroup>
          <SelectLabel>Status</SelectLabel>
          <SelectItem value="draft">Draft</SelectItem>
          <SelectItem value="review">In review</SelectItem>
          <SelectItem value="published">Published</SelectItem>
        </SelectGroup>
      </SelectContent>
    </Select>
  );
}

describe('Select', () => {
  it('opens on click, selects a value, and closes', async () => {
    const onValueChange = vi.fn();
    const user = userEvent.setup();
    renderUI(<BasicSelect onValueChange={onValueChange} />);

    const trigger = screen.getByRole('combobox', { name: 'Status' });
    expect(trigger).toHaveTextContent('Choose status');
    await user.click(trigger);

    const listbox = await screen.findByRole('listbox');
    expect(listbox).toBeInTheDocument();
    expect(screen.getByText('Draft')).toBeInTheDocument();

    await user.click(screen.getByRole('option', { name: 'Published' }));
    await waitFor(() => expect(screen.queryByRole('listbox')).not.toBeInTheDocument());
    expect(trigger).toHaveTextContent('Published');
    expect(onValueChange).toHaveBeenCalledWith('published');
  });

  it('opens with the keyboard and navigates with arrows', async () => {
    const user = userEvent.setup();
    renderUI(<BasicSelect />);
    const trigger = screen.getByRole('combobox', { name: 'Status' });
    trigger.focus();
    await user.keyboard('{Enter}');
    await screen.findByRole('listbox');
    await user.keyboard('{ArrowDown}');
    await user.keyboard('{ArrowDown}');
    await user.keyboard('{Enter}');
    await waitFor(() => expect(screen.queryByRole('listbox')).not.toBeInTheDocument());
    expect(trigger).toHaveTextContent('Published');
  });

  it('closes on Escape without changing the value', async () => {
    const onValueChange = vi.fn();
    const user = userEvent.setup();
    renderUI(<BasicSelect onValueChange={onValueChange} />);
    await user.click(screen.getByRole('combobox', { name: 'Status' }));
    await screen.findByRole('listbox');
    await user.keyboard('{Escape}');
    await waitFor(() => expect(screen.queryByRole('listbox')).not.toBeInTheDocument());
    expect(onValueChange).not.toHaveBeenCalled();
  });

  it('is inert when disabled', async () => {
    renderUI(<BasicSelect disabled />);
    const trigger = screen.getByRole('combobox', { name: 'Status' });
    expect(trigger).toBeDisabled();
    await userEvent.click(trigger);
    expect(screen.queryByRole('listbox')).not.toBeInTheDocument();
  });

  it('passes axe while the listbox is open', async () => {
    const user = userEvent.setup();
    const { container } = renderUI(<BasicSelect />);
    await user.click(screen.getByRole('combobox', { name: 'Status' }));
    await screen.findByRole('listbox');
    // The options render in a portal outside the container; check the document.
    const results = await runAxe(document.body);
    expect(results.violations).toEqual([]);
    expect(container).toBeInTheDocument();
  });
});
