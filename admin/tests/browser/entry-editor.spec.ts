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
      } else if (['PUT', 'PATCH'].includes(route.request().method())) {
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
    await expect(page.getByText('v2', { exact: true })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Update' })).toBeVisible();

    // Verify SEO Panel & SERP Preview
    await expect(page.getByText('SEO & Discoverability')).toBeVisible();
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

  test('maintains focus when toggling marks mid-sentence and saves bold mark without losing text', async ({ page }) => {
    let capturedBodyPayload: any = null;
    // Unroute previous route handler and register dynamic PUT listener
    await page.unroute('**/api/v1/collections/posts/entries/01M5E0583S0DT2SVVPRZREWT01');
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
      } else if (['PUT', 'PATCH'].includes(route.request().method())) {
        const json = JSON.parse(route.request().postData() || '{}');
        capturedBodyPayload = json.body;
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({
            id: '01M5E0583S0DT2SVVPRZREWT01',
            title: json.title,
            status: json.status,
            version: 3,
          }),
        });
      }
    });

    await page.goto(`${ADMIN_URL}/entries/posts/01M5E0583S0DT2SVVPRZREWT01`);
    await expect(page.getByRole('heading', { name: /Edit Post/i })).toBeVisible();

    const editorLocator = page.locator('.tiptap');
    await editorLocator.click();
    // Select all existing text and delete
    await page.keyboard.press('ControlOrMeta+A');
    await page.keyboard.press('Backspace');

    // Type initial phrase
    await page.keyboard.type('Second paragraph with ');

    // Click Bold toolbar button
    const boldButton = page.getByRole('button', { name: 'Bold' });
    await boldButton.click();

    // Type bold text
    await page.keyboard.type('bold text');

    // Click Bold toolbar button again to toggle off
    await boldButton.click();

    // Type remaining phrase
    await page.keyboard.type(' and a <script>alert(1)</script> literal.');

    // Save changes using top bar button
    await page.getByRole('button', { name: 'Save Draft' }).first().click();

    // Verify PUT request payload contains bold marks and all text intact
    await expect.poll(() => capturedBodyPayload).toBeTruthy();
    const paragraphs = capturedBodyPayload.content.filter((n: any) => n.type === 'paragraph');
    const firstP = paragraphs[0];
    expect(firstP).toBeTruthy();

    const boldNode = firstP.content.find((n: any) => n.text === 'bold text');
    expect(boldNode).toBeTruthy();
    expect(boldNode.marks).toEqual([{ type: 'bold' }]);

    const fullText = firstP.content.map((n: any) => n.text).join('');
    expect(fullText).toBe('Second paragraph with bold text and a <script>alert(1)</script> literal.');
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
        path: `test-results/entry-editor-1440-${theme}-existing.png`,
      });

      // Capture screenshot of SEO panel scrolled into view
      const seoCard = page.locator('#seo-meta-title');
      await seoCard.scrollIntoViewIfNeeded();
      await page.screenshot({
        path: `test-results/entry-editor-1440-${theme}-seo.png`,
      });

      // Navigate to new post to capture clean editor
      await page.goto(`${ADMIN_URL}/entries/posts/new`);
      await page.evaluate((mode) => {
        document.documentElement.classList.toggle('dark', mode === 'dark');
      }, theme);
      await expect(page.getByRole('heading', { name: /Create Post/i })).toBeVisible();
      await page.screenshot({
        path: `test-results/entry-editor-1440-${theme}-new.png`,
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
        path: `test-results/entry-editor-390-${theme}.png`,
      });
    }
  });
});

/**
 * Page Editor suite: pages show a read-only preview of the saved Puck
 * `content` field plus a Customize action that opens the full-screen designer
 * route (the only place that edits/saves content). The entry form never sends
 * `content`, so it cannot clobber designer saves with stale data.
 */
