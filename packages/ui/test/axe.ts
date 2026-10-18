import axe from 'axe-core';

/**
 * Runs axe against a rendered component in jsdom.
 *
 * `color-contrast` is disabled because jsdom has no layout engine and cannot
 * compute rendered text contrast; colour contrast is enforced at the token
 * level (NOTES.md, Spike E: every light/dark token pair was checked with the
 * WCAG relative-luminance formula) and reviewed visually in the gallery.
 * `region` is disabled because gallery fixtures render isolated components
 * outside of landmark structure, which is an artefact of testing, not of the
 * real application shell.
 */
export async function runAxe(container: HTMLElement): Promise<axe.AxeResults> {
  return axe.run(container, {
    resultTypes: ['violations'],
    rules: {
      'color-contrast': { enabled: false },
      region: { enabled: false },
    },
  });
}

export function formatViolations(results: Pick<axe.AxeResults, 'violations'>): string {
  return results.violations
    .map((violation) => {
      const nodes = violation.nodes.map((node) => node.target.join(' ')).join(', ');
      return `${violation.id} (${violation.impact}): ${violation.help} — ${nodes}`;
    })
    .join('\n');
}
