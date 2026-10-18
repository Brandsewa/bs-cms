import { Check, Copy } from 'lucide-react';
import {
  cloneElement,
  isValidElement,
  useId,
  useState,
  type ReactElement,
  type ReactNode,
} from 'react';
import { cn } from '../lib/utils';
import { useI18n } from '../i18n';

export interface FormItemLayoutProps {
  /** Field label. */
  label: ReactNode;
  /** id of the control, used for htmlFor and error/help wiring. */
  id: string;
  /** Marks the label with the required indicator. */
  required?: boolean;
  /** Additional hint text below the label / above the error. */
  helpText?: ReactNode;
  /** Field-level error message (role=alert, wired via aria-describedby). */
  error?: string;
  /** Character counter, e.g. from a controlled input's length vs max. */
  counter?: { current: number; max: number };
  /** When set, shows a copy button for a read-only value. */
  copyValue?: string;
  /** horizontal (settings pages) or vertical (sheets, entry editor). */
  layout?: 'horizontal' | 'vertical';
  /** The control. A single element gets aria-describedby wired automatically. */
  children: ReactNode;
  className?: string;
}

/**
 * The one layout for every field (docs/02-ui-spec.md 3.2): label, required
 * marker, help text, control, error message, character counter and copy
 * button for read-only values.
 */
export function FormItemLayout({
  label,
  id,
  required = false,
  helpText,
  error,
  counter,
  copyValue,
  layout = 'vertical',
  children,
  className,
}: FormItemLayoutProps) {
  const { t } = useI18n();
  const [copied, setCopied] = useState(false);
  const helpId = useId();
  const errorId = useId();
  const counterId = useId();

  const describedBy = [helpText ? helpId : null, error ? errorId : null, counter ? counterId : null]
    .filter(Boolean)
    .join(' ');

  // Wire help/error/counter ids onto the control for screen readers when the
  // child is a single element (inputs, textareas, …).
  const control =
    isValidElement(children) && describedBy
      ? cloneElement(children as ReactElement<{ 'aria-describedby'?: string }>, {
          'aria-describedby': describedBy,
        })
      : children;

  const copy = async () => {
    try {
      await navigator.clipboard.writeText(copyValue ?? '');
      setCopied(true);
      setTimeout(() => setCopied(false), 1500);
    } catch {
      // Clipboard may be unavailable (permissions, insecure context); the
      // value stays visible so nothing is lost.
    }
  };

  const labelNode = (
    <label htmlFor={id} className="block text-13 font-medium text-foreground">
      {label}
      {required ? (
        <>
          {' '}
          <span aria-hidden="true" className="text-destructive">
            *
          </span>
          <span className="sr-only"> ({t('form.required')})</span>
        </>
      ) : null}
    </label>
  );

  return (
    <div
      className={cn(
        layout === 'horizontal' ? 'flex flex-row items-start gap-4' : 'flex flex-col gap-1',
        className
      )}
    >
      <div
        className={cn(
          layout === 'horizontal' ? 'w-44 shrink-0 pt-1.5' : 'flex flex-col gap-1',
          copyValue !== undefined && layout === 'horizontal' && 'flex items-center gap-1'
        )}
      >
        {labelNode}
        {copyValue !== undefined ? (
          <button
            type="button"
            onClick={() => void copy()}
            aria-label={copied ? t('common.copied') : t('form.copyButtonLabel')}
            className="inline-flex h-6 w-6 items-center justify-center rounded-sm text-muted-foreground hover:bg-surface-200 hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
          >
            {copied ? <Check className="h-3.5 w-3.5" /> : <Copy className="h-3.5 w-3.5" />}
          </button>
        ) : null}
        {helpText ? (
          <p id={helpId} className="text-xs text-muted-foreground">
            {helpText}
          </p>
        ) : null}
      </div>
      <div className="min-w-0 flex-1">
        {control}
        {counter ? (
          <p
            id={counterId}
            aria-live="polite"
            className={cn(
              'mt-1 text-right text-xs',
              counter.current > counter.max ? 'text-destructive' : 'text-muted-foreground'
            )}
          >
            {counter.current > counter.max
              ? t('form.charsOverLimit', { count: counter.current - counter.max, max: counter.max })
              : t('form.charsCount', { count: counter.current, max: counter.max })}
          </p>
        ) : null}
        {error ? (
          <p id={errorId} role="alert" className="mt-1 text-xs text-destructive">
            {error}
          </p>
        ) : null}
      </div>
    </div>
  );
}
