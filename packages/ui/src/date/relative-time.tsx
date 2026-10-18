import { useEffect, useState } from 'react';
import { useI18n } from '../i18n';
import { Tooltip } from '../components/tooltip';

export interface RelativeTimeProps {
  /** Past or future instant to render relative to now. */
  date: Date;
  /** Re-render interval in ms (default 30 s, per docs/02-ui-spec.md 3.1). */
  refreshIntervalMs?: number;
  /** Tooltip date-time format; defaults to a full local timestamp. */
  formatExact?: (date: Date) => string;
  className?: string;
}

type Division = { amount: number; unit: Intl.RelativeTimeFormatUnit };

const DIVISIONS: Division[] = [
  { amount: 60, unit: 'second' },
  { amount: 60, unit: 'minute' },
  { amount: 24, unit: 'hour' },
  { amount: 7, unit: 'day' },
  { amount: 4.34524, unit: 'week' },
  { amount: 12, unit: 'month' },
  { amount: Number.POSITIVE_INFINITY, unit: 'year' },
];

function formatRelative(date: Date, now: Date): string {
  let duration = (date.getTime() - now.getTime()) / 1000;
  for (const division of DIVISIONS) {
    if (Math.abs(duration) < division.amount) {
      const formatter = new Intl.RelativeTimeFormat(undefined, { numeric: 'auto' });
      return formatter.format(Math.round(duration), division.unit);
    }
    duration /= division.amount;
  }
  // Unreachable: the last division has an infinite threshold.
  return '';
}

/**
 * Relative timestamp ("3 hours ago") that ticks periodically and shows the
 * exact local timestamp in a tooltip (docs/02-ui-spec.md 3.1 cell renderer:
 * "date/datetime (relative with exact time tooltip)").
 */
export function RelativeTime({
  date,
  refreshIntervalMs = 30_000,
  formatExact,
  className,
}: RelativeTimeProps) {
  const { t } = useI18n();
  const [now, setNow] = useState(() => new Date());

  useEffect(() => {
    const timer = setInterval(() => setNow(new Date()), refreshIntervalMs);
    return () => clearInterval(timer);
  }, [refreshIntervalMs]);

  const seconds = Math.round((date.getTime() - now.getTime()) / 1000);
  const text =
    Math.abs(seconds) < 5 ? t('relativeTime.justNow') : formatRelative(date, now);
  const exact =
    formatExact?.(date) ??
    new Intl.DateTimeFormat(undefined, {
      dateStyle: 'medium',
      timeStyle: 'medium',
    }).format(date);

  return (
    <Tooltip content={t('relativeTime.exactTime', { datetime: exact })} delayDuration={200}>
      <span tabIndex={0} className={className} title={undefined}>
        <span className="sr-only">{t('relativeTime.exactTime', { datetime: exact })}</span>
        <span aria-hidden="true">{text}</span>
      </span>
    </Tooltip>
  );
}
