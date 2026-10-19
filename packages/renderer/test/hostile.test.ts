import { describe, it, expect } from 'vitest';
import { renderToHtml, renderToMarkdown } from '../src/index.js';

// eslint-disable-next-line @typescript-eslint/no-explicit-any
const doc = (content: any[]) => ({ type: 'doc', content });
// eslint-disable-next-line @typescript-eslint/no-explicit-any
const p = (t: string, marks: any[] = []) => ({ type: 'paragraph', content: [{ type: 'text', text: t, marks }] });

describe('hostile', () => {
  it('escapes text', () => {
    const h = renderToHtml(doc([p('<script>alert(1)</script><img src=x onerror=alert(1)>')]));
    expect(h).not.toMatch(/<script|<img/);
  });

  const bad = [
    'javascript:alert(1)',
    'JaVaScRiPt:alert(1)',
    ' javascript:alert(1)',
    'java\tscript:alert(1)',
    'java\nscript:alert(1)',
    'vbscript:x',
    'data:text/html;base64,PHNjcmlwdD4=',
    '&#106;avascript:alert(1)',
    '\u0001javascript:alert(1)',
    '//evil.example',
    'jav&#09;ascript:alert(1)',
    'file:///etc/passwd',
  ];

  for (const u of bad) {
    it('link ' + JSON.stringify(u), () => {
      const h = renderToHtml(doc([p('x', [{ type: 'link', attrs: { href: u } }])]));
      expect(h).not.toMatch(/href="[^"]*(javascript|vbscript|data:|file:)/i);
      expect(/href="\/\/evil/.test(h)).toBe(false);
    });
  }

  it('attr injection', () => {
    const h = renderToHtml(doc([p('x', [{ type: 'link', attrs: { href: '/a" onmouseover="alert(1)' } }])]));
    expect(h).not.toMatch(/onmouseover="/);
  });

  it('image attrs', () => {
    const h = renderToHtml(
      doc([
        {
          type: 'image',
          attrs: {
            src: '/m/a.png" onerror="alert(1)',
            alt: '"><script>x</script>',
            srcset: 'javascript:x 1x',
            width: '1" onload="x',
          },
        },
      ])
    );
    expect(h).not.toMatch(/<script|onerror="|onload="/);
  });

  it('button/embed/callout', () => {
    const h = renderToHtml(
      doc([
        { type: 'button', attrs: { url: 'javascript:alert(1)', label: '<b>x</b>' } },
        { type: 'embed', attrs: { url: 'javascript:alert(1)', provider: 'youtube' } },
        { type: 'callout', attrs: { variant: 'x" onclick="a', title: '<i>' }, content: [p('hi')] },
      ])
    );
    expect(h).not.toMatch(/javascript:|onclick|<b>|<i>/i);
  });

  it('md injection', () => {
    const m = renderToMarkdown(
      doc([
        p('[x](javascript:alert(1)) <script>'),
        p('x', [{ type: 'link', attrs: { href: 'javascript:alert(1)' } }]),
      ])
    );
    expect(m).not.toMatch(/\]\(javascript:/i);
    expect(m).not.toMatch(/<script/i);
  });

  it('deep nesting no crash', () => {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    let n: any = p('x');
    for (let i = 0; i < 5000; i++) n = { type: 'blockquote', content: [n] };
    expect(() => renderToHtml(doc([n]))).not.toThrow();
    expect(() => renderToMarkdown(doc([n]))).not.toThrow();
  });

  it('unknown node', () => {
    const h = renderToHtml(doc([{ type: 'script', content: [p('x')] }]));
    expect(h).not.toMatch(/<script/);
  });
});
