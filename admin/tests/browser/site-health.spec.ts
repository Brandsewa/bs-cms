import { test, expect } from '@playwright/test';
import AxeBuilder from '@axe-core/playwright';
import fixture from '../fixtures/site-health-fixtures.json' with { type: 'json' };

const ADMIN_URL = 'http://127.0.0.1:4173/admin';

test.describe('Site Health Admin Screen', () => {
  test.beforeEach(async ({ page }) => {
    // Default authenticated owner session
    await page.route('**/api/v1/auth/session', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'authenticated',
          user: {
            id: '01M5OWNER1234567890ABCDEF',
            name: 'Alice Owner',
            email: 'owner@example.com',
            roles: [
              {
                id: '01JB0000000000000000000001',
                handle: 'owner',
                name: 'Owner',
                is_system: 1,
              },
            ],
          },
          csrf_token: 'test_csrf_token',
        }),
      });
    });

    await page.route('**/api/v1/me/permissions', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          permissions: [
            {
              subject_type: '*',
              subject_key: '*',
              action: '*',
              effect: 'allow',
            },
          ],
        }),
      });
    });

    await page.route('**/api/v1/site-health', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(fixture),
      });
    });

    await page.route('**/api/v1/site-health/run', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          ...fixture,
          scanned_at: '2026-10-21 15:00:00.000',
        }),
      });
    });
  });

  test('renders site health summary cards and issue list with fix hints', async ({ page }) => {
    await page.goto(`${ADMIN_URL}/system/health`);

    // Verify header and title
    await expect(page.locator('h1')).toContainText('Site Health');
    await expect(page.getByText('Automated audit of content')).toBeVisible();

    // Verify MetricCards
    await expect(page.getByText('Overall Status')).toBeVisible();
    await expect(page.getByText('Warning').first()).toBeVisible();
    await expect(page.getByText('Critical Errors')).toBeVisible();
    await expect(page.getByText('Warnings').first()).toBeVisible();
    await expect(page.getByText('Rules Evaluated')).toBeVisible();

    // Verify Issues list rendered from real fixture
    const issuesList = page.locator('[data-testid="health-issues-list"]');
    await expect(issuesList).toBeVisible();
    await expect(page.getByText("Duplicate title 'A Post Without Description'").first()).toBeVisible();
    await expect(page.getByText('Ensure titles are unique across all published pages and posts.').first()).toBeVisible();
    await expect(page.getByText('Recommended fix:').first()).toBeVisible();
    await expect(page.getByText('Edit entry').first()).toBeVisible();
  });

  test('run now action shows progress bar and completes scan', async ({ page }) => {
    await page.goto(`${ADMIN_URL}/system/health`);

    const runBtn = page.locator('[data-testid="run-health-scan-btn"]');
    await expect(runBtn).toBeVisible();
    await runBtn.click();

    // Verify progress bar appears
    const progressBar = page.locator('[data-testid="health-progress-bar"]');
    await expect(progressBar).toBeVisible();

    // Wait for scan completion and disappearance of progress bar
    await expect(progressBar).toBeHidden({ timeout: 5000 });
  });

  test('filters issues by severity, category, and search query', async ({ page }) => {
    await page.goto(`${ADMIN_URL}/system/health`);

    // Search filter
    const searchInput = page.getByPlaceholder('Search issues…');
    await searchInput.fill('Duplicate meta description');
    await expect(page.getByText('Duplicate meta description shared by multiple').first()).toBeVisible();
    await expect(page.getByText('seo.og.image.missing')).toBeHidden();

    // Clear search
    await searchInput.fill('');

    // Severity tab filter
    const infoTab = page.getByRole('tab', { name: /Info/i });
    await infoTab.click();
    await expect(page.getByText('geo.summary.missing').first()).toBeVisible();
    await expect(page.getByText('seo.title.duplicate')).toBeHidden();
  });

  test('honest empty state when all checks pass', async ({ page }) => {
    await page.route('**/api/v1/site-health', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          summary: { total: 0, errors: 0, warnings: 0, info: 0, status: 'healthy' },
          issues: [],
          rules_checked: 15,
          scanned_at: '2026-10-21 14:00:00.000',
        }),
      });
    });

    await page.goto(`${ADMIN_URL}/system/health`);

    await expect(page.getByText('All site health checks passed')).toBeVisible();
    await expect(
      page.getByText('No content gaps, duplicate titles, broken links, or SEO deficiencies were detected.')
    ).toBeVisible();
    await expect(page.getByRole('button', { name: 'Run scan again' })).toBeVisible();
  });

  test('redirects /settings/health to /system/health seamlessly', async ({ page }) => {
    await page.goto(`${ADMIN_URL}/settings/health`);
    await page.waitForURL('**/system/health');
    expect(page.url()).toContain('/system/health');
    await expect(page.locator('h1')).toContainText('Site Health');
  });

  test('role-awareness: editor receives 403 Forbidden state', async ({ page }) => {
    await page.route('**/api/v1/auth/session', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'authenticated',
          user: {
            id: '01M5EDITOR1234567890ABCDEF',
            name: 'Bob Editor',
            email: 'editor@example.com',
            roles: [{ id: '01JB0000000000000000000003', handle: 'editor', name: 'Editor', is_system: 1 }],
          },
          csrf_token: 'editor_csrf_token',
        }),
      });
    });

    await page.route('**/api/v1/me/permissions', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          permissions: [{ subject_type: 'collection', subject_key: '*', action: '*', effect: 'allow' }],
        }),
      });
    });

    await page.goto(`${ADMIN_URL}/system/health`);
    await expect(page.getByText('No access')).toBeVisible();
    await expect(page.getByText('You do not have permission to view this area')).toBeVisible();
  });

  test('accessibility: axe clean in light and dark mode', async ({ page }) => {
    for (const theme of ['light', 'dark'] as const) {
      await page.goto(`${ADMIN_URL}/system/health`);
      await page.waitForSelector('[data-testid="health-issues-list"]');

      await page.evaluate((mode) => {
        document.documentElement.classList.toggle('dark', mode === 'dark');
      }, theme);
      // Let colour transitions finish so axe samples settled colours, not mid-animation values.
      await page.mouse.move(0, 0);
      await page.waitForTimeout(500);

      const results = await new AxeBuilder({ page })
        .include('main')
        .withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
        .analyze();

      const blocking = results.violations.filter(
        (v) => v.impact === 'serious' || v.impact === 'critical'
      );

      expect(
        blocking,
        `Site Health screen (${theme}) axe violations: ${JSON.stringify(blocking, null, 2)}`
      ).toEqual([]);
    }
  });

  test('responsive layout: usable at 390px mobile viewport', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(`${ADMIN_URL}/system/health`);

    await expect(page.locator('h1')).toContainText('Site Health');
    await expect(page.locator('[data-testid="health-issues-list"]')).toBeVisible();
    await expect(page.locator('[data-testid="run-health-scan-btn"]')).toBeVisible();
  });
});
