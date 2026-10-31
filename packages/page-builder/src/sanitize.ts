import type { ComponentData } from '@puckeditor/core';
import { componentTypes } from './components/index.js';
import type { PuckData } from './types.js';

const DEFAULT_KNOWN_TYPES: ReadonlySet<string> = new Set(componentTypes);

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value);
}

/**
 * Defensive normalizer for untrusted page data (database rows, imports,
 * hand-edited JSON). Rules:
 *
 * - non-object input yields empty page data (`{ root: {}, content: [] }`);
 * - component rows whose `type` is not in the known set are dropped
 *   (defaults to the eight page-builder types; pass a custom set to allow
 *   config-specific extensions);
 * - `props` is ensured to be an object carrying a non-empty string `id`
 *   (generated as `` `${type}-${index}` `` when missing);
 * - non-array `content` becomes an empty array;
 * - `root` and `zones` are passed through only when they are objects, with
 *   every zone's contents filtered like `content`.
 *
 * Never throws: an unexpected failure returns empty page data.
 */
export function sanitizePuckData(
  data: unknown,
  knownTypes: Iterable<string> = DEFAULT_KNOWN_TYPES,
): PuckData {
  const empty: PuckData = { root: {}, content: [] };
  try {
    if (!isRecord(data)) return empty;

    const types = knownTypes instanceof Set ? knownTypes : new Set(knownTypes);
    const content = sanitizeContent(data['content'], types);
    const root = (isRecord(data['root']) ? data['root'] : {}) as PuckData['root'];

    const rawZones = data['zones'];
    if (!isRecord(rawZones)) return { root, content };

    const zones: NonNullable<PuckData['zones']> = {};
    for (const [name, zoneContent] of Object.entries(rawZones)) {
      zones[name] = sanitizeContent(zoneContent, types);
    }
    return { root, content, zones };
  } catch {
    return empty;
  }
}

/** Filter + normalize one content array (root content or a zone). */
function sanitizeContent(value: unknown, types: ReadonlySet<string>): PuckData['content'] {
  if (!Array.isArray(value)) return [];
  const items: PuckData['content'] = [];
  for (let index = 0; index < value.length; index++) {
    const item = sanitizeItem(value[index], index, types);
    if (item) items.push(item);
  }
  return items;
}

/** Filter + normalize a single component row; null when the row is unusable. */
function sanitizeItem(
  raw: unknown,
  index: number,
  types: ReadonlySet<string>,
): ComponentData | null {
  if (!isRecord(raw)) return null;
  const type = raw['type'];
  if (typeof type !== 'string' || !types.has(type)) return null;

  const props = isRecord(raw['props']) ? raw['props'] : {};
  const id = props['id'];
  const safeId = typeof id === 'string' && id.length > 0 ? id : `${type}-${index}`;
  return { type, props: { ...props, id: safeId } } as unknown as ComponentData;
}
