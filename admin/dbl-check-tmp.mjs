import { chromium } from 'playwright';
const browser = await chromium.launch();
const context = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1440, height: 800 } });
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
await page.waitForTimeout(4000);
const out = await page.evaluate(() => ({
  url: location.pathname,
  sidebarCount: document.querySelectorAll('nav[aria-label="Main Navigation"]').length,
  skipLinkCount: document.querySelectorAll('a[href="#main-content"]').length,
  puckEditor: !!document.querySelector('[data-testid="puck-page-editor"]'),
  designerHeader: !!document.querySelector('[data-testid="designer-header"]'),
}));
console.log(JSON.stringify(out, null, 1));
await page.screenshot({ path: 'test-results/dbl-layout.png' });
await browser.close();
