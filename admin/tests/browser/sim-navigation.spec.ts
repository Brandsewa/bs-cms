import { test, expect } from '@playwright/test';

test.describe('Real Simulation Admin Shell & Navigation', () => {
  const BASE_URL = process.env.PLAYWRIGHT_BASE_URL || 'https://localhost:8501';

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
});
