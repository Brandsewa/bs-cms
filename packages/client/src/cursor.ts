import type { ApiClient } from './client';

/**
 * Cursor pagination helpers for the `{ items, meta: { total, count, cursor } }`
 * list envelope (frozen api-v1).
 */

export interface ListMeta {
  total?: number;
  count?: number;
  cursor?: string | null;
}

export interface ListEnvelope<T> {
  items: T[];
  meta?: ListMeta;
}

/** The opaque cursor to pass as `cursor[after]` for the next page, if any. */
export function nextCursor(meta: ListMeta | undefined): string | null {
  return meta?.cursor ?? null;
}

export interface ListQueryOptions {
  limit?: number;
  /** Fetch rows after this opaque cursor. */
  cursorAfter?: string | null;
  search?: string;
  sort?: string;
  [name: string]: string | number | boolean | undefined | null;
}

/** Builds the query object for a list request from pagination options. */
export function buildListQuery(
  options: ListQueryOptions = {}
): Record<string, string | number | boolean | undefined> {
  const query: Record<string, string | number | boolean | undefined> = {};
  for (const [name, value] of Object.entries(options)) {
    if (value === undefined || value === null || value === '') continue;
    const key = name === 'cursorAfter' ? 'cursor[after]' : name;
    query[key] = value as string | number | boolean;
  }
  return query;
}

/** Collects all pages of a cursor-paginated list (use sparingly — exports). */
export async function listAll<T>(
  client: ApiClient,
  fetchPage: (query: Record<string, string | number | boolean | undefined>) => Promise<ListEnvelope<T>>,
  { maxPages = 50 }: { maxPages?: number } = {}
): Promise<T[]> {
  void client;
  const all: T[] = [];
  let cursorAfter: string | null = null;
  for (let page = 0; page < maxPages; page += 1) {
    const envelope = await fetchPage(buildListQuery({ cursorAfter, limit: 100 }));
    all.push(...envelope.items);
    cursorAfter = nextCursor(envelope.meta);
    if (!cursorAfter) break;
  }
  return all;
}
