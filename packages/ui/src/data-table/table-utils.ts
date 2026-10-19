import { useEffect, useState } from 'react';
import type { Density } from '../data-table/data-table-types';

export function useMediaQuery(query: string): boolean {
  const [matches, setMatches] = useState(() => {
    if (typeof window === 'undefined') return false;
    return window.matchMedia(query).matches;
  });

  useEffect(() => {
    const media = window.matchMedia(query);
    setMatches(media.matches);
    const listener = (event: MediaQueryListEvent) => setMatches(event.matches);
    media.addEventListener('change', listener);
    return () => media.removeEventListener('change', listener);
  }, [query]);

  return matches;
}

export interface DataTablePrefs {
  order?: string[];
  visibility?: Record<string, boolean>;
  sizes?: Record<string, number>;
  density?: Density;
}

export function loadPrefs(storageKey: string | undefined): DataTablePrefs {
  if (!storageKey) return {};
  try {
    const raw = window.localStorage.getItem(storageKey);
    return raw ? (JSON.parse(raw) as DataTablePrefs) : {};
  } catch {
    return {};
  }
}

export function savePrefs(storageKey: string | undefined, prefs: DataTablePrefs): void {
  if (!storageKey) return;
  try {
    window.localStorage.setItem(storageKey, JSON.stringify(prefs));
  } catch {
    // storage unavailable
  }
}
