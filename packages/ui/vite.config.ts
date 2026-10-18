import { fileURLToPath } from 'node:url';
import { resolve } from 'node:path';
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';

const root = fileURLToPath(new URL('.', import.meta.url));

// Library-mode build. It is a CI verification artifact (ESM, tree-shakable,
// every dependency externalised, no runtime CSS-in-JS); inside the monorepo
// @bs/ui is consumed directly from source through the package exports.
export default defineConfig({
  plugins: [react()],
  build: {
    lib: {
      entry: resolve(root, 'src/index.ts'),
      formats: ['es'],
      fileName: 'index',
    },
    rollupOptions: {
      external: [
        /^react($|\/)/,
        /^react-dom($|\/)/,
        /^@radix-ui\//,
        /^lucide-react$/,
        /^cmdk$/,
        /^sonner$/,
        /^react-day-picker$/,
        /^date-fns($|\/)/,
        /^class-variance-authority$/,
        /^clsx$/,
        /^tailwind-merge$/,
      ],
    },
    outDir: 'dist',
    emptyOutDir: true,
  },
});
