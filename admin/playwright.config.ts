import { defineConfig } from '@playwright/test';
import { existsSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const adminRoot = dirname(fileURLToPath(import.meta.url));

if (!existsSync(join(adminRoot, 'dist', 'index.html'))) {
  console.error(
    'admin/dist is missing — run `npm run build -w admin` before the browser tests.'
  );
  process.exit(1);
}

export default defineConfig({
  testDir: 'tests/browser',
  timeout: 60_000,
  forbidOnly: !!process.env.CI,
  reporter: process.env.CI ? 'line' : 'list',
  projects: [{ name: 'chromium', use: { browserName: 'chromium' } }],
  webServer: [
    {
      // Static build with SPA fallback — deep-link boot test (defect 3).
      command: 'node scripts/serve-dist.mjs 4173',
      url: 'http://127.0.0.1:4173/admin/',
      reuseExistingServer: !process.env.CI,
      timeout: 15_000,
    },
    {
      // Dev server — the gallery only exists in development builds, which is
      // what the real-browser axe sweep (defect 4) has to scan.
      // Direct node invocation: on Windows the npx cmd shim double-spawns and
      // can orphan the vite child after a failed run, holding the port.
      command: 'node ../node_modules/vite/bin/vite.js --port 5199 --strictPort',
      // vite binds the IPv6 loopback on Windows; localhost resolves there first.
      url: 'http://localhost:5199/',
      reuseExistingServer: !process.env.CI,
      timeout: 30_000,
    },
  ],
});
