import { describe, it, expect } from 'vitest';
import {
  renderToHtml,
  renderToMarkdown,
  renderToPlainText,
  isSafeUrl,
  sanitizeUrl,
  type TiptapDoc,
} from '../src/index.js';

describe('@bs/renderer canonical and structural agreement', () => {
  it('validates URL safety & sanitization', () => {
    expect(isSafeUrl('https://example.com/page')).toBe(true);
    expect(isSafeUrl('http://example.com')).toBe(true);
    expect(isSafeUrl('mailto:test@example.com')).toBe(true);
    expect(isSafeUrl('tel:+123456789')).toBe(true);
    expect(isSafeUrl('/about')).toBe(true);
    expect(isSafeUrl('#section')).toBe(true);
    expect(isSafeUrl('?ref=1')).toBe(true);
    expect(isSafeUrl('bs://entry/01JABCDEF123456')).toBe(true);
    expect(isSafeUrl('javascript:alert(1)')).toBe(false);
    expect(isSafeUrl('javascript:/*--></title></style></textarea></script><svg/onload=alert(1)>')).toBe(false);
    expect(isSafeUrl('vbscript:msgbox(1)')).toBe(false);
    expect(isSafeUrl('data:text/html,<script>alert(1)</script>')).toBe(false);
    expect(isSafeUrl('//evil.com/phish')).toBe(false);

    expect(sanitizeUrl('javascript:alert(1)')).toBe('#');
    expect(sanitizeUrl('//evil.com')).toBe('#');
    expect(
      sanitizeUrl('bs://entry/01JABCDEF', (url) => url.replace('bs://entry/01JABCDEF', '/blog/my-post'))
    ).toBe('/blog/my-post');
  });

  const testDoc: TiptapDoc = {
    type: 'doc',
    schema_version: 1,
    content: [
      {
        type: 'heading',
        attrs: { level: 2 },
        content: [{ type: 'text', text: 'Fast Headless CMS on Shared Hosting' }],
      },
      {
        type: 'paragraph',
        content: [
          { type: 'text', text: 'BS CMS is built with ' },
          { type: 'text', text: 'PHP 8.2+', marks: [{ type: 'bold' }] },
          { type: 'text', text: ' and ' },
          { type: 'text', text: 'MySQL/MariaDB', marks: [{ type: 'italic' }] },
          { type: 'text', text: ', plus ' },
          { type: 'text', text: 'strikethrough', marks: [{ type: 'strike' }] },
          { type: 'text', text: ' and ' },
          { type: 'text', text: 'inline code', marks: [{ type: 'code' }] },
          { type: 'text', text: '. Check ' },
          {
            type: 'text',
            text: 'internal link',
            marks: [{ type: 'link', attrs: { href: 'bs://entry/01JPOST001' } }],
          },
          { type: 'text', text: '.' },
        ],
      },
      {
        type: 'blockquote',
        content: [
          {
            type: 'paragraph',
            content: [{ type: 'text', text: 'Simplicity is prerequisite for reliability.' }],
          },
        ],
      },
      {
        type: 'codeBlock',
        attrs: { language: 'php' },
        content: [{ type: 'text', text: '<?php\ndeclare(strict_types=1);' }],
      },
      {
        type: 'bulletList',
        content: [
          {
            type: 'listItem',
            content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Zero Node daemons on server' }] }],
          },
          {
            type: 'listItem',
            content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Stateless MCP over HTTP' }] }],
          },
        ],
      },
      {
        type: 'orderedList',
        attrs: { start: 1 },
        content: [
          {
            type: 'listItem',
            content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Step 1: Install' }] }],
          },
          {
            type: 'listItem',
            content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Step 2: Publish' }] }],
          },
        ],
      },
      {
        type: 'taskList',
        content: [
          {
            type: 'taskItem',
            attrs: { checked: true },
            content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Checked item' }] }],
          },
          {
            type: 'taskItem',
            attrs: { checked: false },
            content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Unchecked item' }] }],
          },
        ],
      },
      {
        type: 'horizontalRule',
      },
      {
        type: 'image',
        attrs: {
          src: 'https://example.com/diagram.webp',
          alt: 'Architecture Diagram',
          title: 'System Design',
          width: 1200,
          height: 675,
          srcset: 'https://example.com/diagram-600.webp 600w, https://example.com/diagram.webp 1200w',
          sizes: '(max-width: 600px) 100vw, 1200px',
          blurhash: 'LEHV6n002y00$5xU9F-o#R.7s.',
          media_id: '01JMED123',
        },
      },
      {
        type: 'callout',
        attrs: { type: 'tip', title: 'Performance Tip' },
        content: [
          {
            type: 'paragraph',
            content: [{ type: 'text', text: 'All HTML and Markdown render from the same stored AST.' }],
          },
        ],
      },
      {
        type: 'faq',
        attrs: {
          items: [
            {
              question: 'What is BS CMS?',
              answer: 'An enterprise headless CMS built for speed and security.',
            },
            {
              question: 'How fast is it?',
              answer: 'Sub-5-minute static publishes to shared hosting.',
            },
          ],
        },
      },
      {
        type: 'keyFacts',
        attrs: {
          facts: [
            { statement: 'Publish Speed', value: '26s', source_url: 'https://example.com/benchmarks' },
            { statement: 'Daemons on Host', value: 'Zero' },
          ],
        },
      },
      {
        type: 'bookmark',
        attrs: {
          url: 'https://example.com/article',
          title: 'External Resource',
          description: 'A great article about web performance.',
          image: 'https://example.com/article.jpg',
        },
      },
      {
        type: 'embed',
        attrs: {
          src: 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
        },
      },
      {
        type: 'button',
        attrs: {
          text: 'Download Whitepaper',
          url: 'https://example.com/whitepaper.pdf',
        },
      },
      {
        type: 'table',
        content: [
          {
            type: 'tableRow',
            content: [
              { type: 'tableHeader', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Feature' }] }] },
              { type: 'tableHeader', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'BS CMS' }] }] },
            ],
          },
          {
            type: 'tableRow',
            content: [
              { type: 'tableCell', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Shared Hosting' }] }] },
              { type: 'tableCell', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Yes (LiteSpeed/Apache)' }] }] },
            ],
          },
        ],
      },
    ],
  };

  const resolveUrl = (url: string) => {
    if (url === 'bs://entry/01JPOST001') {
      return '/blog/getting-started';
    }
    return url;
  };

  it('renders all BS document schema v1 nodes to HTML', () => {
    const html = renderToHtml(testDoc, { resolveUrl });
    expect(html).toContain('<h2>Fast Headless CMS on Shared Hosting</h2>');
    expect(html).toContain('<strong>PHP 8.2+</strong>');
    expect(html).toContain('<em>MySQL/MariaDB</em>');
    expect(html).toContain('<s>strikethrough</s>');
    expect(html).toContain('<code>inline code</code>');
    expect(html).toContain('<a href="/blog/getting-started" rel="noopener noreferrer">internal link</a>');
    expect(html).toContain('<blockquote><p>Simplicity is prerequisite for reliability.</p></blockquote>');
    expect(html).toContain('<pre><code class="language-php">&lt;?php\ndeclare(strict_types=1);</code></pre>');
    expect(html).toContain('<li>Zero Node daemons on server</li>');
    expect(html).toContain('<ol>\n<li>Step 1: Install</li>');
    expect(html).toContain('<li class="task-list-item"><input type="checkbox" disabled checked /> Checked item</li>');
    expect(html).toContain('<hr />');
    expect(html).toContain('loading="lazy"');
    expect(html).toContain('width="1200"');
    expect(html).toContain('height="675"');
    expect(html).toContain('srcset="https://example.com/diagram-600.webp 600w, https://example.com/diagram.webp 1200w"');
    expect(html).toContain('data-blurhash="LEHV6n002y00$5xU9F-o#R.7s."');
    expect(html).toContain('data-media-id="01JMED123"');
    expect(html).toContain('<div class="callout callout-tip" role="note">');
    expect(html).toContain('<p class="callout-title"><strong>Performance Tip</strong></p>');
    expect(html).toContain('<details class="faq-item">');
    expect(html).toContain('<summary>What is BS CMS?</summary>');
    expect(html).toContain('<dl class="key-facts">');
    expect(html).toContain('<dt>Publish Speed</dt><dd>26s <a href="https://example.com/benchmarks" class="fact-source" rel="noopener noreferrer">(Source)</a></dd>');
    expect(html).toContain('<div class="bookmark-card">');
    expect(html).toContain('<div class="embed-placeholder" data-embed-src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ">');
    expect(html).toContain('<a href="https://example.com/whitepaper.pdf" class="button btn-primary" role="button">Download Whitepaper</a>');
    expect(html).toContain('<div class="table-container">');
    expect(html).toContain('<th>Feature</th>');
  });

  it('renders all BS document schema v1 nodes to Markdown', () => {
    const md = renderToMarkdown(testDoc, { resolveUrl });
    expect(md).toContain('## Fast Headless CMS on Shared Hosting');
    expect(md).toContain('**PHP 8.2+**');
    expect(md).toContain('*MySQL/MariaDB*');
    expect(md).toContain('~~strikethrough~~');
    expect(md).toContain('`inline code`');
    expect(md).toContain('[internal link](/blog/getting-started)');
    expect(md).toContain('> Simplicity is prerequisite for reliability.');
    expect(md).toContain('```php\n<?php\ndeclare(strict_types=1);\n```');
    expect(md).toContain('- Zero Node daemons on server');
    expect(md).toContain('1. Step 1: Install');
    expect(md).toContain('- [x] Checked item');
    expect(md).toContain('- [ ] Unchecked item');
    expect(md).toContain('---');
    expect(md).toContain('![Architecture Diagram](https://example.com/diagram.webp "System Design")');
    expect(md).toContain(':::callout tip [Performance Tip]');
    expect(md).toContain(':::faq\n### What is BS CMS?\nAn enterprise headless CMS built for speed and security.\n### How fast is it?\nSub-5-minute static publishes to shared hosting.\n:::');
    expect(md).toContain(':::facts\n- Publish Speed | 26s | https://example.com/benchmarks\n- Daemons on Host | Zero\n:::');
    expect(md).toContain(':::bookmark https://example.com/article');
    expect(md).toContain(':::embed https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ\n:::');
    expect(md).toContain(':::button [Download Whitepaper] https://example.com/whitepaper.pdf\n:::');
    expect(md).toContain('| Feature | BS CMS |');
    expect(md).toContain('| --- | --- |');
    expect(md).toContain('| Shared Hosting | Yes \\(LiteSpeed/Apache\\) |');
  });

  it('extracts plain text cleanly', () => {
    const plain = renderToPlainText(testDoc);
    expect(plain).toContain('Fast Headless CMS on Shared Hosting');
    expect(plain).toContain('Simplicity is prerequisite for reliability.');
    expect(plain).toContain('Zero Node daemons on server');
    expect(plain).toContain('Step 1: Install');
    expect(plain).toContain('Checked item');
    expect(plain).toContain('What is BS CMS?');
    expect(plain).toContain('Publish Speed: 26s');
    expect(plain).toContain('Download Whitepaper');
  });
});
