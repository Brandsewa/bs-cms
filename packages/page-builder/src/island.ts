import type { ComponentType } from 'react';

/**
 * DOM attribute marking a hydration mount point in the static HTML emitted by
 * the Astro starter. The value is the island name:
 *
 * ```html
 * <div data-bs-island="faq" data-bs-island-props='…serialized props…'>…</div>
 * ```
 *
 * A (future) client loader queries `[data-bs-island]` on load and hydrates
 * the registered component into each match. This module intentionally ships
 * only the convention + registry types; no loader implementation.
 */
export const ISLAND_ATTRIBUTE = 'data-bs-island';

/** A component that can be hydrated into an island mount point. */
export type IslandComponent<Props extends Record<string, unknown> = Record<string, unknown>> =
  ComponentType<Props>;

/** A named island registration produced by {@link defineIsland}. */
export interface BsIsland<Props extends Record<string, unknown> = Record<string, unknown>> {
  name: string;
  component: IslandComponent<Props>;
}

/**
 * Register a component as a hydratable island. Future-proofing only: the
 * helper normalizes the `{ name, component }` pair that an Astro-side loader
 * will look up by `data-bs-island` name; it performs no loading itself.
 *
 * ```ts
 * export const islands = [
 *   defineIsland('faq', FaqIsland),
 *   defineIsland('gallery', GalleryIsland),
 * ] as const;
 * ```
 */
export function defineIsland<Props extends Record<string, unknown>>(
  name: string,
  component: IslandComponent<Props>,
): BsIsland<Props> {
  return { name, component };
}
