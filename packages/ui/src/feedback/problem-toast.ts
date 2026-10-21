import { toast } from 'sonner';
import { copyToClipboard } from '../lib/utils';
import { errorToast } from '../components/toaster';

/** Minimal ProblemDetails interface matching RFC 9457 */
export interface ProblemDetailsLike {
  type?: string;
  title: string;
  status?: number;
  detail?: string;
  request_id?: string;
  errors?: Record<string, string[]>;
  [key: string]: unknown;
}

export interface ProblemToastOptions {
  fallbackMessage?: string;
  duration?: number;
}

/**
 * Display a user-friendly error toast from an RFC 9457 Problem Details object or unknown error.
 * Includes title/detail, validation summaries if present, and an action button to copy the request_id.
 */
export function problemToast(
  error: unknown,
  options: ProblemToastOptions = {}
): string | number {
  const { fallbackMessage = 'An unexpected error occurred.', duration = Infinity } = options;

  let title = 'Error';
  let message = fallbackMessage;
  let requestId: string | undefined;

  if (error && typeof error === 'object') {
    const p = error as ProblemDetailsLike;
    if (typeof p.title === 'string' && p.title.trim()) {
      title = p.title;
    }
    if (typeof p.detail === 'string' && p.detail.trim()) {
      message = p.detail;
    } else if (typeof (error as Error).message === 'string' && (error as Error).message.trim()) {
      message = (error as Error).message;
    }

    if (typeof p.request_id === 'string' && p.request_id.trim()) {
      requestId = p.request_id;
    }

    // Append validation summary if field errors exist
    if (p.errors && typeof p.errors === 'object') {
      const fieldEntries = Object.entries(p.errors);
      if (fieldEntries.length > 0) {
        const firstField = fieldEntries[0];
        if (firstField && firstField[1]?.[0]) {
          message = `${message ? message + ' ' : ''}${firstField[0]}: ${firstField[1][0]}`;
        }
      }
    }
  } else if (typeof error === 'string' && error.trim()) {
    message = error;
  }

  const toastMessage = title !== 'Error' && message ? `${title}: ${message}` : message || title;

  if (requestId) {
    return toast.error(toastMessage, {
      duration,
      action: {
        label: 'Copy Request ID',
        onClick: () => {
          void copyToClipboard(requestId as string);
        },
      },
    });
  }

  return errorToast(toastMessage, { duration });
}
