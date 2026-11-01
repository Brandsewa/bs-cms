import { chromium } from 'playwright';
const browser = await chromium.launch();
const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
await context.addInitScript(() => {
  const { origin, localStorage } = window;
  if (origin === 'http://localhost:8581') localStorage.setItem('bs.cmsOrigin', 'https://localhost:8481');
  if (origin.startsWith('http://localhost:5173')) localStorage.setItem('bs.staticPreviewOrigin', 'http://localhost:8581');
});
const page = await context.newPage();
await page.goto('http://localhost:5173/admin/', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(2000);
if (page.url().includes('login')) {
  await page.getByLabel(/email/i).fill('admin_release@example.com');
  await page.getByLabel(/password/i).fill('AdminPass2026!Secure');
  await page.getByRole('button', { name: /sign in/i }).click();
  await page.waitForURL(/\/admin\/?$/, { timeout: 20000 });
}
await page.goto('http://localhost:5173/admin/entries/pages/01M69FAFTMBBA6BN32ETJ1WZTZ/design', { waitUntil: 'domcontentloaded' });
const editor = page.locator('[data-testid="puck-page-editor"]');
await editor.locator('[data-testid="dropzone:root:default-zone"]').waitFor({ timeout: 25000 });
// drag Hero in
const item = page.getByText('Hero', { exact: true }).first();
const itemBox = await item.boundingBox();
const dropBox = await editor.locator('[data-testid="dropzone:root:default-zone"]').boundingBox();
await page.mouse.move(itemBox.x + itemBox.width / 2, itemBox.y + itemBox.height / 2);
await page.mouse.down();
await page.mouse.move(dropBox.x + dropBox.width / 2, dropBox.y + 30, { steps: 12 });
await page.mouse.up();
await editor.locator('.bs-hero').first().waitFor({ timeout: 10000 });
await page.waitForTimeout(1500);
await editor.locator('[data-puck-component^="Hero"]').first().click();
await page.waitForTimeout(1200);
const info = await page.evaluate(() => {
  const labels = [...document.querySelectorAll('label')].filter((l) => l.offsetParent !== null).map((l) => ({ text: l.textContent.slice(0, 40), for_: l.htmlFor || null }));
  const inputs = [...document.querySelectorAll('input, textarea')].filter((i) => i.offsetParent !== null).map((i) => ({ tag: i.tagName, name: i.name || null, title: i.title || null, labelledby: i.getAttribute('aria-labelledby') }));
  return { labels, inputs };
});
console.log(JSON.stringify(info, null, 1));
await page.screenshot({ path: 'test-results/panel-probe.png' });
await browser.close();
