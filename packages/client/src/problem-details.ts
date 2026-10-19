/**
 * RFC 9457 "Problem Details for HTTP APIs" — the error format every BS CMS
 * endpoint returns (application/problem+json).
 */

export interface ProblemDetails {
  /** URI reference identifying the problem type; "about:blank" when unknown. */
  type: string;
  /** Short human-readable summary (English). */
  title: string;
  /** HTTP status code. */
  status: number;
  /** Human-readable explanation of this occurrence. */
  detail?: string;
  /** URI reference of the affected resource. */
  instance?: string;
  /** Server-generated correlation id, shown in error states for support. */
  request_id?: string;
  /** Per-field validation messages (BS CMS extension for 422 responses). */
  errors?: Record<string, string[]>;
  /** Additional extension members are preserved. */
  [member: string]: unknown;
}

export function isProblemDetails(value: unknown): value is ProblemDetails {
  return (
    typeof value === 'object' &&
    value !== null &&
    typeof (value as ProblemDetails).title === 'string' &&
    typeof (value as ProblemDetails).status === 'number'
  );
}

/**
 * Parses an error response into ProblemDetails. Never throws: any transport
 * or format failure degrades to a generic problem with the response's real
 * status so callers can always render an error state.
 */
export async function parseProblemResponse(response: Response): Promise<ProblemDetails> {
  const status = response.status;
  const fallbackTitle = response.statusText || 'Request failed';
  try {
    const contentType = response.headers.get('content-type') ?? '';
    if (!contentType.includes('json')) {
      return { type: 'about:blank', title: fallbackTitle, status };
    }
    const body: unknown = await response.json();
    if (isProblemDetails(body)) {
      return {
        ...body,
        status,
        type: typeof body.type === 'string' ? body.type : 'about:blank',
      };
    }
    // JSON body that is not a problem document (e.g. an unexpected proxy error page).
    return {
      type: 'about:blank',
      title: fallbackTitle,
      status,
      detail: typeof body === 'string' ? body : undefined,
    };
  } catch (error) {
    return { type: 'about:blank', title: fallbackTitle, status };
  }
}

/** True when a problem carries at least one field-level validation message. */
export function hasFieldErrors(problem: { errors?: ProblemDetails['errors'] } & Record<string, unknown>): boolean {
  const errors = problem.errors;
  return (
    typeof errors === 'object' &&
    errors !== null &&
    Object.values(errors).some((messages) => Array.isArray(messages) && messages.length > 0)
  );
}
