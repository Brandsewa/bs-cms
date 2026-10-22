import { useEffect } from 'react';

/**
 * Updates document.title with standard BS CMS Studio format:
 * "<title> | BS CMS Studio"
 */
export function useDocumentTitle(title?: string) {
  useEffect(() => {
    const base = 'BS CMS Studio';
    if (title && title.trim()) {
      document.title = `${title.trim()} | ${base}`;
    } else {
      document.title = base;
    }
  }, [title]);
}
