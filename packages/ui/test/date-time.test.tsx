import { describe, expect, it, vi } from 'vitest';
import { fireEvent, renderUI, screen, waitFor, within } from './test-utils';
import { userEvent } from './test-utils';
import { runAxe } from './axe';
import { Calendar } from '../src/date/calendar';
import { DatePicker } from '../src/date/date-picker';
import { DateRangePicker } from '../src/date/date-range-picker';
import { DateTimePicker } from '../src/date/date-time-picker';
import { TimeInput, timePattern } from '../src/date/time-input';
import { RelativeTime } from '../src/date/relative-time';

describe('Calendar', () => {
  it('renders a grid with selectable days and month/year dropdowns', async () => {
    const onSelect = vi.fn();
    const user = userEvent.setup();
    const { container } = renderUI(
      <Calendar mode="single" selected={new Date(2026, 9, 18)} onSelect={onSelect} captionLayout="dropdown" />
    );
    const grid = screen.getByRole('grid');
    expect(grid).toBeInTheDocument();
    // v10 exposes dropdowns as comboboxes when captionLayout="dropdown".
    const dropdowns = screen.getAllByRole('combobox');
    expect(dropdowns.length).toBeGreaterThanOrEqual(2);

    const day18 = screen.getByRole('gridcell', { name: /18/ }).querySelector('button');
    expect(day18).not.toBeNull();
    await user.click(day18!);
    expect(onSelect).toHaveBeenCalled();

    expect(container).toBeInTheDocument();
  });

  it('disables days outside min/max', () => {
    renderUI(
      <Calendar
        mode="single"
        defaultMonth={new Date(2026, 9, 1)}
        disabled={[{ after: new Date(2026, 9, 20) }]}
      />
    );
    const late = screen.getByRole('gridcell', { name: /25/ }).querySelector('button');
    expect(late).toBeDisabled();
  });

  it('passes axe', async () => {
    const { container } = renderUI(
      <Calendar mode="single" defaultMonth={new Date(2026, 9, 1)} captionLayout="dropdown" animate={false} />
    );
    const results = await runAxe(container);
    expect(results.violations).toEqual([]);
  });
});

describe('DatePicker', () => {
  it('opens the calendar, picks a date and shows it formatted', async () => {
    const onChange = vi.fn();
    const user = userEvent.setup();
    renderUI(
      <DatePicker value={undefined} onChange={onChange} aria-label="Publish date" />
    );
    await user.click(screen.getByRole('button', { name: 'Publish date' }));
    const dialog = await screen.findByRole('dialog');
    expect(within(dialog).getByRole('grid')).toBeInTheDocument();

    await user.click(within(dialog).getByRole('button', { name: /18.*October|October.*18/ }));
    expect(onChange).toHaveBeenCalledTimes(1);
    const picked = onChange.mock.calls[0]![0] as Date;
    expect(picked.getDate()).toBe(18);
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
  });

  it('picks today from the shortcut and clears', async () => {
    const onChange = vi.fn();
    const user = userEvent.setup();
    renderUI(
      <DatePicker value={new Date(2026, 0, 15)} onChange={onChange} aria-label="Publish date" />
    );
    await user.click(screen.getByRole('button', { name: 'Publish date' }));
    const dialog = await screen.findByRole('dialog');
    await user.click(within(dialog).getByRole('button', { name: 'Today' }));
    const picked = onChange.mock.calls[0]![0] as Date;
    expect(picked.toDateString()).toBe(new Date().toDateString());

    renderUI(<DatePicker value={new Date(2026, 0, 15)} onChange={onChange} aria-label="Second" />);
    await user.click(screen.getByRole('button', { name: 'Second' }));
    const dialog2 = await screen.findByRole('dialog');
    await user.click(within(dialog2).getByRole('button', { name: 'Clear' }));
    expect(onChange).toHaveBeenLastCalledWith(undefined);
  });

  it('passes axe while open', async () => {
    const user = userEvent.setup();
    renderUI(<DatePicker value={undefined} onChange={vi.fn()} aria-label="Date" />);
    await user.click(screen.getByRole('button', { name: 'Date' }));
    await screen.findByRole('dialog');
    const results = await runAxe(document.body);
    expect(results.violations).toEqual([]);
  });
});

