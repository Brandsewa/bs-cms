import { describe, expect, it, vi } from 'vitest';
import { renderUI, screen, waitFor } from './test-utils';
import { userEvent } from './test-utils';
import { runAxe } from './axe';
import {
  DropdownMenu,
  DropdownMenuCheckboxItem,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuRadioGroup,
  DropdownMenuRadioItem,
  DropdownMenuSeparator,
  DropdownMenuShortcut,
  DropdownMenuTrigger,
} from '../src/components/dropdown-menu';

function RowActions({ onDelete, onView }: { onDelete: () => void; onView: () => void }) {
  return (
    <DropdownMenu>
      <DropdownMenuTrigger aria-label="Row actions">⋯</DropdownMenuTrigger>
      <DropdownMenuContent>
        <DropdownMenuLabel>Actions</DropdownMenuLabel>
        <DropdownMenuItem onSelect={onView}>
          Edit
          <DropdownMenuShortcut>E</DropdownMenuShortcut>
        </DropdownMenuItem>
        <DropdownMenuSeparator />
        <DropdownMenuItem destructive onSelect={onDelete}>
          Delete
        </DropdownMenuItem>
      </DropdownMenuContent>
    </DropdownMenu>
  );
}

describe('DropdownMenu', () => {
  it('opens on click, fires item handlers and closes', async () => {
    const onDelete = vi.fn();
    const onView = vi.fn();
    const user = userEvent.setup();
    renderUI(<RowActions onDelete={onDelete} onView={onView} />);

    await user.click(screen.getByRole('button', { name: 'Row actions' }));
    const menu = await screen.findByRole('menu');
    expect(menu).toBeInTheDocument();

    await user.click(screen.getByRole('menuitem', { name: /edit/i }));
    await waitFor(() => expect(screen.queryByRole('menu')).not.toBeInTheDocument());
    expect(onView).toHaveBeenCalledOnce();
    expect(onDelete).not.toHaveBeenCalled();
  });

  it('navigates items with arrow keys and activates with Enter', async () => {
    const onDelete = vi.fn();
    const onView = vi.fn();
    const user = userEvent.setup();
    renderUI(<RowActions onDelete={onDelete} onView={onView} />);

    await user.click(screen.getByRole('button', { name: 'Row actions' }));
    await screen.findByRole('menu');
    await user.keyboard('{ArrowDown}');
    expect(screen.getByRole('menuitem', { name: /edit/i })).toHaveFocus();
    await user.keyboard('{ArrowDown}');
    expect(screen.getByRole('menuitem', { name: /delete/i })).toHaveFocus();
    await user.keyboard('{Enter}');
    await waitFor(() => expect(onDelete).toHaveBeenCalledOnce());
  });

  it('closes on Escape without activating an item', async () => {
    const onDelete = vi.fn();
    const user = userEvent.setup();
    renderUI(<RowActions onDelete={onDelete} onView={vi.fn()} />);
    await user.click(screen.getByRole('button', { name: 'Row actions' }));
    await screen.findByRole('menu');
    await user.keyboard('{Escape}');
    await waitFor(() => expect(screen.queryByRole('menu')).not.toBeInTheDocument());
    expect(onDelete).not.toHaveBeenCalled();
  });

  it('supports checkbox and radio items', async () => {
    const onChecked = vi.fn();
    const onSort = vi.fn();
    const user = userEvent.setup();
    renderUI(
      <DropdownMenu>
        <DropdownMenuTrigger>Columns</DropdownMenuTrigger>
        <DropdownMenuContent>
          <DropdownMenuCheckboxItem checked={false} onCheckedChange={onChecked}>
            Status
          </DropdownMenuCheckboxItem>
          <DropdownMenuRadioGroup value="asc" onValueChange={onSort}>
            <DropdownMenuRadioItem value="asc">Ascending</DropdownMenuRadioItem>
            <DropdownMenuRadioItem value="desc">Descending</DropdownMenuRadioItem>
          </DropdownMenuRadioGroup>
        </DropdownMenuContent>
      </DropdownMenu>
    );
    await user.click(screen.getByRole('button', { name: 'Columns' }));
    await screen.findByRole('menu');
    await user.click(screen.getByRole('menuitemcheckbox', { name: 'Status' }));
    expect(onChecked).toHaveBeenCalledWith(true);
    await user.click(screen.getByRole('button', { name: 'Columns' }));
    await screen.findByRole('menu');
    await user.click(screen.getByRole('menuitemradio', { name: 'Descending' }));
    expect(onSort).toHaveBeenCalledWith('desc');
  });

  it('passes axe while open', async () => {
    const user = userEvent.setup();
    renderUI(<RowActions onDelete={vi.fn()} onView={vi.fn()} />);
    await user.click(screen.getByRole('button', { name: 'Row actions' }));
    await screen.findByRole('menu');
    const results = await runAxe(document.body);
    expect(results.violations).toEqual([]);
  });
});
