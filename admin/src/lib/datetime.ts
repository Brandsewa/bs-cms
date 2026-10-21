/**
 * Shared datetime utilities for the BrandSewa CMS Admin.
 *
 * Ensures all API datetimes (which SQLite/MariaDB return as UTC strings without a 'Z' suffix,
 * e.g. "2026-10-21 01:53:37.000" or "2026-10-21 01:53:37") are parsed strictly as UTC.
 */

/**
 * Parses an API datetime value (string, Date, or number) into a Date object in UTC.
 * If the string does not have an explicit timezone offset or trailing 'Z', it assumes UTC.
 */
export function parseApiUtcDate(val: unknown): Date | null {
  if (val === null || val === undefined) return null;
  if (val instanceof Date) {
    return Number.isNaN(val.getTime()) ? null : val;
  }
  if (typeof val === 'number') {
    const d = new Date(val);
    return Number.isNaN(d.getTime()) ? null : d;
  }
  if (typeof val !== 'string') return null;

  let s = val.trim();
  if (!s) return null;

  // Pattern matching: "YYYY-MM-DD HH:mm:ss" or "YYYY-MM-DDTHH:mm:ss" optionally followed by fraction of a second (.sss)
  if (/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(\.\d+)?$/.test(s)) {
    s = s.replace(' ', 'T') + 'Z';
  } else if (/^\d{4}-\d{2}-\d{2}$/.test(s)) {
    s = `${s}T00:00:00Z`;
  }

  const d = new Date(s);
  return Number.isNaN(d.getTime()) ? null : d;
}

/**
 * Formats a date into a localized date string (e.g. "Oct 21, 01:53").
 */
export function formatAdminDate(
  val: unknown,
  options: Intl.DateTimeFormatOptions = {
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  }
): string {
  const d = parseApiUtcDate(val);
  if (!d) return typeof val === 'string' ? val : '—';
  try {
    return d.toLocaleDateString(undefined, options);
  } catch {
    return String(val);
  }
}

/**
 * Formats a date into a localized time string (e.g. "01:53 AM" or "01:53").
 */
export function formatAdminTime(
  val: unknown,
  options: Intl.DateTimeFormatOptions = {
    hour: '2-digit',
    minute: '2-digit',
  }
): string {
  const d = parseApiUtcDate(val);
  if (!d) return typeof val === 'string' ? val : '—';
  try {
    return d.toLocaleTimeString(undefined, options);
  } catch {
    return String(val);
  }
}
