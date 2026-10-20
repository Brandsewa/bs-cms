// @vitest-environment jsdom
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { renderHook, waitFor } from '@testing-library/react';
import { createElement, type ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { ApiClient } from '../src/client';
import { defineApiHooks } from '../src/hooks';

function withProvider() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const wrapper = ({ children }: { children: ReactNode }) =>
    createElement(QueryClientProvider, { client: queryClient }, children);
  return { wrapper, queryClient };
}

describe('defineApiHooks', () => {
  it('useApiQuery fetches typed data and builds stable query keys', async () => {
    const fetchMock = vi.fn(
      async () =>
        new Response(JSON.stringify({ items: [], meta: { total: 0, cursor: null } }), {
          status: 200,
          headers: { 'content-type': 'application/json' },
        })
    );
    vi.stubGlobal('fetch', fetchMock);
    const client = new ApiClient({ baseUrl: 'https://api.example.com' });
    const hooks = defineApiHooks(client);

    const { wrapper, queryClient } = withProvider();
    const { result } = renderHook(
      () =>
        hooks.useApiQuery('GET /taxonomies/{handle}/terms', {
          params: { handle: 'categories' },
          query: { tree: '1' },
        }),
      { wrapper }
    );
    await waitFor(() => expect(result.current.isSuccess).toBe(true));

    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(String(fetchMock.mock.calls[0]![0])).toBe(
      'https://api.example.com/taxonomies/categories/terms?tree=1'
    );
    // The query cache key is [method, path, params, query] for invalidation.
    const cached = queryClient.getQueryCache().getAll()[0];
    expect(cached.queryKey).toEqual([
      'GET',
      '/taxonomies/{handle}/terms',
      { handle: 'categories' },
      { tree: '1' },
    ]);
  });

  it('useApiMutation carries CSRF and If-Match headers', async () => {
    const requests: Array<{ url: string; headers: Record<string, string> }> = [];
    vi.stubGlobal(
      'fetch',
      vi.fn(async (url: string | URL, init?: RequestInit) => {
        requests.push({ url: String(url), headers: init?.headers as Record<string, string> });
        return new Response(JSON.stringify({ status: 'updated' }), {
          status: 200,
          headers: { 'content-type': 'application/json' },
        });
      })
    );
    const client = new ApiClient({ baseUrl: 'https://api.example.com', csrfToken: 'csrf-1' });
    const hooks = defineApiHooks(client);

    const { result } = renderHook(
      () => hooks.useApiMutation('PUT /collections/{handle}/entries/{id}'),
      { wrapper: withProvider().wrapper }
    );
    result.current.mutate({ body: { title: 'x' }, ifMatch: 'W/"3"' });
    await waitFor(() => expect(result.current.isSuccess).toBe(true));

    expect(requests[0]?.headers['X-CSRF-Token']).toBe('csrf-1');
    expect(requests[0]?.headers['If-Match']).toBe('W/"3"');
  });

  it('query errors carry status and problem details', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(
        async () =>
          new Response(
            JSON.stringify({ title: 'Forbidden', status: 403, detail: 'No access' }),
            { status: 403, headers: { 'content-type': 'application/json' } }
          )
      )
    );
    const client = new ApiClient({ baseUrl: 'https://api.example.com' });
    const hooks = defineApiHooks(client);

    const { result } = renderHook(
      () => hooks.useApiQuery('GET /users', { query: { limit: 10 } }),
      { wrapper: withProvider().wrapper }
    );
    await waitFor(() => expect(result.current.isError).toBe(true));
    const error = result.current.error as Error & { status: number; problem: { detail?: string } };
    expect(error.status).toBe(403);
    expect(error.problem.detail).toBe('No access');
  });
});


