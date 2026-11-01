import { chromium } from 'playwright';
const browser = await chromium.launch();
const context = await browser.newContext({ ignoreHTTPSErrors: true });
const page = await context.newPage();
const errors = [];
page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text().slice(0, 250)); });
page.on('pageerror', (e) => errors.push('pageerror: ' + String(e).slice(0, 300)));
await page.goto('http://localhost:5173/admin/', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(2000);
if (page.url().includes('login')) {
  await page.getByLabel(/email/i).fill('admin_release@example.com');
  await page.getByLabel(/password/i).fill('AdminPass2026!Secure');
  await page.getByRole('button', { name: /sign in/i }).click();
  await page.waitForURL(/\/admin\/?$/, { timeout: 20000 });
}
errors.length = 0;
await page.goto('http://localhost:5173/admin/entries/pages/01M69FAFTMBBA6BN32ETJ1WZTZ/design', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(5000);
const out = await page.evaluate(() => ({
  url: location.pathname,
  root: document.getElementById('root')?.childElementCount,
  text: (document.getElementById('root')?.textContent || '').slice(0, 200),
}));
console.log(JSON.stringify(out, null, 1));
console.log('ERRORS:', errors.slice(0, 5));
await browser.close();
