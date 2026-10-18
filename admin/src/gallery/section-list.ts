/**
 * Section ids shared with tooling that must not import the component graph
 * (the Playwright axe sweep imports this instead of the registry, keeping
 * @bs/ui — and its JSON message catalog — out of the test loader).
 *
 * Kept in sync with `registry.ts` by `section-list.test.ts`.
 */
export const GALLERY_SECTIONS = [
  { id: 'foundations', name: 'Foundations' },
  { id: 'primitives', name: 'Primitives' },
  { id: 'controls', name: 'Searchable controls' },
  { id: 'date-time', name: 'Date & time' },
  { id: 'form', name: 'Form system' },
  { id: 'overlays', name: 'Overlays' },
  { id: 'feedback', name: 'Feedback & status' },
] as const;

export type GallerySectionId = (typeof GALLERY_SECTIONS)[number]['id'];
