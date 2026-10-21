import { test, expect } from '@playwright/test';
import { AxeBuilder } from '@axe-core/playwright';

const ADMIN_URL = 'http://127.0.0.1:4173/admin';

test.describe('Dashboard Home Screen (v1)', () => {
  test.beforeEach(async ({ page }) => {
    // Default mock dashboard endpoint for test verification
    await page.route('**/api/v1/dashboard', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          greeting: { user_name: 'Anna Admin', environment: 'production' },
          counts: { drafts: 3, scheduled: 1, pending_ai_changes: 2 },
          recent_edits: [
            {
              id: '1',
              collection: 'posts',
              title: 'Getting Started with BS CMS',
              status: 'published',
              updated_at: '2026-10-19T10:00:00Z',
              updated_by: 'Anna Admin',
            },
          ],
          drafts_waiting: [
            { id: '2', collection: 'posts', title: 'Draft Post', status: 'draft', updated_at: '2026-10-19T11:00:00Z' },
          ],
          scheduled_items: [
            { id: '3', collection: 'posts', title: 'Scheduled Launch', scheduled_at: '2026-10-25T12:00:00Z' },
          ],
          pending_ai_changes: [
            { id: '4', actor_label: 'Claude (via Anna)', action: 'update_entry', created_at: '2026-10-19T12:00:00Z' },
          ],
          site_health: { status: 'healthy', total_issues: 0, errors: 0, warnings: 0 },
          system_status: { cron: 'healthy', mail: 'healthy', disk_usage_percent: 28, php_version: '8.3.12' },
          onboarding: {
            completed: false,
            dismissed: false,
            steps: {
              create_page: true,
              write_post: true,
              site_details: true,
              configure_email: false,
              connect_astro: false,
              connect_ai: false,
            },
          },
        }),
      });
    });

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

    // Guard: ensure components in packages/ui (Card and Button) receive Tailwind styling in production build
    const firstMetricCard = metricsRow.locator('> div').first();
    const cardPadding = await firstMetricCard.evaluate((el) => {
      const style = window.getComputedStyle(el);
      return parseFloat(style.paddingTop) || parseFloat(style.paddingLeft);
    });
    expect(cardPadding).toBeGreaterThan(0);

    const firstButton = quickActions.locator('a, button').first();
    const btnPadding = await firstButton.evaluate((el) => {
      const style = window.getComputedStyle(el);
      return parseFloat(style.paddingLeft) || parseFloat(style.paddingRight);
    });
    expect(btnPadding).toBeGreaterThan(0);

    // Verify Onboarding Checklist Card
    const onboarding = page.locator('[data-testid="onboarding-checklist-card"]');
    await expect(onboarding).toBeVisible();
    await expect(onboarding.locator('text=Welcome to BS CMS — Getting Started')).toBeVisible();

    // Verify Recent Edits Table
    const recentEdits = page.locator('[data-testid="recent-edits-widget"]');
    await expect(recentEdits).toBeVisible();
    await expect(recentEdits.locator('text=Getting Started with BS CMS')).toBeVisible();

    // Verify Content Queue
    const queue = page.locator('[data-testid="content-queue-widget"]');
    await expect(queue).toBeVisible();
    await expect(queue.locator('text=Drafts in progress')).toBeVisible();
    await expect(queue.locator('text=Scheduled for release')).toBeVisible();

    // Verify System & Health Widget (Owner/Admin only)
    const healthWidget = page.locator('[data-testid="system-health-widget"]');
    await expect(healthWidget).toBeVisible();
    await expect(healthWidget.locator('text=Content & SEO Health')).toBeVisible();
    await expect(healthWidget.locator('text=Background Cron')).toBeVisible();

    // Verify Shortcuts Card
    const shortcuts = page.locator('[data-testid="dashboard-shortcuts-card"]');
    await expect(shortcuts).toBeVisible();
    await expect(shortcuts.locator('text=Keyboard shortcuts')).toBeVisible();
  });

  test('onboarding checklist can be toggled and dismissed', async ({ page }) => {
    await page.goto(`${ADMIN_URL}/`);

    const onboarding = page.locator('[data-testid="onboarding-checklist-card"]');
    await expect(onboarding).toBeVisible();

    // Dismiss the card
    const dismissBtn = onboarding.locator('button[aria-label="Dismiss onboarding checklist"]');
    await dismissBtn.click();

    // Should no longer be visible
    await expect(onboarding).not.toBeVisible();
  });

  test('role-awareness: content editor sees only content-related widgets', async ({ page }) => {
    // Override session to content editor (no system/admin roles)
    await page.route('**/api/v1/auth/session', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'authenticated',
          user: {
            id: 'usr_editor',
            name: 'Ed Editor',
            email: 'editor@example.com',
            roles: ['editor'],
            permissions: ['entries:*'],
          },
          csrf_token: 'test_csrf_token',
        }),
      });
    });

    await page.goto(`${ADMIN_URL}/`);

    // Verify greeting
    await expect(page.locator('text=Welcome back, Ed Editor')).toBeVisible();

    // Quick actions: can create posts, pages, and media
    const quickActions = page.locator('[data-testid="quick-actions-widget"]');
    await expect(quickActions.locator('text=New post')).toBeVisible();
    await expect(quickActions.locator('text=New page')).toBeVisible();

    // System health widget should be hidden for pure content editors
    await expect(page.locator('[data-testid="system-health-widget"]')).not.toBeVisible();

    // AI pending changes column should be hidden from ContentQueueWidget
    const queue = page.locator('[data-testid="content-queue-widget"]');
    await expect(queue.locator('text=Pending AI changes')).not.toBeVisible();
  });

  test('role-awareness: author cannot create pages', async ({ page }) => {
    // Override session to author
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

  test('production 404 does not fabricate mock data and displays honest empty state', async ({ page }) => {
    // Override dashboard route to return 404
    await page.route('**/api/v1/dashboard', async (route) => {
      await route.fulfill({
        status: 404,
        contentType: 'application/json',
        body: JSON.stringify({
          type: 'https://bs-cms.org/errors/not-found',
          title: 'Not Found',
          status: 404,
          detail: 'Endpoint /api/v1/dashboard does not exist.',
        }),
      });
    });

    await page.goto(`${ADMIN_URL}/`);

    // Verify honest status banner
    const statusBanner = page.locator('[data-testid="dashboard-status-banner"]');
    await expect(statusBanner).toBeVisible();
    await expect(statusBanner).toHaveText('Dashboard data endpoint is not yet available.');

    // Verify metric cards show zero counts, not fabricated numbers
    const metricsRow = page.locator('[data-testid="dashboard-metrics-row"]');
    await expect(metricsRow.locator('text=Drafts in progress')).toBeVisible();
    await expect(metricsRow.locator('text=0').first()).toBeVisible();

    // Verify recent edits shows empty message rather than mock items
    const recentEdits = page.locator('[data-testid="recent-edits-widget"]');
    await expect(recentEdits.locator('text=No recent content edits found.')).toBeVisible();

    // Verify content queues show honest empty states
    await expect(page.locator('text=No drafts waiting.')).toBeVisible();
    await expect(page.locator('text=No items currently scheduled.')).toBeVisible();
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

  test('role-awareness: editor payload omits system_status and recent_activity gracefully', async ({ page }) => {
    // Editor session
    await page.route('**/api/v1/auth/session', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'authenticated',
          user: {
            id: 'usr_editor',
            name: 'Ed Editor',
            email: 'editor@example.com',
            roles: ['editor'],
            permissions: ['entries:*'],
          },
          csrf_token: 'test_csrf_token',
        }),
      });
    });

    // Real backend behavior: editor payload omits system_status and recent_activity
    await page.route('**/api/v1/dashboard', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          greeting: { user_name: 'Ed Editor', environment: 'production' },
          counts: { drafts: 1, scheduled: 0, pending_ai_changes: 0 },
          recent_edits: [
            { id: '1', collection: 'posts', title: 'Editor Post', status: 'draft', updated_at: '2026-10-20T10:00:00Z' },
          ],
          drafts_waiting: [
            { id: '1', collection: 'posts', title: 'Editor Post', status: 'draft', updated_at: '2026-10-20T10:00:00Z' },
          ],
          scheduled_items: [],
          pending_ai_changes: [],
          site_health: { status: 'healthy', total_issues: 0, errors: 0, warnings: 0 },
          onboarding: {
            completed: false,
            steps: { create_page: false, write_post: true, site_details: false, configure_email: false, connect_astro: false, connect_ai: false },
          },
        }),
      });
    });

    await page.goto(`${ADMIN_URL}/`);

    // Verify widgets render cleanly without crashing on missing system_status or recent_activity
    await expect(page.locator('text=Welcome back, Ed Editor')).toBeVisible();
    await expect(page.locator('[data-testid="system-health-widget"]')).not.toBeVisible();
    await expect(page.locator('[data-testid="recent-edits-widget"]')).toBeVisible();
    await expect(page.locator('[data-testid="recent-edits-widget"]').locator('text=Editor Post')).toBeVisible();
  });

  test('role-awareness: author payload with self-scoped content and empty AI changes', async ({ page }) => {
    // Author session
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

    // Real backend behavior: author sees self-authored items, no AI queue, no telemetry
    await page.route('**/api/v1/dashboard', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          greeting: { user_name: 'Arthur Author', environment: 'production' },
          counts: { drafts: 2, scheduled: 0, pending_ai_changes: 0 },
          recent_edits: [
            { id: '10', collection: 'posts', title: 'My Author Story', status: 'draft', updated_at: '2026-10-20T11:00:00Z' },
          ],
          drafts_waiting: [
            { id: '10', collection: 'posts', title: 'My Author Story', status: 'draft', updated_at: '2026-10-20T11:00:00Z' },
          ],
          scheduled_items: [],
          pending_ai_changes: [],
          site_health: { status: 'healthy', total_issues: 0, errors: 0, warnings: 0 },
          onboarding: {
            completed: false,
            steps: { create_page: false, write_post: true, site_details: false, configure_email: false, connect_astro: false, connect_ai: false },
          },
        }),
      });
    });

    await page.goto(`${ADMIN_URL}/`);

    await expect(page.locator('text=Welcome back, Arthur Author')).toBeVisible();
    await expect(page.locator('[data-testid="recent-edits-widget"]').locator('text=My Author Story')).toBeVisible();
    await expect(page.locator('[data-testid="system-health-widget"]')).not.toBeVisible();
  });

  test('dark theme renders neutral near-black gray and light theme renders light neutral gray', async ({ page }) => {
    const parseRgb = (str: string) => {
      const match = str.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/);
      if (!match) throw new Error(`Cannot parse color: ${str}`);
      return { r: Number(match[1]), g: Number(match[2]), b: Number(match[3]) };
    };

    // 1. Dark Mode verification
    await page.goto(`${ADMIN_URL}/`);
    await page.evaluate(() => {
      document.documentElement.classList.remove('light');
      document.documentElement.classList.add('dark');
    });

    const darkColors = await page.evaluate(() => {
      const body = window.getComputedStyle(document.body).backgroundColor;
      const navEl = document.querySelector('nav');
      const sidebar = navEl ? window.getComputedStyle(navEl).backgroundColor : body;
      const cardEl = document.querySelector('[data-testid="dashboard-metrics-row"] > div') || document.body;
      const card = window.getComputedStyle(cardEl).backgroundColor;
      return { body, sidebar, card };
    });

    for (const [target, colorStr] of Object.entries(darkColors)) {
      const rgb = parseRgb(colorStr);
      // R, G, B within 3 of each other (neutral, NOT navy hue)
      expect(Math.abs(rgb.r - rgb.g), `${target} (dark) R and G differ by more than 3: ${colorStr}`).toBeLessThanOrEqual(3);
      expect(Math.abs(rgb.g - rgb.b), `${target} (dark) G and B differ by more than 3: ${colorStr}`).toBeLessThanOrEqual(3);
      expect(Math.abs(rgb.r - rgb.b), `${target} (dark) R and B differ by more than 3: ${colorStr}`).toBeLessThanOrEqual(3);
      // Value below 40 (near-black)
      expect(rgb.r, `${target} (dark) R is too bright: ${colorStr}`).toBeLessThan(40);
      expect(rgb.g, `${target} (dark) G is too bright: ${colorStr}`).toBeLessThan(40);
      expect(rgb.b, `${target} (dark) B is too bright: ${colorStr}`).toBeLessThan(40);
    }

    // 2. Light Mode verification
    await page.evaluate(() => {
      document.documentElement.classList.remove('dark');
      document.documentElement.classList.add('light');
    });

    const lightColors = await page.evaluate(() => {
      const body = window.getComputedStyle(document.body).backgroundColor;
      const navEl = document.querySelector('nav');
      const sidebar = navEl ? window.getComputedStyle(navEl).backgroundColor : body;
      return { body, sidebar };
    });

    for (const [target, colorStr] of Object.entries(lightColors)) {
      const rgb = parseRgb(colorStr);
      // Neutral light gray/white (R, G, B within 4 of each other and above 240)
      expect(Math.abs(rgb.r - rgb.g), `${target} (light) R and G differ by more than 4: ${colorStr}`).toBeLessThanOrEqual(4);
      expect(Math.abs(rgb.g - rgb.b), `${target} (light) G and B differ by more than 4: ${colorStr}`).toBeLessThanOrEqual(4);
      expect(rgb.r, `${target} (light) R is too dark: ${colorStr}`).toBeGreaterThan(240);
    }
  });

  test('sidebar nav items render icon and label horizontally on one row', async ({ page }) => {
    await page.goto(`${ADMIN_URL}/`);
    const navLink = page.locator('nav a').first();
    await expect(navLink).toBeVisible();

    const layout = await navLink.evaluate((el) => {
      const style = window.getComputedStyle(el);
      const icon = el.querySelector('svg');
      const label = el.querySelector('span.truncate');
      const iconRect = icon ? icon.getBoundingClientRect() : null;
      const labelRect = label ? label.getBoundingClientRect() : null;
      return {
        display: style.display,
        flexDirection: style.flexDirection,
        alignItems: style.alignItems,
        iconY: iconRect ? iconRect.y : 0,
        labelY: labelRect ? labelRect.y : 0,
        iconX: iconRect ? iconRect.x : 0,
        labelX: labelRect ? labelRect.x : 0,
      };
    });

    expect(layout.display).toBe('flex');
    expect(layout.flexDirection).toBe('row');
    expect(layout.alignItems).toBe('center');
    expect(layout.labelX).toBeGreaterThan(layout.iconX);
    expect(Math.abs(layout.iconY - layout.labelY)).toBeLessThanOrEqual(6);
  });

  test('theme follows prefers-color-scheme by default', async ({ browser }) => {
    const darkContext = await browser.newContext({ colorScheme: 'dark' });
    const darkPage = await darkContext.newPage();
    await darkPage.goto(`${ADMIN_URL}/`);
    const isDark = await darkPage.evaluate(() => document.documentElement.classList.contains('dark'));
    expect(isDark).toBe(true);
    await darkContext.close();

    const lightContext = await browser.newContext({ colorScheme: 'light' });
    const lightPage = await lightContext.newPage();
    await lightPage.goto(`${ADMIN_URL}/`);
    const isLightDark = await lightPage.evaluate(() => document.documentElement.classList.contains('dark'));
    expect(isLightDark).toBe(false);
    await lightContext.close();
  });
});