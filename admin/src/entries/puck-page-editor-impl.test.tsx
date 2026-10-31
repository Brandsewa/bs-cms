import { act, type ReactElement } from 'react';
import { createRoot } from 'react-dom/client';
import { describe, expect, it, vi } from 'vitest';
import { PuckPageEditorImpl } from './puck-page-editor-impl';

/**
 * Read-only fallback test: with `disabled`, the impl renders a static
 * <Render> preview of the sanitized page (no interactive editor chrome).
 * This exercises the real @bs/page-builder components in jsdom; the
 * interactive <Puck> canvas itself is covered by Playwright.
 */
describe('PuckPageEditorImpl read-only fallback', () => {
  it('renders the sanitized page statically when disabled', async () => {
    const { container, root } = await renderEditor(
      <PuckPageEditorImpl
        value={{
          content: [
            {
              type: 'Hero',
              props: { heading: 'Static Preview Hero', text: '', ctaLabel: '', ctaUrl: '' },
            },
            { type: 'NotARealBlock', props: { junk: true } },
          ],
        }}
        onChange={vi.fn()}
        disabled
      />
    );

    const readout = container.querySelector('[data-testid="puck-page-readout"]');
    expect(readout).not.toBeNull();
    expect(container.textContent).toContain('Static Preview Hero');
    // Unknown types are dropped by sanitizePuckData; the editor chrome never mounts.
    expect(container.textContent).not.toContain('NotARealBlock');
    expect(container.querySelector('[data-testid="puck-page-editor"]')).toBeNull();

    await act(async () => {
      root.unmount();
    });
  });
});

async function renderEditor(ui: ReactElement) {
  const container = document.createElement('div');
  document.body.appendChild(container);
  const root = createRoot(container);
  await act(async () => {
    root.render(ui);
  });
  return { container, root };
}
