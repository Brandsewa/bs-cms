import { useCallback, useEffect, useState } from 'react';
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

export interface UseDirtyGuardOptions {
  /** True while the form has unsaved changes. */
  when: boolean;
}

export interface DirtyGuardDialogStrings {
  title?: string;
  body?: string;
  confirm?: string;
  cancel?: string;
}

export interface DirtyGuard {
  /**
   * Ask the user before leaving: resolves true when leaving is confirmed.
   * No dialog is shown when the guard is inactive.
   */
  confirmLeave: () => Promise<boolean>;
  /** Render next to the app root; driven by confirmLeave. */
  dialog: React.JSX.Element;
}

/**
 * Unsaved-changes guard (docs/02-ui-spec.md 3.2 "dirty state"):
 * - warns on browser tab close / reload via beforeunload;
 * - exposes confirmLeave() for route blockers, sheet and dialog close
 *   handlers, resolving once the user chooses.
 */
export function useDirtyGuard({ when }: UseDirtyGuardOptions): DirtyGuard {
  const { t } = useI18n();
  const [pendingResolve, setPendingResolve] = useState<
    ((leave: boolean) => void) | null
  >(null);

  useEffect(() => {
    if (!when) return;
    const handler = (event: BeforeUnloadEvent): void => {
      event.preventDefault();
      // Chrome requires returnValue to be set as well.
      event.returnValue = '';
    };
    window.addEventListener('beforeunload', handler);
    return () => window.removeEventListener('beforeunload', handler);
  }, [when]);

  const confirmLeave = useCallback((): Promise<boolean> => {
    if (!when) return Promise.resolve(true);
    return new Promise((resolve) => {
      setPendingResolve(() => resolve);
    });
  }, [when]);

  const settle = (leave: boolean): void => {
    pendingResolve?.(leave);
    setPendingResolve(null);
  };

  const dialog = (
    <AlertDialog
      open={pendingResolve !== null}
      onOpenChange={(open) => {
        if (!open) settle(false);
      }}
    >
      <AlertDialogContent>
        <AlertDialogTitle>{t('form.unsavedTitle')}</AlertDialogTitle>
        <AlertDialogDescription>{t('form.unsavedBody')}</AlertDialogDescription>
        <AlertDialogFooter>
          <AlertDialogCancel onClick={() => settle(false)}>
            {t('form.keepEditing')}
          </AlertDialogCancel>
          <AlertDialogAction onClick={() => settle(true)}>
            {t('form.discard')}
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  );

  return { confirmLeave, dialog };
}
