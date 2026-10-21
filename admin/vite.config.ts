import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';

// The admin is built to static files served by PHP from /admin (shared
// hosting): hashed filenames, no runtime server.
//
// base must be an ABSOLUTE '/admin/' in production builds: the PHP front
// controller answers every /admin/* path with index.html (SPA fallback), so
// relative asset URLs (./assets/…) would resolve against deep-link paths
// (/admin/a/b/c/assets/… → 404).
export default defineConfig(({ command, mode }) => {
  const isDevSim = mode === 'sim' || process.env.VITE_DEV_SIM === '1';
  const simTarget = process.env.BS_SIM_PROXY_TARGET || 'https://localhost:8481';

  return {
    base: isDevSim ? '/admin/' : command === 'build' ? '/admin/' : '/',
    plugins: [react(), tailwindcss()],
    server: isDevSim
      ? {
          port: 5173,
          proxy: {
            '/api': {
              target: simTarget,
              changeOrigin: true,
              secure: false,
            },
            '/oauth': {
              target: simTarget,
              changeOrigin: true,
              secure: false,
            },
            '/mcp': {
              target: simTarget,
              changeOrigin: true,
              secure: false,
            },
            '/.well-known': {
              target: simTarget,
              changeOrigin: true,
              secure: false,
            },
          },
        }
      : undefined,
    build: {
      outDir: 'dist',
      target: 'es2022',
      sourcemap: false,
    },
  };
});
