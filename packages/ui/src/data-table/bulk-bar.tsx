import { Loader2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { cn } from '../lib/utils';
import { useI18n } from '../i18n';
import { Button } from '../components/button';
import { Progress } from '../components/progress';
import { ConfirmationModal, TypeToConfirmDialog } from '../feedback/confirmation';
import { ErrorState } from '../layout/states';
import type { BulkActionOutcome, DataTableBulkAction } from './data-table-types';

export interface BulkBarProps<T> {
  /** Rows the actions will run against (page selection or all matching). */
  selectedRows: T[];
  /** Total number of selected ids (for the count display). */
  selectedCount: number;
  actions: Array<DataTableBulkAction<T>>;
  onClear: () => void;
  /** After a successful non-destructive action: refresh + clear selection. */
  onComplete?: (outcome: BulkActionOutcome<T>) => void;
  softDelete?: { message: string; onUndo: () => void; onExpire: () => void } | null;
  className?: string;
}

/** Row-generic erased for component state; retry casts rows back to T. */
type BulkOutcomeUnknown = { succeeded: unknown[]; failed: Array<{ row: unknown; reason: string }> };

type RunState =
  | { phase: 'idle' }
  | { phase: 'running'; actionId: string; done: number; total: number }
  | { phase: 'confirm'; actionId: string }
  | { phase: 'type-confirm'; actionId: string }
  | { phase: 'partial'; actionId: string; outcome: BulkOutcomeUnknown };

/**
 * Sticky bulk action bar (docs/02-ui-spec.md 3.1): "N selected", per-item
 * progress, partial-failure summary with retry, type-to-confirm for
 * destructive bulk operations, permission-aware actions.
 */
export function BulkBar<T>({
  selectedRows,
  selectedCount,
  actions,
  onClear,
  onComplete,
  className,
}: BulkBarProps<T>) {
  const { t } = useI18n();
  const [state, setState] = useState<RunState>({ phase: 'idle' });
  const [lastRunRows, setLastRunRows] = useState<T[]>([]);
  const mounted = useRef(true);

  useEffect(() => {
    mounted.current = true;
    return () => {
      mounted.current = false;
    };
  }, []);

  if (selectedCount === 0 && state.phase === 'idle') return null;

  const runAction = async (action: DataTableBulkAction<T>, rows: T[]) => {
    setState({ phase: 'running', actionId: action.id, done: 0, total: rows.length });
    // Per-item execution with progress; one request per item keeps partial
    // failure reporting exact (server bulk endpoints batch internally).
    const succeeded: T[] = [];
    const failed: Array<{ row: T; reason: string }> = [];
    for (const [index, row] of rows.entries()) {
      try {
        const outcome = await action.run([row]);
        succeeded.push(...outcome.succeeded);
        failed.push(...outcome.failed);
      } catch (error) {
        failed.push({ row, reason: error instanceof Error ? error.message : String(error) });
      }
      if (mounted.current) {
        setState({ phase: 'running', actionId: action.id, done: index + 1, total: rows.length });
      }
    }
    const outcome: BulkActionOutcome<T> = { succeeded, failed };
    if (!mounted.current) return;
    if (failed.length > 0) {
      setLastRunRows(rows);
      setState({
        phase: 'partial',
        actionId: action.id,
        outcome: outcome as unknown as BulkOutcomeUnknown,
      });
    } else {
      setState({ phase: 'idle' });
      onComplete?.(outcome);
      onClear();
    }
  };

  const beginAction = (action: DataTableBulkAction<T>) => {
    if (action.confirm === 'confirm') {
      setState({ phase: 'confirm', actionId: action.id });
      return;
    }
    if (action.confirm && action.confirm !== 'none') {
      // remaining case after the 'confirm' check above: the type-to-confirm object
      setState({ phase: 'type-confirm', actionId: action.id });
      return;
    }
    void runAction(action, selectedRows);
  };

  const activeAction =
    'actionId' in state ? actions.find((action) => action.id === state.actionId) : undefined;

  const confirmAction = activeAction?.confirm;
  const typeToConfirm =
    confirmAction && confirmAction !== 'none' && confirmAction !== 'confirm'
      ? confirmAction
      : null;

  const running = state.phase === 'running';
  const progressPercent =
    state.phase === 'running' && state.total > 0
      ? Math.round((state.done / state.total) * 100)
      : 0;

  return (
    <>
      <div
        role="toolbar"
        aria-label={t('common.selected', { count: selectedCount })}
        className={cn(
          'sticky bottom-0 z-[var(--z-sticky)] flex flex-wrap items-center gap-2 rounded-lg border border-border bg-card px-3 py-2 shadow-lg',
          className
        )}
      >
        <span className="text-13 font-medium tabular-nums text-foreground">
          {t('common.selected', { count: selectedCount })}
        </span>

        {running ? (
          <span className="flex min-w-48 flex-1 items-center gap-2 text-13 text-muted-foreground">
            <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" />
            {t('dataTable.bulkProgress', { done: state.done, total: state.total })}
            <Progress value={progressPercent} className="flex-1" aria-label={t('dataTable.bulkRunning')} />
          </span>
        ) : (
          <span className="flex flex-wrap items-center gap-1.5">
            {actions
              .filter((action) => action.hidden?.(selectedRows) !== true)
              .map((action) => {
                const Icon = action.icon;
                return (
                  <Button
                    key={action.id}
                    size="sm"
                    variant={action.destructive ? 'destructive' : 'outline'}
                    onClick={() => beginAction(action)}
                  >
                    {Icon ? <Icon aria-hidden="true" /> : null}
                    {action.label}
                  </Button>
                );
              })}
            <Button size="sm" variant="ghost" onClick={onClear}>
              {t('dataTable.clearSelection')}
            </Button>
          </span>
        )}
      </div>

      {state.phase === 'confirm' && activeAction ? (
        <ConfirmationModal
          open
          onOpenChange={(open) => {
            if (!open) setState({ phase: 'idle' });
          }}
          title={activeAction.label}
          body={t('confirm.confirmBody')}
          confirmLabel={activeAction.label}
          destructive={activeAction.destructive}
          onConfirm={() => {
            setState({ phase: 'idle' });
            void runAction(activeAction, selectedRows);
          }}
        />
      ) : null}

      {state.phase === 'type-confirm' && typeToConfirm && activeAction ? (
        <TypeToConfirmDialog
          open
          onOpenChange={(open) => {
            if (!open) setState({ phase: 'idle' });
          }}
          title={activeAction.label}
          body={typeToConfirm.body}
          phrase={typeToConfirm.phrase(selectedRows)}
          onConfirm={() => {
            setState({ phase: 'idle' });
            void runAction(activeAction, selectedRows);
          }}
        />
      ) : null}

      {state.phase === 'partial' ? (
        <div
          role="alertdialog"
          aria-modal="true"
          aria-label={t('dataTable.bulkPartialTitle')}
          className="fixed inset-0 z-[var(--z-modal)] flex items-center justify-center bg-overlay p-4"
        >
          <div className="w-full max-w-lg rounded-lg border border-border bg-card p-6 shadow-lg">
            <h2 className="text-base font-semibold text-foreground">
              {t('dataTable.bulkPartialTitle')}
            </h2>
            {state.phase === 'partial' ? (
              state.outcome.failed.length > 0 && state.outcome.succeeded.length === 0 ? (
                <ErrorState
                  message={state.outcome.failed[0]?.reason}
                  className="mt-4 border-0 bg-transparent px-0 py-4"
                />
              ) : (
                <>
                  <p className="mt-2 text-13 text-muted-foreground">
                    {t('dataTable.bulkPartialBody', {
                      succeeded: state.outcome.succeeded.length,
                      failed: state.outcome.failed.length,
                    })}
                  </p>
                  <ul className="mt-3 max-h-48 space-y-1 overflow-y-auto text-13">
                    {state.outcome.failed.map((failure, index) => (
                      <li
                        key={index}
                        className="flex items-start justify-between gap-2 rounded-sm bg-surface-100 px-2 py-1"
                      >
                        <span className="truncate text-foreground">
                          {'title' in (failure.row as object)
                            ? String((failure.row as { title: unknown }).title)
                            : JSON.stringify(failure.row).slice(0, 60)}
                        </span>
                        <span className="shrink-0 text-destructive-text">{failure.reason}</span>
                      </li>
                    ))}
                  </ul>
                </>
              )
            ) : null}
            <div className="mt-4 flex justify-end gap-2">
              <Button
                variant="outline"
                onClick={() => {
                  setState({ phase: 'idle' });
                  if (state.phase === 'partial') {
                    onComplete?.({
                      succeeded: state.outcome.succeeded as T[],
                      failed: [],
                    });
                  }
                }}
              >
                {t('dataTable.bulkClose')}
              </Button>
              {state.phase === 'partial' && lastRunRows.length > 0 ? (
                <Button
                  onClick={() => {
                    const failedRows = state.outcome.failed.map(
                      (failure) => failure.row as T
                    );
                    const action = actions.find(
                      (candidate) => candidate.id === state.actionId
                    );
                    setState({ phase: 'idle' });
                    if (action) void runAction(action, failedRows);
                  }}
                >
                  {t('dataTable.bulkRetryFailed')}
                </Button>
              ) : null}
            </div>
          </div>
        </div>
      ) : null}
    </>
  );
}