test.describe('Page Editor Screen Suite (Puck content field)', () => {
  const PAGE_ENTRY = {
    id: '01M5E0583S0DPAGES00000001',
    slug: 'home',
    title: 'Home',
    status: 'published',
    version: 2,
    excerpt: 'The home landing page.',
    seo: { title: 'Home | BrandSewa', description: 'Homepage built with the visual editor.' },
    template: 'default',
    summary: 'Landing page',
    show_in_nav: true,
    nav_label: 'Home',
    content: {
      root: {},
      content: [
        {
          type: 'Hero',
          props: {
            id: 'Hero-1',
            heading: 'Puck Hero Heading',
            text: 'Puck hero subtext',
            ctaLabel: 'Learn more',
            ctaUrl: 'https://example.com',
          },
        },
        {
          type: 'RichText',
          props: { id: 'RichText-1', html: '<p>Puck rich paragraph</p>' },
        },
      ],
    },
  };

  let capturedPagePayload: any = null;

  test.beforeEach(async ({ page }) => {
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

    await page.route('**/api/v1/collections/pages', async (route) => {
      if (route.request().method() === 'GET') {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify(fixturesData.collections_pages),
        });
      }
    });

    await page.route('**/api/v1/collections/pages/entries/01M5E0583S0DPAGES00000001', async (route) => {
      if (route.request().method() === 'GET') {
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify(PAGE_ENTRY),
        });
      } else if (['PUT', 'PATCH'].includes(route.request().method())) {
        const json = JSON.parse(route.request().postData() || '{}');
        capturedPagePayload = json;
        await route.fulfill({
          status: 200,
          contentType: 'application/json',
          body: JSON.stringify({ id: PAGE_ENTRY.id, title: json.title, status: json.status, version: 3 }),
        });
      }
    });
  });

  test('renders the saved page preview and keeps the entry form hands off the content field', async ({ page }) => {
    await page.goto(`${ADMIN_URL}/entries/pages/01M5E0583S0DPAGES00000001`);

    await expect(page.getByRole('heading', { name: /Edit Page/i })).toBeVisible();

    // The entry editor shows a read-only preview of the saved content plus a
    // Customize action; the interactive editor lives on the designer route.
    const preview = page.locator('[data-testid="puck-content-preview"]');
    await expect(preview).toBeVisible();
    await expect(preview.locator('.bs-hero__heading')).toHaveText('Puck Hero Heading');
    await expect(preview.getByTestId('puck-customize-button')).toBeVisible();

    // Saving the entry form must NOT send `content` — the designer route owns
    // that field, so the form can never clobber designer saves with stale data.
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect.poll(() => capturedPagePayload).toBeTruthy();

    expect(capturedPagePayload.title).toBe('Home');
    expect(capturedPagePayload.content).toBeUndefined();
    // The legacy field must not be sent either.
    expect(capturedPagePayload.blocks).toBeUndefined();
  });

  test('Customize opens the full-screen designer and Exit returns to the page', async ({ page }) => {
    await page.goto(`${ADMIN_URL}/entries/pages/01M5E0583S0DPAGES00000001`);
    await expect(page.getByRole('heading', { name: /Edit Page/i })).toBeVisible();

    await page.getByTestId('puck-customize-button').click();

    // The designer route is chrome-less with its own toolbar.
    await expect(page).toHaveURL(new RegExp('/design/pages/01M5E0583S0DPAGES00000001$'));
    const header = page.getByTestId('designer-header');
    await expect(header).toBeVisible();
    await expect(header.getByText('Home')).toBeVisible();
    await expect(header.getByTestId('designer-save-draft')).toBeVisible();
    await expect(header.getByTestId('designer-publish')).toBeVisible();

    // Exit returns to the entry editor.
    await header.getByTestId('designer-exit').click();
    await expect(page).toHaveURL(new RegExp('/entries/pages/01M5E0583S0DPAGES00000001$'));
    await expect(page.getByRole('heading', { name: /Edit Page/i })).toBeVisible();
  });

  test('new page shows the empty state and the create payload carries no content field', async ({ page }) => {
    await page.route('**/api/v1/collections/pages/entries', async (route) => {
      if (route.request().method() === 'POST') {
        capturedPagePayload = JSON.parse(route.request().postData() || '{}');
        await route.fulfill({
          status: 201,
          contentType: 'application/json',
          body: JSON.stringify({ id: 'new-page-id', title: capturedPagePayload.title, status: 'draft', version: 1 }),
        });
      }
    });

    await page.goto(`${ADMIN_URL}/entries/pages/new`);
    await expect(page.getByRole('heading', { name: /Create Page/i })).toBeVisible();
    await expect(page.locator('[data-testid="puck-preview-empty"]')).toBeVisible();
    await expect(page.getByTestId('puck-customize-button')).toBeDisabled();

    await page.locator('#entry-title-input').fill('Fresh Page');
    await page.getByRole('button', { name: 'Save', exact: true }).click();
    await expect.poll(() => capturedPagePayload).toBeTruthy();

    expect(capturedPagePayload.title).toBe('Fresh Page');
    expect(capturedPagePayload.content).toBeUndefined();
  });
});
