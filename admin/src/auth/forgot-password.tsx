import React, { useState } from 'react';
import { Button, Input, Label } from '@bs/ui';
import { apiClient } from '../lib/api';
import { AuthCard } from './auth-card';

export function ForgotPasswordRoute() {
  const [email, setEmail] = useState('');
  const [isLoading, setIsLoading] = useState(false);
  const [isSubmitted, setIsSubmitted] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsLoading(true);
    setErrorMessage(null);

    try {
      // Backend returns 200 with generic message to prevent enumeration
      await apiClient.request('POST', '/auth/password/forgot', {
        body: { email: email.trim() },
      });
      setIsSubmitted(true);
    } catch {
      setErrorMessage('A network error occurred. Please try again.');
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <AuthCard
      title="Reset your password"
      description={
        isSubmitted
          ? 'Check your inbox for a password reset link.'
          : 'Enter your email address and we will send you a link to reset your password.'
      }
      footer={
        <div>
          <a href="/admin/login" className="text-primary hover:underline">
            Back to sign in
          </a>
        </div>
      }
    >
      {isSubmitted ? (
        <div className="space-y-4">
          <div
            role="status"
            className="rounded-md border border-primary/20 bg-primary/10 p-4 text-13 text-foreground"
          >
            If that email address exists in our system, we have sent a password reset link. Please
            check your email (including spam/junk folders).
          </div>
          <Button
            type="button"
            variant="outline"
            className="w-full"
            onClick={() => {
              setIsSubmitted(false);
              setEmail('');
            }}
          >
            Send another link
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
            <Label htmlFor="forgot-email">Email</Label>
            <Input
              id="forgot-email"
              type="email"
              autoComplete="email"
              required
              autoFocus
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              disabled={isLoading}
              placeholder="admin@example.com"
            />
          </div>

          <Button type="submit" className="w-full" disabled={isLoading || !email.trim()}>
            {isLoading ? 'Sending reset link...' : 'Send reset link'}
          </Button>
        </form>
      )}
    </AuthCard>
  );
}
