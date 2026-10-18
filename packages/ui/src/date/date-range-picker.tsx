import { format } from 'date-fns';
import { Calendar as CalendarIcon, X } from 'lucide-react';
import { useState } from 'react';
import { cn } from '../lib/utils';
import { useI18n } from '../i18n';
import { Button } from '../components/button';
import { Calendar } from './calendar';
import type { DateRange } from 'react-day-picker';
import { minMaxMatcher } from './matchers';
import { Popover, PopoverContent, PopoverTrigger } from '../components/popover';

export interface DateRangeValue {
  from: Date | undefined;
  to?: Date | undefined;
}

export interface DateRangePickerProps {
  value: DateRangeValue | undefined;
  onChange: (value: DateRangeValue | undefined) => void;
  /** Filter presets (docs/02-ui-spec.md 3.1 Filter Bar date range). */
  withPresets?: boolean;
  placeholder?: string;
  disabled?: boolean;
  minDate?: Date;
  maxDate?: Date;
  numberOfMonths?: 1 | 2;
  className?: string;
  'aria-label'?: string;
}

function startOfDay(date: Date): Date {
  return new Date(date.getFullYear(), date.getMonth(), date.getDate());
}

function addDays(date: Date, days: number): Date {
  const next = new Date(date);
  next.setDate(next.getDate() + days);
  return next;
}

function startOfMonth(date: Date): Date {
  return new Date(date.getFullYear(), date.getMonth(), 1);
}

/**
 * Date range picker with calendar presets (docs/02-ui-spec.md 3.6): today,
 * last 7/30 days, this month and custom selection. "Apply" confirms a custom
 * range so mid-drag selections do not fire onChange.
 */
export function DateRangePicker({
  value,
  onChange,
  withPresets = true,
  placeholder,
  disabled = false,
  minDate,
  maxDate,
  numberOfMonths = 2,
  className,
  'aria-label': ariaLabel,
}: DateRangePickerProps) {
  const { t } = useI18n();
  const [open, setOpen] = useState(false);
  const [draft, setDraft] = useState<DateRangeValue | undefined>(value);

  const label = value?.from
    ? `${format(value.from, 'yyyy-MM-dd')}${value.to ? ` – ${format(value.to, 'yyyy-MM-dd')}` : ''}`
    : (placeholder ?? t('dateTime.pickDateRange'));

  const today = startOfDay(new Date());

  const presets: Array<{ key: string; label: string; range: () => DateRangeValue }> = [
    { key: 'today', label: t('dateTime.presetToday'), range: () => ({ from: today, to: today }) },
    {
      key: 'last7',
      label: t('dateTime.presetLast7'),
      range: () => ({ from: addDays(today, -6), to: today }),
    },
    {
      key: 'last30',
      label: t('dateTime.presetLast30'),
      range: () => ({ from: addDays(today, -29), to: today }),
    },
    {
      key: 'thisMonth',
      label: t('dateTime.presetThisMonth'),
      range: () => ({ from: startOfMonth(today), to: today }),
    },
  ];

  const apply = (range: DateRangeValue | undefined) => {
    onChange(range);
    setOpen(false);
  };

  return (
    <div className={cn('inline-flex w-full items-center gap-1', className)}>
      <Popover
        open={open}
        onOpenChange={(next) => {
          setOpen(next);
          if (next) setDraft(value);
        }}
      >
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
              !value?.from && 'text-muted-foreground'
            )}
          >
            <span className="truncate">{label}</span>
            <CalendarIcon className="h-4 w-4 shrink-0 opacity-50" aria-hidden="true" />
          </button>
        </PopoverTrigger>
      <PopoverContent align="start" className="w-auto p-0" aria-label={ariaLabel}>
        {withPresets ? (
          <div className="flex flex-wrap gap-1 border-b border-border p-2">
            {presets.map((preset) => (
              <Button
                key={preset.key}
                size="sm"
                variant="outline"
                onClick={() => {
                  setDraft(preset.range());
                  apply(preset.range());
                }}
              >
                {preset.label}
              </Button>
            ))}
          </div>
        ) : null}
        <Calendar
          mode="range"
          numberOfMonths={numberOfMonths}
          selected={{ from: draft?.from, to: draft?.to }}
          onSelect={(selected: DateRange | undefined) => {
            setDraft({ from: selected?.from, to: selected?.to });
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
              setDraft(undefined);
              apply(undefined);
            }}
          >
            {t('dateTime.clear')}
          </Button>
          <Button
            size="sm"
            disabled={!draft?.from}
            onClick={() => apply(draft)}
          >
            {t('dateTime.apply')}
          </Button>
        </div>
      </PopoverContent>
      </Popover>
      {value?.from ? (
        <Button
          size="icon"
          variant="ghost"
          aria-label={t('dateTime.clear')}
          disabled={disabled}
          onClick={() => apply(undefined)}
        >
          <X className="h-4 w-4" aria-hidden="true" />
        </Button>
      ) : null}
    </div>
  );
}
