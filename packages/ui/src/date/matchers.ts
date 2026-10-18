import type { Matcher } from 'react-day-picker';

/**
 * Builds a react-day-picker disabled matcher for optional min/max bounds.
 * Undefined bounds are omitted (the library rejects `{ after: undefined }`).
 */
export function minMaxMatcher(min: Date | undefined, max: Date | undefined): Matcher[] {
  const matchers: Matcher[] = [];
  if (min) matchers.push({ before: min });
  if (max) matchers.push({ after: max });
  return matchers;
}
