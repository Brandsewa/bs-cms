import { test, expect } from '@playwright/test';

const ADMIN_URL = 'http://127.0.0.1:4173/admin';

test.describe('Command Menu & Global Hotkeys', () => {
  test.beforeEach(async ({ page }) => {
    // Session mock (owner)
    await page.route('**/api/v1/auth/session', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'authenticated',
          user: { id: 'u1', name: 'Owner Admin', email: 'owner@example.com', roles: ['owner'] },
          csrf_token: 'test_token',
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
          counts: { drafts: 2, scheduled: 0, pending_ai_changes: 1 },
          recent_edits: [],
          drafts_waiting: [],
          scheduled_items: [],
          pending_ai_changes: [
            { id: '1', actor_label: 'AI Agent', action: 'update_entry', created_at: '2026-10-21T10:00:00Z' },
          ],
          site_health: { status: 'healthy', total_issues: 0, errors: 0, warnings: 0 },
          system_status: { cron: 'healthy', mail: 'healthy', disk_usage_percent: 25, php_version: '8.3' },
          onboarding: { completed: true, dismissed: true, steps: {} },
        }),
      });
    });
  });

  test('opens command menu via Ctrl+K and button click, filters items, and displays theme options', async ({
    page,
  }) => {
    await page.goto(`${ADMIN_URL}/`);

    // Top search trigger button
    const searchButton = page.getByRole('button', { name: /search and quick actions/i }).first();
    await expect(searchButton).toBeVisible();

    // Open via button click
    await searchButton.click();
    const commandDialog = page.getByRole('dialog');
    await expect(commandDialog).toBeVisible();

    // Verify command input and groups
    const commandInput = page.getByPlaceholder('Type a command or search...');
    await expect(commandInput).toBeVisible();
    // Verify items visible initially
    await expect(page.getByText('Quick Actions', { exact: true })).toBeVisible();
    await expect(page.getByText('Light Theme')).toBeVisible();
    await expect(page.getByText('Dark Theme')).toBeVisible();

    // Filter items inside command dialog
    await commandInput.pressSequentially('Users', { delay: 50 });
    await expect(commandDialog.getByText('Users', { exact: true })).toBeVisible();
    await expect(commandDialog.getByText('Taxonomies')).not.toBeVisible();

    // Close on Escape
    await page.keyboard.press('Escape');
    await expect(commandDialog).not.toBeVisible();

    // Open via keyboard shortcut Control+K
    await page.keyboard.press('Control+k');
    await expect(page.getByRole('dialog')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(page.getByRole('dialog')).not.toBeVisible();
  });

  test('renders global banner area with pending AI changes banner', async ({ page }) => {
    await page.goto(`${ADMIN_URL}/`);
    const banner = page.getByText(/pending review and approval/i);
    await expect(banner).toBeVisible();
  });

  test('provides skip to main content link for keyboard users', async ({ page }) => {
    await page.goto(`${ADMIN_URL}/`);
    const skipLink = page.getByRole('link', { name: /skip to main content/i });
    await expect(skipLink).toHaveAttribute('href', '#main-content');
  });
});
