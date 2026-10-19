import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import * as fs from 'node:fs';
import * as path from 'node:path';
import * as os from 'node:os';
import { CmsBuildClient } from '../src/build-client';

describe('CmsBuildClient', () => {
  let tempCacheDir: string;

  beforeEach(() => {
    tempCacheDir = fs.mkdtempSync(path.join(os.tmpdir(), 'bs-client-cache-'));
  });

  afterEach(() => {
    if (fs.existsSync(tempCacheDir)) {
      fs.rmSync(tempCacheDir, { recursive: true, force: true });
    }
    vi.restoreAllMocks();
  });

  it('sends Authorization: Bearer header when buildToken is provided', async () => {
    let capturedHeaders: Record<string, string> = {};

    global.fetch = vi.fn().mockImplementation(async (_url: string, init?: RequestInit) => {
      capturedHeaders = (init?.headers as Record<string, string>) || {};
      return new Response(JSON.stringify({ collections: {} }), {
        status: 200,
        headers: { 'content-type': 'application/json' },
      });
    });

    const client = new CmsBuildClient({
      baseUrl: 'https://example.com',
      buildToken: 'secret-build-token-123',
      cacheDir: tempCacheDir,
    });

    await client.fetchManifest();

    expect(capturedHeaders['Authorization']).toBe('Bearer secret-build-token-123');
    expect(capturedHeaders['Accept']).toBe('application/json');
  });

  it('fetches entries page with pagination parameters', async () => {
    let requestedUrl = '';

    global.fetch = vi.fn().mockImplementation(async (url: string) => {
      requestedUrl = url;
      return new Response(
        JSON.stringify({
          data: [{ id: '01JPOST001', slug: 'hello-world', title: 'Hello World' }],
          next_cursor: 'cursor_page_2',
        }),
        {
          status: 200,
          headers: { 'content-type': 'application/json' },
        }
      );
    });

    const client = new CmsBuildClient({
      baseUrl: 'https://example.com',
      cacheDir: tempCacheDir,
    });

    const page = await client.fetchEntriesPage('posts', { limit: 10, cursor: 'cursor_page_1' });

    expect(requestedUrl).toContain('/api/v1/public/collections/posts/entries?limit=10&cursor=cursor_page_1');
    expect(page.data).toHaveLength(1);
    expect(page.next_cursor).toBe('cursor_page_2');
  });

  it('utilizes on-disk cache keyed by updated_at', async () => {
    const calls: string[] = [];

    global.fetch = vi.fn().mockImplementation(async (url: string) => {
      calls.push(url);
      if (url.includes('cursor_page_2')) {
        return new Response(JSON.stringify({ data: [], next_cursor: null }), {
          status: 200,
          headers: { 'content-type': 'application/json' },
        });
      }
      return new Response(
        JSON.stringify({
          data: [
            {
              id: '01JPOST001',
              slug: 'fast-cms',
              title: 'Fast CMS',
              updated_at: '2026-10-19T10:00:00Z',
            },
          ],
          next_cursor: 'cursor_page_2',
        }),
        {
          status: 200,
          headers: { 'content-type': 'application/json' },
        }
      );
    });

    const client = new CmsBuildClient({
      baseUrl: 'https://example.com',
      cacheDir: tempCacheDir,
    });

    // 1. Initial fetch writes to disk cache
    const initialEntries = await client.fetchAllEntries('posts');
    expect(initialEntries).toHaveLength(1);
    expect(calls).toHaveLength(2); // page 1 and page 2

    const cacheFile = path.join(tempCacheDir, 'posts', '01JPOST001.json');
    expect(fs.existsSync(cacheFile)).toBe(true);

    // 2. Fetch again with same updated_at -> read from cache
    const cachedEntries = await client.fetchAllEntries('posts');
    expect(cachedEntries).toHaveLength(1);
    expect(cachedEntries[0]?.title).toBe('Fast CMS');

    // 3. If updated_at changed on server, cache is invalidated and updated
    global.fetch = vi.fn().mockImplementation(async () => {
      return new Response(
        JSON.stringify({
          data: [
            {
              id: '01JPOST001',
              slug: 'fast-cms',
              title: 'Fast CMS Updated',
              updated_at: '2026-10-19T12:00:00Z',
            },
          ],
          next_cursor: null,
        }),
        {
          status: 200,
          headers: { 'content-type': 'application/json' },
        }
      );
    });

    const updatedEntries = await client.fetchAllEntries('posts');
    expect(updatedEntries).toHaveLength(1);
    expect(updatedEntries[0]?.title).toBe('Fast CMS Updated');
    expect(updatedEntries[0]?.updated_at).toBe('2026-10-19T12:00:00Z');
  });

  it('retries on 500 error before succeeding', async () => {
    let attempts = 0;

    global.fetch = vi.fn().mockImplementation(async () => {
      attempts++;
      if (attempts === 1) {
        return new Response('Server Error', { status: 500, statusText: 'Internal Error' });
      }
      return new Response(
        JSON.stringify({
          id: '01JPOST001',
          slug: 'resilient-fetch',
          title: 'Resilient Fetch',
        }),
        {
          status: 200,
          headers: { 'content-type': 'application/json' },
        }
      );
    });

    const client = new CmsBuildClient({
      baseUrl: 'https://example.com',
      maxRetries: 2,
    });

    const entry = await client.fetchEntry('posts', 'resilient-fetch');
    expect(attempts).toBe(2);
    expect(entry?.title).toBe('Resilient Fetch');
  });
});
