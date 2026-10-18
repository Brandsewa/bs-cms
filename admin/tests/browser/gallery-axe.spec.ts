import { test, expect } from '@playwright/test';
import { AxeBuilder } from '@axe-core/playwright';
import { GALLERY_SECTIONS } from '../../src/gallery/section-list';

/**
 * Real-browser accessibility sweep (M1 review defect 4): jsdom axe cannot
 * compute colour contrast, so the vitest suite excludes that rule. This
 * spec runs every gallery section in LIGHT and DARK in Chromium and fails
 * on any serious/critical violation — including color-contrast.
 */

const GALLERY_URL = 'http://localhost:5199';

for (const theme of ['light', 'dark'] as const) {
  for (const section of GALLERY_SECTIONS) {
    test(`gallery ${section.name} (${theme}) has no serious/critical axe violations`, async ({
      page,
    }) => {
      await page.addInitScript((stored) => {
        window.localStorage.setItem('bs-theme', stored);
      }, theme);
      await page.goto(`${GALLERY_URL}/_gallery?section=${section.id}`);
      await expect(page.locator('nav[aria-label="Gallery sections"]')).toBeVisible();
      await expect(page.locator('[data-theme-scope]').first()).toBeVisible();
      // Theme tokens apply per scope; make sure the document class matches
      // so token-level colour pairs are the ones under test.
      await page.evaluate((mode) => {
        document.documentElement.classList.toggle('dark', mode === 'dark');
      }, theme);

      const results = await new AxeBuilder({ page })
        .withTags(['wcag2a', 'wcag2aa', 'wcag21aa'])
        .analyze();

      const blocking = results.violations.filter(
        (violation) => violation.impact === 'serious' || violation.impact === 'critical'
      );
      const report = blocking
        .map(
          (violation) =>
            `${violation.id} (${violation.impact}): ${violation.help} — ${violation.nodes
              .map((node) => node.target.join(' '))
              .join(', ')}`
        )
        .join('\n');
      expect(report, report || 'blocking violations').toBe('');
    });
  }
}
