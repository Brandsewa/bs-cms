import { act, type ReactElement } from 'react';
import { createRoot } from 'react-dom/client';
import { describe, expect, it, vi } from 'vitest';
import { PuckPageEditor } from './puck-page-editor';

/**
 * Route-level wiring tests for the lazy Puck wrapper. The implementation
 * module is mocked here (the real Puck DnD canvas is not meaningfully
 * testable in jsdom); the read-only render path is covered in
 * puck-page-editor-impl.test.tsx and the adapter logic in
 * puck-content.test.ts.
 */

vi.mock('./puck-page-editor-impl', () => ({
  PuckPageEditorImpl: (props: { value: unknown; disabled?: boolean }) => (
    <div
      data-testid="puck-impl-stub"
      data-disabled={String(props.disabled ?? false)}
      data-value-json={JSON.stringify(props.value ?? null)}
    />
  ),
}));

async function renderEditor(ui: ReactElement) {
  const container = document.createElement('div');
  document.body.appendChild(container);
  const root = createRoot(container);
  await act(async () => {
    root.render(ui);
  });
  return { container, root };
}

const sample = { root: {}, content: [] };

describe('PuckPageEditor lazy wrapper', () => {
  it('resolves the lazy chunk and forwards value, onChange and disabled', async () => {
    const onChange = () => {};
    const { container, root } = await renderEditor(
      <PuckPageEditor value={sample} onChange={onChange} disabled />
    );

    const stub = container.querySelector('[data-testid="puck-impl-stub"]');
    expect(stub).not.toBeNull();
    expect(stub?.getAttribute('data-disabled')).toBe('true');
    expect(JSON.parse(stub?.getAttribute('data-value-json') ?? 'null')).toEqual(sample);

    await act(async () => {
      root.unmount();
    });
  });

  it('shows the skeleton fallback while the chunk is loading', async () => {
    vi.resetModules();
    // Never-resolving module request keeps the Suspense boundary on its
    // fallback, mirroring a slow chunk fetch on the first pages visit.
    vi.doMock('./puck-page-editor-impl', () => new Promise(() => {}));
    const { PuckPageEditor: PendingWrapper } = await import('./puck-page-editor');

    const { container, root } = await renderEditor(
      <PendingWrapper value={sample} onChange={() => {}} />
    );

    const fallback = container.querySelector('[data-testid="puck-page-editor-loading"]');
    expect(fallback).not.toBeNull();
    expect(fallback?.getAttribute('role')).toBe('status');
    expect(fallback?.textContent).toContain('Loading page editor');

    await act(async () => {
      root.unmount();
    });
  });
});
