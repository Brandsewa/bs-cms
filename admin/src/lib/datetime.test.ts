import { describe, it, expect } from 'vitest';
import { parseApiUtcDate, formatAdminDate, formatAdminTime } from './datetime';

describe('parseApiUtcDate', () => {
  it('parses space-separated UTC strings without timezone marker as UTC', () => {
    const raw = '2026-10-21 01:53:37.000';
    const parsed = parseApiUtcDate(raw);
    expect(parsed).not.toBeNull();
    expect(parsed?.toISOString()).toBe('2026-10-21T01:53:37.000Z');
  });

  it('parses space-separated UTC strings without milliseconds', () => {
    const raw = '2026-10-21 01:53:37';
    const parsed = parseApiUtcDate(raw);
    expect(parsed).not.toBeNull();
    expect(parsed?.toISOString()).toBe('2026-10-21T01:53:37.000Z');
  });

  it('parses ISO strings with Z correctly', () => {
    const raw = '2026-10-21T01:53:37.000Z';
    const parsed = parseApiUtcDate(raw);
    expect(parsed?.toISOString()).toBe('2026-10-21T01:53:37.000Z');
  });

  it('parses date-only strings as midnight UTC', () => {
    const raw = '2026-10-21';
    const parsed = parseApiUtcDate(raw);
    expect(parsed?.toISOString()).toBe('2026-10-21T00:00:00.000Z');
  });

  it('preserves existing offset if provided', () => {
    const raw = '2026-10-21T07:23:37.000+05:30';
    const parsed = parseApiUtcDate(raw);
    expect(parsed?.toISOString()).toBe('2026-10-21T01:53:37.000Z');
  });

  it('handles null, undefined, empty, and invalid values gracefully', () => {
    expect(parseApiUtcDate(null)).toBeNull();
    expect(parseApiUtcDate(undefined)).toBeNull();
    expect(parseApiUtcDate('')).toBeNull();
    expect(parseApiUtcDate('invalid-date')).toBeNull();
  });

  describe('timezone invariant behavior in non-UTC timezones', () => {
    const raw = '2026-10-21 01:53:37.000';
    const parsed = parseApiUtcDate(raw)!;

    it('formats accurately for Asia/Kolkata (+05:30)', () => {
      // 01:53:37 UTC + 5h30m = 07:23:37 on Oct 21
      const formatted = parsed.toLocaleString('en-US', {
        timeZone: 'Asia/Kolkata',
        hour12: false,
      });
      expect(formatted).toContain('07:23:37');
      expect(formatted).toContain('10/21/2026');
    });

    it('formats accurately for America/Los_Angeles (-07:00 / PDT in October)', () => {
      // 01:53:37 UTC on Oct 21 - 7h = 18:53:37 on Oct 20
      const formatted = parsed.toLocaleString('en-US', {
        timeZone: 'America/Los_Angeles',
        hour12: false,
      });
      expect(formatted).toContain('18:53:37');
      expect(formatted).toContain('10/20/2026');
    });

    it('relative duration calculation yields seconds, not hours ago', () => {
      // Simulate now being 30 seconds after the event in UTC
      const nowUtc = new Date('2026-10-21T01:54:07.000Z');
      const diffSeconds = Math.round((nowUtc.getTime() - parsed.getTime()) / 1000);
      expect(diffSeconds).toBe(30);
    });
  });
});

describe('formatAdminDate and formatAdminTime', () => {
  it('formats dates consistently', () => {
    const str = formatAdminDate('2026-10-21 01:53:37.000');
    expect(str).toBeTruthy();
    expect(str).not.toBe('—');
  });

  it('formats times consistently', () => {
    const str = formatAdminTime('2026-10-21 01:53:37.000');
    expect(str).toBeTruthy();
    expect(str).not.toBe('—');
  });
});
