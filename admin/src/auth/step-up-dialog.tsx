import React, { useState } from 'react';
import {
  Button,
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  Input,
  Label,
} from '@bs/ui';
import { apiClient } from '../lib/api';

export interface StepUpDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  title?: string;
  description?: string;
  onSuccess: () => void;
}

export function StepUpDialog({
  open,
  onOpenChange,
  title = 'Security confirmation required',
  description = 'Please confirm your password or 2FA code to perform this sensitive action.',
  onSuccess,
}: StepUpDialogProps) {
  const [password, setPassword] = useState('');
  const [code, setCode] = useState('');
  const [mode, setMode] = useState<'password' | 'code'>('password');
  const [isLoading, setIsLoading] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsLoading(true);
    setErrorMessage(null);

    try {
      const payload: Record<string, string> = {};
      if (mode === 'password') {
        payload.password = password;
      } else {
        payload.code = code.trim();
      }

      const res = await apiClient.request('POST', '/auth/step-up', {
        body: payload,
      });

      if (!res.ok) {
        setErrorMessage(
          res.problem.detail || 'Verification failed. Please check your credentials and try again.'
        );
        return;
      }

      setPassword('');
      setCode('');
      onOpenChange(false);
      onSuccess();
    } catch {
      setErrorMessage('A network error occurred. Please try again.');
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-md">
        <form onSubmit={handleSubmit}>
          <DialogHeader>
            <DialogTitle>{title}</DialogTitle>
            <DialogDescription>{description}</DialogDescription>
          </DialogHeader>

          <div className="space-y-4 py-4">
            {errorMessage && (
              <div
                role="alert"
                className="rounded-md border border-destructive/20 bg-destructive/10 p-3 text-13 text-destructive"
              >
                {errorMessage}
              </div>
            )}

            {mode === 'password' ? (
              <div className="space-y-1.5">
                <Label htmlFor="step-up-password">Current account password</Label>
                <Input
                  id="step-up-password"
                  type="password"
                  autoFocus
                  required
                  value={password}
                  onChange={(e) => setPassword(e.target.value)}
                  disabled={isLoading}
                  placeholder="Enter your password"
                />
              </div>
            ) : (
              <div className="space-y-1.5">
                <Label htmlFor="step-up-code">2FA Authentication code or recovery code</Label>
                <Input
                  id="step-up-code"
                  type="text"
                  autoFocus
                  required
                  value={code}
                  onChange={(e) => setCode(e.target.value)}
                  disabled={isLoading}
                  placeholder="6-digit code or backup code"
                  className="font-mono text-center tracking-wider"
                />
              </div>
            )}

            <div>
              <button
                type="button"
                onClick={() => {
                  setMode(mode === 'password' ? 'code' : 'password');
                  setErrorMessage(null);
                }}
                className="text-12 text-primary hover:underline"
              >
                {mode === 'password' ? 'Verify with 2FA code instead' : 'Verify with password instead'}
              </button>
            </div>
          </div>

          <DialogFooter>
            <Button
              type="button"
              variant="outline"
              onClick={() => onOpenChange(false)}
              disabled={isLoading}
            >
              Cancel
            </Button>
            <Button
              type="submit"
              disabled={isLoading || (mode === 'password' ? !password : !code.trim())}
            >
              {isLoading ? 'Verifying...' : 'Confirm'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  );
}
