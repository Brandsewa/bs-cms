import { Plus, X } from 'lucide-react';
import { useId } from 'react';
import { cn } from '../lib/utils';
import { useI18n } from '../i18n';
import { Button } from '../components/button';
import { Input } from '../components/input';

export interface SingleValueArrayFieldProps {
  values: string[];
  onChange: (values: string[]) => void;
  placeholder?: string;
  /** aria-label for each row input (indexed automatically). */
  inputLabel: string;
  addLabel?: string;
  disabled?: boolean;
  className?: string;
}

/**
 * Single-value array field: a list of equal inputs with add/remove
 * (docs/02-ui-spec.md 3.2 field arrays). Usable standalone or through a
 * Controller.
 */
export function SingleValueArrayField({
  values,
  onChange,
  placeholder,
  inputLabel,
  addLabel,
  disabled = false,
  className,
}: SingleValueArrayFieldProps) {
  const { t } = useI18n();
  const baseId = useId();

  const update = (index: number, value: string) => {
    const next = [...values];
    next[index] = value;
    onChange(next);
  };

  return (
    <div className={cn('space-y-2', className)}>
      {values.map((value, index) => (
        <div key={index} className="flex items-center gap-1">
          <Input
            id={`${baseId}-${index}`}
            value={value}
            disabled={disabled}
            placeholder={placeholder}
            aria-label={`${inputLabel} ${index + 1}`}
            onChange={(event) => update(index, event.target.value)}
          />
          <Button
            size="icon-sm"
            variant="ghost"
            disabled={disabled}
            aria-label={`${t('common.remove')} ${index + 1}`}
            onClick={() => onChange(values.filter((_, existing) => existing !== index))}
          >
            <X className="h-4 w-4" aria-hidden="true" />
          </Button>
        </div>
      ))}
      <Button
        type="button"
        size="sm"
        variant="outline"
        disabled={disabled}
        onClick={() => onChange([...values, ''])}
      >
        <Plus className="h-4 w-4" aria-hidden="true" />
        {addLabel ?? t('common.open')}
      </Button>
    </div>
  );
}

export interface KeyValueItem {
  key: string;
  value: string;
}

export interface KeyValueArrayFieldProps {
  items: KeyValueItem[];
  onChange: (items: KeyValueItem[]) => void;
  keyPlaceholder?: string;
  valuePlaceholder?: string;
  addLabel?: string;
  disabled?: boolean;
  className?: string;
  'aria-label'?: string;
}

/**
 * Key/value array field for structured data (meta tags, custom headers,
 * structured-data mappings): rows of key + value with add/remove.
 */
export function KeyValueArrayField({
  items,
  onChange,
  keyPlaceholder,
  valuePlaceholder,
  addLabel,
  disabled = false,
  className,
  'aria-label': ariaLabel,
}: KeyValueArrayFieldProps) {
  const { t } = useI18n();

  return (
    <div className={cn('space-y-2', className)} role="group" aria-label={ariaLabel}>
      {items.map((item, index) => (
        <div key={index} className="flex items-center gap-1">
          <Input
            value={item.key}
            disabled={disabled}
            placeholder={keyPlaceholder}
            aria-label={`${ariaLabel ? `${ariaLabel} ` : ''}key ${index + 1}`}
            onChange={(event) => {
              const next = [...items];
              next[index] = { ...item, key: event.target.value };
              onChange(next);
            }}
            className="w-40"
          />
          <Input
            value={item.value}
            disabled={disabled}
            placeholder={valuePlaceholder}
            aria-label={`${ariaLabel ? `${ariaLabel} ` : ''}value ${index + 1}`}
            onChange={(event) => {
              const next = [...items];
              next[index] = { ...item, value: event.target.value };
              onChange(next);
            }}
          />
          <Button
            size="icon-sm"
            variant="ghost"
            disabled={disabled}
            aria-label={`${t('common.remove')} row ${index + 1}`}
            onClick={() => onChange(items.filter((_, existing) => existing !== index))}
          >
            <X className="h-4 w-4" aria-hidden="true" />
          </Button>
        </div>
      ))}
      <Button
        type="button"
        size="sm"
        variant="outline"
        disabled={disabled}
        onClick={() => onChange([...items, { key: '', value: '' }])}
      >
        <Plus className="h-4 w-4" aria-hidden="true" />
        {addLabel ?? t('common.open')}
      </Button>
    </div>
  );
}

export interface ConditionalFieldProps {
  /** Render children only when the condition holds. */
  show: boolean;
  children: React.ReactNode;
}

/**
 * Conditional field helper: pair with react-hook-form's `watch()` to show
 * fields depending on other values (docs/02-ui-spec.md 3.2 conditional
 * fields). Keeps the declarative intent explicit in screen code.
 */
export function ConditionalField({ show, children }: ConditionalFieldProps) {
  if (!show) return null;
  return <>{children}</>;
}
