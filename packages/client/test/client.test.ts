import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ApiClient } from '../src/client';
import { buildListQuery, listAll, nextCursor } from '../src/cursor';
import { uploadChunked } from '../src/upload';

function fetchJson(body: unknown, status = 200) {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'content-type': 'application/json' },
  });
}

describe('ApiClient', () => {
  let requests: Array<{ url: string; init: RequestInit }>;

  beforeEach(() => {
    requests = [];
    vi.stubGlobal(
      'fetch',
      vi.fn(async (url: string | URL, init?: RequestInit) => {
        requests.push({ url: String(url), init: init ?? {} });
        return fetchJson({ ok: true });
      })
    );
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('GET builds URL with path params and query; no CSRF header on reads', async () => {
    const client = new ApiClient({ baseUrl: 'https://api.example.com', csrfToken: 'tok' });
    await client.request('GET', '/taxonomies/{handle}/terms', {
      params: { handle: 'categories' },
      query: { tree: '1', limit: 50, empty: undefined },
    });
    expect(requests[0]!.url).toBe(
      'https://api.example.com/taxonomies/categories/terms?tree=1&limit=50'
    );
    expect(requests[0]!.init.headers).not.toHaveProperty('X-CSRF-Token');
    const headers = requests[0]!.init.headers as Record<string, string>;
    expect(headers['X-CSRF-Token']).toBeUndefined();
  });

  it('mutations carry X-CSRF-Token and JSON body', async () => {
    const client = new ApiClient({ baseUrl: 'https://api.example.com', csrfToken: 'tok' });
    await client.request('POST', '/auth/login', { body: { email: 'a@b.c', password: 'x' } });
    const headers = requests[0]!.init.headers as Record<string, string>;
    expect(headers['X-CSRF-Token']).toBe('tok');
    expect(headers['Content-Type']).toBe('application/json');
    expect(requests[0]!.init.body).toBe(JSON.stringify({ email: 'a@b.c', password: 'x' }));
  });

  it('If-Match surfaces 428 as a typed precondition failure', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () =>
        fetchJson(
          { title: 'Precondition required', status: 428, detail: 'Missing If-Match' },
          428
        )
      )
    );
    const client = new ApiClient({ baseUrl: 'https://api.example.com' });
    const result = await client.request<{ ok: boolean }>('PUT', '/collections/posts/entries/{id}', {
      params: { id: '42' },
      body: { title: 'x' },
      ifMatch: 'W/"7"',
    });
    expect(result.ok).toBe(false);
    if (!result.ok) {
      expect(result.kind).toBe('precondition');
      expect(result.status).toBe(428);
      expect(result.problem.detail).toBe('Missing If-Match');
    }
  });

  it('If-Match surfaces 409 as a typed precondition failure', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => fetchJson({ title: 'Conflict', status: 409 }, 409)));
    const client = new ApiClient({ baseUrl: 'https://api.example.com' });
    const result = await client.request('PATCH', '/collections/posts/entries/{id}', {
      params: { id: '42' },
      body: {},
      ifMatch: 'W/"7"',
    });
    expect(result.ok).toBe(false);
    if (!result.ok) expect(result.kind).toBe('precondition');
  });

  it('428 without ifMatch surfaces as normal ApiError', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () =>
        fetchJson(
          { title: 'Precondition required', status: 428, detail: 'Header required' },
          428
        )
      )
    );
    const client = new ApiClient({ baseUrl: 'https://api.example.com' });
    const result = await client.request<{ ok: boolean }>('PUT', '/entries/{id}', {
      params: { id: '1' },
      body: {},
    });
    expect(result.ok).toBe(false);
    if (!result.ok) {
      expect(result.status).toBe(428);
      expect(result.kind).toBeUndefined();
      expect(result.problem.detail).toBe('Header required');
    }
  });

  it('never retries 4xx', async () => {
    const fetchMock = vi.fn(async () => fetchJson({ title: 'Not found' }, 404));
    vi.stubGlobal('fetch', fetchMock);
    const client = new ApiClient({ baseUrl: 'https://api.example.com', retries: 3 });
    await client.request('GET', '/nope');
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it('POST 503 is not retried by default', async () => {
    const fetchMock = vi.fn(async () => fetchJson({ title: 'Service unavailable' }, 503));
    vi.stubGlobal('fetch', fetchMock);
    const client = new ApiClient({ baseUrl: 'https://api.example.com', retries: 2 });
    const result = await client.request('POST', '/items', { body: { name: 'item1' } });
    expect(result.ok).toBe(false);
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it('POST 503 is retried when caller supplies an idempotencyKey', async () => {
    const fetchMock = vi
      .fn()
      .mockImplementationOnce(async (url: string | URL, init?: RequestInit) => {
        requests.push({ url: String(url), init: init ?? {} });
        return fetchJson({ title: 'Service unavailable' }, 503);
      })
      .mockImplementationOnce(async (url: string | URL, init?: RequestInit) => {
        requests.push({ url: String(url), init: init ?? {} });
        return fetchJson({ id: 'item1' });
      });
    vi.stubGlobal('fetch', fetchMock);
    const client = new ApiClient({ baseUrl: 'https://api.example.com', retries: 2 });
    const result = await client.request<{ id: string }>('POST', '/items', {
      body: { name: 'item1' },
      idempotencyKey: 'idemp-uuid-1234',
    });
    expect(result.ok).toBe(true);
    expect(fetchMock).toHaveBeenCalledTimes(2);
    expect(requests[0]!.init.headers).toHaveProperty('Idempotency-Key', 'idemp-uuid-1234');
    expect(requests[1]!.init.headers).toHaveProperty('Idempotency-Key', 'idemp-uuid-1234');
  });

  it('GET 503 is retried and succeeds', async () => {
    const fetchMock = vi
      .fn()
      .mockImplementationOnce(async () => fetchJson({ title: 'Boom' }, 503))
      .mockImplementationOnce(async () => fetchJson({ ok: true }));
    vi.stubGlobal('fetch', fetchMock);
    const client = new ApiClient({ baseUrl: 'https://api.example.com', retries: 2 });
    const result = await client.request<{ ok: boolean }>('GET', '/flaky');
    expect(result.ok).toBe(true);
    expect(fetchMock).toHaveBeenCalledTimes(2);
  });

  it('PUT 503 is retried', async () => {
    const fetchMock = vi
      .fn()
      .mockImplementationOnce(async () => fetchJson({ title: 'Boom' }, 503))
      .mockImplementationOnce(async () => fetchJson({ ok: true }));
    vi.stubGlobal('fetch', fetchMock);
    const client = new ApiClient({ baseUrl: 'https://api.example.com', retries: 2 });
    const result = await client.request<{ ok: boolean }>('PUT', '/entries/{id}', {
      params: { id: '42' },
      body: { title: 'updated' },
    });
    expect(result.ok).toBe(true);
    expect(fetchMock).toHaveBeenCalledTimes(2);
  });

  it('an aborted request is never retried', async () => {
    const controller = new AbortController();
    controller.abort();
    const fetchMock = vi.fn(async () => {
      const err = new Error('The operation was aborted');
      err.name = 'AbortError';
      throw err;
    });
    vi.stubGlobal('fetch', fetchMock);
    const client = new ApiClient({ baseUrl: 'https://api.example.com', retries: 2 });
    const result = await client.request('GET', '/aborted', { signal: controller.signal });
    expect(result.ok).toBe(false);
    expect(result.status).toBe(0);
    expect(fetchMock).toHaveBeenCalledTimes(1);
  });

  it('retries network errors on idempotent methods', async () => {
    const fetchMock = vi
      .fn()
      .mockRejectedValueOnce(new TypeError('failed to fetch'))
      .mockImplementationOnce(async () => fetchJson({ ok: true }));
    vi.stubGlobal('fetch', fetchMock);
    const client = new ApiClient({ baseUrl: 'https://api.example.com', retries: 2 });
    const result = await client.request('GET', '/x');
    expect(result.ok).toBe(true);
    expect(fetchMock).toHaveBeenCalledTimes(2);
  });

  it('requestMultipart returns status 0 on network error and respects retry policy', async () => {
    const form = new FormData();
    form.append('chunk_index', '0');

    // Without retry flag, does not retry POST multipart
    const failClient = new ApiClient({ baseUrl: 'https://api.example.com', retries: 2 });
    const fetchFail = vi.fn().mockRejectedValue(new TypeError('network fail'));
    vi.stubGlobal('fetch', fetchFail);
    const failResult = await failClient.requestMultipart('POST', '/media/chunks/{id}', { id: 's1' }, form);
    expect(failResult.ok).toBe(false);
    if (!failResult.ok) {
      expect(failResult.status).toBe(0);
      expect(failResult.problem.detail).toBe('network fail');
    }
    expect(fetchFail).toHaveBeenCalledTimes(1);

    // With retry flag, retries and succeeds
    const successClient = new ApiClient({ baseUrl: 'https://api.example.com', retries: 2 });
    const fetchSuccess = vi
      .fn()
      .mockRejectedValueOnce(new TypeError('failed to fetch chunk'))
      .mockImplementationOnce(async () => fetchJson({ status: 'saved', chunk_index: 0 }));
    vi.stubGlobal('fetch', fetchSuccess);
    const successResult = await successClient.requestMultipart(
      'POST',
      '/media/chunks/{id}',
      { id: 's1' },
      form,
      { retry: true }
    );
    expect(successResult.ok).toBe(true);
    expect(fetchSuccess).toHaveBeenCalledTimes(2);
  });
});

describe('cursor helpers', () => {
  it('buildListQuery maps cursorAfter to cursor[after] and drops empties', () => {
    const query = buildListQuery({ limit: 50, cursorAfter: 'abc', search: '', owner: undefined });
    expect(query).toEqual({ limit: 50, 'cursor[after]': 'abc' });
  });

  it('nextCursor returns null when the page is last', () => {
    expect(nextCursor({ cursor: 'next' })).toBe('next');
    expect(nextCursor({ cursor: null })).toBeNull();
    expect(nextCursor(undefined)).toBeNull();
  });

  it('listAll walks every page', async () => {
    const pages = [
      { items: ['a', 'b'], meta: { cursor: 'c1', total: 3 } },
      { items: ['c'], meta: { cursor: null, total: 3 } },
    ];
    let call = 0;
    const items = await listAll(new ApiClient({ baseUrl: 'https://x' }), async (query) => {
      const envelope = pages[call++]!;
      void query;
      return envelope;
    });
    expect(items).toEqual(['a', 'b', 'c']);
    expect(call).toBe(2);
  });
});

describe('uploadChunked', () => {
  function fakeFile(size: number): File {
    return new File([new Uint8Array(size)], 'photo.png', { type: 'image/png' });
  }

  it('init → chunks (multipart) → assemble, with progress', async () => {
    const bodies: Array<Record<string, string>> = [];
    const requestBodies: string[] = [];
    const fetchMock = vi.fn(async (url: string | URL, init?: RequestInit) => {
      requestBodies.push(String(init?.body ?? ''));
      const urlText = String(url);
      if (urlText.endsWith('/media/chunks/init')) {
        return fetchJson({ session_id: 's1' }, 201);
      }
      if (urlText.includes('/media/chunks/s1/assemble')) {
        return fetchJson({ id: 'm1', filename: 'photo.png' }, 201);
      }
      if (urlText.includes('/media/chunks/s1')) {
        const form = init!.body as FormData;
        bodies.push({
          chunk_index: String(form.get('chunk_index')),
          chunkSize: String((form.get('chunk') as Blob).size),
        });
        return fetchJson({ status: 'saved', chunk_index: Number(form.get('chunk_index')) });
      }
      throw new Error('unexpected ' + urlText);
    });
    vi.stubGlobal('fetch', fetchMock);
    const client = new ApiClient({ baseUrl: 'https://api.example.com', csrfToken: 'tok' });

    const progress: Array<{ done: number; total: number }> = [];
    const result = await uploadChunked(client, fakeFile(5 * 1024 * 1024), {
      chunkSize: 2 * 1024 * 1024,
      onProgress: (p) => progress.push({ done: p.chunksDone, total: p.chunksTotal }),
    });

    expect(result.ok).toBe(true);
    expect(result.media).toEqual({ id: 'm1', filename: 'photo.png' });

    // init request carries the contract fields.
    const initBody = JSON.parse(requestBodies[0]!);
    expect(initBody).toEqual({
      original_name: 'photo.png',
      total_chunks: 3,
      total_size: 5 * 1024 * 1024,
    });

    // 3 chunk requests in order with multipart payloads.
    expect(bodies.map((body) => body.chunk_index)).toEqual(['0', '1', '2']);
    expect(progress).toEqual([
      { done: 1, total: 3 },
      { done: 2, total: 3 },
      { done: 3, total: 3 },
    ]);
  });

  it('surfaces init failure', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => fetchJson({ title: 'Payload too large', status: 413 }, 413))
    );
    const client = new ApiClient({ baseUrl: 'https://api.example.com' });
    const result = await uploadChunked(client, fakeFile(10));
    expect(result.ok).toBe(false);
    expect(result.error).toBe('Payload too large');
  });

  it('invokes onUnauthorized callback when request returns 401', async () => {
    const onUnauthorized = vi.fn();
    vi.stubGlobal('fetch', vi.fn(async () => fetchJson({ title: 'Unauthorized', status: 401 }, 401)));
    const client = new ApiClient({ baseUrl: 'https://api.example.com', onUnauthorized });
    const res = await client.request('GET', '/auth/session');
    expect(res.ok).toBe(false);
    expect(res.status).toBe(401);
    expect(onUnauthorized).toHaveBeenCalledTimes(1);
    expect(onUnauthorized).toHaveBeenCalledWith(expect.objectContaining({ status: 401 }));
  });
});
