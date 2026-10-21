import { test, expect } from '@playwright/test';

/**
 * Real Simulation Admin Shell & Navigation tests.
 *
 * Requirements:
 * - Requires a running local simulation (e.g. `dev\\sim.ps1 up dev 8081 mariadb11`).
 * - Gated on the `BS_SIM_URL` environment variable: automatically skipped in CI or environments
 *   without an active simulation.
 * - To run locally against the simulation:
 *     $env:BS_SIM_URL = 'https://localhost:8481'  (or 8501 for instance s3)
 *     npm run test:browser -w admin -- tests/browser/sim-navigation.spec.ts
 */
const SIM_URL = process.env.BS_SIM_URL;

test.describe('Real Simulation Admin Shell & Navigation', () => {
  test.skip(!SIM_URL, 'Skipping simulation navigation tests: BS_SIM_URL is not set.');

  const BASE_URL = SIM_URL || 'https://localhost:8501';

  test.use({ ignoreHTTPSErrors: true });

  const ROLES_NAV_EXPECTATIONS: Record<string, { expected: string[]; forbidden: string[] }> = {
    owner: {
      expected: ['Entries', 'Taxonomies', 'Media', 'Navigation', 'AI Studio', 'Users', 'Developer', 'Settings'],
      forbidden: [],
    },
    admin: {
      expected: ['Entries', 'Taxonomies', 'Media', 'Navigation', 'AI Studio', 'Users', 'Developer', 'Settings'],
      forbidden: [],
    },
    editor: {
      expected: ['Entries', 'Taxonomies', 'Media', 'Navigation'],
      forbidden: ['Users', 'Developer', 'Settings'],
    },
    author: {
      expected: ['Entries', 'Taxonomies', 'Media'],
      forbidden: ['Navigation', 'AI Studio', 'Users', 'Developer', 'Settings'],
    },
    viewer: {
      expected: ['Entries', 'Taxonomies', 'Media'],
      forbidden: ['Navigation', 'AI Studio', 'Users', 'Developer', 'Settings'],
    },
  };

  for (const [role, { expected, forbidden }] of Object.entries(ROLES_NAV_EXPECTATIONS)) {
    test(`renders correct sidebar nav items and navigates cleanly for role: ${role}`, async ({ page }) => {
      // 1. Login
      await page.goto(`${BASE_URL}/admin/login?return_to=${encodeURIComponent('/admin/')}`);
      await page.fill('input[type="email"]', `${role}@example.com`);
      await page.fill('input[type="password"]', 'TestUser2026!Secure');

      await Promise.all([
        page.waitForNavigation({ timeout: 15000 }),
        page.click('button[type="submit"]'),
      ]);

      await page.waitForURL('**/admin/**', { timeout: 10000 });

      // 2. Assert sidebar nav element (getByRole navigation with name Navigation)
      const sidebarNav = page.getByRole('navigation', { name: 'Navigation' });
      await expect(sidebarNav).toBeVisible();

      const itemLabels = await sidebarNav.locator('ul li a span.truncate').allTextContents();
      const cleanLabels = itemLabels.map((l) => l.trim());

      for (const item of expected) {
        expect(cleanLabels).toContain(item);
      }
      for (const item of forbidden) {
        expect(cleanLabels).not.toContain(item);
      }

      // 3. Click every visible sidebar link and verify route renders without blank content or crash
      const visibleLinks = await sidebarNav.locator('ul li a').evaluateAll((links) =>
        links.map((a) => (a as HTMLAnchorElement).href)
      );

      for (const link of visibleLinks) {
        await page.goto(link);
        // Verify no fatal React error boundaries triggered
        const errorBoundary = page.locator('text=Something went wrong');
        await expect(errorBoundary).not.toBeVisible();

        // Main content must render non-empty
        const mainContent = page.locator('main');
        await expect(mainContent).toBeVisible();
        const text = await mainContent.innerText();
        expect(text.trim().length).toBeGreaterThan(0);
      }
    });
  }

  test('collection list entries page renders real entries from simulation database', async ({ page }) => {
    // 1. Login as owner
    await page.goto(`${BASE_URL}/admin/login?return_to=${encodeURIComponent('/admin/entries')}`);
    await page.fill('input[type="email"]', 'owner@example.com');
    await page.fill('input[type="password"]', 'TestUser2026!Secure');
    await Promise.all([
      page.waitForNavigation({ timeout: 15000 }),
      page.click('button[type="submit"]'),
    ]);

    await page.waitForURL('**/admin/entries', { timeout: 10000 });

    // 2. Verify collection title and DataTable rendered
    await expect(page.getByRole('heading', { name: 'Posts' })).toBeVisible();
    const table = page.getByRole('table', { name: 'Posts table' });
    await expect(table).toBeVisible();

    // 3. Verify columns
    await expect(table.getByRole('columnheader', { name: /Title/i })).toBeVisible();
    await expect(table.getByRole('columnheader', { name: /Status/i })).toBeVisible();

    // 4. Verify live data row exists (e.g. Bulk Post 11 or 10 created in sim)
    await expect(page.getByText('Bulk Post 11', { exact: true })).toBeVisible();

    // 5. Switch to Pages collection
    const pagesSwitcher = page.getByRole('button', { name: 'Pages' });
    await expect(pagesSwitcher).toBeVisible();
    await pagesSwitcher.click();

    await page.waitForURL('**/admin/entries/pages');
    await expect(page.getByRole('heading', { name: 'Pages' })).toBeVisible();
    // Pages is currently empty in sim DB, so empty state is displayed honestly
    await expect(page.getByText('No pages found')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Create Page' })).toBeVisible();
  });
});
