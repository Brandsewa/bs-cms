import { parseProblemResponse, type ProblemDetails } from './problem-details';

/**
 * Typed API client over the frozen api-v1 contract.
 *
 * - Mutating requests carry X-CSRF-Token (set from the session bootstrap).
 * - Conditional writes accept `ifMatch`; 428/409 responses surface as a
 *   typed `precondition` failure instead of a generic error.
 * - Never retries 4xx; network errors and 5xx retry with linear backoff
 *   (default 2 attempts) and are always abortable via `signal`.
 */

export interface ApiClientOptions {
  /** API origin, e.g. "https://example.com" (no trailing slash). */
  baseUrl: string;
  /** CSRF token from the session bootstrap; needed for mutating requests. */
  csrfToken?: string;
  /** Retry attempts for network errors and 5xx (default 2). 4xx never retries. */
  retries?: number;
  /** Fetch override for tests. */
  fetchImpl?: typeof fetch;
}

export type ApiSuccess<T> = { ok: true; status: number; data: T };
export type ApiPreconditionFailure = {
  ok: false;
  status: 428 | 409;
  kind: 'precondition';
  problem: ProblemDetails;
};
export type ApiError = {
  ok: false;
  status: number;
  kind?: 'precondition';
  problem: ProblemDetails;
};
export type ApiResult<T> = ApiSuccess<T> | ApiPreconditionFailure | ApiError;

export interface RequestOptions {
  /** Path parameters substituted into `{placeholders}`. */
  params?: Record<string, string>;
  /** Query string parameters (values are URL-encoded; undefined skips). */
  query?: Record<string, string | number | boolean | undefined>;
  /** JSON request body. */
  body?: unknown;
  /** Raw body (multipart FormData / binary) sent as-is. */
  rawBody?: FormData | ArrayBuffer;
  /** Extra headers (Content-Type is set automatically for JSON). */
  headers?: Record<string, string>;
  /** ETag for conditional writes → If-Match header. */
  ifMatch?: string;
  signal?: AbortSignal;
}

export class ApiClient {
  readonly baseUrl: string;
  private csrfToken?: string;
  private readonly retries: number;
  private readonly fetchImpl: typeof fetch;

  constructor(options: ApiClientOptions) {
    this.baseUrl = options.baseUrl.replace(/\/+$/, '');
    this.csrfToken = options.csrfToken;
    this.retries = options.retries ?? 2;
    this.fetchImpl = options.fetchImpl ?? fetch.bind(globalThis);
  }

  setCsrfToken(token: string | undefined): void {
    this.csrfToken = token;
  }

  getCsrfToken(): string | undefined {
    return this.csrfToken;
  }

  /**
   * Performs a typed request. The success `data` type and the error problem
   * come from the OpenAPI operation (response type + RFC 9457 body).
   */
  async request<TResponse>(
    method: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE',
    pathTemplate: string,
    options: RequestOptions = {}
  ): Promise<ApiResult<TResponse>> {
    const url = this.buildUrl(pathTemplate, options.params, options.query);
    const isMutation = method !== 'GET';

    const headers: Record<string, string> = { ...options.headers };
    if (options.ifMatch) headers['If-Match'] = options.ifMatch;
    if (isMutation && this.csrfToken) headers['X-CSRF-Token'] = this.csrfToken;

    let body: BodyInit | undefined;
    if (options.rawBody !== undefined) {
      body = options.rawBody;
    } else if (options.body !== undefined) {
      headers['Content-Type'] = 'application/json';
      body = JSON.stringify(options.body);
    }

    let attempt = 0;
    for (;;) {
      let response: Response;
      try {
        response = await this.fetchImpl(url, {
          method,
          headers,
          body,
          credentials: 'same-origin',
          signal: options.signal,
        });
      } catch (error) {
        // Network failure: retry (a 4xx case never reaches here).
        if (attempt < this.retries && !options.signal?.aborted) {
          attempt += 1;
          await backoff(attempt);
          continue;
        }
        return {
          ok: false,
          status: 0,
          problem: {
            type: 'about:blank',
            title: 'Network error',
            status: 0,
            detail: error instanceof Error ? error.message : String(error),
          },
        };
      }

      if (response.status >= 500 && attempt < this.retries) {
        attempt += 1;
        await backoff(attempt);
        continue;
      }

      if (response.ok) {
        const data =
          response.status === 204 ? (undefined as TResponse) : ((await response.json()) as TResponse);
        return { ok: true, status: response.status, data };
      }

      const problem = await parseProblemResponse(response);
      if ((response.status === 428 || response.status === 409) && options.ifMatch !== undefined) {
        return { ok: false, status: response.status as 428 | 409, kind: 'precondition', problem };
      }
      return { ok: false, status: response.status, problem };
    }
  }

  /** Multipart request (chunk uploads, media replace). */
  async requestMultipart<TResponse>(
    method: 'POST' | 'PUT',
    pathTemplate: string,
    params: Record<string, string>,
    form: FormData,
    signal?: AbortSignal
  ): Promise<ApiResult<TResponse>> {
    const url = this.buildUrl(pathTemplate, params, undefined);
    const headers: Record<string, string> = {};
    if (this.csrfToken) headers['X-CSRF-Token'] = this.csrfToken;
    const response = await this.fetchImpl(url, {
      method,
      headers,
      body: form,
      credentials: 'same-origin',
      signal,
    });
      if (response.ok) {
        return { ok: true, status: response.status, data: (await response.json()) as TResponse };
      }
      return { ok: false, status: response.status, problem: await parseProblemResponse(response) };
    }

  private buildUrl(
    pathTemplate: string,
    params: Record<string, string> | undefined,
    query: Record<string, string | number | boolean | undefined> | undefined
  ): string {
    let path = pathTemplate;
    for (const [name, value] of Object.entries(params ?? {})) {
      path = path.replace(`{${name}}`, encodeURIComponent(value));
    }
    const search = new URLSearchParams();
    for (const [name, value] of Object.entries(query ?? {})) {
      if (value !== undefined) search.set(name, String(value));
    }
    const queryString = search.toString();
    return `${this.baseUrl}${path}${queryString ? `?${queryString}` : ''}`;
  }
}

async function backoff(attempt: number): Promise<void> {
  const delayMs = Math.min(500 * attempt, 2000);
  await new Promise((resolve) => setTimeout(resolve, delayMs));
}
