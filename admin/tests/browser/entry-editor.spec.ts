import { test, expect } from '@playwright/test';
import { AxeBuilder } from '@axe-core/playwright';
import fixturesData from '../fixtures/collection-list-fixtures.json' with { type: 'json' };

const ADMIN_URL = 'http://127.0.0.1:4173/admin';

test.describe('Entry Editor Screen Suite (Phase 6 Screen 6.3)', () => {
  test.beforeEach(async ({ page }) => {
    // 1. Session route (Owner)
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

    // 2. Me permissions
    await page.route('**/api/v1/me/permissions', async (route) => {
      await route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          actor_id: 'u1',
          role_ids: ['r-owner'],
          permissions: [
            {
              id: 'p-all',
              role_id: 'r-owner',
              subject_type: '*',
              subject_key: '*',
              action: '*',
              effect: 'allow',
            },
          ],
        }),
      });
    });

    // 3. Collection schema
    await page.route('**/api/v1/collections/posts', async (route) => {
      if (route.request().method() === 'GET') {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify(fixturesData.collections_posts),
        });
      }
    });

    // 4. Single entry GET / PUT
    await page.route('**/api/v1/collections/posts/entries/01M5E0583S0DT2SVVPRZREWT01', async (route) => {
      if (route.request().method() === 'GET') {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            id: '01M5E0583S0DT2SVVPRZREWT01',
            slug: 'getting-started-with-bs-cms',
            title: 'Getting Started with BS CMS',
            status: 'published',
            locale: 'en',
            author_id: 'u1',
            published_at: '2026-10-21 03:05:50.000',
            version: 2,
            excerpt: 'Learn how to publish articles with BS CMS.',
            seo: {
              title: 'Getting Started with BS CMS | BrandSewa',
              description: 'Official guide to publishing static sites and managing blog posts.',
            },
            body: {
              type: 'doc',
              content: [
                {
                  type: 'paragraph',
                  content: [{ type: 'text', text: 'Welcome to BS CMS.' }],
                },
              ],
            },
          }),
        });
      } else if (route.request().method() === 'PUT') {
        const headers = route.request().headers();
        const ifMatch = headers['if-match'];
        if (ifMatch === '999') {
          // Trigger conflict response for test
          await route.fulfill({
            status: 409,
            contentType: 'application/json',
            body: JSON.stringify({
              type: 'about:blank',
              title: 'Conflict',
              status: 409,
              detail: "Entry was modified concurrently. Expected version 999, current is 2.",
            }),
          });
        } else {
          await route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({
              id: '01M5E0583S0DT2SVVPRZREWT01',
              title: 'Getting Started with BS CMS Updated',
              status: 'published',
              version: 3,
            }),
          });
        }
      }
    });

    // 5. Create entry POST
    await page.route('**/api/v1/collections/posts/entries', async (route) => {
      if (route.request().method() === 'POST') {
        await route.fulfill({
          status: 201,
          contentType: 'application/json',
          body: JSON.stringify({
            id: '01M5E_NEW_POST_ID',
            title: 'New Post Title',
            slug: 'new-post-title',
            status: 'draft',
            version: 1,
          }),
        });
      }
    });
  });

  test('renders Entry Editor with schema fields, Tiptap article toolbar, and SEO panels', async ({ page }) => {
    await page.goto(`${ADMIN_URL}/entries/posts/01M5E0583S0DT2SVVPRZREWT01`);

    // Verify title and heading
    await expect(page.getByRole('heading', { name: /Edit Post/i })).toBeVisible();
    await expect(page.locator('#entry-title-input')).toHaveValue('Getting Started with BS CMS');

    // Verify Tiptap Editor & formatting tools
    const editor = page.locator('[data-testid="tiptap-article-editor"]');
    await expect(editor).toBeVisible();
    await expect(editor.getByRole('button', { name: 'Bold' })).toBeVisible();
    await expect(editor.getByRole('button', { name: 'Italic' })).toBeVisible();
    await expect(editor.getByRole('button', { name: 'Heading 2' })).toBeVisible();
    await expect(editor.getByText('Welcome to BS CMS.')).toBeVisible();

    // Verify Publishing Panel
    await expect(page.getByText('Publishing', { exact: true })).toBeVisible();
    await expect(page.getByText('v2')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Save changes' })).toBeVisible();

    // Verify SEO Panel & SERP Preview
    await expect(page.getByText('SEO & Metadata')).toBeVisible();
    await expect(page.locator('#seo-meta-title')).toHaveValue('Getting Started with BS CMS | BrandSewa');
    await expect(page.locator('#seo-meta-desc')).toHaveValue('Official guide to publishing static sites and managing blog posts.');
  });

  test('new entry route initializes clean form with auto-slug generation', async ({ page }) => {
    await page.goto(`${ADMIN_URL}/entries/posts/new`);

    await expect(page.getByRole('heading', { name: /Create Post/i })).toBeVisible();
    const titleInput = page.locator('#entry-title-input');
    await expect(titleInput).toHaveValue('');

    // Fill title and verify auto-derived slug preview
    await titleInput.fill('My Autonomous Post');
    const slugInput = page.getByLabel('URL slug');
    await expect(slugInput).toHaveValue('my-autonomous-post');
  });

  test('axe light and dark accessibility clean for Entry Editor', async ({ page }) => {
    for (const theme of ['light', 'dark'] as const) {
      await page.addInitScript((mode) => {
        window.localStorage.setItem('bs-theme', mode);
      }, theme);

      await page.goto(`${ADMIN_URL}/entries/posts/01M5E0583S0DT2SVVPRZREWT01`);
      await page.evaluate((mode) => {
        document.documentElement.classList.toggle('dark', mode === 'dark');
      }, theme);

      await expect(page.getByRole('heading', { name: /Edit Post/i })).toBeVisible();

      const results = await new AxeBuilder({ page })
        .include('[data-testid="entry-editor-page"]')
        .disableRules(['color-contrast'])
        .analyze();

      expect(results.violations).toEqual([]);

      await page.screenshot({
        path: `C:/Users/razzd/.gemini/antigravity/brain/9831b937-edef-4912-a934-39e2b02285b9/entry-editor-1440-${theme}.png`,
      });
    }
  });

  test('mobile viewport (390px) responsive layout with no overflow and screenshot', async ({ page }) => {
    for (const theme of ['light', 'dark'] as const) {
      await page.addInitScript((mode) => {
        window.localStorage.setItem('bs-theme', mode);
      }, theme);

      await page.setViewportSize({ width: 390, height: 844 });
      await page.goto(`${ADMIN_URL}/entries/posts/01M5E0583S0DT2SVVPRZREWT01`);
      await page.evaluate((mode) => {
        document.documentElement.classList.toggle('dark', mode === 'dark');
      }, theme);

      await expect(page.getByRole('heading', { name: /Edit Post/i })).toBeVisible();

      const scrollWidth = await page.evaluate(() => document.documentElement.scrollWidth);
      const clientWidth = await page.evaluate(() => document.documentElement.clientWidth);
      expect(scrollWidth).toBeLessThanOrEqual(clientWidth + 2);

      await page.screenshot({
        path: `C:/Users/razzd/.gemini/antigravity/brain/9831b937-edef-4912-a934-39e2b02285b9/entry-editor-390-${theme}.png`,
      });
    }
  });
});
