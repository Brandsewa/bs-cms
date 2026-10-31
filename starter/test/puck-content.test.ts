import { describe, it, expect } from 'vitest';
import { renderPuckToHtml, sanitizePuckData, componentTypes } from '@bs/page-builder';
import { getFixturePages, getPuckDemoContent } from '../src/lib/bs';

const fixturePages = getFixturePages();
const homeFixture = fixturePages.find((p) => p.slug === 'home');
const demoFixture = fixturePages.find((p) => p.slug === 'puck-demo');

describe('fixture pages carry Puck page-builder content', () => {
  it('provides a home page and a puck-demo page with PuckData content', () => {
    expect(homeFixture).toBeDefined();
    expect(demoFixture).toBeDefined();
    for (const page of fixturePages) {
      const data = (page as { content?: unknown }).content as Record<string, unknown>;
      expect(data).toBeInstanceOf(Object);
      expect(Array.isArray(data.content)).toBe(true);
      for (const row of data.content as Array<{ type: string }>) {
        expect(componentTypes).toContain(row.type);
      }
    }
  });

  it('demo page includes at least Hero, RichText and Faq', () => {
    const types = getPuckDemoContent().content.map((row) => row.type);
    expect(types).toContain('Hero');
    expect(types).toContain('RichText');
    expect(types).toContain('Faq');
  });
});

describe('sanitizePuckData', () => {
  it('normalizes missing content to empty Puck data', () => {
    expect(sanitizePuckData(undefined)).toEqual({ root: {}, content: [] });
    expect(sanitizePuckData(null)).toEqual({ root: {}, content: [] });
    expect(sanitizePuckData('garbage')).toEqual({ root: {}, content: [] });
  });

  it('drops unknown component rows and keeps known ones', () => {
    const raw = {
      root: {},
      content: [
        { type: 'Hero', props: { id: 'h1', heading: 'Hi' } },
        { type: 'LegacyBlock', props: { id: 'x' } },
        { type: 'Faq', props: { items: [] } },
      ],
    };
    const clean = sanitizePuckData(raw);
    expect(clean.content.map((row) => row.type)).toEqual(['Hero', 'Faq']);
  });
});

describe('renderPuckToHtml on fixture content', () => {
  const html = renderPuckToHtml(getPuckDemoContent());

  it('renders the Hero block markup', () => {
    expect(html).toContain('class="bs-hero"');
    expect(html).toContain('Pages, Composed Visually');
    expect(html).toContain('class="bs-hero__cta bs-btn"');
  });

  it('renders RichText and Callout markup', () => {
    expect(html).toContain('class="bs-richtext"');
    expect(html).toContain('class="bs-callout bs-callout--tip"');
  });

  it('renders the Faq block markup', () => {
    expect(html).toContain('class="bs-faq"');
    expect(html).toContain('Does editing require a rebuild?');
  });

  it('never emits a hydration runtime for content pages', () => {
    expect(html).not.toContain('astro-island');
    expect(html).not.toContain('<script');
  });

  it('is safe on undefined content (pages without editor data)', () => {
    expect(() => renderPuckToHtml(undefined)).not.toThrow();
    expect(renderPuckToHtml(undefined)).not.toContain('bs-hero');
  });
});