describe('DateRangePicker', () => {
  it('applies a preset range immediately', async () => {
    const onChange = vi.fn();
    const user = userEvent.setup();
    renderUI(
      <DateRangePicker value={undefined} onChange={onChange} aria-label="Filter period" />
    );
    await user.click(screen.getByRole('button', { name: 'Filter period' }));
    const dialog = await screen.findByRole('dialog');
    await user.click(within(dialog).getByRole('button', { name: 'Last 7 days' }));
    expect(onChange).toHaveBeenCalledTimes(1);
    const range = onChange.mock.calls[0]![0] as { from: Date; to: Date };
    const today = new Date();
    expect(range.to.toDateString()).toBe(today.toDateString());
    expect(range.from.toDateString()).toBe(
      new Date(today.getTime() - 6 * 86_400_000).toDateString()
    );
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
  });

  it('requires Apply for a hand-picked custom range', async () => {
    const onChange = vi.fn();
    const user = userEvent.setup();
    renderUI(
      <DateRangePicker value={undefined} onChange={onChange} aria-label="Filter period" />
    );
    await user.click(screen.getByRole('button', { name: 'Filter period' }));
    const dialog = await screen.findByRole('dialog');
    await user.click(within(dialog).getByRole('button', { name: /October 5th/ }));
    expect(onChange).not.toHaveBeenCalled();
    await user.click(within(dialog).getByRole('button', { name: 'Apply' }));
    expect(onChange).toHaveBeenCalledTimes(1);
  });

  it('clears the value from the trigger', async () => {
    const onChange = vi.fn();
    const user = userEvent.setup();
    renderUI(
      <DateRangePicker
        value={{ from: new Date(2026, 9, 1), to: new Date(2026, 9, 10) }}
        onChange={onChange}
        aria-label="Filter period"
      />
    );
    expect(screen.getByRole('button', { name: 'Filter period' })).toHaveTextContent('2026-10-01');
    await user.click(screen.getByRole('button', { name: 'Clear' }));
    expect(onChange).toHaveBeenCalledWith(undefined);
  });

  it('passes axe while open', async () => {
    const user = userEvent.setup();
    renderUI(<DateRangePicker value={undefined} onChange={vi.fn()} aria-label="Range" />);
    await user.click(screen.getByRole('button', { name: 'Range' }));
    await screen.findByRole('dialog');
    const results = await runAxe(document.body);
    expect(results.violations).toEqual([]);
  });
});

