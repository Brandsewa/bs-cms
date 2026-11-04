import { describe, it, expect } from 'vitest';
import { sanitizePuckData, componentTypes } from '../src/index.js';

const heroRow = (id?: string) => ({
  type: 'Hero',
  props: { heading: 'H', text: '', ctaLabel: '', ctaUrl: '', ...(id ? { id } : {}) },
});

describe('sanitizePuckData', () => {
  it('returns empty page data for null, undefined and scalars', () => {
    const empty = { root: {}, content: [] };
    expect(sanitizePuckData(null)).toEqual(empty);
    expect(sanitizePuckData(undefined)).toEqual(empty);
    expect(sanitizePuckData(42)).toEqual(empty);
    expect(sanitizePuckData('nope')).toEqual(empty);
    expect(sanitizePuckData(true)).toEqual(empty);
  });

  it('drops rows with unknown types', () => {
    const data = {
      root: {},
      content: [heroRow('a'), { type: 'Bogus', props: { id: 'b' } }, heroRow('c')],
    };
    const out = sanitizePuckData(data);
    expect(out.content).toHaveLength(2);
    expect(JSON.stringify(out)).not.toContain('Bogus');
  });

  it('drops rows without a usable type', () => {
    const out = sanitizePuckData({ content: [{ props: { id: 'x' } }, { type: 5 }, 'nope', null] });
    expect(out.content).toEqual([]);
  });

  it('generates `${type}-${index}` ids when missing', () => {
    const out = sanitizePuckData({ content: [heroRow(), heroRow()] });
    expect(out.content[0]?.props?.id).toBe('Hero-0');
    expect(out.content[1]?.props?.id).toBe('Hero-1');
  });

  it('regenerates empty or non-string ids', () => {
    const out = sanitizePuckData({
      content: [heroRow(''), { type: 'Hero', props: { id: 99 } }],
    });
    expect(out.content[0]?.props?.id).toBe('Hero-0');
    expect(out.content[1]?.props?.id).toBe('Hero-1');
  });

  it('keeps existing string ids', () => {
    const out = sanitizePuckData({ content: [heroRow('my-hero')] });
    expect(out.content[0]?.props?.id).toBe('my-hero');
  });

  it('defaults missing props to an object', () => {
    const out = sanitizePuckData({ content: [{ type: 'Hero' }] });
    expect(out.content).toHaveLength(1);
    expect(out.content[0]?.props).toEqual({ id: 'Hero-0' });
  });

  it('treats non-array content as empty', () => {
    expect(sanitizePuckData({ content: 'nope' }).content).toEqual([]);
    expect(sanitizePuckData({ content: { 0: heroRow('a') } }).content).toEqual([]);
    expect(sanitizePuckData({}).content).toEqual([]);
  });

  it('passes root through only when it is an object', () => {
    expect(sanitizePuckData({ root: { title: 'Page' } }).root).toEqual({ title: 'Page' });
    expect(sanitizePuckData({ root: 'nope' }).root).toEqual({});
    expect(sanitizePuckData({ root: [1, 2] }).root).toEqual({});
    expect(sanitizePuckData({}).root).toEqual({});
  });

  it('filters zone contents and ignores non-object zones', () => {
    const data = {
      content: [heroRow('main-page')],
      zones: {
        'sidebar': [{ type: 'RichText', props: { html: '<p>x</p>' } }, { type: 'Nope' }, 7],
        'broken': 'nope',
      },
    };
    const out = sanitizePuckData(data);
    expect(out.zones?.['sidebar']).toHaveLength(1);
    expect(out.zones?.['sidebar']?.[0]?.props?.id).toBe('RichText-0');
    expect(out.zones?.['broken']).toEqual([]);

    expect(sanitizePuckData({ zones: 'nope' }).zones).toBeUndefined();
    expect(sanitizePuckData({}).zones).toBeUndefined();
  });

  it('honors a custom known-type list', () => {
    const data = { content: [heroRow('a'), { type: 'Custom', props: { id: 'b' } }] };
    const out = sanitizePuckData(data, ['Custom']);
    expect(out.content).toHaveLength(1);
    expect(out.content[0]?.type).toBe('Custom');
  });

  it('never throws on hostile input', () => {
    const hostile = {
      content: [
        {
          get type(): string {
            throw new Error('boom');
          },
        },
      ],
    };
    expect(() => sanitizePuckData(hostile)).not.toThrow();
    expect(sanitizePuckData(hostile)).toEqual({ root: {}, content: [] });
  });

  it('sanitizes script tags and inline event handlers in RichText blocks', () => {
    const data = {
      content: [
        {
          type: 'RichText',
          props: {
            id: 'rt-1',
            html: '<p>Hello</p><script>alert("xss")</script><img src="x" onerror="stealCookie()" /><a href="javascript:alert(1)">link</a>',
          },
        },
      ],
    };
    const out = sanitizePuckData(data);
    const html = out.content[0]?.props?.html as string;
    expect(html).not.toContain('<script');
    expect(html).not.toContain('onerror=');
    expect(html).not.toContain('javascript:');
    expect(html).toContain('<p>Hello</p>');
    expect(html).toContain('<img src="x" />');
    expect(html).toContain('<a href="#">link</a>');
  });

  it('covers exactly the nine contract component types', () => {
    expect([...componentTypes].sort()).toEqual(
      [
        'Hero',
        'RichText',
        'Callout',
        'Faq',
        'CtaBanner',
        'FeaturesGrid',
        'ImageBlock',
        'Spacer',
        'ContactForm',
      ].sort(),
    );
  });
});
