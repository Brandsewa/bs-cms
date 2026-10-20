import { describe, expect, it } from 'vitest';
import { sanitizeReturnTo } from './return-to';

describe('sanitizeReturnTo', () => {
  const origin = 'https://bskickstarter.com';

  it('returns /admin for falsy or empty input', () => {
    expect(sanitizeReturnTo(null, origin)).toBe('/admin');
    expect(sanitizeReturnTo(undefined, origin)).toBe('/admin');
    expect(sanitizeReturnTo('', origin)).toBe('/admin');
    expect(sanitizeReturnTo('   ', origin)).toBe('/admin');
  });

  it('allows valid /admin paths and query strings', () => {
    expect(sanitizeReturnTo('/admin', origin)).toBe('/admin');
    expect(sanitizeReturnTo('/admin/', origin)).toBe('/admin/');
    expect(sanitizeReturnTo('/admin/entries/posts', origin)).toBe('/admin/entries/posts');
    expect(sanitizeReturnTo('/admin/media?folder=123', origin)).toBe('/admin/media?folder=123');
    expect(sanitizeReturnTo('/admin?preview=true', origin)).toBe('/admin?preview=true');
  });

  it('allows valid /oauth/authorize paths with query strings', () => {
    expect(sanitizeReturnTo('/oauth/authorize', origin)).toBe('/oauth/authorize');
    expect(sanitizeReturnTo('/oauth/authorize?client_id=123&response_type=code', origin)).toBe(
      '/oauth/authorize?client_id=123&response_type=code'
    );
  });

  it('allows same-origin absolute URLs and reduces them to pathname + search', () => {
    expect(
      sanitizeReturnTo('https://bskickstarter.com/oauth/authorize?client_id=x', origin)
    ).toBe('/oauth/authorize?client_id=x');
    expect(
      sanitizeReturnTo('https://bskickstarter.com/admin/entries?status=draft', origin)
    ).toBe('/admin/entries?status=draft');
  });

  it('rejects cross-origin URLs', () => {
    expect(sanitizeReturnTo('https://evil.example/oauth/authorize', origin)).toBe('/admin');
    expect(sanitizeReturnTo('http://evil.com/admin', origin)).toBe('/admin');
    expect(sanitizeReturnTo('https://evil.example', origin)).toBe('/admin');
  });

  it('rejects URLs with userinfo/credentials in the URL (user:pass@)', () => {
    expect(sanitizeReturnTo('https://bskickstarter.com@evil.example/', origin)).toBe('/admin');
    expect(sanitizeReturnTo('https://user:pass@bskickstarter.com/admin', origin)).toBe('/admin');
    expect(sanitizeReturnTo('https://bskickstarter.com:secret@evil.example/admin', origin)).toBe(
      '/admin'
    );
  });

  it('rejects subdomain and suffix evasion attempts', () => {
    expect(sanitizeReturnTo('https://bskickstarter.com.evil.example/admin', origin)).toBe(
      '/admin'
    );
    expect(sanitizeReturnTo('https://notbskickstarter.com/admin', origin)).toBe('/admin');
  });

  it('rejects directory traversal attempts resolving outside the allow-list', () => {
    expect(sanitizeReturnTo('/oauth/authorize/../../api/v1/users', origin)).toBe('/admin');
    expect(sanitizeReturnTo('/admin/../../api/v1/users', origin)).toBe('/admin');
  });

  it('rejects percent-encoded dots and slashes in path (%2e%2e, %2f)', () => {
    expect(
      sanitizeReturnTo('/oauth/authorize/%2e%2e/%2e%2e/api/v1/users', origin)
    ).toBe('/admin');
    expect(
      sanitizeReturnTo('/oauth/authorize%2f..%2f..%2fapi%2fv1%2fusers', origin)
    ).toBe('/admin');
    expect(sanitizeReturnTo('/admin%2f..%2fapi', origin)).toBe('/admin');
    expect(sanitizeReturnTo('/admin/%2e%2e/api', origin)).toBe('/admin');
  });

  it('preserves valid encoded query parameters when path is safe', () => {
    expect(
      sanitizeReturnTo(
        '/oauth/authorize?redirect_uri=https%3A%2F%2Fclient.example%2Fcallback&client_id=x',
        origin
      )
    ).toBe('/oauth/authorize?redirect_uri=https%3A%2F%2Fclient.example%2Fcallback&client_id=x');
  });

  it('rejects protocol-relative and backslash evasion attempts', () => {
    expect(sanitizeReturnTo('//evil.com/admin', origin)).toBe('/admin');
    expect(sanitizeReturnTo('/\\evil.com', origin)).toBe('/admin');
    expect(sanitizeReturnTo('/admin\\evil', origin)).toBe('/admin');
  });

  it('rejects raw and percent-encoded control characters', () => {
    expect(sanitizeReturnTo('/admin\r\nSet-Cookie: session=evil', origin)).toBe('/admin');
    expect(sanitizeReturnTo('/admin\x00evil', origin)).toBe('/admin');
    expect(sanitizeReturnTo('/admin%0d%0aSet-Cookie: session=evil', origin)).toBe('/admin');
    expect(sanitizeReturnTo('/admin%00evil', origin)).toBe('/admin');
  });

  it('rejects non-allow-listed internal paths', () => {
    expect(sanitizeReturnTo('/api/v1/users', origin)).toBe('/admin');
    expect(sanitizeReturnTo('/public/info', origin)).toBe('/admin');
    expect(sanitizeReturnTo('/admin-fake', origin)).toBe('/admin');
    expect(sanitizeReturnTo('/administrator', origin)).toBe('/admin');
    expect(sanitizeReturnTo('javascript:alert(1)', origin)).toBe('/admin');
    expect(sanitizeReturnTo('data:text/html,...', origin)).toBe('/admin');
  });
});
