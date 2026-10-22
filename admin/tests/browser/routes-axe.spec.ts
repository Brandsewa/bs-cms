import { test, expect } from '@playwright/test';
import { suppressWelcome } from './helpers';
import { AxeBuilder } from '@axe-core/playwright';

/**
 * CI Accessibility Acceptance Suite:
 * Iterates through every routable admin screen in both light and dark themes
 * and verifies that no serious or critical accessibility violations exist.
 */

const ADMIN_URL = 'http://127.0.0.1:4173/admin';

// Representative routes mapped from route-tree.tsx
const ADMIN_ROUTES = [
  { path: '/', name: 'Dashboard' },
  { path: '/entries', name: 'Collection List (Entries)' },
  { path: '/users', name: 'Users List' },
  { path: '/users/roles', name: 'Roles Matrix' },
  { path: '/settings/general', name: 'General Settings' },
  { path: '/settings/email', name: 'Email Settings' },
  { path: '/403', name: 'Forbidden State' },
  { path: '/not-found-preview', name: '404 Not Found' },
];

const THEMES = ['light', 'dark'] as const;

test.describe('CI Route Accessibility Suite (Axe)', () => {
  test.beforeEach(async ({ page }) => {
    await suppressWelcome(page);
    // Session mock (owner with full permissions)
    await page.route('**/api/v1/auth/session', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'authenticated',
          user: { id: 'u1', name: 'Owner Admin', email: 'owner@example.com', roles: ['owner'] },
          csrf_token: 'csrf_test_token',
        }),
      });
    });

    // Dashboard mock
    await page.route('**/api/v1/dashboard', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          greeting: { user_name: 'Owner Admin', environment: 'production' },
          counts: { drafts: 2, scheduled: 0, pending_ai_changes: 0 },
          recent_edits: [],
          drafts_waiting: [],
          scheduled_items: [],
          pending_ai_changes: [],
          site_health: { status: 'healthy', total_issues: 0, errors: 0, warnings: 0 },
          system_status: { cron: 'healthy', mail: 'healthy', disk_usage_percent: 25, php_version: '8.3' },
          onboarding: { completed: true, dismissed: true, steps: {} },
        }),
      });
    });

    // AI status mock
    await page.route('**/api/v1/ai/status', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          ai_disabled: false,
          active_grants_count: 1,
          active_ai_tokens_count: 1,
          pending_changes_count: 0,
        }),
      });
    });

    // Frontend status mock
    await page.route('**/api/v1/frontend/status', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          connected: true,
          target_type: 'github_actions',
          last_deployment: { status: 'succeeded' },
          pending_changes_count: 0,
          site_url: 'http://localhost',
        }),
      });
    });

    // Users and roles mocks
    await page.route('**/api/v1/users*', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          users: [
            {
              id: 'u1',
              email: 'owner@example.com',
              name: 'Owner Admin',
              status: 'active',
              role_handle: 'owner',
              created_at: '2026-10-19T00:00:00Z',
            },
          ],
          total: 1,
        }),
      });
    });

    await page.route('**/api/v1/roles*', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          roles: [
            { id: 'r1', handle: 'owner', name: 'Owner', is_system: true },
            { id: 'r2', handle: 'admin', name: 'Admin', is_system: true },
          ],
        }),
      });
    });

    // Settings mock
    await page.route('**/api/v1/settings/general', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          site_name: 'Test CMS',
          tagline: 'Modern Content Platform',
          canonical_url: 'https://example.com',
          environment: 'production',
        }),
      });
    });

    await page.route('**/api/v1/settings/email', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          provider: 'smtp',
          smtp_host: 'smtp.example.com',
          smtp_port: 587,
          from_address: 'admin@example.com',
          from_name: 'Test Admin',
        }),
      });
    });

    // Collections mock
    await page.route('**/api/v1/collections/posts/entries*', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          data: [
            {
              id: 'e1',
              title: 'Getting Started',
              status: 'published',
              updated_at: '2026-10-21T10:00:00Z',
              created_at: '2026-10-20T10:00:00Z',
            },
          ],
          pagination: { total: 1, page: 1, pageSize: 20 },
        }),
      });
    });
  });

  for (const theme of THEMES) {
    for (const route of ADMIN_ROUTES) {
      test(`route ${route.name} (${theme}) has no serious/critical axe violations`, async ({
        page,
      }) => {
        await page.addInitScript((th) => {
          window.localStorage.setItem('bs-theme', th);
        }, theme);

        await page.goto(`${ADMIN_URL}${route.path}`);
        await page.waitForLoadState('networkidle');

        // Apply dark class synchronously if needed
        await page.evaluate((mode) => {
          document.documentElement.classList.toggle('dark', mode === 'dark');
        }, theme);

        const results = await new AxeBuilder({ page })
          .withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
          .analyze();

        const blocking = results.violations.filter(
          (v) => v.impact === 'serious' || v.impact === 'critical'
        );

        const report = blocking
          .map(
            (v) =>
              `${v.id} (${v.impact}): ${v.help} — ${v.nodes
                .map((node) => node.target.join(' '))
                .join(', ')}`
          )
          .join('\n');

        expect(report, report || 'blocking violations').toBe('');
      });
    }
  }
});
