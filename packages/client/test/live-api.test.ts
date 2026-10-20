import { describe, expect, it } from 'vitest';
import { ApiClient } from '../src/client';
import { buildListQuery, nextCursor } from '../src/cursor';

/**
 * Live contract-drift test against the real deployment
 * (https://bskickstarter.com). SKIPPED unless credentials are provided via
 * environment variables — never committed:
 *
 *   BS_LIVE_BASE_URL   (default https://bskickstarter.com)
 *   BS_LIVE_EMAIL      (account email)
 *   BS_LIVE_PASSWORD   (account password)
 *
 * Read-only: it logs in, reads the session and public shapes, and asserts
 * the frozen api-v1 contract still holds.
 */

const baseUrl = process.env.BS_LIVE_BASE_URL ?? 'https://bskickstarter.com';
const email = process.env.BS_LIVE_EMAIL;
const password = process.env.BS_LIVE_PASSWORD;
const liveEnabled = Boolean(email && password);

describe.skipIf(!liveEnabled)('live API contract (bskickstarter.com)', () => {
  it('login returns a session with CSRF token', async () => {
    const client = new ApiClient({ baseUrl });
    const login = await client.request<{ user: unknown; csrf_token: string }>('POST', '/auth/login', {
      body: { email, password },
    });
    expect(login.ok).toBe(true);
    if (!login.ok) return;
    expect(typeof login.data.csrf_token).toBe('string');
    client.setCsrfToken(login.data.csrf_token);

    const session = await client.request<{ user: unknown }>('GET', '/auth/session');
    expect(session.ok).toBe(true);
    if (!session.ok) return;
    expect(session.data.user).toBeTruthy();
  });

  it('entries list matches the frozen envelope { items, meta.cursor }', async () => {
    const client = new ApiClient({ baseUrl });
    await login(client);
    const result = await client.request<{
      items: Array<Record<string, unknown>>;
      meta?: { total?: number; count?: number; cursor?: string | null };
    }>('GET', '/collections/posts/entries', { query: buildListQuery({ limit: 5 }) });
    expect(result.ok).toBe(true);
    if (!result.ok) return;
    expect(Array.isArray(result.data.items)).toBe(true);
    expect(result.data.items.length).toBeLessThanOrEqual(5);
    expect(typeof nextCursor(result.data.meta)).toBe('string');
  });

  it('terms tree endpoint returns a hierarchy', async () => {
    const client = new ApiClient({ baseUrl });
    await login(client);
    const result = await client.request<Array<Record<string, unknown>>>(
      'GET',
      '/taxonomies/{handle}/terms',
      { params: { handle: 'categories' }, query: { tree: '1' } }
    );
    expect(result.ok).toBe(true);
    if (!result.ok) return;
    expect(Array.isArray(result.data)).toBe(true);
  });

  async function login(client: ApiClient): Promise<void> {
    const login = await client.request<{ csrf_token: string }>('POST', '/auth/login', {
      body: { email, password },
    });
    expect(login.ok).toBe(true);
    if (login.ok) client.setCsrfToken(login.data.csrf_token);
  }
});
