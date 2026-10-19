import { expect, test } from '@playwright/test';

/**
 * Deep-link boot test (M1 review defect 3): the PHP SPA fallback serves
 * index.html for any /admin/* path, so relative asset URLs resolved against
 * the deep path and 404'd. The build now uses base '/admin/'; this test
 * loads a deep link directly and asserts the app boots with zero failed
 * requests. It runs against the built bundle served by scripts/serve-dist.mjs.
 */
test('boots the app from a deep link with no failed asset requests', async ({ page }) => {
  const failedRequests: string[] = [];
  const badResponses: string[] = [];

  page.on('requestfailed', (request) => {
    failedRequests.push(`${request.url()} — ${request.failure()?.errorText}`);
  });
  page.on('response', (response) => {
    if (response.status() >= 400) badResponses.push(`${response.status()} ${response.url()}`);
  });
  const pageErrors: string[] = [];
  page.on('pageerror', (error) => pageErrors.push(String(error)));

  await page.goto('http://127.0.0.1:4173/admin/a/b/c');
  // The app must boot; an unknown path renders the router's not-found view
  // (the real NotFound state component arrives with the M2 layout work).
  await expect(page.locator('#root > *').first()).toBeVisible({ timeout: 15_000 });

  // The built index.html must reference base-absolute assets.
  const sources = await page.evaluate(() =>
    Array.from(document.querySelectorAll('script[src], link[href]')).map((node) =>
      node.getAttribute('src') ?? node.getAttribute('href')
    )
  );
  for (const source of sources) {
    if (source && (source.endsWith('.js') || source.endsWith('.css'))) {
      expect(source.startsWith('/admin/'), `asset ${source} must sit under /admin/`).toBe(true);
    }
  }

  expect(failedRequests, `failed requests: ${failedRequests.join('; ')}`).toEqual([]);
  expect(badResponses, `4xx/5xx responses: ${badResponses.join('; ')}`).toEqual([]);
  expect(pageErrors, `page errors: ${pageErrors.join('; ')}`).toEqual([]);
});

test('serves the external theme bootstrap from the build base', async ({ page }) => {
  const responses: Array<{ url: string; status: number }> = [];
  page.on('response', (response) => responses.push({ url: response.url(), status: response.status() }));
  await page.goto('http://127.0.0.1:4173/admin/');
  await expect(page.locator('#root > *').first()).toBeVisible();

  const themeInit = responses.find((response) => response.url.endsWith('theme-init.js'));
  expect(themeInit, 'theme-init.js was requested').toBeTruthy();
  expect(themeInit!.status).toBe(200);
});
