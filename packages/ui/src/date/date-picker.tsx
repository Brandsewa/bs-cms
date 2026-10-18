import { format } from 'date-fns';
import { Calendar as CalendarIcon } from 'lucide-react';
import { useState } from 'react';
import { cn } from '../lib/utils';
import { useI18n } from '../i18n';
import { Button } from '../components/button';
import { Calendar } from './calendar';
import { minMaxMatcher } from './matchers';
import { Popover, PopoverContent, PopoverTrigger } from '../components/popover';

export interface DatePickerProps {
  value: Date | undefined;
  onChange: (value: Date | undefined) => void;
  placeholder?: string;
  disabled?: boolean;
  minDate?: Date;
  maxDate?: Date;
  /** Formats the trigger label; defaults to yyyy-MM-dd. */
  formatLabel?: (date: Date) => string;
  className?: string;
  'aria-label'?: string;
}

/**
 * Single date picker (docs/02-ui-spec.md 3.6): calendar with month/year
 * dropdowns, keyboard navigation, min/max, a Today shortcut and clear.
 * No native date input is used.
 */
export function DatePicker({
  value,
  onChange,
  placeholder,
  disabled = false,
  minDate,
  maxDate,
  formatLabel = (date) => format(date, 'yyyy-MM-dd'),
  className,
  'aria-label': ariaLabel,
}: DatePickerProps) {
  const { t } = useI18n();
  const [open, setOpen] = useState(false);

  return (
    <div className={cn('inline-flex w-full items-center gap-1', className)}>
      <Popover open={open} onOpenChange={setOpen}>
        <PopoverTrigger asChild>
          <button
            type="button"
            aria-haspopup="dialog"
            aria-expanded={open}
            aria-label={ariaLabel}
            disabled={disabled}
            className={cn(
              'flex h-8 min-w-0 flex-1 items-center justify-between gap-2 rounded-md border border-input bg-transparent px-2.5 py-1 text-left text-13 shadow-xs transition-colors',
              'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-1 focus-visible:ring-offset-background',
              'disabled:cursor-not-allowed disabled:opacity-50',
              !value && 'text-muted-foreground'
            )}
          >
            <span className="truncate">{value ? formatLabel(value) : (placeholder ?? t('dateTime.pickDate'))}</span>
            <CalendarIcon className="h-4 w-4 shrink-0 opacity-50" aria-hidden="true" />
          </button>
        </PopoverTrigger>
        <PopoverContent align="start" className="w-auto p-0" aria-label={ariaLabel}>
          <Calendar
            mode="single"
            selected={value}
            onSelect={(selected) => {
              onChange(selected);
              setOpen(false);
            }}
            disabled={minMaxMatcher(minDate, maxDate)}
            captionLayout="dropdown"
            animate={false}
          />
          <div className="flex items-center justify-between gap-2 border-t border-border p-2">
            <Button
              size="sm"
              variant="ghost"
              onClick={() => {
                onChange(undefined);
                setOpen(false);
              }}
            >
              {t('dateTime.clear')}
            </Button>
            <Button
              size="sm"
              variant="outline"
              onClick={() => {
                const today = new Date();
                onChange(new Date(today.getFullYear(), today.getMonth(), today.getDate()));
                setOpen(false);
              }}
            >
              {t('dateTime.today')}
            </Button>
          </div>
        </PopoverContent>
      </Popover>
    </div>
  );
}
