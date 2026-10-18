import { Loader2 } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { useI18n } from '../i18n';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogTitle,
} from '../components/alert-dialog';

export interface ConfirmationModalProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  title: string;
  body?: string;
  confirmLabel?: string;
  cancelLabel?: string;
  destructive?: boolean;
  /** Loading while the confirmed action runs; buttons lock meanwhile. */
  loading?: boolean;
  onConfirm: () => void | Promise<void>;
}

/**
 * Confirmation modal for reversible or low-risk confirmations
 * (docs/02-ui-spec.md 3.3). Destructive flows use TypeToConfirmDialog.
 */
export function ConfirmationModal({
  open,
  onOpenChange,
  title,
  body,
  confirmLabel,
  cancelLabel,
  destructive = false,
  loading = false,
  onConfirm,
}: ConfirmationModalProps) {
  const { t } = useI18n();
  return (
    <AlertDialog open={open} onOpenChange={onOpenChange}>
      <AlertDialogContent>
        <AlertDialogTitle>{title}</AlertDialogTitle>
        {body ? <AlertDialogDescription>{body}</AlertDialogDescription> : null}
        <AlertDialogFooter>
          <AlertDialogCancel disabled={loading}>
            {cancelLabel ?? t('confirm.cancel')}
          </AlertDialogCancel>
          <AlertDialogAction
            variant={destructive ? 'destructive' : 'default'}
            disabled={loading}
            onClick={(event) => {
              event.preventDefault();
              void onConfirm();
            }}
          >
            {loading ? <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" /> : null}
            {confirmLabel ?? t('confirm.confirm')}
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  );
}

export interface TypeToConfirmDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  title: string;
  body?: string;
  /** The exact phrase the user must type (typically the entry name). */
  phrase: string;
  confirmLabel?: string;
  onConfirm: () => void | Promise<void>;
  /** Async while confirming; input and buttons lock meanwhile. */
  loading?: boolean;
}

/**
 * Type-to-confirm dialog (docs/02-ui-spec.md 3.1: bulk delete of more than
 * 10 items or published items; 4.9: crawler policy changes). Confirm stays
 * disabled until the typed text matches exactly; Enter submits when valid.
 */
export function TypeToConfirmDialog({
  open,
  onOpenChange,
  title,
  body,
  phrase,
  confirmLabel,
  onConfirm,
  loading = false,
}: TypeToConfirmDialogProps) {
  const { t } = useI18n();
  const [typed, setTyped] = useState('');
  const [attempted, setAttempted] = useState(false);
  const inputRef = useRef<HTMLInputElement>(null);

  const matches = typed === phrase;

  useEffect(() => {
    if (open) {
      setTyped('');
      setAttempted(false);
      inputRef.current?.focus();
    }
  }, [open]);

  return (
    <AlertDialog open={open} onOpenChange={onOpenChange}>
      <AlertDialogContent>
        <AlertDialogTitle>{title}</AlertDialogTitle>
        {body ? <AlertDialogDescription>{body}</AlertDialogDescription> : null}
        <label htmlFor="type-to-confirm-input" className="block text-13 text-muted-foreground">
          {t('confirm.typeToConfirmPrompt', { phrase })}
        </label>
        <input
          id="type-to-confirm-input"
          ref={inputRef}
          type="text"
          value={typed}
          disabled={loading}
          autoComplete="off"
          aria-invalid={attempted && !matches ? true : undefined}
          onChange={(event) => {
            setTyped(event.target.value);
            if (attempted) setAttempted(false);
          }}
          onKeyDown={(event) => {
            if (event.key === 'Enter' && matches && !loading) {
              void onConfirm();
            }
          }}
          className="flex h-8 w-full rounded-md border border-input bg-transparent px-2.5 py-1 text-13 shadow-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring aria-[invalid=true]:border-destructive"
        />
        {attempted && !matches ? (
          <p role="alert" className="text-xs text-destructive">
            {t('confirm.typeToConfirmMismatch')}
          </p>
        ) : null}
        <AlertDialogFooter>
          <AlertDialogCancel disabled={loading}>{t('confirm.cancel')}</AlertDialogCancel>
          <AlertDialogAction
            variant="destructive"
            disabled={!matches || loading}
            onClick={(event) => {
              event.preventDefault();
              void onConfirm();
            }}
          >
            {loading ? <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" /> : null}
            {confirmLabel ?? t('confirm.confirmDestructive')}
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  );
}
