import { test, expect } from '@playwright/test';
import meFixtures from '../fixtures/me-fixtures.json' with { type: 'json' };

const ADMIN_URL = 'http://127.0.0.1:4173/admin';

test.describe('My Account Screen (/admin/account)', () => {
  test.beforeEach(async ({ page }) => {
    let twoFactorOn = false;
    // Mock authentication check
    await page.route('**/api/v1/auth/session', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'authenticated',
          csrf_token: 'csrf123',
          user: { ...meFixtures.profile, two_factor_enabled: twoFactorOn, roles: [{ id: 'r-owner', handle: 'owner', name: 'Owner' }] },
        }),
      });
    });

    await page.route('**/api/v1/me/permissions', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          actor_id: 'u1',
          role_ids: ['r-owner'],
          permissions: [{ id: 'p-all', role_id: 'r-owner', subject_type: '*', subject_key: '*', action: '*', effect: 'allow' }],
        }),
      });
    });

    // Mock /api/v1/me
    await page.route('**/api/v1/me', async (route) => {
      if (route.request().method() === 'GET') {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({ ...meFixtures.profile, two_factor_enabled: twoFactorOn }),
        });
      } else if (route.request().method() === 'PATCH') {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({ status: 'profile_updated' }),
        });
      }
    });

    // Mock /api/v1/me/sessions
    await page.route('**/api/v1/me/sessions', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(meFixtures.sessions),
      });
    });

    // Mock /api/v1/me/sessions/* for revoke
    await page.route('**/api/v1/me/sessions/*', async (route) => {
      if (route.request().method() === 'DELETE') {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({ status: 'session_revoked' }),
        });
      }
    });

    // Mock /api/v1/me/notification-preferences
    await page.route('**/api/v1/me/notification-preferences', async (route) => {
      if (route.request().method() === 'GET') {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify(meFixtures.notificationPreferences),
        });
      } else if (route.request().method() === 'PATCH') {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({ status: 'preferences_updated' }),
        });
      }
    });

    // Mock 2FA Setup
    await page.route('**/api/v1/me/2fa/setup', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(meFixtures.twoFactorSetup),
      });
    });

    // Mock 2FA Confirm
    await page.route('**/api/v1/me/2fa/confirm', async (route) => {
      twoFactorOn = true;
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          status: '2fa_enabled',
          recovery_codes: [
            'ABCD-1234',
            'EFGH-5678',
            'IJKL-9012',
            'MNOP-3456',
            'QRST-7890',
            'UVWX-1234',
            'YZAB-5678',
            'CDEF-9012',
            'GHIJ-3456',
            'KLMN-7890',
          ],
        }),
      });
    });

    // Mock AI status & frontend status for banner area
    await page.route('**/api/v1/ai/status', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ ai_disabled: false, pending_changes_count: 0 }),
      });
    });
    await page.route('**/api/v1/frontend/status', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ connected: true, last_deployment: { status: 'succeeded' } }),
      });
    });
  });

  test('loads My Account view with profile details and tabs', async ({ page }) => {
    await page.goto(`${ADMIN_URL}/account`);
    await expect(page.locator('h1')).toHaveText('My Account');

    // Verify tabs are rendered
    await expect(page.locator('button[role="tab"]:has-text("Profile")')).toBeVisible();
    await expect(page.locator('button[role="tab"]:has-text("Password")')).toBeVisible();
    await expect(page.locator('button[role="tab"]:has-text("Email")')).toBeVisible();
    await expect(page.locator('button[role="tab"]:has-text("2FA Security")')).toBeVisible();
    await expect(page.locator('button[role="tab"]:has-text("Sessions")')).toBeVisible();

    // Verify profile form inputs
    const nameInput = page.locator('#profile-name');
    await expect(nameInput).toHaveValue(meFixtures.profile.name);
  });

  test('navigates to My Account from user dropdown menu', async ({ page }) => {
    await page.goto(`${ADMIN_URL}/`);
    const userMenuBtn = page.locator('button[aria-label="User menu"]');
    await userMenuBtn.click();

    const accountLink = page.locator('[role="menuitem"]:has-text("My account")');
    await expect(accountLink).toBeVisible();
    await accountLink.click();

    await expect(page).toHaveURL(/.*\/admin\/account/);
    await expect(page.locator('h1')).toHaveText('My Account');
  });

  test('initiates 2FA setup and renders SVG QR code', async ({ page }) => {
    await page.goto(`${ADMIN_URL}/account`);
    await page.locator('button[role="tab"]:has-text("2FA Security")').click();

    const setupBtn = page.locator('button:has-text("Set Up Two-Factor Authentication")');
    await expect(setupBtn).toBeVisible();
    await setupBtn.click();

    // Verify QR code svg is rendered
    const qrSvg = page.locator('svg[aria-label="Two factor authentication QR code"]');
    await expect(qrSvg).toBeVisible();

    // Verify secret code is displayed
    await expect(page.locator(`code:has-text("${meFixtures.twoFactorSetup.secret}")`)).toBeVisible();

    // Fill 6-digit TOTP code and confirm
    await page.locator('input[aria-label="6-digit TOTP verification code"]').fill('123456');
    await page.locator('button:has-text("Verify & Enable")').click();

    // Verify recovery codes are displayed upon successful enable
    await expect(page.locator('text=Backup Recovery Codes')).toBeVisible();
    await expect(page.locator('text=ABCD-1234')).toBeVisible();
  });

  test('displays active sessions and allows revoking non-current session', async ({ page }) => {
    await page.goto(`${ADMIN_URL}/account`);
    await page.locator('button[role="tab"]:has-text("Sessions")').click();

    // Verify current session badge
    await expect(page.locator('text=Current Session')).toBeVisible();

    // Verify revoke button exists on non-current sessions
    const revokeButtons = page.locator('button:has-text("Revoke")');
    await expect(revokeButtons.first()).toBeVisible();

    // Revoke first non-current session
    await revokeButtons.first().click();
    await expect(page.locator('text=Current Session')).toBeVisible();
  });
});
