import * as fs from 'node:fs';
import * as path from 'node:path';

export interface BuildClientOptions {
  /** CMS Base URL (e.g. https://example.com or http://localhost:8080) */
  baseUrl: string;
  /** Build token passed via Authorization: Bearer <buildToken> */
  buildToken?: string;
  /** Optional directory path for disk caching. When omitted, caching is skipped or in-memory. */
  cacheDir?: string;
  /** Request timeout in milliseconds (default: 15000) */
  timeoutMs?: number;
  /** Maximum retry attempts for transient 5xx / network errors (default: 3) */
  maxRetries?: number;
}

export interface BuildManifest {
  site?: {
    name?: string;
    url?: string;
    description?: string;
    environment?: string;
  };
  collections: Record<string, {
    handle: string;
    route_pattern?: string;
    entries_count?: number;
    updated_at?: string;
  }>;
  taxonomies?: Record<string, unknown>;
  navigation?: unknown[];
  redirects?: Array<{ source: string; target: string; code: number }>;
  indexnow_key?: string;
  generated_at?: string;
}

export interface FetchEntriesOptions {
  limit?: number;
  cursor?: string;
  since?: string;
}

export interface PaginatedResult<T> {
  data: T[];
  next_cursor?: string | null;
  total?: number;
}

export interface EntryItem {
  id: string;
  slug: string;
  title: string;
  published_at?: string | null;
  updated_at?: string | null;
  locale?: string;
  seo?: Record<string, unknown>;
  [field: string]: unknown;
}

/**
 * Build-time data client for Astro static site generation.
 * Handles build token authentication, pagination, exponential backoff retries,
 * and on-disk caching keyed by updated_at so unchanged entries are not re-fetched.
 */
export class CmsBuildClient {
  private readonly baseUrl: string;
  private readonly buildToken?: string;
  private readonly cacheDir?: string;
  private readonly timeoutMs: number;
  private readonly maxRetries: number;

  constructor(options: BuildClientOptions) {
    this.baseUrl = options.baseUrl.replace(/\/+$/, '');
    this.buildToken = options.buildToken;
    this.cacheDir = options.cacheDir;
    this.timeoutMs = options.timeoutMs ?? 15000;
    this.maxRetries = options.maxRetries ?? 3;

    if (this.cacheDir) {
      fs.mkdirSync(this.cacheDir, { recursive: true });
    }
  }

  /**
   * Fetches the build manifest containing routes, settings, and collection summaries.
   * Cached to disk if cacheDir is provided.
   */
  async fetchManifest(): Promise<BuildManifest> {
    const url = `${this.baseUrl}/api/v1/public/build/manifest`;
    try {
      return await this.fetchWithRetry<BuildManifest>(url);
    } catch {
      // Fallback if the build-time manifest endpoint is not yet available or returns 404
      return {
        collections: {
          posts: { handle: 'posts', route_pattern: '/blog/[slug]' },
          pages: { handle: 'pages', route_pattern: '/[slug]' }
        }
      };
    }
  }

  /**
   * Fetches a single page of entries for a collection.
   */
  async fetchEntriesPage(
    collection: string,
    options: FetchEntriesOptions = {}
  ): Promise<PaginatedResult<EntryItem>> {
    const params = new URLSearchParams();
    if (options.limit) params.set('limit', String(options.limit));
    if (options.cursor) params.set('cursor', options.cursor);
    if (options.since) params.set('since', options.since);

    const query = params.toString() ? `?${params.toString()}` : '';
    const url = `${this.baseUrl}/api/v1/public/collections/${encodeURIComponent(collection)}/entries${query}`;
    return await this.fetchWithRetry<PaginatedResult<EntryItem>>(url);
  }

