import { describe, expect, it } from 'vitest';

describe('debug Response.json', () => {
  it('parses', async () => {
    const response = new Response(
      JSON.stringify({ title: 'X', detail: 'Y' }),
      { status: 428, headers: { 'content-type': 'application/json' } }
    );
    try {
      const body = await response.json();
      console.log('BODY ' + JSON.stringify(body));
    } catch (error) {
      console.log('JSON-THROW ' + String(error).slice(0, 200));
    }
    expect(true).toBe(true);
  });
});
