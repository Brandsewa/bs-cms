import { describe, it, expect } from 'vitest';
import { renderPuckToHtml } from '../src/index.js';

/** Shorthand: render a single component row through the full pipeline. */
function renderOne(type: string, props: Record<string, unknown>): string {
  return renderPuckToHtml({ root: {}, content: [{ type, props }] });
}

describe('renderPuckToHtml', () => {
  it('renders Hero with classes, heading and CTA link', () => {
    const html = renderOne('Hero', {
      id: 'hero-1',
      heading: 'Plans & dreams',
      text: 'A quiet intro.',
      ctaLabel: 'Read more',
      ctaUrl: '/blog',
    });
    expect(html).toContain('<section id="hero-1" class="bs-hero">');
    expect(html).toContain('class="bs-hero__heading"');
    expect(html).toContain('Plans &amp; dreams');
    expect(html).toContain('class="bs-hero__text"');
    expect(html).toContain('href="/blog"');
    expect(html).toContain('class="bs-hero__cta bs-btn"');
  });

  it('omits the Hero CTA when the label is empty', () => {
    const html = renderOne('Hero', {
      id: 'hero-2',
      heading: 'Hi',
      text: '',
      ctaLabel: '',
      ctaUrl: '/blog',
    });
    expect(html).not.toContain('bs-hero__cta');
    expect(html).not.toContain('bs-hero__text');
  });

  it('renders RichText HTML verbatim', () => {
    const html = renderOne('RichText', {
      html: '<p>Hi <strong>there</strong></p>',
    });
    expect(html).toContain('class="bs-richtext"');
    expect(html).toContain('<p>Hi <strong>there</strong></p>');
  });

  it('renders Callout with variant modifier class', () => {
    const html = renderOne('Callout', {
      variant: 'warning',
      title: 'Careful',
      content: 'This cannot be undone.',
    });
    expect(html).toContain('class="bs-callout bs-callout--warning"');
    expect(html).toContain('class="bs-callout__title"');
    expect(html).toContain('Careful');
    expect(html).toContain('This cannot be undone.');
  });

  it('renders Faq items', () => {
    const html = renderOne('Faq', {
      items: [
        { question: 'Who?', answer: 'Us.' },
        { question: 'What?', answer: 'This.' },
      ],
    });
    expect(html).toContain('class="bs-faq"');
    expect(html).toContain('class="bs-faq__question"');
    expect(html).toContain('Who?');
    expect(html).toContain('class="bs-faq__answer"');
    expect(html).toContain('This.');
    expect(html).toContain('bs-faq__item');
  });

  it('renders CtaBanner with alignment class and button', () => {
    const html = renderOne('CtaBanner', {
      id: 'cta-1',
      heading: 'Get started',
      text: 'It is free.',
      buttonLabel: 'Sign up',
      buttonUrl: '/signup',
      align: 'center',
    });
    expect(html).toContain('<section id="cta-1" class="bs-cta bs-cta--center">');
    expect(html).toContain('class="bs-cta__heading"');
    expect(html).toContain('class="bs-cta__button bs-btn"');
    expect(html).toContain('href="/signup"');
  });

  it('renders FeaturesGrid with column modifier and items', () => {
    const html = renderOne('FeaturesGrid', {
      id: 'feat-1',
      columns: '3',
      items: [
        { title: 'Fast', description: 'Ships static HTML.' },
        { title: 'Small', description: 'No runtime.' },
      ],
    });
    expect(html).toContain('class="bs-features bs-features--3"');
    expect(html).toContain('class="bs-features__item"');
    expect(html).toContain('class="bs-features__title"');
    expect(html).toContain('Fast');
    expect(html).toContain('class="bs-features__description"');
  });

  it('renders ImageBlock with img and optional caption', () => {
    const html = renderOne('ImageBlock', {
      id: 'img-1',
      src: 'https://example.com/cat.jpg',
      alt: 'A cat',
      caption: 'A very good cat',
    });
    expect(html).toContain('<figure id="img-1" class="bs-image">');
    expect(html).toContain('class="bs-image__img"');
    expect(html).toContain('src="https://example.com/cat.jpg"');
    expect(html).toContain('alt="A cat"');
    expect(html).toContain('class="bs-image__caption"');
    expect(html).toContain('A very good cat');
  });

  it('omits the img element when src is empty', () => {
    const html = renderOne('ImageBlock', { id: 'img-2', src: '', alt: '' });
    expect(html).toContain('class="bs-image"');
    expect(html).not.toContain('<img');
  });

  it('renders Spacer with an inline pixel height', () => {
    const html = renderOne('Spacer', { height: 96 });
    expect(html).toContain('class="bs-spacer"');
    expect(html).toContain('style="height:96px"');
    expect(html).toContain('aria-hidden="true"');
  });

  it('renders multiple blocks in order and drops unknown types', () => {
    const html = renderPuckToHtml({
      root: {},
      content: [
        { type: 'Spacer', props: { id: 's1', height: 32 } },
        { type: 'Bogus', props: { id: 'b1' } },
        { type: 'RichText', props: { id: 'r1', html: '<p>end</p>' } },
      ],
    });
    const spacerAt = html.indexOf('bs-spacer');
    const richtextAt = html.indexOf('bs-richtext');
    expect(spacerAt).toBeGreaterThanOrEqual(0);
    expect(richtextAt).toBeGreaterThan(spacerAt);
    expect(html).not.toContain('Bogus');
  });

  it('never throws on garbage input', () => {
    expect(() => renderPuckToHtml(null)).not.toThrow();
    expect(() => renderPuckToHtml(undefined)).not.toThrow();
    expect(() => renderPuckToHtml(42)).not.toThrow();
    expect(() => renderPuckToHtml({ content: [{ type: 'Nope', props: {} }] })).not.toThrow();
    expect(typeof renderPuckToHtml('garbage')).toBe('string');
  });
});
