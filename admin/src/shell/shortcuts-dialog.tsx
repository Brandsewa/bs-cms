import { useState, useEffect } from 'react';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
  Kbd,
} from '@bs/ui';
import { Keyboard } from 'lucide-react';

export interface KeyboardShortcutsDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

export function KeyboardShortcutsDialog({ open, onOpenChange }: KeyboardShortcutsDialogProps) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <div className="flex items-center gap-2">
            <Keyboard className="h-5 w-5 text-muted-foreground" />
            <DialogTitle>Keyboard Shortcuts</DialogTitle>
          </div>
          <DialogDescription>
            Quick actions and navigation hotkeys available across the admin shell.
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4 py-2">
          <div>
            <h4 className="text-11 font-semibold uppercase tracking-wider text-muted-foreground mb-2">
              Navigation
            </h4>
            <div className="grid grid-cols-2 gap-2 text-13">
              <div className="flex items-center justify-between rounded border border-border bg-surface-100 p-2">
                <span>Go to Dashboard</span>
                <span className="flex gap-1">
                  <Kbd>G</Kbd> <Kbd>D</Kbd>
                </span>
              </div>
              <div className="flex items-center justify-between rounded border border-border bg-surface-100 p-2">
                <span>Go to Entries</span>
                <span className="flex gap-1">
                  <Kbd>G</Kbd> <Kbd>E</Kbd>
                </span>
              </div>
              <div className="flex items-center justify-between rounded border border-border bg-surface-100 p-2">
                <span>Go to Media</span>
                <span className="flex gap-1">
                  <Kbd>G</Kbd> <Kbd>M</Kbd>
                </span>
              </div>
              <div className="flex items-center justify-between rounded border border-border bg-surface-100 p-2">
                <span>Go to Settings</span>
                <span className="flex gap-1">
                  <Kbd>G</Kbd> <Kbd>S</Kbd>
                </span>
              </div>
            </div>
          </div>

          <div>
            <h4 className="text-11 font-semibold uppercase tracking-wider text-muted-foreground mb-2">
              Commands & Search
            </h4>
            <div className="grid grid-cols-2 gap-2 text-13">
              <div className="flex items-center justify-between rounded border border-border bg-surface-100 p-2">
                <span>Command Menu</span>
                <span className="flex gap-1">
                  <Kbd>Ctrl</Kbd> <Kbd>K</Kbd>
                </span>
              </div>
              <div className="flex items-center justify-between rounded border border-border bg-surface-100 p-2">
                <span>Shortcuts Help</span>
                <Kbd>?</Kbd>
              </div>
            </div>
          </div>
        </div>
      </DialogContent>
    </Dialog>
  );
}

export function useShortcutsDialog() {
  const [open, setOpen] = useState(false);

  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === '?' && !['INPUT', 'TEXTAREA'].includes((e.target as HTMLElement)?.tagName)) {
        e.preventDefault();
        setOpen((prev) => !prev);
      }
    };
    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, []);

  return { open, setOpen };
}
