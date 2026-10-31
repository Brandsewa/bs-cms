import { test, expect } from '@playwright/test';

const ADMIN_URL = 'http://127.0.0.1:4173/admin';
const PAGE_ID = '01M5E0583S0DPAGES00000001';

/**
 * Full-screen page designer suite: /entries/pages/:id/design hosts the Puck
 * editor with its own Save-as-Draft / Preview / Publish / Exit toolbar and no
 * admin chrome. This route is the only writer of the pages `content` field.
 */

const PAGE_ENTRY = {
  id: PAGE_ID,
  slug: 'home',
  title: 'Home',
  status: 'published',
  version: 2,
  excerpt: 'The home landing page.',
  template: 'default',
  content: {
    root: {},
    content: [
      {
        type: 'Hero',
        props: { id: 'Hero-1', heading: 'Designer Hero Heading', text: 'Designer hero subtext' },
      },
    ],
  },
};

test.describe('Page Designer (full-screen Puck builder)', () => {
  let capturedPayload: any = null;
  let captureStatus = 200;
  let popups: string[] = [];

  test.beforeEach(async ({ page }) => {
    capturedPayload = null;
    captureStatus = 200;
    popups = [];

    await page.addInitScript(() => {
      // The designer opens the preview window synchronously (popup-blocker
      // safe) and navigates it after the token resolves — record both the
      // open and the final navigation.
      const wins: string[] = [];
      (window as unknown as { __popups: string[] }).__popups = wins;
      window.open = ((url: string | URL, ...rest: unknown[]) => {
        wins.push('open:' + String(url));
        const fake: { close: () => void; location: unknown } = {
          close: () => wins.push('closed'),
          location: undefined,
        };
        Object.defineProperty(fake, 'location', {
          // `win.location.href = url` reads `location` then assigns `href` on
          // the result — so the getter must return an object with a setter.
          get: () => ({
            set href(v: unknown) {
              wins.push(String(v));
            },
          }),
          set: (v: unknown) => wins.push(String(v)),
        });
        return fake as unknown as Window;
      }) as typeof window.open;
    });

    await page.route('**/api/v1/auth/session', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'authenticated',
          csrf_token: 'csrf123',
          user: {
            id: 'u1',
            email: 'owner@example.com',
            name: 'Alice Owner',
            roles: [{ id: 'r-owner', handle: 'owner', name: 'Owner' }],
          },
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
          permissions: [
            { id: 'p-all', role_id: 'r-owner', subject_type: '*', subject_key: '*', action: '*', effect: 'allow' },
          ],
        }),
      });
    });

    await page.route(`**/api/v1/collections/pages/entries/${PAGE_ID}`, async (route) => {
      if (route.request().method() === 'GET') {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify(PAGE_ENTRY),
        });
      } else if (['PUT', 'PATCH'].includes(route.request().method())) {
        capturedPayload = JSON.parse(route.request().postData() || '{}');
        await route.fulfill({ status: captureStatus, contentType: 'application/json', body: JSON.stringify({ id: PAGE_ID, version: 3 }) });
      }
    });

    await page.route('**/api/v1/preview/token', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ token: 'tok123', url: `https://site.test/preview?token=tok123` }),
      });
    });
  });

  test('renders chrome-less full screen with saved content and toolbar', async ({ page }) => {
    await page.goto(`${ADMIN_URL}/entries/pages/${PAGE_ID}/design`);

    const header = page.getByTestId('designer-header');
    await expect(header).toBeVisible();
    await expect(header.getByText('Home')).toBeVisible();
    await expect(header.getByText('Published', { exact: true })).toBeVisible();
    await expect(header.getByTestId('designer-exit')).toBeVisible();
    await expect(header.getByTestId('designer-preview')).toBeVisible();
    await expect(header.getByTestId('designer-save-draft')).toBeVisible();
    await expect(header.getByTestId('designer-publish')).toBeVisible();

    // Saved content renders on the canvas; no dirty badge yet.
    const editor = page.locator('[data-testid="puck-page-editor"]');
    await expect(editor.locator('.bs-hero__heading')).toHaveText('Designer Hero Heading');
    await expect(header.getByText('Unsaved changes')).toHaveCount(0);

    // Preview opens a window synchronously and navigates it to the token URL
    // returned by the API.
    await header.getByTestId('designer-preview').click();
    await expect
      .poll(async () => page.evaluate(() => (window as unknown as { __popups?: string[] }).__popups ?? []))
      .toContain('https://site.test/preview?token=tok123');
  });

  test('dragging a component in marks the session dirty; Save as Draft PATCHes content + status', async ({ page }) => {
    await page.goto(`${ADMIN_URL}/entries/pages/${PAGE_ID}/design`);
    const header = page.getByTestId('designer-header');
    const editor = page.locator('[data-testid="puck-page-editor"]');
    await expect(editor.locator('.bs-hero__heading')).toBeVisible();

    // Drag "Rich text" from the drawer onto the root dropzone (dnd-kit needs
    // real pointer movement — click-to-add is not supported on drawer items).
    const item = page.getByText('Rich text', { exact: true }).first();
    const itemBox = await item.boundingBox();
    const dropBox = await editor.locator('[data-testid="dropzone:root:default-zone"]').boundingBox();
    expect(itemBox).toBeTruthy();
    expect(dropBox).toBeTruthy();
    await page.mouse.move(itemBox!.x + itemBox!.width / 2, itemBox!.y + itemBox!.height / 2);
    await page.mouse.down();
    await page.mouse.move(dropBox!.x + dropBox!.width / 2, dropBox!.y + 30, { steps: 12 });
    await page.mouse.move(dropBox!.x + dropBox!.width / 2, dropBox!.y + Math.min(80, dropBox!.height / 2), { steps: 6 });
    await page.mouse.up();
    await expect(editor.locator('.bs-richtext')).toHaveCount(1);
    await expect(header.getByText('Unsaved changes')).toBeVisible();

    // Save as Draft: content goes as-is, status drops back to draft.
    await header.getByTestId('designer-save-draft').click();
    await expect.poll(() => capturedPayload).toBeTruthy();
    expect(capturedPayload.status).toBe('draft');
    const types = capturedPayload.content.content.map((c: any) => c.type);
    expect(types).toContain('Hero');
    expect(types).toContain('RichText');
    await expect(header.getByText('Unsaved changes')).toHaveCount(0);
  });

  test('Publish sends status published', async ({ page }) => {
    await page.route(`**/api/v1/collections/pages/entries/${PAGE_ID}`, async (route) => {
      if (route.request().method() === 'GET') {
        // Draft entry so the primary action reads "Publish".
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({ ...PAGE_ENTRY, status: 'draft' }),
        });
      } else if (route.request().method() === 'PATCH') {
        capturedPayload = JSON.parse(route.request().postData() || '{}');
        await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ id: PAGE_ID, version: 3 }) });
      }
    });

    await page.goto(`${ADMIN_URL}/entries/pages/${PAGE_ID}/design`);
    const header = page.getByTestId('designer-header');
    await expect(header.getByText('Draft', { exact: true })).toBeVisible();

    await header.getByTestId('designer-publish').click();
    await expect.poll(() => capturedPayload).toBeTruthy();
    expect(capturedPayload.status).toBe('published');
    expect(capturedPayload.content).toBeTruthy();
  });

  test('409 shows the conflict banner and Discard & reload refetches', async ({ page }) => {
    await page.goto(`${ADMIN_URL}/entries/pages/${PAGE_ID}/design`);
    const header = page.getByTestId('designer-header');
    const editor = page.locator('[data-testid="puck-page-editor"]');
    await expect(editor.locator('.bs-hero__heading')).toBeVisible();

    captureStatus = 409;
    await header.getByTestId('designer-save-draft').click();
    const conflict = page.getByTestId('designer-conflict');
    await expect(conflict).toBeVisible();

    captureStatus = 200;
    await conflict.getByRole('button', { name: /discard & reload/i }).click();
    await expect(page.getByTestId('designer-conflict')).toHaveCount(0);
    await expect(editor.locator('.bs-hero__heading')).toHaveText('Designer Hero Heading');
  });

  test('Exit with unsaved changes asks, then returns without saving', async ({ page }) => {
    await page.goto(`${ADMIN_URL}/entries/pages/${PAGE_ID}/design`);
    const header = page.getByTestId('designer-header');
    const editor = page.locator('[data-testid="puck-page-editor"]');
    await expect(editor.locator('.bs-hero__heading')).toBeVisible();

    // Make the session dirty via a drag-add.
    const item = page.getByText('Spacer', { exact: true }).first();
    const itemBox = await item.boundingBox();
    const dropBox = await editor.locator('[data-testid="dropzone:root:default-zone"]').boundingBox();
    await page.mouse.move(itemBox!.x + itemBox!.width / 2, itemBox!.y + itemBox!.height / 2);
    await page.mouse.down();
    await page.mouse.move(dropBox!.x + dropBox!.width / 2, dropBox!.y + 30, { steps: 12 });
    await page.mouse.up();
    await expect(header.getByText('Unsaved changes')).toBeVisible();

    await header.getByTestId('designer-exit').click();
    await expect(page.getByRole('alertdialog')).toBeVisible();

    // Confirming discards: no PATCH was captured, and we land on the editor.
    await page.getByRole('button', { name: /discard & exit/i }).click();
    await expect(page).toHaveURL(new RegExp(`/entries/pages/${PAGE_ID}$`));
    expect(capturedPayload).toBeNull();
  });
});
