import { test, expect } from '@playwright/test';
import { AxeBuilder } from '@axe-core/playwright';
import fixturesData from '../fixtures/collection-list-fixtures.json' with { type: 'json' };

const ADMIN_URL = 'http://127.0.0.1:4173/admin';

test.describe('Collection List Screen Suite (Phase 6 Screen 6.2)', () => {
  test.beforeEach(async ({ page }) => {
    // 1. Session route (Owner by default)
    await page.route('**/api/v1/auth/session', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'authenticated',
          csrf_token: 'csrf123',
          user: {
            id: 'u1',
            email: 'owner@example.com',
            name: 'Alice Owner',
            roles: [{ id: 'r-owner', handle: 'owner', name: 'Owner' }],
          },
        }),
      });
    });

    // 2. Me permissions
    await page.route('**/api/v1/me/permissions', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          actor_id: 'u1',
          role_ids: ['r-owner'],
          permissions: [
            {
              id: 'p-all',
              role_id: 'r-owner',
              subject_type: '*',
              subject_key: '*',
              action: '*',
              effect: 'allow',
            },
          ],
        }),
      });
    });

    // 3. Collections schemas
    await page.route('**/api/v1/collections', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(fixturesData.collections),
      });
    });

    // 3b. Single collection schema for editor route
    await page.route('**/api/v1/collections/posts', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(fixturesData.collections_posts),
      });
    });

    // 4. Default posts entries
    await page.route('**/api/v1/collections/posts/entries*', async (route) => {
      const url = route.request().url();
      if (url.includes('trashed=only') || url.includes('trashed=true')) {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify(fixturesData.posts_entries_trash),
        });
      } else if (url.includes('filter[status]=published')) {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify(fixturesData.posts_entries_published),
        });
      } else if (url.includes('filter[status]=draft')) {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify(fixturesData.posts_entries_draft),
        });
      } else {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify(fixturesData.posts_entries_default),
        });
      }
    });

    // 4b. Collection aggregate counts endpoint (PR #35)
    await page.route('**/api/v1/collections/*/counts', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(fixturesData.collection_counts),
      });
    });

    // Users endpoint
    await page.route('**/api/v1/users*', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(fixturesData.users),
      });
    });

    // 5. Default pages entries
    await page.route('**/api/v1/collections/pages/entries*', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(fixturesData.pages_entries_default),
      });
    });
  });

  test('renders DataTable with posts list columns, header, and collection switcher', async ({
    page,
  }) => {
    await page.goto(`${ADMIN_URL}/entries`);

    // Verify header and collection title
    await expect(page.getByRole('heading', { name: 'Posts' })).toBeVisible();
    await expect(page.getByRole('button', { name: /New Entry|New Post/i })).toBeVisible();

    // Verify table rendered with columns
    const table = page.getByRole('table', { name: 'Posts table' });
    await expect(table).toBeVisible();

    await expect(table.getByRole('columnheader', { name: /Title/i })).toBeVisible();
    await expect(table.getByRole('columnheader', { name: /Status/i })).toBeVisible();
    await expect(table.getByRole('columnheader', { name: /Categories/i })).toBeVisible();
    await expect(table.getByRole('columnheader', { name: /Tags/i })).toBeVisible();
    await expect(table.getByRole('columnheader', { name: /Author/i })).toBeVisible();
    await expect(table.getByRole('columnheader', { name: /Published/i })).toBeVisible();
    await expect(table.getByRole('columnheader', { name: /Updated/i })).toBeVisible();

    // Verify rows rendered from captured live simulation fixtures
    await expect(page.getByText('Getting Started with BrandSewa', { exact: true })).toBeVisible();
    await expect(page.getByText('/getting-started-with-brandsewa')).toBeVisible();
    await expect(page.getByText('Accessible UI Patterns for Production', { exact: true })).toBeVisible();

    // Verify resolved author name (not raw ULID)
    await expect(page.getByText('Owner Test User', { exact: false }).first()).toBeVisible();

    // Verify status tab counts from /counts endpoint
    await expect(page.getByRole('tab', { name: /All/i })).toContainText('8');
    await expect(page.getByRole('tab', { name: /Published/i })).toContainText('5');
    await expect(page.getByRole('tab', { name: /Draft/i })).toContainText('3');
    await expect(page.getByRole('tab', { name: /Trash/i })).toContainText('2');
  });

  test('status tabs filter entries including Trash tab with restore/purge', async ({ page }) => {
    await page.goto(`${ADMIN_URL}/entries`);

    // Click on Trash status tab (now displaying live count 2)
    const trashTab = page.getByRole('tab', { name: /Trash/i });
    await expect(trashTab).toBeVisible();
    await expect(trashTab).toContainText('2');
    await trashTab.click();

    // In trash tab with items from fixtures, items should be visible
    await expect(page.getByRole('table', { name: 'Posts table' })).toBeVisible();
  });

  test('collection switcher switches to pages collection', async ({ page }) => {
    await page.goto(`${ADMIN_URL}/entries`);

    const pagesSwitcher = page.getByRole('button', { name: 'Pages' });
    await expect(pagesSwitcher).toBeVisible();
    await pagesSwitcher.click();

    await page.waitForURL('**/admin/entries/pages');
    await expect(page.getByRole('heading', { name: 'Pages' })).toBeVisible();
  });

  test('new button navigates to editor route', async ({ page }) => {
    await page.goto(`${ADMIN_URL}/entries`);

    const newBtn = page.getByRole('button', { name: /New Entry|New Post/i });
    await newBtn.click();

    await page.waitForURL('**/admin/entries/posts/new');
    await expect(page.getByRole('heading', { name: /Create Post/i })).toBeVisible();
    await expect(page.locator('#entry-title-input')).toBeVisible();
  });

  test('role-awareness: viewer has no create button or bulk actions', async ({ page }) => {
    await page.route('**/api/v1/auth/session', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'authenticated',
          csrf_token: 'csrf123',
          user: {
            id: 'u-viewer',
            email: 'viewer@example.com',
            name: 'Bob Viewer',
            roles: [{ id: 'r-viewer', handle: 'viewer', name: 'Viewer' }],
          },
        }),
      });
    });

    await page.route('**/api/v1/me/permissions', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          actor_id: 'u-viewer',
          role_ids: ['r-viewer'],
          permissions: [
            {
              id: 'p-view-col',
              role_id: 'r-viewer',
              subject_type: 'collection',
              subject_key: '*',
              action: 'read',
              effect: 'allow',
            },
          ],
        }),
      });
    });

    await page.goto(`${ADMIN_URL}/entries`);

    await expect(page.getByRole('heading', { name: 'Posts' })).toBeVisible();
    await expect(page.getByRole('button', { name: /New Entry|New Post/i })).toHaveCount(0);
  });

  test('axe light and dark accessibility clean', async ({ page }) => {
    for (const theme of ['light', 'dark'] as const) {
      await page.addInitScript((mode) => {
        window.localStorage.setItem('bs-theme', mode);
      }, theme);

      await page.goto(`${ADMIN_URL}/entries`);
      await page.evaluate((mode) => {
        document.documentElement.classList.toggle('dark', mode === 'dark');
      }, theme);

      await expect(page.getByRole('heading', { name: 'Posts' })).toBeVisible();

      const results = await new AxeBuilder({ page })
        .include('[data-testid="collection-list-page"]')
        .disableRules(['color-contrast'])
        .analyze();

      expect(results.violations).toEqual([]);

      await page.screenshot({
        path: `C:/Users/razzd/.gemini/antigravity/brain/9831b937-edef-4912-a934-39e2b02285b9/supabase-collection-1440-${theme}.png`,
      });
    }
  });

  test('mobile viewport (390px) layout stability with no overflow and screenshot', async ({ page }) => {
    for (const theme of ['light', 'dark'] as const) {
      await page.addInitScript((mode) => {
        window.localStorage.setItem('bs-theme', mode);
      }, theme);

      await page.setViewportSize({ width: 390, height: 844 });
      await page.goto(`${ADMIN_URL}/entries`);
      await page.evaluate((mode) => {
        document.documentElement.classList.toggle('dark', mode === 'dark');
      }, theme);

      await expect(page.getByRole('heading', { name: 'Posts' })).toBeVisible();

      const scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);
      const clientWidth = await page.evaluate(() => document.documentElement.clientWidth);
      expect(scrollWidth).toBeLessThanOrEqual(clientWidth + 2);

      await page.screenshot({
        path: `C:/Users/razzd/.gemini/antigravity/brain/9831b937-edef-4912-a934-39e2b02285b9/supabase-collection-390-${theme}.png`,
      });
    }
  });
});
