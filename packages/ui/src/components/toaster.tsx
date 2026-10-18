import { Toaster as SonnerToaster, toast } from 'sonner';
import { useTheme } from '../theming';

/**
 * Sonner toasts themed with the BS CMS tokens (docs/02-ui-spec.md 2:
 * success, error, info, with action buttons; errors persist until dismissed).
 * Mount once in the admin shell. Helpers live in feedback/undo-toast.
 */
function Toaster() {
  const { resolvedTheme } = useTheme();
  return (
    <SonnerToaster
      theme={resolvedTheme}
      position="bottom-right"
      closeButton
      toastOptions={{
        classNames: {
          toast:
            'group rounded-md border border-border bg-popover text-popover-foreground shadow-lg',
          description: 'text-muted-foreground',
          actionButton: 'bg-primary text-primary-foreground rounded-sm',
          cancelButton: 'bg-secondary text-secondary-foreground rounded-sm',
        },
      }}
    />
  );
}

/** Errors stay on screen until dismissed (docs/02-ui-spec.md 2). */
function errorToast(message: string, options?: Parameters<typeof toast.error>[1]) {
  return toast.error(message, { duration: Infinity, ...options });
}

function successToast(message: string, options?: Parameters<typeof toast.success>[1]) {
  return toast.success(message, options);
}

function infoToast(message: string, options?: Parameters<typeof toast>[1]) {
  return toast(message, options);
}

export { Toaster, errorToast, infoToast, successToast, toast };
