import {
  Button,
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@bs/ui';
import { Clock } from 'lucide-react';
import { sanitizeReturnTo } from '../lib/return-to';

export interface SessionExpiredDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  returnTo?: string;
}

export function SessionExpiredDialog({
  open,
  onOpenChange,
  returnTo,
}: SessionExpiredDialogProps) {
  const handleSignIn = () => {
    const current =
      returnTo || (typeof window !== 'undefined' ? window.location.pathname + window.location.search : '/admin/');
    const safe = sanitizeReturnTo(current);
    window.location.href = `/admin/login?return_to=${encodeURIComponent(safe)}`;
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <div className="mx-auto mb-2 flex h-10 w-10 items-center justify-center rounded-full bg-amber-500/10 text-amber-500">
            <Clock className="h-5 w-5" />
          </div>
          <DialogTitle className="text-center">Session Expired</DialogTitle>
          <DialogDescription className="text-center">
            Your administrative session has timed out due to inactivity. Please sign in again to continue where you left off.
          </DialogDescription>
        </DialogHeader>
        <DialogFooter className="mt-4 flex flex-col gap-2 sm:flex-row sm:justify-end">
          <Button onClick={handleSignIn} className="w-full sm:w-auto">
            Sign In Again
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
