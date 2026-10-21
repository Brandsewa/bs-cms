import { chromium } from 'playwright';
import fs from 'node:fs';
import path from 'node:path';

(async () => {
  const browser = await chromium.launch();
  const artifactDir = 'C:/Users/razzd/.gemini/antigravity/brain/9ccce393-897e-4d38-ab01-afed63094ca4';

  // Desktop context
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await context.newPage();

  const fixture = JSON.parse(fs.readFileSync('admin/tests/fixtures/audit-fixtures.json', 'utf8'));

  const setupRoutes = async (targetPage) => {
    await targetPage.route('**/api/v1/auth/session', async (r) => {
      await r.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          status: 'authenticated',
          user: {
            id: '01M5OWNER1234567890ABCDEF',
            name: 'Alice Owner',
            email: 'owner@example.com',
            roles: [{ id: '1', handle: 'owner', name: 'Owner' }],
          },
        }),
      });
    });

    await targetPage.route('**/api/v1/me/permissions', async (r) => {
      await r.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({
          permissions: [{ subject_type: '*', subject_key: '*', action: '*', effect: 'allow' }],
        }),
      });
    });

    await targetPage.route('**/api/v1/audit*', async (r) => {
      await r.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(fixture),
      });
    });
  };

  await setupRoutes(page);

  await page.goto('http://127.0.0.1:4173/admin/system/audit');
  await page.waitForSelector('table');

  // 1. Desktop Light
  await page.screenshot({ path: path.join(artifactDir, 'audit-log-light-1440px.png'), fullPage: false });

  // Open detail sheet
  await page.locator('tr:has-text("Trashed entry")').first().click();
  await page.waitForSelector('[data-testid="audit-detail-sheet"]');
  await page.screenshot({ path: path.join(artifactDir, 'audit-detail-sheet-light-1440px.png'), fullPage: false });

  // Close sheet
  await page.keyboard.press('Escape');
  await page.waitForTimeout(300);

  // 2. Desktop Dark
  await page.evaluate(() => document.documentElement.classList.add('dark'));
  await page.screenshot({ path: path.join(artifactDir, 'audit-log-dark-1440px.png'), fullPage: false });

  // Open detail sheet dark
  await page.locator('tr:has-text("Trashed entry")').first().click();
  await page.waitForSelector('[data-testid="audit-detail-sheet"]');
  await page.screenshot({ path: path.join(artifactDir, 'audit-detail-sheet-dark-1440px.png'), fullPage: false });
  await context.close();

  // 3. Mobile Context
  const mobileContext = await browser.newContext({ viewport: { width: 390, height: 844 } });
  const mobilePage = await mobileContext.newPage();
  await setupRoutes(mobilePage);

  // Mobile Light
  await mobilePage.goto('http://127.0.0.1:4173/admin/system/audit');
  await mobilePage.waitForSelector('[data-row-id]');
  await mobilePage.screenshot({ path: path.join(artifactDir, 'audit-log-light-390px.png'), fullPage: false });

  // Mobile Dark
  await mobilePage.evaluate(() => document.documentElement.classList.add('dark'));
  await mobilePage.screenshot({ path: path.join(artifactDir, 'audit-log-dark-390px.png'), fullPage: false });

  await mobileContext.close();
  await browser.close();
  console.log('Screenshots captured successfully.');
})();