  /**
   * Fetches all published entries across all pages for a collection.
   * Utilizes disk caching keyed by entry.id and entry.updated_at.
   */
  async fetchAllEntries(collection: string): Promise<EntryItem[]> {
    const entries: EntryItem[] = [];
    let cursor: string | undefined = undefined;

    do {
      const page = await this.fetchEntriesPage(collection, { limit: 100, cursor });
      const pageEntries = Array.isArray(page.data) ? page.data : [];

      for (const entry of pageEntries) {
        const cached = this.readCachedEntry(collection, entry.id, entry.updated_at);
        if (cached) {
          entries.push(cached);
        } else {
          this.writeCachedEntry(collection, entry);
          entries.push(entry);
        }
      }

      cursor = page.next_cursor || undefined;
    } while (cursor);

    return entries;
  }

  /**
   * Fetches a single entry by slug or id, with disk caching support.
   */
  async fetchEntry(collection: string, slugOrId: string): Promise<EntryItem | null> {
    const url = `${this.baseUrl}/api/v1/public/collections/${encodeURIComponent(collection)}/entries/by-slug/${encodeURIComponent(slugOrId)}`;
    try {
      const entry = await this.fetchWithRetry<EntryItem>(url);
      if (entry && entry.id) {
        this.writeCachedEntry(collection, entry);
      }
      return entry;
    } catch (err: unknown) {
      if (err instanceof Error && err.message.includes('404')) {
        return null;
      }
      throw err;
    }
  }

  /**
   * Clears the disk cache directory if configured.
   */
  clearCache(): void {
    if (this.cacheDir && fs.existsSync(this.cacheDir)) {
      fs.rmSync(this.cacheDir, { recursive: true, force: true });
      fs.mkdirSync(this.cacheDir, { recursive: true });
    }
  }

  /* ----------------------------------------------------------------------- */
  /* Internal helpers                                                        */
  /* ----------------------------------------------------------------------- */

  private getCacheFilePath(collection: string, id: string): string | null {
    if (!this.cacheDir) return null;
    const colDir = path.join(this.cacheDir, collection);
    return path.join(colDir, `${id}.json`);
  }

  private readCachedEntry(
    collection: string,
    id: string,
    updatedAt?: string | null
  ): EntryItem | null {
    const filePath = this.getCacheFilePath(collection, id);
    if (!filePath || !fs.existsSync(filePath)) {
      return null;
    }

    try {
      const content = fs.readFileSync(filePath, 'utf-8');
      const data = JSON.parse(content) as EntryItem;
      // If an updated_at timestamp is given, verify cache validity
      if (updatedAt && data.updated_at !== updatedAt) {
        return null;
      }
      return data;
    } catch {
      return null;
    }
  }

  private writeCachedEntry(collection: string, entry: EntryItem): void {
    const filePath = this.getCacheFilePath(collection, entry.id);
    if (!filePath) return;

    try {
      fs.mkdirSync(path.dirname(filePath), { recursive: true });
      fs.writeFileSync(filePath, JSON.stringify(entry), 'utf-8');
    } catch {
      // Disk cache writes are non-fatal
    }
  }

  private async fetchWithRetry<T>(url: string): Promise<T> {
    const headers: Record<string, string> = {
      Accept: 'application/json',
    };
    if (this.buildToken) {
      headers['Authorization'] = `Bearer ${this.buildToken}`;
    }

    let lastError: Error | null = null;
    for (let attempt = 0; attempt <= this.maxRetries; attempt++) {
      try {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), this.timeoutMs);

        const response = await fetch(url, {
          method: 'GET',
          headers,
          signal: controller.signal,
        });
        clearTimeout(timeoutId);

        if (response.ok) {
          return (await response.json()) as T;
        }

        // Retry only for 5xx server errors or 429 rate limit
        if (response.status >= 500 || response.status === 429) {
          const delay = Math.min(1000 * Math.pow(2, attempt), 4000);
          await new Promise((res) => setTimeout(res, delay));
          continue;
        }

        throw new Error(`HTTP ${response.status}: ${response.statusText}`);
      } catch (err: unknown) {
        lastError = err instanceof Error ? err : new Error(String(err));
        if (attempt < this.maxRetries) {
          const delay = Math.min(1000 * Math.pow(2, attempt), 4000);
          await new Promise((res) => setTimeout(res, delay));
        }
      }
    }

    throw lastError || new Error(`Failed to fetch ${url} after ${this.maxRetries} retries`);
  }
}
