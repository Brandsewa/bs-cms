/**
 * Return-to URL sanitizer for admin auth navigation.
 *
 * Requirements:
 * - Must strictly allow only same-origin `/admin/*` or `/oauth/authorize*` paths.
 * - Accepts relative paths (`/admin`, `/oauth/authorize?client_id=...`) or same-origin absolute URLs
 *   (`https://<origin>/oauth/authorize?client_id=...`).
 * - Rejects cross-origin URLs, credentials in URLs (`user:pass@`), protocol-relative URLs (`//evil.com`),
 *   backslashes (`/\evil.com`), raw control characters (\x00-\x1F, \x7F), encoded control characters (%0d%0a),
 *   percent-encoded dots/slashes in the path portion (%2e%2e, %2f), and directory traversal attempts.
 * - Default fallback is `/admin`.
 */
export function sanitizeReturnTo(
  returnTo: string | null | undefined,
  baseOrigin?: string
): string {
  if (!returnTo || typeof returnTo !== 'string') {
    return '/admin';
  }

  const trimmed = returnTo.trim();
  if (!trimmed) {
    return '/admin';
  }

  // Reject raw ASCII control characters (\x00-\x1F, \x7F)
  if (/[\x00-\x1F\x7F]/.test(trimmed)) {
    return '/admin';
  }

  // Reject percent-encoded control characters (%00-%1F, %7F)
  if (/%0[0-9a-f]|%1[0-9a-f]|%7f/i.test(trimmed)) {
    return '/admin';
  }

  // Reject backslashes or protocol-relative paths
  if (trimmed.includes('\\') || trimmed.startsWith('//')) {
    return '/admin';
  }

  // Determine current origin
  const currentOrigin =
    baseOrigin ||
    (typeof window !== 'undefined' && window.location?.origin
      ? window.location.origin
      : 'http://localhost');

  let url: URL;
  try {
    url = new URL(trimmed, currentOrigin);
  } catch {
    return '/admin';
  }

  // Origin must match exactly (same-origin only)
  if (url.origin !== currentOrigin) {
    return '/admin';
  }

  // Reject URLs containing credentials (user:pass@)
  if (url.username || url.password) {
    return '/admin';
  }

  // Extract path portion before query or hash from input to check for encoded traversal tokens
  const pathPart = trimmed.split(/[?#]/)[0] ?? '';
  if (/%2e|%2f/i.test(pathPart)) {
    return '/admin';
  }

  // Also verify url.pathname has no remaining %2e or %2f
  if (/%2e|%2f/i.test(url.pathname)) {
    return '/admin';
  }

  const pathname = url.pathname;
  const isAdminPath = pathname === '/admin' || pathname.startsWith('/admin/');
  const isOAuthPath = pathname === '/oauth/authorize' || pathname.startsWith('/oauth/authorize/');

  if (!isAdminPath && !isOAuthPath) {
    return '/admin';
  }

  return pathname + url.search;
}
