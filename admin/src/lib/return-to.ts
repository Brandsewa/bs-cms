/**
 * Return-to URL sanitizer for admin auth navigation.
 *
 * Requirements:
 * - Must strictly allow only same-origin `/admin/*` or `/oauth/authorize*` paths.
 * - Rejects protocol-relative URLs (`//evil.com`), backslashes (`/\evil.com`), control characters, and external schemes (`https://...`).
 * - Default fallback is `/admin`.
 */
export function sanitizeReturnTo(returnTo: string | null | undefined): string {
  if (!returnTo || typeof returnTo !== 'string') {
    return '/admin';
  }

  const trimmed = returnTo.trim();
  if (!trimmed) {
    return '/admin';
  }

  // Reject ASCII control characters (\x00-\x1F, \x7F)
  if (/[\x00-\x1F\x7F]/.test(trimmed)) {
    return '/admin';
  }

  // Reject protocol-relative URLs, schemes, backslashes, or relative paths not starting with /
  if (
    trimmed.startsWith('//') ||
    trimmed.includes('\\') ||
    /^[a-zA-Z][a-zA-Z0-9+.-]*:/.test(trimmed) ||
    !trimmed.startsWith('/')
  ) {
    return '/admin';
  }

  // Strictly permit `/admin` (or `/admin/...`, `/admin?...`) and `/oauth/authorize` (or `/oauth/authorize?...`)
  const isAdminPath = /^\/admin(\/.*|\?.*)?$/.test(trimmed);
  const isOAuthPath = /^\/oauth\/authorize(\?.*)?$/.test(trimmed);

  if (isAdminPath || isOAuthPath) {
    return trimmed;
  }

  return '/admin';
}
