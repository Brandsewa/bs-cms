import { test, expect } from '@playwright/test';
import { AxeBuilder } from '@axe-core/playwright';
import usersListFixture from '../fixtures/users-list.json' with { type: 'json' };
import rolesListFixture from '../fixtures/roles-list.json' with { type: 'json' };
import userDetailFixture from '../fixtures/user-detail.json' with { type: 'json' };

const ADMIN_URL = 'http://127.0.0.1:4173/admin';

test.describe('Users and Roles Admin Screens', () => {
  test.beforeEach(async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 900 });

    // Default session: Owner
    await page.route('**/api/v1/auth/session', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'authenticated',
          user: {
            id: '01M5D4V4M48ZC1XHQP6VW8PV86', // matches owner in fixture
            name: 'Owner Test User',
            email: 'owner@example.com',
            roles: ['owner'],
            permissions: ['*'],
          },
          csrf_token: 'test_csrf_token',
        }),
      });
    });

    // Default users list
    await page.route('**/api/v1/users', async (route) => {
      if (route.request().method() === 'GET') {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify(usersListFixture),
        });
      } else {
        await route.fallback();
      }
    });

    // Default roles list
    await page.route('**/api/v1/roles', async (route) => {
      if (route.request().method() === 'GET') {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify(rolesListFixture),
        });
      } else {
        await route.fallback();
      }
    });

    // Sessions route
    await page.route('**/api/v1/users/*/sessions**', async (route) => {
      if (route.request().method() === 'GET') {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            items: [
              {
                id: 'sess_123',
                ip_address: '192.168.1.100',
                user_agent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0',
                created_at: '2026-10-20 18:00:00',
                last_active_at: '2026-10-20 18:30:00',
              },
            ],
          }),
        });
      } else {
        await route.fallback();
      }
    });

    // Default user detail
    await page.route('**/api/v1/users/*', async (route) => {
      const url = route.request().url();
      if (route.request().method() === 'GET' && !url.includes('/sessions') && !url.includes('/invite')) {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify(userDetailFixture),
        });
      } else {
        await route.fallback();
      }
    });
  });

  // ==========================================
  // USERS LIST VIEW TESTS
  // ==========================================

  test('renders users list with search, filter controls and table rows', async ({ page }) => {
    await page.goto(`${ADMIN_URL}/users`);
    await expect(page.locator('[data-testid="users-table"]')).toBeVisible();

    // Header & Navigation tabs
    await expect(page.locator('h1:has-text("Users & Roles")')).toBeVisible();
    await expect(page.locator('a:has-text("Users"), button:has-text("Users")').first()).toBeVisible();
    await expect(page.locator('a:has-text("Roles & Permissions"), button:has-text("Roles & Permissions")').first()).toBeVisible();

    // Controls
    await expect(page.locator('#users-search-input')).toBeVisible();
    await expect(page.locator('#users-role-filter')).toBeVisible();
    await expect(page.locator('#users-status-filter')).toBeVisible();
    await expect(page.locator('button:has-text("Invite User")')).toBeVisible();

    // Table data
    const table = page.locator('[data-testid="users-table"]');
    await expect(table).toBeVisible();

    // Verify user emails from fixture appear
    await expect(page.locator('text=author@example.com').first()).toBeVisible();
    await expect(page.locator('text=editor@example.com').first()).toBeVisible();
    await expect(page.locator('text=owner@example.com').first()).toBeVisible();
  });

  test('filters users list by search query and role filter', async ({ page }) => {
    await page.goto(`${ADMIN_URL}/users`);
    await expect(page.locator('[data-testid="users-table"]')).toBeVisible();

    // Search for "author"
    await page.fill('#users-search-input', 'author');
    await expect(page.locator('text=author@example.com')).toBeVisible();
    await expect(page.locator('text=editor@example.com')).not.toBeVisible();

    // Clear search
    await page.fill('#users-search-input', '');
    await expect(page.locator('text=editor@example.com')).toBeVisible();

    // Select role filter "editor"
    await page.click('#users-role-filter');
    await page.click('[role="option"]:has-text("Editor")');
    await expect(page.locator('text=editor@example.com')).toBeVisible();
    await expect(page.locator('text=author@example.com')).not.toBeVisible();
  });

  test('invites a new user and displays invitation link with dev token', async ({ page }) => {
    page.on('console', (msg) => console.log('BROWSER:', msg.text()));
    let invitePayload: Record<string, unknown> | null = null;

    await page.route('**/api/v1/users/invite', async (route) => {
      invitePayload = route.request().postDataJSON();
      await route.fulfill({
        status: 201,
        contentType: 'application/json',
        body: JSON.stringify({
          invitation: {
            id: 'inv_abc987',
            email: invitePayload?.email,
            roles: invitePayload?.roles,
            token: 'inv_tok_secret_value_123',
            expires_at: '2026-10-27T00:00:00Z',
          },
        }),
      });
    });

    await page.goto(`${ADMIN_URL}/users`);
    await expect(page.locator('[data-testid="users-table"]')).toBeVisible();
    await page.click('button:has-text("Invite User")');

    // Dialog should be open
    await expect(page.locator('h2:has-text("Invite New User")')).toBeVisible();

    await page.fill('#invite-email', 'newcolleague@example.com');


    await page.locator('#invite-role-trigger').click();
    await page.locator('[role="option"]:has-text("Author")').click();

    await page.locator('button:has-text("Send Invitation")').click();

    // Should display invitation link with copy button
    await expect(page.locator('text=User Invited Successfully')).toBeVisible();
    await expect(page.locator('text=inv_tok_secret_value_123')).toBeVisible();
    await expect(page.locator('button:has-text("Copy Link")')).toBeVisible();

    expect(invitePayload).toMatchObject({
      email: 'newcolleague@example.com',
    });
  });

  test('prevents removing owner role from the last active owner', async ({ page }) => {
    // Override users route to return only 1 active owner (so activeOwnersCount === 1)
    await page.route('**/api/v1/users', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          ...usersListFixture,
          items: usersListFixture.items.filter((u) => u.email !== 'admin_release@example.com'),
        }),
      });
    });

    await page.goto(`${ADMIN_URL}/users`);
    await expect(page.locator('[data-testid="users-table"]')).toBeVisible();

    // Locate the row for owner@example.com
    const ownerRow = page.locator('tr:has-text("owner@example.com")');
    await ownerRow.locator('button[aria-label^="Open menu"]').click();
    await page.click('[role="menuitem"]:has-text("Change Role")');

    // Change role dialog opens
    await expect(page.locator('h2:has-text("Change User Role")')).toBeVisible();

    // Select "Author" role
    await page.click('#user-role-select');
    await page.click('[role="option"]:has-text("Author")');

    // Warning alert should appear
    await expect(
      page.locator('text=The last active owner cannot have their owner role removed.')
    ).toBeVisible();

    // Save button should be disabled
    const saveBtn = page.locator('button:has-text("Save Role")');
    await expect(saveBtn).toBeDisabled();
  });

  test('changes user role successfully for non-owner', async ({ page }) => {
    let patchCalled = false;

    await page.route('**/api/v1/users/01M5D4V4S9Y8A6S3JM6WGNHE8P', async (route) => {
      if (route.request().method() === 'PATCH') {
        patchCalled = true;
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            ...userDetailFixture,
            roles: [
              {
                id: '01JB0000000000000000000004',
                handle: 'editor',
                name: 'Editor',
              },
            ],
          }),
        });
      } else {
        await route.fallback();
      }
    });

    await page.goto(`${ADMIN_URL}/users`);
    await expect(page.locator('[data-testid="users-table"]')).toBeVisible();

    // Locate author row
    const authorRow = page.locator('tr:has-text("author@example.com")');
    await authorRow.locator('button[aria-label^="Open menu"]').click();
    await page.click('[role="menuitem"]:has-text("Change Role")');

    await expect(page.locator('h2:has-text("Change User Role")')).toBeVisible();

    await page.click('#user-role-select');
    await page.click('[role="option"]:has-text("Editor")');

    await page.click('button:has-text("Save Role")');

    await expect(page.locator('text=User role updated successfully.')).toBeVisible();
    expect(patchCalled).toBe(true);
  });

  test('views user details in slide-over sheet and revokes session', async ({ page }) => {
    let sessionRevoked = false;

    await page.route('**/api/v1/users/*/sessions**', async (route) => {
      if (route.request().method() === 'DELETE') {
        sessionRevoked = true;
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({ success: true }),
        });
      } else {
        await route.fallback();
      }
    });

    await page.goto(`${ADMIN_URL}/users`);
    await expect(page.locator('[data-testid="users-table"]')).toBeVisible();

    const authorRow = page.locator('tr:has-text("author@example.com")');
    await authorRow.locator('button[aria-label^="Open menu"]').click();
    await page.click('[role="menuitem"]:has-text("View Details")');

    // Slide-over Sheet should open
    await expect(page.locator('h2:has-text("User Details")')).toBeVisible();
    await expect(page.locator('text=author@example.com').first()).toBeVisible();
    await expect(page.locator('h3:has-text("Active Sessions")')).toBeVisible();
    await expect(page.locator('text=192.168.1.100')).toBeVisible();

    // Revoke sessions
    await page.click('button:has-text("Revoke All Sessions")');
    await expect(page.locator('text=All active sessions for this user have been terminated.')).toBeVisible();
    expect(sessionRevoked).toBe(true);
  });

  test('suspends and reactivates user with safety confirmations', async ({ page }) => {
    let suspendCalled = false;

    await page.route('**/api/v1/users/*/suspend', async (route) => {
      suspendCalled = true;
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ success: true }),
      });
    });

    await page.goto(`${ADMIN_URL}/users`);
    await expect(page.locator('[data-testid="users-table"]')).toBeVisible();

    // Self suspension is disabled
    const selfRow = page.locator('tr:has-text("owner@example.com")');
    await selfRow.locator('button[aria-label^="Open menu"]').click();
    await expect(page.locator('[role="menuitem"]:has-text("Suspend User")')).toBeDisabled();
    await page.keyboard.press('Escape');

    // Suspend author
    const authorRow = page.locator('tr:has-text("author@example.com")');
    await authorRow.locator('button[aria-label^="Open menu"]').click();
    await page.click('[role="menuitem"]:has-text("Suspend User")');

    // Confirmation modal opens
    await expect(page.locator('text=Suspend User Account')).toBeVisible();
    await page.click('[role="dialog"] button:has-text("Suspend User"), [role="alertdialog"] button:has-text("Suspend User")');

    await expect(page.locator('text=has been suspended')).toBeVisible();
    expect(suspendCalled).toBe(true);
  });

  test('deletes user requiring TypeToConfirm confirmation of email address', async ({ page }) => {
    let deleteCalled = false;

    await page.route('**/api/v1/users/01M5D4V4S9Y8A6S3JM6WGNHE8P', async (route) => {
      if (route.request().method() === 'DELETE') {
        deleteCalled = true;
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({ success: true }),
        });
      } else {
        await route.fallback();
      }
    });

    await page.goto(`${ADMIN_URL}/users`);
    await expect(page.locator('[data-testid="users-table"]')).toBeVisible();

    const authorRow = page.locator('tr:has-text("author@example.com")');
    await authorRow.locator('button[aria-label^="Open menu"]').click();
    await page.click('[role="menuitem"]:has-text("Delete User")');

    // TypeToConfirm modal opens
    await expect(page.locator('text=Permanently Delete User')).toBeVisible();

    const deleteBtn = page.locator('button:has-text("Permanently Delete")');
    await expect(deleteBtn).toBeDisabled();

    // Type mismatched email
    const input = page.locator('#type-to-confirm-input');
    await input.fill('wrong@example.com');
    await expect(deleteBtn).toBeDisabled();

    // Type matching email
    await input.fill('author@example.com');
    await expect(deleteBtn).toBeEnabled();

    await deleteBtn.click();
    await expect(page.locator('text=User author@example.com permanently deleted.')).toBeVisible();
    expect(deleteCalled).toBe(true);
  });

  // ==========================================
  // ROLES & PERMISSIONS MATRIX TESTS
  // ==========================================

  test('renders roles matrix with subject rows, system role badges and Owner callout', async ({ page }) => {
    await page.goto(`${ADMIN_URL}/users/roles`);
    await expect(page.locator('[data-testid="roles-matrix-table"]')).toBeVisible();

    // Page header & navigation
    await expect(page.locator('h1:has-text("Users & Roles")')).toBeVisible();
    await expect(page.locator('text=Role Hierarchy & Permission Matrix')).toBeVisible();

    // Owner immutable note
    await expect(
      page.locator('text=Owner Permissions Are Immutable')
    ).toBeVisible();

    // Matrix table should show canonical subjects
    const matrix = page.locator('[data-testid="roles-matrix-table"]');
    await expect(matrix).toBeVisible();

    await expect(matrix.locator('text=Collections & Content')).toBeVisible();
    await expect(matrix.locator('text=Taxonomies')).toBeVisible();
    await expect(matrix.locator('text=Media Library')).toBeVisible();
    await expect(matrix.locator('text=Users & Access')).toBeVisible();

    // Verify role cards section
    await expect(page.locator('text=Role Definitions')).toBeVisible();
    await expect(page.locator('h3:has-text("Administrator")')).toBeVisible();
    await expect(page.locator('h3:has-text("Editor")')).toBeVisible();
    await expect(page.locator('button:has-text("Create Custom Role")')).toBeVisible();
  });

  test('creates a custom role through dialog', async ({ page }) => {
    let createPayload: Record<string, unknown> | null = null;

    await page.route('**/api/v1/roles', async (route) => {
      if (route.request().method() === 'POST') {
        createPayload = route.request().postDataJSON();
        await route.fulfill({
          status: 201,
          contentType: 'application/json',
          body: JSON.stringify({
            role: {
              id: 'role_custom_new',
              handle: createPayload?.handle,
              name: createPayload?.name,
              description: createPayload?.description,
              is_system: 0,
              is_ai: 0,
              permissions: [],
            },
          }),
        });
      } else {
        await route.fallback();
      }
    });

    await page.goto(`${ADMIN_URL}/users/roles`);
    await expect(page.locator('[data-testid="roles-matrix-table"]')).toBeVisible();
    await page.click('button:has-text("Create Custom Role")');

    await expect(page.locator('h2:has-text("Create Custom Role")')).toBeVisible();

    await page.fill('#role-name', 'Proofreader');
    await page.fill('#role-handle', 'proofreader');
    await page.fill('#role-desc', 'Can review and comment on drafts');

    await page.click('[role="dialog"] button:has-text("Create Role")');
    await expect(page.locator('text=Custom role "Proofreader" created')).toBeVisible();
    expect(createPayload).toMatchObject({
      handle: 'proofreader',
      name: 'Proofreader',
    });
  });

  // ==========================================
  // PERMISSIONS & FORBIDDEN ACCESS TESTS
  // ==========================================

  test('shows ForbiddenState when user lacks users:read permission', async ({ page }) => {
    await page.route('**/api/v1/auth/session', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'authenticated',
          user: {
            id: 'usr_viewer',
            name: 'Val Viewer',
            email: 'viewer@example.com',
            roles: ['viewer'],
            permissions: ['entries:read'],
          },
          csrf_token: 'test_csrf_token',
        }),
      });
    });

    await page.goto(`${ADMIN_URL}/users`);
    await expect(page.locator('text=No access')).toBeVisible();
    await expect(page.locator('text=Back to dashboard')).toBeVisible();

    await page.goto(`${ADMIN_URL}/users/roles`);
    await expect(page.locator('text=No access')).toBeVisible();
  });

  // ==========================================
  // ACCESSIBILITY & RESPONSIVENESS TESTS
  // ==========================================

  test('axe clean in light and dark mode on users and roles screens', async ({ page }) => {
    const urls = [`${ADMIN_URL}/users`, `${ADMIN_URL}/users/roles`];

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
          `Route ${url} (${theme}) axe violations: ${JSON.stringify(blocking, null, 2)}`
        ).toEqual([]);
      }
    }
  });

  test('mobile viewport (360px) layout stability on users and roles screens', async ({ page }) => {
    await page.setViewportSize({ width: 360, height: 640 });

    await page.goto(`${ADMIN_URL}/users`);
    await expect(page.locator('h1:has-text("Users & Roles")')).toBeVisible();
    await expect(page.locator('#users-search-input')).toBeVisible();
    await expect(page.locator('button:has-text("Invite User")')).toBeVisible();

    await page.goto(`${ADMIN_URL}/users/roles`);
    await expect(page.locator('h1:has-text("Users & Roles")')).toBeVisible();
    await expect(page.locator('text=Role Hierarchy & Permission Matrix')).toBeVisible();
  });
});
