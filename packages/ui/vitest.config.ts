import react from '@vitejs/plugin-react';
import { defineConfig } from 'vitest/config';

export default defineConfig({
  plugins: [react()],
  test: {
    environment: 'jsdom',
    setupFiles: ['./test/setup.ts'],
    include: ['test/**/*.test.{ts,tsx}'],
    css: false,
    // Component suites drive heavy user-event interactions; generous
    // timeouts and a worker cap keep them deterministic on loaded machines.
    testTimeout: 20_000,
    hookTimeout: 20_000,
    retry: 1,
  },
});
