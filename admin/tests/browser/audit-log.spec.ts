import { test, expect } from '@playwright/test';
import { AxeBuilder } from '@axe-core/playwright';
import auditFixture from '../fixtures/audit-fixtures.json' with { type: 'json' };

const ADMIN_URL = 'http://127.0.0.1:4173/admin';

test.describe('Audit Log Admin Screen', () => {
  test.beforeEach(async ({ page }) => {
    await page.setViewportSize({ width: 1440, height: 900 });

    // Session: Owner
    await page.route('**/api/v1/auth/session', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'authenticated',
          user: {
            id: '01M5EDS1E6S53MZAREBK0CVM51',
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

    // Permissions: Owner
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

    // Audit logs endpoint
    await page.route('**/api/v1/audit**', async (route) => {
      if (route.request().method() === 'GET') {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify(auditFixture),
        });
      } else {
        await route.fallback();
      }
    });
  });

  test('renders DataTable with real audit log data and correct column headers', async ({ page }) => {
    await page.goto(`${ADMIN_URL}/system/audit`);

    // Verify page title and header
    await expect(page.locator('h1')).toContainText('Audit Log');
    await expect(page.getByText('Immutable, append-only record')).toBeVisible();

    // Verify DataTable rendered with items
    await expect(page.getByRole('table')).toBeVisible();
    await expect(page.getByText('auth.login').first()).toBeVisible();
    await expect(page.getByText('entry.trash').first()).toBeVisible();

    // Check specific entries from real fixture
    await expect(page.getByText('User signed in').first()).toBeVisible();
    await expect(page.getByText('Trashed entry 01M5EDSK9ZMT5ZVXWF6XS25H0B in posts')).toBeVisible();
  });

  test('opens audit row detail sheet with security context and diff viewer', async ({ page }) => {
    await page.goto(`${ADMIN_URL}/system/audit`);

    // Click on a row with a diff (e.g. Trashed entry 01M5EDSK9ZMT5ZVXWF6XS25H0B)
    const targetRow = page.locator('tr:has-text("Trashed entry 01M5EDSK9ZMT5ZVXWF6XS25H0B")');
    await expect(targetRow).toBeVisible();
    await targetRow.click();

    // Verify AuditDetailSheet is open
    const sheet = page.locator('[data-testid="audit-detail-sheet"]');
    await expect(sheet).toBeVisible();

    // Check Request ID and correlation fields
    await expect(sheet.getByText('Security Context & Correlation')).toBeVisible();
    await expect(sheet.getByText('01M5EDSM4GXFW3HNKTVF7HT9ZQ')).toBeVisible();
    await expect(sheet.getByText('172.24.0.6')).toBeVisible();
    await expect(sheet.getByText('Python-urllib/3.14')).toBeVisible();

    // Verify Diff Viewer is rendered with field changes
    const diffViewer = sheet.locator('[data-testid="audit-diff-viewer"]');
    await expect(diffViewer).toBeVisible();
    await expect(diffViewer.getByText('Visual Diff')).toBeVisible();
    await expect(diffViewer.getByText('owner-pub-trash')).toBeVisible();

    // Switch to Raw JSON view
    await diffViewer.getByRole('button', { name: 'Raw JSON' }).click();
    await expect(sheet.locator('pre')).toContainText('"owner-pub-trash"');
  });

  test('redirects /settings/audit to /system/audit seamlessly', async ({ page }) => {
    await page.goto(`${ADMIN_URL}/settings/audit`);
    await page.waitForURL('**/system/audit');
    expect(page.url()).toContain('/system/audit');
    await expect(page.locator('h1')).toContainText('Audit Log');
  });

  test('role-awareness: editor role receives 403 Forbidden state', async ({ page }) => {
    // Override session to editor role (no audit permission)
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
            roles: [
              {
                id: '01JB0000000000000000000003',
                handle: 'editor',
                name: 'Editor',
                is_system: 1,
              },
            ],
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
          permissions: [
            {
              subject_type: 'collection',
              subject_key: '*',
              action: '*',
              effect: 'allow',
            },
          ],
        }),
      });
    });

    await page.goto(`${ADMIN_URL}/system/audit`);

    // Verify 403 Forbidden is rendered
    await expect(page.getByText('No access')).toBeVisible();
    await expect(page.getByText('You do not have permission to view this area')).toBeVisible();
  });

  test('accessibility: axe contrast and WCAG compliance (light and dark theme)', async ({ page }) => {
    await page.goto(`${ADMIN_URL}/system/audit`);
    await page.waitForSelector('table');

    // Light mode accessibility - scope to main region to test the audit log screen
    let axeResults = await new AxeBuilder({ page })
      .include('main')
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
      .analyze();
    expect(axeResults.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical')).toEqual([]);

    // Switch to Dark mode
    await page.evaluate(() => {
      document.documentElement.classList.add('dark');
    });

    // Dark mode accessibility
    axeResults = await new AxeBuilder({ page })
      .include('main')
      .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
      .analyze();
    expect(axeResults.violations.filter((v) => v.impact === 'serious' || v.impact === 'critical')).toEqual([]);
  });

  test('responsive layout: usable at 390px mobile viewport', async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(`${ADMIN_URL}/system/audit`);

    await expect(page.locator('h1')).toContainText('Audit Log');
    // On mobile (<768px), DataTable uses CardMode (data-row-id cards)
    const mobileCard = page.locator('[data-row-id]').first();
    await expect(mobileCard).toBeVisible();

    // Use row action menu to open detail sheet
    const rowMenuBtn = mobileCard.locator('button[aria-haspopup="menu"]');
    await rowMenuBtn.click();
    await page.getByRole('menuitem', { name: 'View details & diff' }).click();

    const sheet = page.locator('[data-testid="audit-detail-sheet"]');
    await expect(sheet).toBeVisible();
    await expect(sheet.getByText('Security Context & Correlation')).toBeVisible();
  });
});
