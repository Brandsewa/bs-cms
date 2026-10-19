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
export default defineConfig(({ command }) => ({
  base: command === 'build' ? '/admin/' : '/',
  plugins: [react(), tailwindcss()],
  build: {
    outDir: 'dist',
    target: 'es2022',
    sourcemap: false,
  },
}));
