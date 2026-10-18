import { act } from 'react';
import { createRoot } from 'react-dom/client';
import { describe, expect, it } from 'vitest';
import { I18nProvider, ThemeProvider } from '@bs/ui';
import type { ReactNode } from 'react';
import { formatViolations, runAxe } from '../../../packages/ui/test/axe';
import { sections } from './registry';
import { ThemeScope } from './ThemeScope';

/**
 * Gallery axe acceptance suite (implementation/phase-05 5.6): every gallery
 * entry is rendered in light and dark and must produce zero serious or
 * critical violations. Moderate/minor findings are logged but do not fail CI.
 */

type Violation = Awaited<ReturnType<typeof runAxe>>['violations'][number];

function mount(content: ReactNode): HTMLElement {
  const container = document.createElement('div');
  document.body.appendChild(container);
  act(() => {
    createRoot(container).render(
      <ThemeProvider>
        <I18nProvider>{content}</I18nProvider>
      </ThemeProvider>
    );
  });
  return container;
}

describe('gallery axe acceptance', () => {
  const entries = sections.flatMap((section) =>
    section.entries.map((entry) => ({ section: section.name, entry }))
  );

  it.each(entries)(
    '$entry.name (light + dark) has no serious/critical axe violations',
    async ({ entry }) => {
      const lightRoot = mount(<ThemeScope theme="light">{entry.render()}</ThemeScope>);
      const darkRoot = mount(<ThemeScope theme="dark">{entry.render()}</ThemeScope>);

      try {
        // axe-core does not support concurrent runs; serialize per theme scope.
        const light = await runAxe(lightRoot);
        const dark = await runAxe(darkRoot);
        const all: Violation[] = [...light.violations, ...dark.violations];
        const blocking = all.filter(
          (violation) => violation.impact === 'serious' || violation.impact === 'critical'
        );
        const advisory = all.filter(
          (violation) => violation.impact !== 'serious' && violation.impact !== 'critical'
        );
        if (advisory.length > 0) {
          console.warn(
            `[gallery axe] advisory findings for "${entry.name}":\n${formatViolations({
              violations: advisory,
            })}`
          );
        }
        expect(blocking, formatViolations({ violations: blocking })).toEqual([]);
      } finally {
        lightRoot.remove();
        darkRoot.remove();
      }
    }
  );
});
