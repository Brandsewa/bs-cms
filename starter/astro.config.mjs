import { defineConfig } from 'astro/config';
import react from '@astrojs/react';

const siteUrl = (process.env.SITE_URL || 'https://bskickstarter.com').replace(/\/+$/, '');

// iframe allowlist — kept in lockstep with starter/public/.htaccess (defense in depth).
const FRAME_SRC =
  "'self' https://www.youtube-nocookie.com https://player.vimeo.com https://open.spotify.com https://w.soundcloud.com https://codepen.io https://platform.twitter.com https://x.com https://twitter.com";

// SHA-256 of the verbatim inline search script in src/pages/search.astro as it
// is emitted into dist/search/index.html. Astro's CSP support only hashes
// scripts it processes/bundles; `is:inline` scripts must be pinned here by
// hand. If you edit search.astro's <script is:inline> block, recompute this
// hash (test/puck-csp-audit.test.ts fails loudly when it drifts).
const SEARCH_INLINE_SCRIPT_HASH = 'sha256-AzK7I4ZL3/M/t3BW97tddRSlVaBYjkDw+WdGuK4tDck=';

export default defineConfig({
  site: siteUrl,
  trailingSlash: 'always',
  output: 'static',
  build: {
    format: 'directory'
  },
  integrations: [react()],
  security: {
    csp: {
      algorithm: 'SHA-256',
      // Extra directives beyond Astro's generated script-src/style-src.
      // Astro emits a per-page <meta http-equiv="content-security-policy">
      // containing these plus `script-src 'self' <build-computed hashes>` and
      // `style-src 'self' 'unsafe-inline'` (hashes suppressed by 'unsafe-inline',
      // which the brand CSS inline <style> blocks and style="" attributes need).
      directives: [
        "default-src 'self'",
        `frame-src ${FRAME_SRC}`,
        "img-src 'self' data: https:",
        "connect-src 'self' https://bskickstarter.com",
      ],
      scriptDirective: {
        // Hashes for Astro-emitted inline scripts (island bootstrap, processed
        // chunks) are computed automatically at build time; this list only
        // pins hand-written is:inline scripts.
        hashes: [SEARCH_INLINE_SCRIPT_HASH],
      },
      styleDirective: {
        resources: ["'self'", "'unsafe-inline'"],
      },
    },
  },
});