describe('DateTimePicker', () => {
  it('composes date and time into one value and shows the timezone', async () => {
    const onChange = vi.fn();
    const user = userEvent.setup();
    renderUI(
      <DateTimePicker
        value={undefined}
        onChange={onChange}
        timezoneLabel="Europe/Berlin"
        aria-label="Schedule"
      />
    );
    expect(screen.getByText(/Europe\/Berlin/)).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Schedule' }));
    const dialog = await screen.findByRole('dialog');
    await user.click(within(dialog).getByRole('button', { name: /October 20th/ }));
    const picked = onChange.mock.calls[0]![0] as Date;
    expect(picked.getDate()).toBe(20);
    expect(picked.getHours()).toBe(9); // default time applied on date pick

    // Completing the time field fires the composed date+time upward.
    const timeField = screen.getByLabelText(/Schedule \(Europe\/Berlin\)/);
    await user.clear(timeField);
    await user.type(timeField, '14:30');
    expect(onChange).toHaveBeenLastCalledWith(expect.any(Date));
    const composed = onChange.mock.calls.at(-1)![0] as Date;
    expect(composed.getHours()).toBe(14);
    expect(composed.getMinutes()).toBe(30);
  });

  it('applies Now and reports invalid time input', async () => {
    const onChange = vi.fn();
    const user = userEvent.setup();
    renderUI(
      <DateTimePicker value={undefined} onChange={onChange} aria-label="Schedule" />
    );
    await user.click(screen.getByRole('button', { name: 'Schedule' }));
    const dialog = await screen.findByRole('dialog');
    await user.click(within(dialog).getByRole('button', { name: 'Now' }));
    expect(onChange).toHaveBeenCalledTimes(1);

    const timeField = screen.getByLabelText(/Schedule \(/);
    await user.type(timeField, '9');
    timeField.blur();
    expect(await screen.findByRole('alert')).toHaveTextContent('Enter a valid time');
  });

  it('passes axe while open', async () => {
    const user = userEvent.setup();
    renderUI(<DateTimePicker value={undefined} onChange={vi.fn()} aria-label="When" />);
    await user.click(screen.getByRole('button', { name: 'When' }));
    await screen.findByRole('dialog');
    const results = await runAxe(document.body);
    expect(results.violations).toEqual([]);
  });
});

describe('TimeInput', () => {
  it('accepts a valid HH:MM and rejects invalid text', async () => {
    const onChange = vi.fn();
    const user = userEvent.setup();
    renderUI(<TimeInput value={undefined} onChange={onChange} aria-label="Time" />);
    const input = screen.getByLabelText('Time');
    await user.type(input, '09:30');
    expect(onChange).toHaveBeenLastCalledWith('09:30');
    expect(input).not.toHaveAttribute('aria-invalid');

    // Non-numeric characters are filtered out, never stored.
    await user.type(input, 'x');
    expect(input).toHaveValue('09:30');

    // An incomplete time marks the field invalid on blur and holds the last
    // good value upstream.
    await user.clear(input);
    await user.type(input, '9');
    fireEvent.blur(input);
    expect(input).toHaveAttribute('aria-invalid', 'true');
    expect(screen.getByRole('alert')).toHaveTextContent('Enter a valid time');
    expect(onChange).toHaveBeenLastCalledWith(undefined);
  });

  it('emits undefined when emptied', async () => {
    const onChange = vi.fn();
    const user = userEvent.setup();
    renderUI(<TimeInput value="08:00" onChange={onChange} aria-label="Time" />);
    const input = screen.getByLabelText('Time');
    await user.clear(input);
    expect(onChange).toHaveBeenCalledWith(undefined);
  });

  it('exports a strict HH:MM pattern', () => {
    expect(timePattern.test('09:30')).toBe(true);
    expect(timePattern.test('24:00')).toBe(false);
    expect(timePattern.test('9:30')).toBe(false);
  });

  it('passes axe with invalid state', async () => {
    const user = userEvent.setup();
    const { container } = renderUI(
      <TimeInput value={undefined} onChange={vi.fn()} aria-label="Time" />
    );
    const input = screen.getByLabelText('Time');
    await user.type(input, '99');
    input.blur();
    const results = await runAxe(container);
    expect(results.violations).toEqual([]);
  });
});

describe('RelativeTime', () => {
  it('renders a relative label with the exact timestamp for screen readers', () => {
    const date = new Date(Date.now() - 3 * 60 * 60 * 1000);
    renderUI(<RelativeTime date={date} />);
    const srOnly = document.querySelector('.sr-only');
    expect(srOnly).toHaveTextContent(/\d{4}/);
    expect(screen.getByText(/hours ago|3 hours ago/)).toBeInTheDocument();
  });

  it('renders future instants and just-now', () => {
    renderUI(<RelativeTime date={new Date(Date.now() + 2 * 60 * 1000)} />);
    expect(screen.getByText(/in 2 minutes|2 minutes/)).toBeInTheDocument();
    renderUI(<RelativeTime date={new Date()} />);
    expect(screen.getAllByText('just now').length).toBeGreaterThan(0);
  });

  it('passes axe', async () => {
    const { container } = renderUI(
      <RelativeTime date={new Date(Date.now() - 60_000)} />
    );
    const results = await runAxe(container);
    expect(results.violations).toEqual([]);
  });
});
