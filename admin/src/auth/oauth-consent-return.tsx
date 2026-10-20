import { useEffect, useState } from 'react';
import { sanitizeReturnTo } from '../lib/return-to';

/**
 * Return handler when backend OAuth authorization flow redirects back into the admin or consent flow.
 */
export function OAuthConsentReturnRoute() {
  const [targetUrl, setTargetUrl] = useState<string>('/admin');

  useEffect(() => {
    const searchParams = new URLSearchParams(window.location.search);
    const returnTo = searchParams.get('return_to');
    const safeUrl = sanitizeReturnTo(returnTo);
    setTargetUrl(safeUrl);

    // Automatically navigate back
    window.location.assign(safeUrl);
  }, []);

  return (
    <div className="flex min-h-screen items-center justify-center bg-background p-6">
      <div className="w-full max-w-sm rounded-lg border border-border bg-card p-6 text-center shadow-sm">
        <h1 className="text-lg font-semibold text-foreground">Redirecting...</h1>
        <p className="mt-2 text-13 text-muted-foreground">
          Returning to authorization request...
        </p>
        <div className="mt-4">
          <a href={targetUrl} className="text-13 text-primary hover:underline">
            Click here if you are not redirected automatically
          </a>
        </div>
      </div>
    </div>
  );
}
