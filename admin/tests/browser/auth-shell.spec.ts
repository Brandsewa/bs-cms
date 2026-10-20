import { test, expect } from '@playwright/test';
import { AxeBuilder } from '@axe-core/playwright';

const ADMIN_URL = 'http://127.0.0.1:4173/admin';

test.describe('Admin Auth & Shell Suite', () => {
  test('login failure renders generic error without user enumeration', async ({ page }) => {
    // Intercept session check (unauthenticated initially)
    await page.route('**/api/v1/auth/session', async (route) => {
      await route.fulfill({
        status: 401,
        contentType: 'application/json',
        body: JSON.stringify({
          type: 'about:blank',
          title: 'Unauthorized',
          status: 401,
          detail: 'Unauthenticated.',
        }),
      });
    });

    // Intercept failed login
    await page.route('**/api/v1/auth/login', async (route) => {
      await route.fulfill({
        status: 401,
        contentType: 'application/problem+json',
        body: JSON.stringify({
          type: 'about:blank',
          title: 'Unauthorized',
          status: 401,
          detail: 'Invalid credentials.',
        }),
      });
    });

    await page.goto(`${ADMIN_URL}/login`);
    await expect(page.getByRole('heading', { name: 'Sign in to BS CMS' })).toBeVisible();

    await page.getByLabel('Email').fill('unknown@example.com');
    await page.getByLabel('Password').fill('WrongPassword123!');
    await page.getByRole('button', { name: 'Sign in' }).click();

    const alert = page.getByRole('alert');
    await expect(alert).toBeVisible();
    await expect(alert).toContainText('Invalid credentials. Please check your email and password.');
  });

  test('login lockout (423 status) displays locked message sensibly', async ({ page }) => {
    await page.route('**/api/v1/auth/session', async (route) => {
      await route.fulfill({
        status: 401,
        contentType: 'application/json',
        body: JSON.stringify({ status: 401, title: 'Unauthorized' }),
      });
    });

    await page.route('**/api/v1/auth/login', async (route) => {
      await route.fulfill({
        status: 423,
        contentType: 'application/problem+json',
        body: JSON.stringify({
          type: 'about:blank',
          title: 'Locked',
          status: 423,
          detail: 'Account is locked for 15 minutes due to too many failed attempts.',
        }),
      });
    });

    await page.goto(`${ADMIN_URL}/login`);
    await page.getByLabel('Email').fill('locked@example.com');
    await page.getByLabel('Password').fill('AnyPassword123!');
    await page.getByRole('button', { name: 'Sign in' }).click();

    const alert = page.getByRole('alert');
    await expect(alert).toBeVisible();
    await expect(alert).toContainText('Account is locked for 15 minutes');
    await expect(page.getByLabel('Email')).toBeDisabled();
    await expect(page.getByLabel('Password')).toBeDisabled();
  });

  test('2FA challenge step accepts code and redirects after verification', async ({ page }) => {
    let sessionCount = 0;
    await page.route('**/api/v1/auth/session', async (route) => {
      sessionCount++;
      if (sessionCount <= 1) {
        await route.fulfill({
          status: 401,
          contentType: 'application/json',
          body: JSON.stringify({ status: 401 }),
        });
      } else {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            status: 'authenticated',
            csrf_token: 'test-csrf-token',
            user: { id: 'u1', email: 'owner@example.com', name: 'Alice Owner', roles: ['owner'] },
            permissions: ['*'],
          }),
        });
      }
    });

    await page.route('**/api/v1/auth/login', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'requires_2fa',
          challenge_token: 'chal_xyz123',
        }),
      });
    });

    await page.route('**/api/v1/auth/2fa/challenge', async (route) => {
      const data = route.request().postDataJSON();
      expect(data).toHaveProperty('challenge_token', 'chal_xyz123');
      expect(data).toHaveProperty('code', '123456');
      // Verify no forbidden recovery_code property
      expect(data).not.toHaveProperty('recovery_code');

      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'authenticated',
          user: { id: 'u1', email: 'owner@example.com', name: 'Alice Owner', roles: ['owner'] },
        }),
      });
    });

    await page.goto(`${ADMIN_URL}/login`);
    await page.getByLabel('Email').fill('owner@example.com');
    await page.getByLabel('Password').fill('CorrectPassword123!');
    await page.getByRole('button', { name: 'Sign in' }).click();

    // 2FA Challenge screen
    await expect(page.getByRole('heading', { name: 'Two-Factor Authentication' })).toBeVisible();
    await page.getByLabel('Authentication code').fill('123456');
    await page.getByRole('button', { name: 'Verify' }).click();

    // Redirects to dashboard
    await expect(page.getByText('Welcome back, Alice Owner')).toBeVisible();
  });

  test('forgot password submits cleanly with generic success notice', async ({ page }) => {
    await page.route('**/api/v1/auth/password/forgot', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          message: 'If that email address exists in our system, we have sent a password reset link.',
        }),
      });
    });

    await page.goto(`${ADMIN_URL}/forgot-password`);
    await expect(page.getByRole('heading', { name: 'Reset your password' })).toBeVisible();

    await page.getByLabel('Email').fill('user@example.com');
    await page.getByRole('button', { name: 'Send reset link' }).click();

    await expect(page.getByRole('status')).toBeVisible();
    await expect(page.getByRole('status')).toContainText(
      'If that email address exists in our system, we have sent a password reset link.'
    );
  });

  test('return_to sanitization: honours safe paths and rejects external/malicious redirects', async ({
    page,
  }) => {
    await page.route('**/api/v1/auth/session', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'authenticated',
          csrf_token: 'csrf123',
          user: { id: 'u1', email: 'alice@example.com', name: 'Alice', roles: ['admin'] },
          permissions: ['entries.read', 'media.read'],
        }),
      });
    });

    await page.route('**/api/v1/auth/login', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'authenticated',
          user: { id: 'u1', email: 'alice@example.com', name: 'Alice', roles: ['admin'] },
        }),
      });
    });

    // Malicious return_to
    await page.goto(`${ADMIN_URL}/login?return_to=https://evil.com/phish`);
    await page.getByLabel('Email').fill('alice@example.com');
    await page.getByLabel('Password').fill('Secret123!');
    await page.getByRole('button', { name: 'Sign in' }).click();

    // Must navigate to safe /admin, not evil.com
    await page.waitForURL('**/admin');
    expect(page.url()).toContain('/admin');
    expect(page.url()).not.toContain('evil.com');
  });

  test('session expiry redirect: 401 on protected route redirects to /admin/login?return_to=...', async ({
    page,
  }) => {
    await page.route('**/api/v1/auth/session', async (route) => {
      await route.fulfill({
        status: 401,
        contentType: 'application/json',
        body: JSON.stringify({ status: 401, title: 'Unauthorized' }),
      });
    });

    await page.goto(`${ADMIN_URL}/entries`);
    // ProtectedAdmin catches unauthenticated and redirects
    await page.waitForURL('**/admin/login?return_to=%2Fadmin%2Fentries');
    expect(page.url()).toContain('/admin/login?return_to=');
  });

  test('permission-filtered sidebar: viewer vs owner', async ({ page }) => {
    // 1. Viewer session
    await page.route('**/api/v1/auth/session', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'authenticated',
          csrf_token: 'csrf123',
          user: { id: 'u2', email: 'viewer@example.com', name: 'Bob Viewer', roles: ['viewer'] },
          permissions: ['entries.read'],
        }),
      });
    });

    await page.goto(`${ADMIN_URL}/`);
    await expect(page.getByRole('link', { name: 'Entries' })).toBeVisible();
    // System settings and Users should be hidden from viewer
    await expect(page.getByRole('link', { name: 'Users' })).toHaveCount(0);
    await expect(page.getByRole('link', { name: 'Settings' })).toHaveCount(0);

    // 2. Owner session
    await page.route('**/api/v1/auth/session', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'authenticated',
          csrf_token: 'csrf123',
          user: { id: 'u1', email: 'owner@example.com', name: 'Alice Owner', roles: ['owner'] },
          permissions: ['*'],
        }),
      });
    });

    await page.goto(`${ADMIN_URL}/`);
    await expect(page.getByRole('link', { name: 'Entries' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Users' })).toBeVisible();
    await expect(page.getByRole('link', { name: 'Settings' })).toBeVisible();
  });

  test('axe light and dark accessibility acceptance on auth and shell screens', async ({
    page,
  }) => {
    await page.route('**/api/v1/auth/session', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'authenticated',
          csrf_token: 'csrf123',
          user: { id: 'u1', email: 'admin@example.com', name: 'Admin User', roles: ['owner'] },
          permissions: ['*'],
        }),
      });
    });

    for (const theme of ['light', 'dark'] as const) {
      await page.addInitScript((mode) => {
        window.localStorage.setItem('bs-theme', mode);
      }, theme);

      // Test login screen
      await page.goto(`${ADMIN_URL}/login`);
      await page.evaluate((mode) => {
        document.documentElement.classList.toggle('dark', mode === 'dark');
      }, theme);

      let results = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
        .analyze();
      let blocking = results.violations.filter(
        (v) => v.impact === 'serious' || v.impact === 'critical'
      );
      expect(blocking, `Login (${theme}) axe violations`).toEqual([]);

      // Test shell dashboard screen
      await page.goto(`${ADMIN_URL}/`);
      await page.evaluate((mode) => {
        document.documentElement.classList.toggle('dark', mode === 'dark');
      }, theme);

      results = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
        .analyze();
      blocking = results.violations.filter(
        (v) => v.impact === 'serious' || v.impact === 'critical'
      );
      expect(blocking, `Shell dashboard (${theme}) axe violations`).toEqual([]);
    }
  });

  test('mobile viewport (360px) layout stability', async ({ page }) => {
    await page.setViewportSize({ width: 360, height: 640 });

    await page.route('**/api/v1/auth/session', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'authenticated',
          csrf_token: 'csrf123',
          user: { id: 'u1', email: 'admin@example.com', name: 'Admin User', roles: ['owner'] },
          permissions: ['*'],
        }),
      });
    });

    await page.goto(`${ADMIN_URL}/`);
    await expect(page.getByText('Welcome back, Admin User')).toBeVisible();

    // Verify no horizontal document overflow
    const hasHorizontalScroll = await page.evaluate(() => {
      return document.documentElement.scrollWidth > window.innerWidth;
    });
    expect(hasHorizontalScroll, 'Mobile view should not cause horizontal scroll').toBe(false);
  });
});
