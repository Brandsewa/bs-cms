import { test, expect } from '@playwright/test';
import { AxeBuilder } from '@axe-core/playwright';

const ADMIN_URL = 'http://127.0.0.1:4173/admin';

test.describe('Dashboard Home Screen (v1)', () => {
  test.beforeEach(async ({ page }) => {
    // Default authenticated session with owner/admin permissions
    await page.route('**/api/v1/auth/session', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'authenticated',
          user: {
            id: 'usr_owner',
            name: 'Anna Admin',
            email: 'admin@example.com',
            roles: ['owner'],
            permissions: ['*'],
          },
          csrf_token: 'test_csrf_token',
        }),
      });
    });
  });

  test('renders all dashboard widgets, metric cards, greeting and environment', async ({ page }) => {
    await page.goto(`${ADMIN_URL}/`);

    // Verify Greeting and Environment
    await expect(page.locator('text=Welcome back, Anna Admin')).toBeVisible();
    await expect(page.locator('text=Environment: production')).toBeVisible();

    // Verify Quick Actions
    const quickActions = page.locator('[data-testid="quick-actions-widget"]');
    await expect(quickActions).toBeVisible();
    await expect(quickActions.locator('text=New post')).toBeVisible();
    await expect(quickActions.locator('text=New page')).toBeVisible();
    await expect(quickActions.locator('text=Upload media')).toBeVisible();

    // Verify Metric Cards Row
    const metricsRow = page.locator('[data-testid="dashboard-metrics-row"]');
    await expect(metricsRow).toBeVisible();
    await expect(metricsRow.locator('text=Drafts in progress')).toBeVisible();
    await expect(metricsRow.locator('text=Scheduled for release')).toBeVisible();
    await expect(metricsRow.locator('text=Pending AI changes')).toBeVisible();

    // Verify Onboarding Checklist Card
    const onboarding = page.locator('[data-testid="onboarding-checklist-card"]');
    await expect(onboarding).toBeVisible();
    await expect(onboarding.locator('text=Create your first page')).toBeVisible();
    await expect(onboarding.locator('text=Write your first blog post')).toBeVisible();

    // Verify Recent Edits Table
    const recentEdits = page.locator('[data-testid="recent-edits-widget"]');
    await expect(recentEdits).toBeVisible();
    await expect(recentEdits.locator('text=Welcome to Brand Sewa CMS v1')).toBeVisible();

    // Verify Content Queue Widget
    const contentQueue = page.locator('[data-testid="content-queue-widget"]');
    await expect(contentQueue).toBeVisible();
    await expect(contentQueue.locator('text=Scheduled Feature Launch Announcement')).toBeVisible();
    await expect(contentQueue.locator('text=Pending AI actions')).toBeVisible();

    // Verify Site Health & System Status Widget
    const systemHealth = page.locator('[data-testid="system-health-widget"]');
    await expect(systemHealth).toBeVisible();
    await expect(systemHealth.locator('text=Content & SEO Health')).toBeVisible();
    await expect(systemHealth.locator('text=Background Cron')).toBeVisible();
    await expect(systemHealth.locator('text=Mail Delivery')).toBeVisible();
    await expect(systemHealth.locator('text=Storage Disk')).toBeVisible();
    await expect(systemHealth.locator('text=PHP Runtime')).toBeVisible();

    // Verify Shortcuts Card
    const shortcuts = page.locator('[data-testid="dashboard-shortcuts-card"]');
    await expect(shortcuts).toBeVisible();
    await expect(shortcuts.locator('text=Quick search / Jump to route')).toBeVisible();
  });

  test('onboarding checklist can be toggled and dismissed', async ({ page }) => {
    await page.goto(`${ADMIN_URL}/`);

    const onboarding = page.locator('[data-testid="onboarding-checklist-card"]');
    await expect(onboarding).toBeVisible();

    // Dismiss onboarding card
    const dismissBtn = onboarding.locator('button[aria-label="Dismiss onboarding checklist"]');
    await expect(dismissBtn).toBeVisible();
    await dismissBtn.click();

    await expect(onboarding).not.toBeVisible();
  });

  test('role-awareness: content editor sees only content-related widgets', async ({ page }) => {
    // Override session to editor role (no system/developer/AI permissions)
    await page.route('**/api/v1/auth/session', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'authenticated',
          user: {
            id: 'usr_editor',
            name: 'Edward Editor',
            email: 'editor@example.com',
            roles: ['editor'],
            permissions: ['entries:*', 'media:*', 'taxonomies:*'],
          },
          csrf_token: 'test_csrf_token',
        }),
      });
    });

    await page.goto(`${ADMIN_URL}/`);

    await expect(page.locator('text=Welcome back, Edward Editor')).toBeVisible();

    // Content widgets visible
    await expect(page.locator('[data-testid="quick-actions-widget"]')).toBeVisible();
    await expect(page.locator('[data-testid="recent-edits-widget"]')).toBeVisible();
    await expect(page.locator('[data-testid="content-queue-widget"]')).toBeVisible();

    // System and AI widgets hidden for pure content editor
    await expect(page.locator('[data-testid="system-health-widget"]')).not.toBeVisible();
    await expect(page.locator('text=Pending AI actions')).not.toBeVisible();
  });

  test('role-awareness: author cannot create pages', async ({ page }) => {
    await page.route('**/api/v1/auth/session', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'authenticated',
          user: {
            id: 'usr_author',
            name: 'Arthur Author',
            email: 'author@example.com',
            roles: ['author'],
            permissions: ['entries:posts:*'],
          },
          csrf_token: 'test_csrf_token',
        }),
      });
    });

    await page.goto(`${ADMIN_URL}/`);

    const quickActions = page.locator('[data-testid="quick-actions-widget"]');
    await expect(quickActions.locator('text=New post')).toBeVisible();
    await expect(quickActions.locator('text=New page')).not.toBeVisible();
  });

  test('axe clean in light and dark mode', async ({ page }) => {
    for (const theme of ['light', 'dark'] as const) {
      await page.goto(`${ADMIN_URL}/`);
      await page.evaluate((mode) => {
        document.documentElement.classList.toggle('dark', mode === 'dark');
      }, theme);

      const results = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
        .analyze();

      const blocking = results.violations.filter(
        (v) => v.impact === 'serious' || v.impact === 'critical'
      );
      expect(blocking, `Dashboard screen (${theme}) axe violations: ${JSON.stringify(blocking, null, 2)}`).toEqual([]);
    }
  });

  test('mobile viewport (360px) layout stability', async ({ page }) => {
    await page.setViewportSize({ width: 360, height: 640 });
    await page.goto(`${ADMIN_URL}/`);

    await expect(page.locator('text=Welcome back, Anna Admin')).toBeVisible();
    await expect(page.locator('[data-testid="dashboard-metrics-row"]')).toBeVisible();
    await expect(page.locator('[data-testid="recent-edits-widget"]')).toBeVisible();
  });
});
