import { test, expect } from '@playwright/test';

const ADMIN_URL = 'http://127.0.0.1:4173/admin';

test.describe('All Settings Sections (Security, Tokens, Publishing, Webhooks, Email Log, AI, Notifications)', () => {
  test.beforeEach(async ({ page }) => {
    // Owner authenticated session
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

  // ==========================================
  // SECURITY SETTINGS
  // ==========================================
  test('security settings: loads, renders controls and saves policies', async ({ page }) => {
    let savedPayload: Record<string, unknown> | null = null;

    await page.route('**/api/v1/settings/security', async (route) => {
      if (route.request().method() === 'GET') {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            password_min_length: 12,
            password_require_uppercase: true,
            password_require_lowercase: true,
            password_require_numbers: true,
            password_require_symbols: true,
            two_factor_policy: 'optional',
            two_factor_enforced_roles: ['01JB0000000000000000000001'],
            session_idle_timeout_minutes: 60,
            session_absolute_lifetime_hours: 24,
            login_max_attempts: 5,
            login_decay_minutes: 15,
            allowed_email_domains: ['example.com'],
          }),
        });
      } else if (route.request().method() === 'PUT') {
        savedPayload = route.request().postDataJSON();
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify(savedPayload),
        });
      }
    });

    await page.goto(`${ADMIN_URL}/settings/security`);

    await expect(page.locator('h1:has-text("Settings")')).toBeVisible();
    await expect(page.locator('text=Password Policy')).toBeVisible();
    await expect(page.locator('#password_min_length')).toHaveValue('12');
    await expect(page.locator('#session_idle')).toHaveValue('60');

    // Change min length to 14
    await page.locator('#password_min_length').fill('14');

    // Click Save
    await page.locator('[data-testid="save-security-settings-btn"]').click();

    await expect(page.locator('text=Security settings saved successfully.')).toBeVisible();
    expect(savedPayload?.password_min_length).toBe(14);
  });

  // ==========================================
  // API TOKENS
  // ==========================================
  test('api tokens: lists tokens, generates new token and shows reveal modal', async ({ page }) => {
    let createdTokenPayload: Record<string, unknown> | null = null;

    await page.route('**/api/v1/tokens', async (route) => {
      if (route.request().method() === 'GET') {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            items: [
              {
                id: 'tok_01',
                name: 'CI Deployment Key',
                role_id: null,
                scopes: ['*'],
                ai_mode: 'draft_only',
                last_used_at: null,
                expires_at: null,
                created_at: '2026-11-01T12:00:00Z',
              },
            ],
          }),
        });
      } else if (route.request().method() === 'POST') {
        createdTokenPayload = route.request().postDataJSON();
        await route.fulfill({
          status: 201,
          contentType: 'application/json',
          body: JSON.stringify({
            token: 'bs_tok_sec_1234567890abcdef',
          }),
        });
      }
    });

    await page.goto(`${ADMIN_URL}/settings/tokens`);

    await expect(page.locator('text=CI Deployment Key')).toBeVisible();

    // Click generate token
    await page.locator('[data-testid="create-token-btn"]').click();
    await expect(page.locator('text=Generate New API Token')).toBeVisible();

    await page.locator('#token_name').fill('Test Token');
    await page.locator('button:has-text("Generate Token")').click();

    // Revealed token modal should display
    await expect(page.locator('text=API Token Generated')).toBeVisible();
    await expect(page.locator('text=bs_tok_sec_1234567890abcdef')).toBeVisible();
    expect(createdTokenPayload?.name).toBe('Test Token');
  });

  // ==========================================
  // PUBLISHING SETTINGS
  // ==========================================
  test('publishing settings: loads config, saves settings and triggers deployment', async ({ page }) => {
    let savedConfig: Record<string, unknown> | null = null;
    let deployTriggered = false;

    await page.route('**/api/v1/settings/publishing', async (route) => {
      if (route.request().method() === 'GET') {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            target_type: 'github_actions',
            enabled: true,
            debounce_window: 60,
            github_repository: 'Brandsewa/bs-cms',
            github_workflow_id: 'deploy.yml',
            has_github_token: true,
            webhook_url: null,
            has_webhook_secret: false,
            url_patterns: { posts: '/blog/{slug}/' },
          }),
        });
      } else if (route.request().method() === 'PUT') {
        savedConfig = route.request().postDataJSON();
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            ...savedConfig,
            has_github_token: true,
          }),
        });
      }
    });

    await page.route('**/api/v1/deployments', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify([
          {
            id: 'dep_01',
            trigger_reason: 'Automated release',
            status: 'succeeded',
            dispatched_at: '2026-11-04T10:00:00Z',
            finished_at: '2026-11-04T10:02:00Z',
            error: null,
          },
        ]),
      });
    });

    await page.route('**/api/v1/deployments/trigger', async (route) => {
      deployTriggered = true;
      await route.fulfill({
        status: 202,
        contentType: 'application/json',
        body: JSON.stringify({ status: 'queued', id: 'dep_02' }),
      });
    });

    await page.goto(`${ADMIN_URL}/settings/publishing`);

    await expect(page.locator('text=Deployment Target & Pipeline')).toBeVisible();
    await expect(page.locator('#github_repository')).toHaveValue('Brandsewa/bs-cms');
    await expect(page.locator('#debounce_window')).toHaveValue('60');

    // Click Deploy Now
    await page.locator('[data-testid="deploy-now-btn"]').click();
    expect(deployTriggered).toBe(true);

    // Change debounce window and save
    await page.locator('#debounce_window').fill('45');
    await page.locator('[data-testid="save-publishing-btn"]').click();

    await expect(page.locator('#main-content').getByText('Publishing settings saved successfully.')).toBeVisible();
    expect(savedConfig?.debounce_window).toBe(45);
  });

  // ==========================================
  // WEBHOOKS
  // ==========================================
  test('webhooks: lists webhooks and dispatches test ping', async ({ page }) => {
    let pingedId: string | null = null;

    await page.route('**/api/v1/webhooks', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify([
          {
            id: 'wh_01',
            name: 'Slack Alerts Hook',
            url: 'https://hooks.slack.com/services/xxx',
            events: ['entry.publish'],
            collections: null,
            is_active: 1,
            created_at: '2026-11-01T10:00:00Z',
          },
        ]),
      });
    });

    await page.route('**/api/v1/webhooks/wh_01/test', async (route) => {
      pingedId = 'wh_01';
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ response_code: 200 }),
      });
    });

    await page.goto(`${ADMIN_URL}/settings/webhooks`);

    await expect(page.locator('text=Slack Alerts Hook')).toBeVisible();

    // Click Ping Test button
    const pingBtn = page.getByRole('button', { name: /Test ping/i });
    await pingBtn.click();

    expect(pingedId).toBe('wh_01');
  });

  // ==========================================
  // EMAIL LOG
  // ==========================================
  test('email log: renders transactional logs and handles resend', async ({ page }) => {
    let resentId: string | null = null;

    await page.route('**/api/v1/settings/email/log*', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify([
          {
            id: 101,
            to_email: 'user@example.com',
            subject: 'Verify your email address',
            template: 'verify_email',
            status: 'sent',
            provider: 'zoho',
            error: null,
            created_at: '2026-11-04T12:00:00Z',
          },
        ]),
      });
    });

    await page.route('**/api/v1/email-log/101/resend', async (route) => {
      resentId = '101';
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ status: 'resent' }),
      });
    });

    await page.goto(`${ADMIN_URL}/settings/email-log`);

    await expect(page.locator('text=Transactional Email Logs')).toBeVisible();
    await expect(page.locator('text=Verify your email address')).toBeVisible();
    await expect(page.locator('text=user@example.com')).toBeVisible();

    // Click Resend
    await page.locator('button:has-text("Resend")').click();
    expect(resentId).toBe('101');
  });

  // ==========================================
  // AI CONNECTIONS
  // ==========================================
  test('ai connections: displays kill switch and handles toggle modal', async ({ page }) => {
    await page.route('**/api/v1/ai/status', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          kill_switch_active: false,
          active_grants_count: 1,
          mode_default: 'draft_only',
        }),
      });
    });

    await page.route('**/api/v1/ai/grants', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          items: [
            {
              id: 'grant_01',
              client_name: 'Antigravity Cursor Agent',
              oauth_client_id: 'client_antigravity',
              user_email: 'admin@example.com',
              role_name: 'Editor',
              scopes: ['entries:write'],
              ai_mode: 'draft_only',
              created_at: '2026-11-02T10:00:00Z',
              last_used_at: null,
              revoked_at: null,
            },
          ],
        }),
      });
    });

    await page.route('**/api/v1/pending-changes*', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ items: [] }),
      });
    });

    await page.goto(`${ADMIN_URL}/settings/ai`);

    await expect(page.locator('text=AI Kill Switch')).toBeVisible();
    await expect(page.locator('text=ONLINE')).toBeVisible();
    await expect(page.locator('text=Antigravity Cursor Agent')).toBeVisible();

    // Click Disconnect All AI
    await page.locator('[data-testid="toggle-kill-switch-btn"]').click();
    await expect(page.locator('text=Emergency Disconnect All AI')).toBeVisible();
  });

  // ==========================================
  // NOTIFICATIONS PREFERENCES
  // ==========================================
  test('notifications preferences: loads preferences, toggles switches and saves with PATCH', async ({ page }) => {
    let patchedPrefs: Record<string, unknown> | null = null;

    await page.route('**/api/v1/me/notification-preferences', async (route) => {
      if (route.request().method() === 'GET') {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            preferences: {
              in_app_system_alerts: true,
              email_on_login: true,
              email_on_role_change: true,
              email_security_alerts: true,
              email_publish_digest: false,
            },
          }),
        });
      } else if (route.request().method() === 'PATCH') {
        patchedPrefs = route.request().postDataJSON();
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            preferences: patchedPrefs?.preferences,
          }),
        });
      }
    });

    await page.goto(`${ADMIN_URL}/settings/notifications`);

    await expect(page.locator('text=In-App & Email Notification Channels')).toBeVisible();
    await expect(page.locator('text=In-App System Alerts')).toBeVisible();
    await expect(page.locator('text=Sign-In Email Notifications')).toBeVisible();

    // Toggle email_publish_digest
    await page.locator('label[for="pref_publish_digest"]').click();

    // Click Save Preferences
    await page.locator('[data-testid="save-notifications-btn"]').click();

    await expect(page.locator('text=Preferences saved.')).toBeVisible();
    expect(patchedPrefs).not.toBeNull();
  });
});
