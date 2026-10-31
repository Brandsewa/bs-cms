import { act, type ReactElement } from 'react';
import { createRoot } from 'react-dom/client';
import { describe, expect, it, vi } from 'vitest';
import { PuckPageEditor, PuckContentPreview } from './puck-page-editor';

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

describe('PuckContentPreview (entry editor card)', () => {
  it('renders the saved content read-only and fires onCustomize from the Customize button', async () => {
    const onCustomize = vi.fn();
    const { container, root } = await renderEditor(
      <PuckContentPreview
        value={{ root: {}, content: [{ type: 'Hero', props: { id: 'Hero-1' } }] }}
        onCustomize={onCustomize}
      />
    );

    const stub = container.querySelector('[data-testid="puck-impl-stub"]');
    expect(stub).not.toBeNull();
    expect(stub?.getAttribute('data-disabled')).toBe('true');

    const button = container.querySelector<HTMLButtonElement>('[data-testid="puck-customize-button"]');
    expect(button).not.toBeNull();
    expect(button?.disabled).toBe(false);
    await act(async () => {
      button?.click();
    });
    expect(onCustomize).toHaveBeenCalledTimes(1);

    await act(async () => {
      root.unmount();
    });
  });

  it('shows the empty state for a page without content and disables customizing unsaved entries', async () => {
    const onCustomize = vi.fn();
    const { container, root } = await renderEditor(
      <PuckContentPreview value={null} onCustomize={onCustomize} canCustomize={false} />
    );

    expect(container.querySelector('[data-testid="puck-preview-empty"]')).not.toBeNull();
    expect(container.querySelector('[data-testid="puck-preview-frame"]')).toBeNull();

    const button = container.querySelector<HTMLButtonElement>('[data-testid="puck-customize-button"]');
    expect(button?.disabled).toBe(true);
    await act(async () => {
      button?.click();
    });
    expect(onCustomize).not.toHaveBeenCalled();

    await act(async () => {
      root.unmount();
    });
  });
});
