import { lazy, Suspense } from 'react';
import type { PuckData } from '@bs/page-builder';
import { Skeleton } from '@bs/ui';

/**
 * Lazy wrapper for the Puck visual editor. The implementation (Puck runtime +
 * @bs/page-builder + media picker field) lives in `puck-page-editor-impl` and
 * is code-split: this module never imports it statically, so the entry-editor
 * chunk of posts and other collections stays free of the page builder.
 */
const PuckPageEditorImpl = lazy(() =>
  import('./puck-page-editor-impl').then((m) => ({ default: m.PuckPageEditorImpl }))
);

export interface PuckPageEditorProps {
  /** Raw `content` value from the entries API (normalized internally). */
  value: unknown;
  /** Called with sanitized Puck data on every editor change. */
  onChange: (data: PuckData) => void;
  /** No save permission: render a static preview instead of the editor. */
  disabled?: boolean;
}

/** Chunk-skeleton shaped like the editor (chrome sides + canvas). */
function PuckEditorSkeleton() {
  return (
    <div
      role="status"
      aria-busy="true"
      className="min-h-[540px] space-y-3 rounded-md border border-border p-3"
      data-testid="puck-page-editor-loading"
    >
      <span className="sr-only">Loading page editor…</span>
      <Skeleton className="h-8 w-full" />
      <div className="flex gap-3">
        <Skeleton className="h-[60vh] w-1/5" />
        <Skeleton className="h-[60vh] min-h-[440px] flex-1" />
        <Skeleton className="hidden h-[60vh] w-1/5 sm:block" />
      </div>
    </div>
  );
}

export function PuckPageEditor({ value, onChange, disabled = false }: PuckPageEditorProps) {
  return (
    <Suspense fallback={<PuckEditorSkeleton />}>
      <PuckPageEditorImpl value={value} onChange={onChange} disabled={disabled} />
    </Suspense>
  );
}
