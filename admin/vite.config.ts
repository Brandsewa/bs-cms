import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';

// The admin is built to static files served by PHP from /admin (shared
// hosting): hashed filenames, no runtime server.
//
// base must be an ABSOLUTE '/admin/' in production builds: the PHP front
// controller answers every /admin/* path with index.html (SPA fallback), so
// relative asset URLs (./assets/…) would resolve against deep-link paths
// (/admin/a/b/c/assets/… → 404). Dev runs at '/'.
export default defineConfig(({ command, mode }) => {
  const isDevSim = mode === 'sim' || process.env.VITE_DEV_SIM === '1';
  const simTarget = process.env.BS_SIM_PROXY_TARGET || 'https://localhost:8481';

  return {
    base: isDevSim ? '/admin/' : command === 'build' ? '/admin/' : '/',
    plugins: [react(), tailwindcss()],
    resolve: {
      dedupe: ['react', 'react-dom'],
    },
    server: isDevSim
      ? {
          port: 5173,
          proxy: (() => {
            // The backend's CSRF middleware rejects mutations whose Origin
            // doesn't match the request host (defense-in-depth). With
            // changeOrigin the Host becomes the sim's, so the browser's
            // http://localhost:5173 Origin would 403 every POST/PATCH —
            // rewrite it to the target origin.
            const proxyOptions = {
              target: simTarget,
              changeOrigin: true,
              secure: false,
              headers: { origin: simTarget },
            };
            return {
              '/api': proxyOptions,
              '/oauth': proxyOptions,
              '/mcp': proxyOptions,
              '/.well-known': proxyOptions,
              // The static site's draft-preview page (astro preview server,
              // default :8581) — same-origin so the preview island's API
              // fetch stays CSP/CORS-clean. /_astro covers the page's assets.
              '/preview': {
                target: process.env.BS_STATIC_PREVIEW_TARGET || 'http://localhost:8581',
                changeOrigin: false,
                secure: false,
              },
              '/_astro': {
                target: process.env.BS_STATIC_PREVIEW_TARGET || 'http://localhost:8581',
                changeOrigin: false,
                secure: false,
              },
            };
          })(),
        }
      : undefined,
    build: {
      outDir: 'dist',
      target: 'es2022',
      sourcemap: false,
    },
  };
});
