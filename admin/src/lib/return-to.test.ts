import { describe, expect, it } from 'vitest';
import { sanitizeReturnTo } from './return-to';

describe('sanitizeReturnTo', () => {
  it('returns /admin for falsy or empty input', () => {
    expect(sanitizeReturnTo(null)).toBe('/admin');
    expect(sanitizeReturnTo(undefined)).toBe('/admin');
    expect(sanitizeReturnTo('')).toBe('/admin');
    expect(sanitizeReturnTo('   ')).toBe('/admin');
  });

  it('allows valid /admin paths and query strings', () => {
    expect(sanitizeReturnTo('/admin')).toBe('/admin');
    expect(sanitizeReturnTo('/admin/')).toBe('/admin/');
    expect(sanitizeReturnTo('/admin/entries/posts')).toBe('/admin/entries/posts');
    expect(sanitizeReturnTo('/admin/media?folder=123')).toBe('/admin/media?folder=123');
    expect(sanitizeReturnTo('/admin?preview=true')).toBe('/admin?preview=true');
  });

  it('allows valid /oauth/authorize paths with query strings', () => {
    expect(sanitizeReturnTo('/oauth/authorize')).toBe('/oauth/authorize');
    expect(sanitizeReturnTo('/oauth/authorize?client_id=123&response_type=code')).toBe(
      '/oauth/authorize?client_id=123&response_type=code'
    );
  });

  it('rejects external URLs and schemes', () => {
    expect(sanitizeReturnTo('https://evil.com')).toBe('/admin');
    expect(sanitizeReturnTo('http://evil.com/admin')).toBe('/admin');
    expect(sanitizeReturnTo('javascript:alert(1)')).toBe('/admin');
    expect(sanitizeReturnTo('data:text/html,...')).toBe('/admin');
  });

  it('rejects protocol-relative and backslash evasion attempts', () => {
    expect(sanitizeReturnTo('//evil.com/admin')).toBe('/admin');
    expect(sanitizeReturnTo('/\\evil.com')).toBe('/admin');
    expect(sanitizeReturnTo('/admin\\evil')).toBe('/admin');
  });

  it('rejects paths containing control characters', () => {
    expect(sanitizeReturnTo('/admin\r\nSet-Cookie: session=evil')).toBe('/admin');
    expect(sanitizeReturnTo('/admin\x00evil')).toBe('/admin');
  });

  it('rejects non-admin, non-oauth internal paths', () => {
    expect(sanitizeReturnTo('/api/v1/users')).toBe('/admin');
    expect(sanitizeReturnTo('/public/info')).toBe('/admin');
    expect(sanitizeReturnTo('/admin-fake')).toBe('/admin');
    expect(sanitizeReturnTo('/administrator')).toBe('/admin');
  });
});
