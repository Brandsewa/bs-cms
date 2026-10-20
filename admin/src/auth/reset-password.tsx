import React, { useState } from 'react';
import { Button, Input, Label } from '@bs/ui';
import { apiClient } from '../lib/api';
import { AuthCard } from './auth-card';

export function ResetPasswordRoute() {
  const searchParams = new URLSearchParams(typeof window !== 'undefined' ? window.location.search : '');
  const token = searchParams.get('token') || '';
  const emailParam = searchParams.get('email') || '';

  const [email, setEmail] = useState(emailParam);
  const [password, setPassword] = useState('');
  const [passwordConfirmation, setPasswordConfirmation] = useState('');
  const [isLoading, setIsLoading] = useState(false);
  const [isSuccess, setIsSuccess] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    if (password !== passwordConfirmation) {
      setErrorMessage('Passwords do not match.');
      return;
    }
    if (password.length < 8) {
      setErrorMessage('Password must be at least 8 characters long.');
      return;
    }

    setIsLoading(true);
    setErrorMessage(null);

    try {
      const res = await apiClient.request('POST', '/auth/password/reset', {
        body: {
          token,
          email: email.trim(),
          password,
          password_confirmation: passwordConfirmation,
        },
      });

      if (!res.ok) {
        setErrorMessage(
          res.problem.detail || 'Failed to reset password. The link may have expired.'
        );
        return;
      }

      setIsSuccess(true);
    } catch {
      setErrorMessage('A network error occurred. Please try again.');
    } finally {
      setIsLoading(false);
    }
  };

  if (!token) {
    return (
      <AuthCard
        title="Invalid link"
        description="Missing reset token. Please request a new password reset link."
        footer={
          <a href="/admin/forgot-password" className="text-primary hover:underline">
            Request new reset link
          </a>
        }
      >
        <div className="text-center py-4">
          <p className="text-13 text-muted-foreground">
            The reset link you followed is missing required parameters.
          </p>
        </div>
      </AuthCard>
    );
  }

  return (
    <AuthCard
      title="Set a new password"
      description="Choose a secure password with at least 8 characters."
      footer={
        <div>
          <a href="/admin/login" className="text-primary hover:underline">
            Back to sign in
          </a>
        </div>
      }
    >
      {isSuccess ? (
        <div className="space-y-4">
          <div
            role="status"
            className="rounded-md border border-primary/20 bg-primary/10 p-4 text-13 text-foreground"
          >
            Your password has been reset successfully. You can now log in with your new password.
          </div>
          <Button
            type="button"
            className="w-full"
            onClick={() => {
              window.location.href = '/admin/login';
            }}
          >
            Go to sign in
          </Button>
        </div>
      ) : (
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
            <Label htmlFor="reset-email">Email address</Label>
            <Input
              id="reset-email"
              type="email"
              autoComplete="email"
              required
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              disabled={isLoading || Boolean(emailParam)}
              placeholder="admin@example.com"
            />
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="reset-password">New password</Label>
            <Input
              id="reset-password"
              type="password"
              autoComplete="new-password"
              required
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              disabled={isLoading}
              placeholder="At least 8 characters"
            />
          </div>

          <div className="space-y-1.5">
            <Label htmlFor="reset-password-confirm">Confirm new password</Label>
            <Input
              id="reset-password-confirm"
              type="password"
              autoComplete="new-password"
              required
              value={passwordConfirmation}
              onChange={(e) => setPasswordConfirmation(e.target.value)}
              disabled={isLoading}
              placeholder="Repeat new password"
            />
          </div>

          <Button
            type="submit"
            className="w-full"
            disabled={isLoading || !password || !passwordConfirmation}
          >
            {isLoading ? 'Resetting password...' : 'Reset password'}
          </Button>
        </form>
      )}
    </AuthCard>
  );
}
