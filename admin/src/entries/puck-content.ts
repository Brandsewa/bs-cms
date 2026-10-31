/**
 * Adapter between the entries API and the Puck page data contract.
 *
 * Kept free of runtime imports from `@bs/page-builder` / `@puckeditor/core`
 * so the entry-editor chunk stays small for non-pages collections; the Puck
 * runtime itself lives behind the lazy `puck-page-editor` wrapper.
 */

/** Minimal structural mirror of the Puck `Data` shape the backend stores. */
export interface PuckContentPayload {
  root: Record<string, unknown>;
  content: Array<{ type: string; props?: Record<string, unknown> }>;
  zones?: Record<string, Array<{ type: string; props?: Record<string, unknown> }>>;
}

export function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

/**
 * Resolve the `content` (Puck data) field of a loaded entry using the same
 * merge pattern as the other editors: the item's top-level field wins, the
 * nested `data` record is the fallback, garbage/missing yields `null`
 * (an empty page is composed from scratch).
 */
export function extractEntryContent(item: unknown, itemData: unknown): unknown {
  if (isRecord(item) && isRecord(item['content'])) return item['content'];
  if (isRecord(itemData) && isRecord(itemData['content'])) return itemData['content'];
  return null;
}

/** A fresh empty Puck page (`{ root: {}, content: [] }`). */
export function emptyPuckData(): PuckContentPayload {
  return { root: {}, content: [] };
}

/**
 * Save payload for the `content` field: send the Puck Data object as-is when
 * one exists (the backend validates the shape), otherwise an empty page.
 */
export function toContentPayload(value: unknown): PuckContentPayload {
  if (isRecord(value)) {
    return {
      root: isRecord(value['root']) ? value['root'] : {},
      content: Array.isArray(value['content']) ? value['content'] : [],
      ...(isRecord(value['zones']) ? { zones: value['zones'] } : {}),
    } as unknown as PuckContentPayload;
  }
  return emptyPuckData();
}
