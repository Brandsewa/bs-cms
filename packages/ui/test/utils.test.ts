import { describe, expect, it } from 'vitest';
import { cn, escapeRegExp, isCtrlOrMeta } from '../src/lib/utils';

describe('cn', () => {
  it('joins conditional classes', () => {
    expect(cn('a', false && 'b', 'c')).toBe('a c');
  });

  it('resolves tailwind conflicts with the last class winning', () => {
    expect(cn('p-2', 'p-4')).toBe('p-4');
  });
});

describe('isCtrlOrMeta', () => {
  it('accepts ctrlKey or metaKey', () => {
    expect(isCtrlOrMeta({ metaKey: false, ctrlKey: true })).toBe(true);
    expect(isCtrlOrMeta({ metaKey: true, ctrlKey: false })).toBe(true);
    expect(isCtrlOrMeta({ metaKey: false, ctrlKey: false })).toBe(false);
  });
});

describe('escapeRegExp', () => {
  it('escapes regex metacharacters', () => {
    expect(escapeRegExp('a.b*c(d)[e]f$g^h')).toBe('a\\.b\\*c\\(d\\)\\[e\\]f\\$g\\^h');
  });
});
