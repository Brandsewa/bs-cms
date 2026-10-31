import { lazy, Suspense } from 'react';
import type { ReactElement } from 'react';
import type { PuckData } from '@bs/page-builder';
import { isRecord } from './puck-content';
import { Button, Skeleton } from '@bs/ui';
import { Paintbrush } from 'lucide-react';

/**
 * Lazy wrapper around the Puck runtime. The implementation (Puck runtime +
 * @bs/page-builder + media picker field) lives in `puck-page-editor-impl` and
 * is code-split: the entry-editor chunk of posts and other collections never
 * downloads the page builder, and the entry editor itself only ever mounts it
 * in read-only mode (the interactive editor lives on the designer route).
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
  /** Optional full-screen toolbar rendered in place of Puck's header. */
  header?: () => ReactElement;
  /** Puck canvas height (designer passes 100%). */
  height?: string;
}

export function PuckPageEditor({ value, onChange, disabled = false, header, height }: PuckPageEditorProps) {
  return (
    <Suspense fallback={<PuckEditorSkeleton />}>
      <PuckPageEditorImpl value={value} onChange={onChange} disabled={disabled} header={header} height={height} />
    </Suspense>
  );
}

export interface PuckContentPreviewProps {
  /** Raw `content` value from the entries API (last saved version). */
  value: unknown;
  /** Invoked by the Customize button (the host owns navigation + guards). */
  onCustomize: () => void;
  /** False while the entry has no id yet (unsaved) — customizing is disabled. */
  canCustomize?: boolean;
}

/** A fresh empty Puck page has no content rows. */
function isEmptyContent(value: unknown): boolean {
  if (value === null || value === undefined) return true;
  if (!isRecord(value)) return true;
  const content = value['content'];
  return !Array.isArray(content) || content.length === 0;
}

/** Chunk-skeleton shaped like the preview card. */
function PreviewSkeleton() {
  return (
    <div role="status" aria-busy="true" className="space-y-3" data-testid="puck-preview-loading">
      <span className="sr-only">Loading page preview…</span>
      <Skeleton className="h-[300px] w-full" />
    </div>
  );
}

/**
 * Read-only preview of the page's last saved Puck content with a Customize
 * action that opens the full-screen builder. The interactive editor lives on
 * the designer route — a Puck canvas cannot render legibly inside the entry
 * editor's content column, and keeping the two save flows apart prevents the
 * entry editor from clobbering designer saves with stale data.
 */
export function PuckContentPreview({ value, onCustomize, canCustomize = true }: PuckContentPreviewProps) {
  const empty = isEmptyContent(value);

  return (
    <div className="space-y-3" data-testid="puck-content-preview">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-[11px] text-muted-foreground">
          {empty
            ? 'This page has no visual content yet — open the builder to compose it.'
            : 'Preview of the last saved version. Use the builder to edit the page content.'}
        </p>
        <Button size="sm" onClick={onCustomize} disabled={!canCustomize} data-testid="puck-customize-button">
          <Paintbrush className="mr-1.5 h-3.5 w-3.5" />
          Customize
        </Button>
      </div>

      {empty ? (
        <button
          type="button"
          onClick={onCustomize}
          disabled={!canCustomize}
          className="flex min-h-[220px] w-full flex-col items-center justify-center gap-2 rounded-md border border-dashed border-border bg-surface-100 text-muted-foreground transition-colors hover:bg-surface-200 disabled:cursor-not-allowed disabled:opacity-60"
          data-testid="puck-preview-empty"
        >
          <Paintbrush className="h-6 w-6" />
          <span className="text-sm font-medium text-foreground">Open the visual builder</span>
          <span className="text-xs">
            {canCustomize
              ? 'Drag sections onto the canvas, arrange them, and publish.'
              : 'Save the page first, then customize its content.'}
          </span>
        </button>
      ) : (
        <div className="max-h-[420px] overflow-y-auto rounded-md border border-border bg-background" data-testid="puck-preview-frame">
          <Suspense fallback={<PreviewSkeleton />}>
            <PuckPageEditorImpl value={value} onChange={() => {}} disabled />
          </Suspense>
        </div>
      )}
    </div>
  );
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
