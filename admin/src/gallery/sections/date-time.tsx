import { useState } from 'react';
import {
  Calendar,
  DatePicker,
  DateRangePicker,
  DateTimePicker,
  RelativeTime,
  TimeInput,
} from '@bs/ui';
import type { GallerySection } from '../registry';

function PickersShowcase() {
  const [date, setDate] = useState<Date | undefined>(new Date(2026, 9, 18));
  return (
    <div className="flex flex-wrap items-start gap-4">
      <DatePicker value={date} onChange={setDate} aria-label="Publish date" className="w-56" />
      <DatePicker value={undefined} onChange={() => {}} disabled aria-label="Locked date" className="w-56" />
    </div>
  );
}

function RangeShowcase() {
  const [range, setRange] = useState<{ from: Date | undefined; to?: Date | undefined } | undefined>();
  return (
    <DateRangePicker value={range} onChange={setRange} aria-label="Filter period" className="w-72" />
  );
}

function DateTimeShowcase() {
  const [moment, setMoment] = useState<Date | undefined>(new Date(2026, 9, 20, 9, 0));
  return (
    <div className="max-w-md space-y-4">
      <DateTimePicker
        value={moment}
        onChange={setMoment}
        timezoneLabel="Europe/Berlin"
        aria-label="Schedule for"
      />
      <TimeInput value="09:30" onChange={() => {}} aria-label="Standalone time" />
    </div>
  );
}

function CalendarShowcase() {
  const [selected, setSelected] = useState<Date | undefined>(new Date(2026, 9, 18));
  return (
    <Calendar
      mode="single"
      selected={selected}
      onSelect={setSelected}
      defaultMonth={new Date(2026, 9, 1)}
      captionLayout="dropdown"
      animate={false}
    />
  );
}

function RelativeShowcase() {
  const now = Date.now();
  return (
    <div className="space-y-1 text-13">
      <p>
        Created <RelativeTime date={new Date(now - 3 * 60 * 60 * 1000)} />
      </p>
      <p>
        Updated <RelativeTime date={new Date(now - 40 * 1000)} />
      </p>
      <p>
        Scheduled <RelativeTime date={new Date(now + 2 * 24 * 60 * 60 * 1000)} />
      </p>
    </div>
  );
}

export const dateTimeSection: GallerySection = {
  id: 'date-time',
  name: 'Date & time',
  entries: [
    {
      id: 'date-picker',
      name: 'Date Picker',
      description: 'Popover calendar, Today shortcut, clear, min/max; no native date input.',
      render: () => <PickersShowcase />,
      props: [
        { name: 'value / onChange', type: 'Date | undefined', description: 'Controlled selection.' },
        { name: 'minDate / maxDate', type: 'Date', description: 'Outside days disabled.' },
        { name: 'formatLabel', type: '(date: Date) => string', description: 'Trigger label format (default yyyy-MM-dd).' },
      ],
    },
    {
      id: 'date-range-picker',
      name: 'Date Range Picker',
      description: 'Presets apply immediately (today, last 7/30, this month); hand-picked ranges need Apply.',
      render: () => <RangeShowcase />,
      props: [
        { name: 'withPresets', type: 'boolean', defaultValue: 'true', description: 'Shows the preset row.' },
        { name: 'numberOfMonths', type: '1 | 2', defaultValue: '2', description: 'Calendar panes.' },
      ],
    },
    {
      id: 'date-time-picker',
      name: 'Date-Time Picker & Time Input',
      description: 'Composed date + HH:MM with timezone label and Now; standalone TimeInput validates on blur.',
      render: () => <DateTimeShowcase />,
      props: [
        { name: 'timezoneLabel', type: 'string', description: 'Defaults to the browser IANA zone.' },
        { name: 'value / onChange (TimeInput)', type: 'string ("HH:MM") | undefined', description: 'Invalid text never propagates.' },
      ],
    },
    {
      id: 'calendar',
      name: 'Calendar',
      description: 'react-day-picker with month/year dropdowns, keyboard navigation and disabled matchers.',
      render: () => <CalendarShowcase />,
      props: [
        { name: 'mode', type: "'single' | 'range' | 'multiple'", description: 'Selection model.' },
        { name: 'captionLayout', type: "'label' | 'dropdown'", defaultValue: "'label'", description: 'Dropdowns give month/year pickers.' },
      ],
    },
    {
      id: 'relative-time',
      name: 'Relative Time',
      description: '"3 hours ago" that ticks every 30 s; exact timestamp in tooltip and for screen readers.',
      render: () => <RelativeShowcase />,
      props: [
        { name: 'refreshIntervalMs', type: 'number', defaultValue: '30000', description: 'Re-render interval.' },
        { name: 'formatExact', type: '(date: Date) => string', description: 'Override the tooltip timestamp format.' },
      ],
    },
  ],
};
