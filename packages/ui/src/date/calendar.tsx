import { ChevronDown, ChevronLeft, ChevronRight } from 'lucide-react';
import {
  DayPicker,
  type DayPickerProps,
  type DropdownProps,
} from 'react-day-picker';
import { cn } from '../lib/utils';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '../components/select';

export type CalendarProps = DayPickerProps;

/**
 * Month/year caption dropdowns rendered with the @bs/ui Select (Radix).
 * react-day-picker's default dropdowns are native <select> elements, which
 * the BS CMS spec forbids anywhere in the UI (docs/02-ui-spec.md, and the
 * DOM-level gallery test enforces it for library-rendered markup too).
 * onChange is synthesised as the library expects a select change event.
 */
function CalendarDropdown({ options, value, onChange, disabled, ...props }: DropdownProps) {
  const handleValueChange = (next: string): void => {
    (onChange as ((event: { target: { value: string } }) => void) | undefined)?.({
      target: { value: next },
    });
  };

  return (
    <Select
      value={value !== undefined ? String(value) : undefined}
      onValueChange={handleValueChange}
      disabled={disabled}
    >
      <SelectTrigger aria-label={props['aria-label']} className="h-7 w-auto gap-1 px-2 text-13">
        <SelectValue />
      </SelectTrigger>
      <SelectContent className="max-h-72">
        {(options ?? []).map((option) => (
          <SelectItem key={option.value} value={String(option.value)} disabled={option.disabled}>
            {option.label}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  );
}

/**
 * Calendar primitive on react-day-picker, styled with BS CMS tokens
 * (docs/02-ui-spec.md 3.6): month/year dropdowns, keyboard navigation,
 * min/max via disabled matcher. Full keyboard support and aria labelling
 * come from the library.
 */
function Calendar({ className, classNames, showOutsideDays = true, ...props }: CalendarProps) {
  return (
    <DayPicker
      showOutsideDays={showOutsideDays}
      className={cn('p-3', className)}
      classNames={{
        months: 'relative flex flex-col sm:flex-row gap-4',
        month: 'flex flex-col gap-3',
        nav: 'flex items-center gap-1',
        button_previous: cn(
          'inline-flex h-7 w-7 items-center justify-center rounded-md border border-border bg-transparent text-foreground transition-colors',
          'hover:bg-surface-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring'
        ),
        button_next: cn(
          'inline-flex h-7 w-7 items-center justify-center rounded-md border border-border bg-transparent text-foreground transition-colors',
          'hover:bg-surface-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring'
        ),
        month_caption: 'flex h-7 items-center justify-center text-13 font-medium',
        dropdowns: 'flex items-center justify-center gap-1',
        months_dropdown: 'rounded-md border border-input bg-transparent px-1.5 py-0.5 text-13 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
        years_dropdown: 'rounded-md border border-input bg-transparent px-1.5 py-0.5 text-13 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
        month_grid: 'mt-2 border-collapse',
        weekdays: 'flex',
        weekday: 'w-9 text-xs font-normal text-muted-foreground',
        weeks: 'mt-1 flex flex-col gap-1',
        week: 'flex w-full',
        day: 'relative w-9 h-8 text-center text-13 focus-within:relative focus-within:z-20',
        day_button: cn(
          'inline-flex h-8 w-9 items-center justify-center rounded-md text-13 transition-colors',
          'hover:bg-surface-200 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring'
        ),
        range_start: 'rounded-l-md bg-accent',
        range_end: 'rounded-r-md bg-accent',
        selected: '[&:not(.rdp-outside)]>button:bg-primary [&:not(.rdp-outside)]>button:text-primary-foreground hover:[&:not(.rdp-outside)]>button:bg-primary hover:[&:not(.rdp-outside)]>button:text-primary-foreground',
        today: '[&>button]:font-semibold [&>button]:text-primary',
        outside: 'opacity-40',
        disabled: 'opacity-30',
        hidden: 'invisible',
        ...classNames,
      }}
      components={{
        MonthsDropdown: CalendarDropdown,
        YearsDropdown: CalendarDropdown,
        Chevron: ({ orientation }) =>
          orientation === 'left' ? (
            <ChevronLeft className="h-4 w-4" aria-hidden="true" />
          ) : orientation === 'right' ? (
            <ChevronRight className="h-4 w-4" aria-hidden="true" />
          ) : (
            <ChevronDown className="h-4 w-4" aria-hidden="true" />
          ),
      }}
      {...props}
    />
  );
}

export { Calendar };
