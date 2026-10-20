import { defineConfig } from 'vitest/config';

export default defineConfig({
  test: {
    pool: 'threads',
    poolOptions: {
      threads: {
        singleThread: true,
      },
      forks: {
        singleFork: true,
      },
    },
    testTimeout: 120000,
    hookTimeout: 120000,
    teardownTimeout: 120000,
  },
});
