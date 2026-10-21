import { useState, useEffect } from 'react';
import {
  Button,
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@bs/ui';
import { Sparkles, ArrowRight, ShieldCheck } from 'lucide-react';
import { useNavigate } from '@tanstack/react-router';

export function FirstRunWelcomeDialog({ userId }: { userId?: string }) {
  const navigate = useNavigate();
  const storageKey = `bs_welcome_seen_${userId || 'current'}`;
  const [open, setOpen] = useState(false);

  useEffect(() => {
    if (typeof window !== 'undefined') {
      const seen = localStorage.getItem(storageKey);
      if (!seen) {
        setOpen(true);
      }
    }
  }, [storageKey]);

  const handleClose = () => {
    setOpen(false);
    if (typeof window !== 'undefined') {
      localStorage.setItem(storageKey, 'true');
    }
  };

  const handleExplore = () => {
    handleClose();
    navigate({ to: '/account' });
  };

  return (
    <Dialog open={open} onOpenChange={setOpen}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <div className="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-xl bg-primary/10 text-primary border border-primary/20">
            <Sparkles className="h-6 w-6" />
          </div>
          <DialogTitle className="text-center text-lg">Welcome to BS CMS Studio</DialogTitle>
          <DialogDescription className="text-center text-13">
            Your high-performance, autonomous content management environment is configured and ready to go.
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-3 py-3">
          <div className="flex items-start gap-3 rounded-md border border-border bg-surface-100 p-3">
            <ShieldCheck className="h-4 w-4 text-emerald-500 mt-0.5 shrink-0" />
            <div className="text-12 space-y-0.5">
              <p className="font-semibold text-foreground">Secure by Default</p>
              <p className="text-muted-foreground">
                Role-based access control, step-up security, and full audit logging are active.
              </p>
            </div>
          </div>
        </div>

        <DialogFooter className="mt-2 flex flex-col gap-2 sm:flex-row sm:justify-between">
          <Button variant="ghost" onClick={handleClose} className="w-full sm:w-auto text-12">
            Dismiss
          </Button>
          <Button onClick={handleExplore} className="w-full sm:w-auto gap-1 text-12">
            Configure My Account <ArrowRight className="h-3.5 w-3.5" />
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
