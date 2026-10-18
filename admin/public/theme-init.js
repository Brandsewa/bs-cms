/**
 * BS CMS theme bootstrap.
 *
 * Runs synchronously in <head> before first paint so the correct theme class
 * is on <html> before any styles resolve — no flash of the wrong theme
 * (docs/02-ui-spec.md 2). Kept as an external file because the admin CSP is
 * `script-src 'self'` (no inline scripts). Must stay dependency-free,
 * ES5-compatible browser code.
 */
(function () {
  'use strict';
  var preference = 'system';
  try {
    var stored = window.localStorage.getItem('bs-theme');
    if (stored === 'light' || stored === 'dark' || stored === 'system') {
      preference = stored;
    }
  } catch (e) {
    /* localStorage unavailable (privacy mode); fall back to system. */
  }
  var dark =
    preference === 'dark' ||
    (preference === 'system' &&
      window.matchMedia('(prefers-color-scheme: dark)').matches);
  var root = document.documentElement;
  root.classList.toggle('dark', dark);
  root.style.colorScheme = dark ? 'dark' : 'light';
})();
