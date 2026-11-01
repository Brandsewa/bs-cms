import { chromium } from 'playwright';
const browser = await chromium.launch();
const context = await browser.newContext({ ignoreHTTPSErrors: true, viewport: { width: 1440, height: 800 } });
await context.addInitScript(() => localStorage.setItem('bs-theme', 'dark'));
const page = await context.newPage();
await page.goto('http://localhost:5173/admin/', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(2000);
if (page.url().includes('login')) {
  await page.getByLabel(/email/i).fill('admin_release@example.com');
  await page.getByLabel(/password/i).fill('AdminPass2026!Secure');
  await page.getByRole('button', { name: /sign in/i }).click();
  await page.waitForURL(/\/admin\/?$/, { timeout: 20000 });
}
await page.goto('http://localhost:5173/admin/entries/pages/01M69FAFTMBBA6BN32ETJ1WZTZ', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(3500);
await page.screenshot({ path: 'test-results/page-editor-final.png', fullPage: true });
await browser.close();
console.log('done');
