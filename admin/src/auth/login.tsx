import React, { useState } from 'react';
import { Button, Input, Label } from '@bs/ui';
import { apiClient } from '../lib/api';
import { sanitizeReturnTo } from '../lib/return-to';
import { AuthCard } from './auth-card';
import { TwoFactorChallenge } from './two-factor-challenge';

export function LoginRoute() {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [rememberMe, setRememberMe] = useState(false);
  const [isLoading, setIsLoading] = useState(false);
  const [errorMessage, setErrorMessage] = useState<string | null>(null);
  const [isLocked, setIsLocked] = useState(false);

  // 2FA state
  const [challengeToken, setChallengeToken] = useState<string | null>(null);

  const searchParams = new URLSearchParams(typeof window !== 'undefined' ? window.location.search : '');
  const returnTo = sanitizeReturnTo(searchParams.get('return_to'));

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setIsLoading(true);
    setErrorMessage(null);
    setIsLocked(false);

    try {
      const res = await apiClient.request<{
        status: 'authenticated' | 'requires_2fa';
        challenge_token?: string;
        user?: { id: string; email: string; name: string; role: string };
      }>('POST', '/auth/login', {
        body: {
          email,
          password,
          remember_me: rememberMe,
        },
      });

      if (!res.ok) {
        if (res.status === 423) {
          setIsLocked(true);
          setErrorMessage(
            res.problem.detail ||
              'Account is temporarily locked due to too many failed attempts. Please try again later or contact your administrator.'
          );
        } else {
          // Keep generic message to prevent username/email enumeration
          setErrorMessage('Invalid credentials. Please check your email and password.');
        }
        return;
      }

      if (res.data.status === 'requires_2fa' && res.data.challenge_token) {
        setChallengeToken(res.data.challenge_token);
        return;
      }

      // Success! Fetch session for CSRF token bootstrap then redirect
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

  if (challengeToken) {
    return (
      <TwoFactorChallenge
        challengeToken={challengeToken}
        returnTo={returnTo}
        onCancel={() => setChallengeToken(null)}
      />
    );
  }

  return (
    <AuthCard
      title="Sign in to BS CMS"
      description="Enter your email and password to access the admin dashboard"
      footer={
        <div className="space-y-2">
          <div>
            <a href="/admin/forgot-password" className="text-primary hover:underline">
              Forgot your password?
            </a>
          </div>
        </div>
      }
    >
      <form onSubmit={handleSubmit} className="space-y-4" noValidate>
        {errorMessage && (
          <div
            role="alert"
            className={`rounded-md p-3 text-13 ${
              isLocked
                ? 'border border-destructive/30 bg-destructive/10 text-destructive'
                : 'border border-destructive/20 bg-destructive/10 text-destructive'
            }`}
          >
            {errorMessage}
          </div>
        )}

        <div className="space-y-1.5">
          <Label htmlFor="login-email">Email</Label>
          <Input
            id="login-email"
            type="email"
            autoComplete="email"
            required
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            disabled={isLoading || isLocked}
            placeholder="admin@example.com"
          />
        </div>

        <div className="space-y-1.5">
          <Label htmlFor="login-password">Password</Label>
          <Input
            id="login-password"
            type="password"
            autoComplete="current-password"
            required
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            disabled={isLoading || isLocked}
          />
        </div>

        <div className="flex items-center gap-2">
          <input
            id="remember-me"
            type="checkbox"
            className="h-4 w-4 rounded border-border text-primary focus:ring-primary"
            checked={rememberMe}
            onChange={(e) => setRememberMe(e.target.checked)}
            disabled={isLoading || isLocked}
          />
          <Label htmlFor="remember-me" className="text-13 font-normal cursor-pointer">
            Remember me
          </Label>
        </div>

        <Button type="submit" className="w-full" disabled={isLoading || isLocked || !email || !password}>
          {isLoading ? 'Signing in...' : 'Sign in'}
        </Button>
      </form>
    </AuthCard>
  );
}
