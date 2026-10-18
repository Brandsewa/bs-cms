import { cn } from '../lib/utils';
import { useI18n } from '../i18n';

export interface FieldErrorEntry {
  /** Field name path in the form (react-hook-form name). */
  name: string;
  /** Human label used in the summary text. */
  label: string;
  message: string;
}

export interface FormErrorSummaryProps {
  errors: FieldErrorEntry[];
  /** Focus the first invalid control; default focuses by `bs-field-{name}` id. */
  onFocusField?: (name: string) => void;
  className?: string;
}

function defaultFocusField(name: string): void {
  document.getElementById(`bs-field-${name}`)?.focus();
  document.getElementById(`bs-field-${name}`)?.scrollIntoView({ block: 'center' });
}

/**
 * Form-level error summary for long forms (docs/02-ui-spec.md 3.2): lists
 * every invalid field at the top; each entry is a button that focuses the
 * field. Announced via role=alert.
 */
export function FormErrorSummary({ errors, onFocusField, className }: FormErrorSummaryProps) {
  const { t } = useI18n();
  if (errors.length === 0) return null;

  const focus = (name: string): void => {
    if (onFocusField) onFocusField(name);
    else defaultFocusField(name);
  };

  return (
    <div
      role="alert"
      aria-live="assertive"
      className={cn(
        'rounded-md border border-destructive/50 bg-destructive/10 p-3 text-13',
        className
      )}
    >
      <p className="font-medium text-destructive">
        {errors.length === 1
          ? t('form.errorSummary', { count: errors.length })
          : t('form.errorSummaryPlural', { count: errors.length })}
      </p>
      <ul className="mt-1.5 list-inside list-disc space-y-1">
        {errors.map((entry) => (
          <li key={entry.name}>
            <button
              type="button"
              onClick={() => focus(entry.name)}
              className="text-destructive underline-offset-2 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
              {entry.label}: {entry.message}
            </button>
          </li>
        ))}
      </ul>
    </div>
  );
}

/**
 * Flattens react-hook-form's nested error object into summary entries.
 * `labels` maps field names to human labels; nested arrays keep index paths
 * (`items.0.title`).
 */
export function collectErrors(
  errors: unknown,
  labels: Record<string, string>,
  prefix = ''
): FieldErrorEntry[] {
  const entries: FieldErrorEntry[] = [];
  if (typeof errors !== 'object' || errors === null) return entries;
  for (const [key, value] of Object.entries(errors as Record<string, unknown>)) {
    const name = prefix ? `${prefix}.${key}` : key;
    if (typeof value === 'object' && value !== null) {
      const record = value as Record<string, unknown>;
      if (typeof record.message === 'string' && typeof record.type !== 'undefined') {
        entries.push({
          name,
          label: labels[name] ?? name,
          message: record.message,
        });
      } else {
        entries.push(...collectErrors(value, labels, name));
      }
    }
  }
  return entries;
}
