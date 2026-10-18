import { format } from 'date-fns';
import { Calendar as CalendarIcon, Clock } from 'lucide-react';
import { useState } from 'react';
import { cn } from '../lib/utils';
import { useI18n } from '../i18n';
import { Button } from '../components/button';
import { Calendar } from './calendar';
import { minMaxMatcher } from './matchers';
import { TimeInput } from './time-input';
import { Popover, PopoverContent, PopoverTrigger } from '../components/popover';

export interface DateTimePickerProps {
  /** Full date+time value in the browser's local timezone. */
  value: Date | undefined;
  onChange: (value: Date | undefined) => void;
  placeholder?: string;
  disabled?: boolean;
  minDate?: Date;
  maxDate?: Date;
  /**
   * Label for the timezone of the value. Defaults to the browser's IANA zone
   * (docs/02-ui-spec.md 3.6: date-time picker with timezone label).
   */
  timezoneLabel?: string;
  className?: string;
  'aria-label'?: string;
}

function browserTimezone(): string {
  try {
    return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
  } catch {
    return 'UTC';
  }
}

/**
 * Date-time picker: calendar for the date plus an "HH:MM" time input, a
 * timezone label and a "Now" shortcut (docs/02-ui-spec.md 3.6). Scheduling
 * in the entry editor uses this component.
 */
export function DateTimePicker({
  value,
  onChange,
  placeholder,
  disabled = false,
  minDate,
  maxDate,
  timezoneLabel,
  className,
  'aria-label': ariaLabel,
}: DateTimePickerProps) {
  const { t } = useI18n();
  const [open, setOpen] = useState(false);
  const timeLabel = timezoneLabel ?? browserTimezone();

  const setTime = (time: string | undefined, base: Date | undefined = value) => {
    if (!time) return;
    const [hours, minutes] = time.split(':').map((part) => Number.parseInt(part, 10));
    const next = base ? new Date(base) : new Date();
    next.setHours(hours ?? 0, minutes ?? 0, 0, 0);
    onChange(next);
  };

  const timeValue = value ? format(value, 'HH:mm') : undefined;

  return (
    <div className={cn('flex w-full flex-col gap-1', className)}>
      <div className="flex items-center gap-1">
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
              <span className="truncate">
                {value
                  ? `${format(value, 'yyyy-MM-dd')} ${format(value, 'HH:mm')}`
                  : (placeholder ?? t('dateTime.pickDateTime'))}
              </span>
              <CalendarIcon className="h-4 w-4 shrink-0 opacity-50" aria-hidden="true" />
            </button>
          </PopoverTrigger>
          <PopoverContent align="start" className="w-auto p-0" aria-label={ariaLabel}>
            <Calendar
              mode="single"
              selected={value}
              onSelect={(selected) => {
                if (selected) {
                  setTime(timeValue ?? '09:00', selected);
                } else {
                  onChange(undefined);
                }
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
                  onChange(new Date());
                  setOpen(false);
                }}
              >
                {t('dateTime.now')}
              </Button>
            </div>
          </PopoverContent>
        </Popover>
        <span className="flex items-center gap-1 text-muted-foreground">
          <Clock className="h-4 w-4" aria-hidden="true" />
          <TimeInput
            value={timeValue}
            onChange={(time) => setTime(time)}
            disabled={disabled}
            aria-label={`${ariaLabel ?? t('dateTime.pickTime')} (${timeLabel})`}
          />
        </span>
      </div>
      <p className="text-xs text-muted-foreground">{t('dateTime.timezone', { tz: timeLabel })}</p>
    </div>
  );
}
