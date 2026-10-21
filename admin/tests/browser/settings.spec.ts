import { test, expect } from '@playwright/test';
import { AxeBuilder } from '@axe-core/playwright';
import siteSettingsFixture from '../fixtures/site-settings.json' with { type: 'json' };
import emailSettingsFixture from '../fixtures/email-settings.json' with { type: 'json' };

const ADMIN_URL = 'http://127.0.0.1:4173/admin';

test.describe('Settings Admin Screens (General & Email)', () => {
  test.beforeEach(async ({ page }) => {
    // Default session: Owner with wildcard permissions
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

    // Default Site Settings singleton mock
    await page.route('**/api/v1/singletons/site_settings', async (route) => {
      if (route.request().method() === 'GET') {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify(siteSettingsFixture),
        });
      } else {
        await route.fallback();
      }
    });

    // Default Email Settings mock
    await page.route('**/api/v1/settings/email', async (route) => {
      if (route.request().method() === 'GET') {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify(emailSettingsFixture),
        });
      } else {
        await route.fallback();
      }
    });
  });

  // ==========================================
  // GENERAL SETTINGS TESTS
  // ==========================================

  test('renders general settings with site details, environment badge and tabs', async ({ page }) => {
    await page.goto(`${ADMIN_URL}/settings/general`);

    // Verify Breadcrumbs & Header
    await expect(page.locator('text=Settings').first()).toBeVisible();
    await expect(page.locator('h1:has-text("Settings")')).toBeVisible();

    // Verify Tab navigation
    await expect(page.locator('button:has-text("General"), a:has-text("General")').first()).toBeVisible();
    await expect(page.locator('button:has-text("Email"), a:has-text("Email")').first()).toBeVisible();

    // Verify Form Fields populated from real fixture
    const siteTitleInput = page.locator('#general-site-name');
    await expect(siteTitleInput).toHaveValue('Brand Sewa Central');

    const taglineInput = page.locator('#general-tagline');
    await expect(taglineInput).toHaveValue('High performance headless CMS');

    const canonicalInput = page.locator('#general-canonical-url');
    await expect(canonicalInput).toHaveValue('https://brandsewa.com');

    // Environment card
    await expect(page.locator('text=PRODUCTION').first()).toBeVisible();
    await expect(page.locator('text=Site Details')).toBeVisible();
  });

  test('updates general settings successfully without environment change', async ({ page }) => {
    let patchedBody: Record<string, unknown> | null = null;

    await page.route('**/api/v1/singletons/site_settings', async (route) => {
      if (route.request().method() === 'PATCH') {
        patchedBody = route.request().postDataJSON();
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            ...siteSettingsFixture,
            site_name: patchedBody?.site_name,
            tagline: patchedBody?.tagline,
          }),
        });
      } else {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify(siteSettingsFixture),
        });
      }
    });

    await page.goto(`${ADMIN_URL}/settings/general`);

    const siteTitleInput = page.locator('#general-site-name');
    await siteTitleInput.fill('Brand Sewa Enterprise');

    const saveButton = page.locator('button:has-text("Save Changes")');
    await saveButton.click();

    // Verify Success alert
    await expect(page.locator('text=General settings saved successfully.')).toBeVisible();
    expect(patchedBody).not.toBeNull();
    expect(patchedBody?.site_name).toBe('Brand Sewa Enterprise');
  });

  test('environment change triggers confirmation modal and completes with confirm_token', async ({ page }) => {
    let attempt = 0;
    let receivedConfirmToken: string | null = null;

    await page.route('**/api/v1/singletons/site_settings', async (route) => {
      if (route.request().method() === 'PATCH') {
        attempt++;
        const postData = route.request().postDataJSON();
        if (attempt === 1) {
          // First attempt requires confirmation
          await route.fulfill({
            status: 409,
            contentType: 'application/json',
            body: JSON.stringify({
              type: 'https://bs-cms.org/errors/confirmation-required',
              title: 'Confirmation Required',
              status: 409,
              detail: 'Switching environment requires explicit confirmation.',
              confirm_token: 'staging_confirm_token_xyz',
            }),
          });
        } else {
          // Second attempt with token
          receivedConfirmToken = postData.confirm_token;
          await route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({
              ...siteSettingsFixture,
              environment: 'staging',
            }),
          });
        }
      } else {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify(siteSettingsFixture),
        });
      }
    });

    await page.goto(`${ADMIN_URL}/settings/general`);

    // Click Staging radio
    const stagingRadio = page.locator('label[for="env-staging"]');
    await stagingRadio.click();

    // Click Save Changes
    const saveButton = page.locator('button:has-text("Save Changes")');
    await saveButton.click();

    // Confirmation modal should be visible
    await expect(page.locator('text=Confirm Environment Change')).toBeVisible();
    await expect(
      page.locator('text=Are you sure you want to change the site environment to "staging"?')
    ).toBeVisible();

    // Confirm the action in the modal overlay
    const confirmButton = page.locator('button:has-text("Switch to staging")');
    await confirmButton.dispatchEvent('click');

    // Should succeed
    await expect(page.locator('text=General settings saved successfully.')).toBeVisible();
    expect(receivedConfirmToken).toBe('staging_confirm_token_xyz');
  });

  // ==========================================
  // EMAIL SETTINGS TESTS
  // ==========================================

  test('renders email settings with provider presets, sender identity and deliverability checklist', async ({ page }) => {
    await page.goto(`${ADMIN_URL}/settings/email`);

    // Verify Header and breadcrumbs
    await expect(page.locator('h1:has-text("Settings")')).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Delivery Provider' })).toBeVisible();

    // Verify Provider preset badge
    await expect(page.locator('text=Zoho CPaaS (ZeptoMail)').first()).toBeVisible();

    // Verify Fields populated from fixture
    const hostInput = page.locator('#email-host');
    await expect(hostInput).toHaveValue('smtp.zeptomail.com');

    const portInput = page.locator('#email-port');
    await expect(portInput).toHaveValue('465');

    const usernameInput = page.locator('#email-username');
    await expect(usernameInput).toHaveValue('emailapikey');

    const fromNameInput = page.locator('#email-from-name');
    await expect(fromNameInput).toHaveValue('Brand Sewa CMS');

    const fromEmailInput = page.locator('#email-from-email');
    await expect(fromEmailInput).toHaveValue('notifications@example.com');

    // Verify Password Masking
    const passwordInput = page.locator('#email-password');
    await expect(passwordInput).toHaveValue('••••••••••••');
    await expect(passwordInput).toBeDisabled();
    await expect(page.locator('button:has-text("Replace")')).toBeVisible();

    // Verify Deliverability Checklist
    await expect(page.locator('text=Deliverability Checklist')).toBeVisible();
    await expect(page.locator('text=SPF Record:')).toBeVisible();
    await expect(page.locator('text=DKIM Key:')).toBeVisible();
    await expect(page.locator('text=DMARC Policy:')).toBeVisible();
  });

  test('switching provider preset updates port, host and encryption defaults', async ({ page }) => {
    await page.goto(`${ADMIN_URL}/settings/email`);

    // Click Provider Select Trigger
    const providerTrigger = page.locator('#email-provider');
    await providerTrigger.click();

    // Select Resend
    const resendOption = page.locator('[role="option"]:has-text("Resend (SMTP)")');
    await resendOption.click();

    // Host should auto-populate to smtp.resend.com
    await expect(page.locator('#email-host')).toHaveValue('smtp.resend.com');
    await expect(page.locator('#email-username')).toHaveValue('resend');
    await expect(page.locator('#email-port')).toHaveValue('465');
  });

  test('password replace allows entering new token and saving', async ({ page }) => {
    let putBody: Record<string, unknown> | null = null;

    await page.route('**/api/v1/settings/email', async (route) => {
      if (route.request().method() === 'PUT') {
        putBody = route.request().postDataJSON();
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({ status: 'email_settings_updated' }),
        });
      } else {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify(emailSettingsFixture),
        });
      }
    });

    await page.goto(`${ADMIN_URL}/settings/email`);

    // Click Replace
    await page.locator('button:has-text("Replace")').click();

    // Password input should now be enabled and empty
    const passwordInput = page.locator('#email-password');
    await expect(passwordInput).toBeEnabled();
    await passwordInput.fill('new_secret_zeptomail_token_123');

    // Click Save Settings
    await page.locator('button:has-text("Save Settings")').click();

    await expect(page.locator('text=Email settings saved successfully.')).toBeVisible();
    expect(putBody?.password).toBe('new_secret_zeptomail_token_123');
  });

  test('test connection executes connection test and displays verified status', async ({ page }) => {
    await page.route('**/api/v1/settings/email/test', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ connected: true }),
      });
    });

    await page.goto(`${ADMIN_URL}/settings/email`);

    const testConnButton = page.locator('button:has-text("Test Connection")');
    await testConnButton.click();

    await expect(page.locator('text=Connection Verified')).toBeVisible();
    await expect(
      page.locator('text=SMTP server connected and authenticated successfully.')
    ).toBeVisible();
  });

  test('send test email opens dialog and dispatches to recipient', async ({ page }) => {
    let sentTo: string | null = null;

    await page.route('**/api/v1/settings/email/send-test', async (route) => {
      const body = route.request().postDataJSON();
      sentTo = body.to;
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ status: 'test_email_sent' }),
      });
    });

    await page.goto(`${ADMIN_URL}/settings/email`);

    // Open Test Email Dialog
    await page.locator('button:has-text("Send Test Email")').click();

    // Dialog should open
    await expect(page.locator('text=Send Test Email').first()).toBeVisible();

    const recipientInput = page.locator('#test-email-recipient');
    await recipientInput.fill('team-lead@brandsewa.com');

    // Click Dispatch Email inside the modal dialog
    const dispatchButton = page.locator('button:has-text("Dispatch Email")');
    await dispatchButton.dispatchEvent('click');

    // Dialog closes and success alert appears
    await expect(page.getByText('Email Dispatched', { exact: true })).toBeVisible();
    await expect(
      page.locator('text=Test email dispatched to team-lead@brandsewa.com')
    ).toBeVisible();
    expect(sentTo).toBe('team-lead@brandsewa.com');
  });

  // ==========================================
  // ROLE SCOPING & FORBIDDEN STATE TESTS
  // ==========================================

  test('non-admin roles (editor/viewer) receive ForbiddenState on settings routes', async ({ page }) => {
    // Session as Editor without settings.manage permission
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

    // Try navigating to General Settings
    await page.goto(`${ADMIN_URL}/settings/general`);
    await expect(page.locator('text=No access')).toBeVisible();
    await expect(page.locator('text=Back to dashboard')).toBeVisible();

    // Try navigating to Email Settings
    await page.goto(`${ADMIN_URL}/settings/email`);
    await expect(page.locator('text=No access')).toBeVisible();
  });

  // ==========================================
  // ACCESSIBILITY & RESPONSIVENESS TESTS
  // ==========================================

  test('axe clean in light and dark mode on settings screens', async ({ page }) => {
    const urls = [`${ADMIN_URL}/settings/general`, `${ADMIN_URL}/settings/email`];

    for (const url of urls) {
      for (const theme of ['light', 'dark'] as const) {
        await page.goto(url);
        await page.evaluate((mode) => {
          document.documentElement.classList.toggle('dark', mode === 'dark');
        }, theme);

        const results = await new AxeBuilder({ page })
          .withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
          .analyze();

        const blocking = results.violations.filter(
          (v) => v.impact === 'serious' || v.impact === 'critical'
        );

        expect(
          blocking,
          `Settings route ${url} (${theme}) axe violations: ${JSON.stringify(blocking, null, 2)}`
        ).toEqual([]);
      }
    }
  });

  test('mobile viewport (360px) layout stability on settings screens', async ({ page }) => {
    await page.setViewportSize({ width: 360, height: 640 });

    await page.goto(`${ADMIN_URL}/settings/general`);
    await expect(page.locator('h1:has-text("Settings")')).toBeVisible();
    await expect(page.locator('#general-site-name')).toBeVisible();
    await expect(page.locator('button:has-text("Save Changes")')).toBeVisible();

    await page.goto(`${ADMIN_URL}/settings/email`);
    await expect(page.locator('h1:has-text("Settings")')).toBeVisible();
    await expect(page.locator('#email-host')).toBeVisible();
    await expect(page.locator('button:has-text("Save Settings")')).toBeVisible();
  });
});
