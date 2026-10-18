import { describe, expect, it, vi } from 'vitest';
import { renderUI, screen, waitFor, userEvent } from './test-utils';
import { runAxe } from './axe';
import {
  ContextMenu,
  ContextMenuContent,
  ContextMenuItem,
  ContextMenuTrigger,
} from '../src/components/context-menu';
import {
  Dialog,
  DialogClose,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogTitle,
  DialogTrigger,
} from '../src/components/dialog';
import { Button } from '../src/components/button';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogTitle,
  AlertDialogTrigger,
} from '../src/components/alert-dialog';

describe('ContextMenu', () => {
  it('opens on right-click (contextmenu event) and fires items', async () => {
    const onCopy = vi.fn();
    const user = userEvent.setup();
    renderUI(
      <ContextMenu>
        <ContextMenuTrigger>
          <div>Media item</div>
        </ContextMenuTrigger>
        <ContextMenuContent>
          <ContextMenuItem onSelect={onCopy}>Copy URL</ContextMenuItem>
        </ContextMenuContent>
      </ContextMenu>
    );
    const region = screen.getByText('Media item');
    await user.pointer({ keys: '[MouseRight]', target: region });
    const menu = await screen.findByRole('menu');
    expect(menu).toBeInTheDocument();
    await user.click(screen.getByRole('menuitem', { name: 'Copy URL' }));
    await waitFor(() => expect(onCopy).toHaveBeenCalledOnce());
  });

  it('passes axe while open', async () => {
    const user = userEvent.setup();
    renderUI(
      <ContextMenu>
        <ContextMenuTrigger aria-label="Context target">
          <div>Target</div>
        </ContextMenuTrigger>
        <ContextMenuContent>
          <ContextMenuItem>Download</ContextMenuItem>
        </ContextMenuContent>
      </ContextMenu>
    );
    await user.pointer({ keys: '[MouseRight]', target: screen.getByText('Target') });
    await screen.findByRole('menu');
    const results = await runAxe(document.body);
    expect(results.violations).toEqual([]);
  });
});

describe('Dialog', () => {
  it('traps focus inside while open and returns it to the trigger on close', async () => {
    const user = userEvent.setup();
    renderUI(
      <Dialog>
        <DialogTrigger>Edit entry</DialogTrigger>
        <DialogContent>
          <DialogTitle>Edit entry</DialogTitle>
          <DialogDescription>Update the entry details.</DialogDescription>
          <DialogFooter>
            <DialogClose asChild>
              <Button>Cancel</Button>
            </DialogClose>
            <Button>Save</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    );
    const trigger = screen.getByRole('button', { name: 'Edit entry' });
    await user.click(trigger);
    const dialog = await screen.findByRole('dialog');
    expect(dialog).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Save' })).toBeInTheDocument();

    // Tab cycles within the dialog (focus trap).
    await user.tab();
    await user.tab();
    const inside = document.activeElement;
    expect(
      [screen.getByRole('button', { name: 'Cancel' }), screen.getByRole('button', { name: 'Save' }), screen.getByRole('button', { name: 'Close' })].includes(inside as HTMLButtonElement)
    ).toBe(true);

    await user.click(screen.getByRole('button', { name: 'Cancel' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    await waitFor(() => expect(trigger).toHaveFocus());
  });

  it('closes on Escape and overlay click and reports open state', async () => {
    const onOpenChange = vi.fn();
    const user = userEvent.setup();
    renderUI(
      <Dialog onOpenChange={onOpenChange}>
        <DialogTrigger>Open</DialogTrigger>
        <DialogContent>
          <DialogTitle>Titled</DialogTitle>
        </DialogContent>
      </Dialog>
    );
    await user.click(screen.getByRole('button', { name: 'Open' }));
    await screen.findByRole('dialog');
    await user.keyboard('{Escape}');
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(onOpenChange).toHaveBeenLastCalledWith(false);
  });

  it('passes axe while open (title + description wired)', async () => {
    const user = userEvent.setup();
    renderUI(
      <Dialog>
        <DialogTrigger aria-label="Delete entry">Delete</DialogTrigger>
        <DialogContent>
          <DialogTitle>Delete entry</DialogTitle>
          <DialogDescription>This moves the entry to trash.</DialogDescription>
        </DialogContent>
      </Dialog>
    );
    await user.click(screen.getByRole('button', { name: 'Delete entry' }));
    await screen.findByRole('dialog');
    const results = await runAxe(document.body);
    expect(results.violations).toEqual([]);
  });
});

describe('AlertDialog', () => {
  it('confirms destructive action from the action button', async () => {
    const onConfirm = vi.fn();
    const user = userEvent.setup();
    renderUI(
      <AlertDialog>
        <AlertDialogTrigger>Delete user</AlertDialogTrigger>
        <AlertDialogContent>
          <AlertDialogTitle>Delete this user?</AlertDialogTitle>
          <AlertDialogDescription>
            The user loses access immediately.
          </AlertDialogDescription>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction onClick={onConfirm}>Delete</AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    );
    await user.click(screen.getByRole('button', { name: 'Delete user' }));
    await screen.findByRole('alertdialog');
    await user.click(screen.getByRole('button', { name: 'Delete' }));
    await waitFor(() => expect(onConfirm).toHaveBeenCalledOnce());
  });

  it('keeps focus off the destructive action initially (cancel is focused)', async () => {
    const user = userEvent.setup();
    renderUI(
      <AlertDialog>
        <AlertDialogTrigger>Remove</AlertDialogTrigger>
        <AlertDialogContent>
          <AlertDialogTitle>Remove role?</AlertDialogTitle>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction>Remove</AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    );
    await user.click(screen.getByRole('button', { name: 'Remove' }));
    await screen.findByRole('alertdialog');
    await waitFor(() =>
      expect(screen.getByRole('button', { name: 'Cancel' })).toHaveFocus()
    );
  });

  it('passes axe while open', async () => {
    const user = userEvent.setup();
    renderUI(
      <AlertDialog>
        <AlertDialogTrigger aria-label="Purge">Purge</AlertDialogTrigger>
        <AlertDialogContent>
          <AlertDialogTitle>Purge permanently?</AlertDialogTitle>
          <AlertDialogDescription>This cannot be undone.</AlertDialogDescription>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction>Purge</AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>
    );
    await user.click(screen.getByRole('button', { name: 'Purge' }));
    await screen.findByRole('alertdialog');
    const results = await runAxe(document.body);
    expect(results.violations).toEqual([]);
  });
});
