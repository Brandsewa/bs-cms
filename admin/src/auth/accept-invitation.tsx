import React, { useEffect, useState } from 'react';
import { Button, Input, Label } from '@bs/ui';
import { apiClient } from '../lib/api';
import { AuthCard } from './auth-card';

export function AcceptInvitationRoute() {
  const searchParams = new URLSearchParams(typeof window !== 'undefined' ? window.location.search : '');
  const token = searchParams.get('token') || '';

  const [isLoadingToken, setIsLoadingToken] = useState(true);
  const [tokenError, setTokenError] = useState<string | null>(null);
  const [inviteEmail, setInviteEmail] = useState('');

  const [name, setName] = useState('');
  const [password, setPassword] = useState('');
  const [passwordConfirmation, setPasswordConfirmation] = useState('');
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);

  useEffect(() => {
    if (!token) {
      setTokenError('Invitation token is missing from the link.');
      setIsLoadingToken(false);
      return;
    }

    const validateToken = async () => {
      try {
        const res = await apiClient.request<{ email?: string; role?: string }>(
          'GET',
          `/auth/invitations/${encodeURIComponent(token)}`
        );
        if (!res.ok) {
          setTokenError(
            res.problem.detail ||
              'This invitation link is invalid or has expired. Please contact an administrator.'
          );
        } else {
          setInviteEmail(res.data.email || '');
        }
      } catch {
        setTokenError('Could not verify invitation token. Please check your network connection.');
      } finally {
        setIsLoadingToken(false);
      }
    };

    void validateToken();
  }, [token]);

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

    setIsSubmitting(true);
    setErrorMessage(null);

    try {
      const res = await apiClient.request(
        'POST',
        `/auth/invitations/${encodeURIComponent(token)}/accept`,
        {
          body: {
            name: name.trim(),
            password,
            password_confirmation: passwordConfirmation,
          },
        }
      );

      if (!res.ok) {
        setErrorMessage(res.problem.detail || 'Failed to accept invitation. Please try again.');
        return;
      }

      // Bootstrap session and redirect to admin dashboard
      const sessionRes = await apiClient.request<{ csrf_token?: string }>('GET', '/auth/session');
      if (sessionRes.ok && sessionRes.data.csrf_token) {
        apiClient.setCsrfToken(sessionRes.data.csrf_token);
      }

      window.location.href = '/admin';
    } catch {
      setErrorMessage('A network error occurred. Please try again.');
    } finally {
      setIsSubmitting(false);
    }
  };

  if (isLoadingToken) {
    return (
      <AuthCard title="Verifying invitation" description="Please wait while we check your invite link...">
        <div className="flex justify-center py-8">
          <div className="h-6 w-6 animate-spin rounded-full border-2 border-primary border-t-transparent" />
        </div>
      </AuthCard>
    );
  }

  if (tokenError) {
    return (
      <AuthCard
        title="Invalid Invitation"
        description="We could not accept this invitation."
        footer={
          <a href="/admin/login" className="text-primary hover:underline">
            Go to sign in
          </a>
        }
      >
        <div
          role="alert"
          className="rounded-md border border-destructive/20 bg-destructive/10 p-4 text-13 text-destructive"
        >
          {tokenError}
        </div>
      </AuthCard>
    );
  }

  return (
    <AuthCard
      title="Accept Invitation"
      description={`Set up your BS CMS account for ${inviteEmail || 'your email'}.`}
      footer={
        <div>
          <a href="/admin/login" className="text-primary hover:underline">
            Already have an account? Sign in
          </a>
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
          <Label htmlFor="accept-name">Full name</Label>
          <Input
            id="accept-name"
            type="text"
            required
            autoFocus
            value={name}
            onChange={(e) => setName(e.target.value)}
            disabled={isSubmitting}
            placeholder="Jane Doe"
          />
        </div>

        <div className="space-y-1.5">
          <Label htmlFor="accept-password">Choose password</Label>
          <Input
            id="accept-password"
            type="password"
            autoComplete="new-password"
            required
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            disabled={isSubmitting}
            placeholder="At least 8 characters"
          />
        </div>

        <div className="space-y-1.5">
          <Label htmlFor="accept-password-confirm">Confirm password</Label>
          <Input
            id="accept-password-confirm"
            type="password"
            autoComplete="new-password"
            required
            value={passwordConfirmation}
            onChange={(e) => setPasswordConfirmation(e.target.value)}
            disabled={isSubmitting}
            placeholder="Repeat password"
          />
        </div>

        <Button
          type="submit"
          className="w-full"
          disabled={isSubmitting || !name.trim() || !password || !passwordConfirmation}
        >
          {isSubmitting ? 'Creating account...' : 'Create account & enter'}
        </Button>
      </form>
    </AuthCard>
  );
}
