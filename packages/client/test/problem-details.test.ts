import { describe, expect, it } from 'vitest';
import { hasFieldErrors, isProblemDetails, parseProblemResponse } from '../src/problem-details';

function jsonResponse(body: unknown, status: number, contentType = 'application/problem+json') {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'content-type': contentType },
  });
}

describe('parseProblemResponse', () => {
  it('parses an RFC 9457 problem document', async () => {
    const response = jsonResponse(
      {
        type: 'https://bscms.dev/problems/validation-failed',
        title: 'Validation failed',
        status: 422,
        detail: 'The given data was invalid.',
        instance: '/api/v1/collections/posts/entries/42',
        request_id: 'req_01HX',
        errors: { title: ['Title is required.'] },
      },
      422
    );

    const problem = await parseProblemResponse(response);
    expect(problem.title).toBe('Validation failed');
    expect(problem.status).toBe(422);
    expect(problem.request_id).toBe('req_01HX');
    expect(problem.errors).toEqual({ title: ['Title is required.'] });
  });

  it('falls back to a generic problem for a non-JSON error body', async () => {
    const response = new Response('Bad Gateway', {
      status: 502,
      statusText: 'Bad Gateway',
      headers: { 'content-type': 'text/html' },
    });

    const problem = await parseProblemResponse(response);
    expect(problem.status).toBe(502);
    expect(problem.title).toBe('Bad Gateway');
    expect(problem.type).toBe('about:blank');
  });

  it('falls back to a generic problem for JSON that is not a problem document', async () => {
    const response = jsonResponse({ error: 'proxy exploded' }, 500, 'application/json');

    const problem = await parseProblemResponse(response);
    expect(problem.status).toBe(500);
    expect(isProblemDetails(problem)).toBe(true);
  });

  it('never throws on malformed JSON', async () => {
    const response = new Response('{not json', {
      status: 500,
      statusText: 'Internal Server Error',
      headers: { 'content-type': 'application/problem+json' },
    });

    const problem = await parseProblemResponse(response);
    expect(problem.status).toBe(500);
    expect(problem.title).toBe('Internal Server Error');
  });
});

describe('isProblemDetails', () => {
  it('accepts a valid problem document', () => {
    expect(isProblemDetails({ title: 'Forbidden', status: 403 })).toBe(true);
  });

  it('rejects non-problem values', () => {
    expect(isProblemDetails(null)).toBe(false);
    expect(isProblemDetails({ title: 42, status: 403 })).toBe(false);
    expect(isProblemDetails({ status: 403 })).toBe(false);
  });
});

describe('hasFieldErrors', () => {
  it('detects field validation messages', () => {
    const problem = { title: 'Validation failed', status: 422, errors: { slug: ['Taken.'] } };
    expect(hasFieldErrors(problem)).toBe(true);
  });

  it('ignores empty error maps', () => {
    expect(hasFieldErrors({ title: 'Validation failed', status: 422, errors: {} })).toBe(false);
    expect(hasFieldErrors({ title: 'Forbidden', status: 403 })).toBe(false);
  });
});
