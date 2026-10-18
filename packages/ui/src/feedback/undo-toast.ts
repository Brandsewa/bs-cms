import { toast } from '../components/toaster';
import type { Messages } from '../i18n';
import en from '../i18n/en.json';

/**
 * Minimal catalog lookup for helpers that run outside React (event
 * handlers); mirrors I18nProvider interpolation.
 */
function catalogText(key: string, vars?: Record<string, string | number>): string {
  let value: unknown = en;
  for (const segment of key.split('.')) {
    if (typeof value !== 'object' || value === null) return key;
    value = (value as Record<string, unknown>)[segment];
  }
  let text = typeof value === 'string' ? value : key;
  if (vars) {
    text = text.replace(/\{(\w+)\}/g, (match, name: string) =>
      name in vars ? String(vars[name]) : match
    );
  }
  return text;
}

export interface UndoToastOptions {
  message: string;
  /** Runs when the user clicks Undo (rollback of the optimistic update). */
  onUndo: () => void;
  /** Runs when the toast expires without undo (finalise the mutation). */
  onExpire?: () => void;
  /** Undo window in ms (default 8 s). */
  duration?: number;
  actionLabel?: string;
}

/**
 * Undo toast helper (docs/02-ui-spec.md 1: destructive actions "can be
 * undone where possible (undo toast)"). If the toast expires without undo,
 * onExpire runs so the caller can make the deletion permanent.
 */
export function undoToast({
  message,
  onUndo,
  onExpire,
  duration = 8000,
  actionLabel,
}: UndoToastOptions): string | number {
  let undone = false;
  return toast(message, {
    duration,
    action: {
      label: actionLabel ?? catalogText('toast.undo'),
      onClick: () => {
        undone = true;
        onUndo();
      },
    },
    onDismiss: () => {
      if (!undone) onExpire?.();
    },
    onAutoClose: () => {
      if (!undone) onExpire?.();
    },
  });
}

export type { Messages };
