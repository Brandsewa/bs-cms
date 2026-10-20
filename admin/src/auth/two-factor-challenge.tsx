import React, { useState } from 'react';
import { Button, Input, Label } from '@bs/ui';
import { apiClient } from '../lib/api';
import { AuthCard } from './auth-card';

export function TwoFactorChallenge({
  challengeToken,
  returnTo,
  onCancel,
}: {
  challengeToken: string;
  returnTo: string;
  onCancel: () => void;
}) {
  const [code, setCode] = useState('');
  const [isRecovery, setIsRecovery] = useState(false);
  const [isLoading, setIsLoading] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsLoading(true);
    setErrorMessage(null);

    try {
      // NOTE: backend API contract strictly requires single field `code` for both TOTP and recovery code
      const res = await apiClient.request<{
        status: 'authenticated';
        user?: { id: string; email: string; name: string; role: string };
      }>('POST', '/auth/2fa/challenge', {
        body: {
          challenge_token: challengeToken,
          code: code.trim(),
        },
      });

      if (!res.ok) {
        setErrorMessage(
          res.problem.detail || 'Invalid authentication code. Please check and try again.'
        );
        return;
      }

      // Bootstrap CSRF token
      const sessionRes = await apiClient.request<{ csrf_token?: string }>('GET', '/auth/session');
      if (sessionRes.ok && sessionRes.data.csrf_token) {
        apiClient.setCsrfToken(sessionRes.data.csrf_token);
      }

      window.location.href = returnTo;
    } catch {
      setErrorMessage('A network error occurred. Please try again.');
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <AuthCard
      title="Two-Factor Authentication"
      description={
        isRecovery
          ? 'Enter one of your 8-character backup recovery codes.'
          : 'Enter the 6-digit code from your authenticator app.'
      }
      footer={
        <div className="space-y-2">
          <div>
            <button
              type="button"
              onClick={() => {
                setIsRecovery(!isRecovery);
                setCode('');
                setErrorMessage(null);
              }}
              className="text-primary hover:underline"
            >
              {isRecovery
                ? 'Use authenticator app (TOTP)'
                : 'Lost access? Use a backup recovery code'}
            </button>
          </div>
          <div>
            <button
              type="button"
              onClick={onCancel}
              className="text-muted-foreground hover:underline"
            >
              Cancel and back to sign in
            </button>
          </div>
        </div>
      }
    >
      <form onSubmit={handleSubmit} className="space-y-4" noValidate>
        {errorMessage && (
          <div
            role="alert"
            className="rounded-md border border-destructive/20 bg-destructive/10 p-3 text-13 text-destructive"
          >
            {errorMessage}
          </div>
        )}

        <div className="space-y-1.5">
          <Label htmlFor="two-factor-code">
            {isRecovery ? 'Backup recovery code' : 'Authentication code'}
          </Label>
          <Input
            id="two-factor-code"
            type="text"
            inputMode={isRecovery ? 'text' : 'numeric'}
            autoComplete="one-time-code"
            autoFocus
            required
            value={code}
            onChange={(e) => setCode(e.target.value)}
            disabled={isLoading}
            placeholder={isRecovery ? 'e.g. ab12-cd34' : '123456'}
            className="text-center font-mono tracking-widest text-lg"
          />
        </div>

        <Button type="submit" className="w-full" disabled={isLoading || !code.trim()}>
          {isLoading ? 'Verifying...' : 'Verify'}
        </Button>
      </form>
    </AuthCard>
  );
}
