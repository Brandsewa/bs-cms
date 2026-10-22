import type { Page } from '@playwright/test';

/** Mark the first-run welcome dialog as already seen so it does not cover the screen under test. */
export async function suppressWelcome(page: Page): Promise<void> {
  await page.addInitScript(() => {
    const original = Storage.prototype.getItem;
    Storage.prototype.getItem = function (key: string): string | null {
      if (key.startsWith('bs_welcome_seen_')) return 'true';
      return original.call(this, key);
    };
  });
}
