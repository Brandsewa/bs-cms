import { act } from 'react';
import { createRoot } from 'react-dom/client';
import { describe, expect, it } from 'vitest';
import { I18nProvider, ThemeProvider } from '@bs/ui';
import { sections } from './registry';
import { ThemeScope } from './ThemeScope';

/**
 * DOM-level ban on native controls (docs/02-ui-spec.md 3.2/3.6): no
 * <select>, no native date/time inputs, no <datalist> anywhere in the
 * rendered document — including markup produced by libraries
 * (M1 review defect 1: react-day-picker rendered native month/year selects).
 * The ESLint rule only covers our JSX; this test covers library output.
 */

const FORBIDDEN_SELECTORS = [
  'select',
  'datalist',
  'input[type="date"]',
  'input[type="time"]',
  'input[type="datetime-local"]',
  'input[type="month"]',
  'input[type="week"]',
];

describe('no native select/date inputs in rendered markup', () => {
  const entries = sections.flatMap((section) =>
    section.entries.map((entry) => ({ section: section.name, entry }))
  );

  it.each(entries)(
    '$entry.name (light + dark) renders no native selects or date inputs',
    ({ entry }) => {
      const lightRoot = document.createElement('div');
      const darkRoot = document.createElement('div');
      document.body.appendChild(lightRoot);
      document.body.appendChild(darkRoot);

      try {
        act(() => {
          const light = createRoot(lightRoot);
          const dark = createRoot(darkRoot);
          light.render(
            <ThemeProvider>
              <I18nProvider>
                <ThemeScope theme="light">{entry.render()}</ThemeScope>
              </I18nProvider>
            </ThemeProvider>
          );
          dark.render(
            <ThemeProvider>
              <I18nProvider>
                <ThemeScope theme="dark">{entry.render()}</ThemeScope>
              </I18nProvider>
            </ThemeProvider>
          );
        });

        for (const selector of FORBIDDEN_SELECTORS) {
          expect(
            document.querySelector(selector),
            `"${selector}" must not appear for entry "${entry.name}"`
          ).toBeNull();
        }
      } finally {
        lightRoot.remove();
        darkRoot.remove();
      }
    }
  );
});
