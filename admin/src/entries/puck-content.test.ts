import { describe, expect, it } from 'vitest';
import {
  emptyPuckData,
  extractEntryContent,
  toContentPayload,
} from './puck-content';

const puckPage = {
  root: { props: { title: 'Home' } },
  content: [
    { type: 'Hero', props: { id: 'Hero-1', heading: 'Welcome' } },
    { type: 'Spacer', props: { id: 'Spacer-2', size: 'm' } },
  ],
};

describe('extractEntryContent', () => {
  it('prefers the item top-level content field', () => {
    const nested = { content: [] };
    expect(extractEntryContent({ content: puckPage }, { content: nested })).toBe(puckPage);
  });

  it('falls back to the nested itemData content field', () => {
    const nested = { content: [{ type: 'RichText' }] };
    expect(extractEntryContent({}, { content: nested })).toBe(nested);
  });

  it('returns null when no content object exists (new page)', () => {
    expect(extractEntryContent({}, {})).toBeNull();
    expect(extractEntryContent(null, null)).toBeNull();
  });

  it('treats non-object shapes as missing', () => {
    expect(extractEntryContent({ content: [] }, {})).toBeNull(); // array, not Puck data
    expect(extractEntryContent({ content: 'garbage' }, {})).toBeNull();
    expect(extractEntryContent({ content: null }, { content: 42 })).toBeNull();
  });
});

describe('toContentPayload', () => {
  it('sends a saved Puck Data object unchanged (deep-equal)', () => {
    expect(toContentPayload(puckPage)).toEqual(puckPage);
  });

  it('normalizes partial records into the backend contract', () => {
    expect(toContentPayload({})).toEqual({ root: {}, content: [] });
    expect(toContentPayload({ root: { props: { title: 'x' } } })).toEqual({
      root: { props: { title: 'x' } },
      content: [],
    });
    expect(toContentPayload({ content: puckPage.content, zones: { 'dropzone/a': [] } })).toEqual({
      root: {},
      content: puckPage.content,
      zones: { 'dropzone/a': [] },
    });
    expect(toContentPayload({ content: 'bad', root: 5 })).toEqual({ root: {}, content: [] });
  });

  it('wraps missing content in a fresh empty page', () => {
    const payload = toContentPayload(null);
    expect(payload).toEqual({ root: {}, content: [] });
    // Fresh object each call — callers must not share a mutable default.
    expect(toContentPayload(undefined)).not.toBe(payload);
    expect(emptyPuckData()).toEqual({ root: {}, content: [] });
  });
});
