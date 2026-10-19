import { strict as assert } from 'node:assert';
import {
  renderToHtml,
  renderToMarkdown,
  renderToPlainText,
  isSafeUrl,
  sanitizeUrl,
  type TiptapDoc
} from '../src/index.js';

console.log('--- Testing URL Safety & Sanitization ---');
assert.equal(isSafeUrl('https://example.com/page'), true);
assert.equal(isSafeUrl('http://example.com'), true);
assert.equal(isSafeUrl('mailto:test@example.com'), true);
assert.equal(isSafeUrl('tel:+123456789'), true);
assert.equal(isSafeUrl('/about'), true);
assert.equal(isSafeUrl('#section'), true);
assert.equal(isSafeUrl('?ref=1'), true);
assert.equal(isSafeUrl('bs://entry/01JABCDEF123456'), true);
assert.equal(isSafeUrl('javascript:alert(1)'), false);
assert.equal(isSafeUrl('javascript:/*--></title></style></textarea></script><svg/onload=alert(1)>'), false);
assert.equal(isSafeUrl('vbscript:msgbox(1)'), false);
assert.equal(isSafeUrl('data:text/html,<script>alert(1)</script>'), false);
assert.equal(isSafeUrl('//evil.com/phish'), false);

assert.equal(sanitizeUrl('javascript:alert(1)'), '#');
assert.equal(sanitizeUrl('//evil.com'), '#');
assert.equal(
  sanitizeUrl('bs://entry/01JABCDEF', (url) => url.replace('bs://entry/01JABCDEF', '/blog/my-post')),
  '/blog/my-post'
);
console.log('✓ URL safety tests passed.');

console.log('\n--- Testing Document Schema v1 Node Rendering ---');

const testDoc: TiptapDoc = {
  type: 'doc',
  schema_version: 1,
  content: [
    {
      type: 'heading',
      attrs: { level: 2 },
      content: [{ type: 'text', text: 'Fast Headless CMS on Shared Hosting' }]
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
          marks: [{ type: 'link', attrs: { href: 'bs://entry/01JPOST001' } }]
        },
        { type: 'text', text: '.' }
      ]
    },
    {
      type: 'blockquote',
      content: [
        {
          type: 'paragraph',
          content: [{ type: 'text', text: 'Simplicity is prerequisite for reliability.' }]
        }
      ]
    },
    {
      type: 'codeBlock',
      attrs: { language: 'php' },
      content: [{ type: 'text', text: '<?php\ndeclare(strict_types=1);' }]
    },
    {
      type: 'bulletList',
      content: [
        {
          type: 'listItem',
          content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Zero Node daemons on server' }] }]
        },
        {
          type: 'listItem',
          content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Stateless MCP over HTTP' }] }]
        }
      ]
    },
    {
      type: 'orderedList',
      attrs: { start: 1 },
      content: [
        {
          type: 'listItem',
          content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Step 1: Install' }] }]
        },
        {
          type: 'listItem',
          content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Step 2: Publish' }] }]
        }
      ]
    },
    {
      type: 'taskList',
      content: [
        {
          type: 'taskItem',
          attrs: { checked: true },
          content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Checked item' }] }]
        },
        {
          type: 'taskItem',
          attrs: { checked: false },
          content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Unchecked item' }] }]
        }
      ]
    },
    {
      type: 'horizontalRule'
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
        media_id: '01JMED123'
      }
    },
    {
      type: 'callout',
      attrs: { type: 'tip', title: 'Performance Tip' },
      content: [
        {
          type: 'paragraph',
          content: [{ type: 'text', text: 'All HTML and Markdown render from the same stored AST.' }]
        }
      ]
    },
    {
      type: 'faq',
      attrs: {
        items: [
          {
            question: 'What is BS CMS?',
            answer: 'An enterprise headless CMS built for speed and security.'
          },
          {
            question: 'How fast is it?',
            answer: 'Sub-5-minute static publishes to shared hosting.'
          }
        ]
      }
    },
    {
      type: 'keyFacts',
      attrs: {
        facts: [
          { statement: 'Publish Speed', value: '26s', source_url: 'https://example.com/benchmarks' },
          { statement: 'Daemons on Host', value: 'Zero' }
        ]
      }
    },
    {
      type: 'bookmark',
      attrs: {
        url: 'https://example.com/article',
        title: 'External Resource',
        description: 'A great article about web performance.',
        image: 'https://example.com/article.jpg'
      }
    },
    {
      type: 'embed',
      attrs: {
        src: 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'
      }
    },
    {
      type: 'button',
      attrs: {
        text: 'Download Whitepaper',
        url: 'https://example.com/whitepaper.pdf'
      }
    },
    {
      type: 'table',
      content: [
        {
          type: 'tableRow',
          content: [
            { type: 'tableHeader', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Feature' }] }] },
            { type: 'tableHeader', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'BS CMS' }] }] }
          ]
        },
        {
          type: 'tableRow',
          content: [
            { type: 'tableCell', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Shared Hosting' }] }] },
            { type: 'tableCell', content: [{ type: 'paragraph', content: [{ type: 'text', text: 'Yes (LiteSpeed/Apache)' }] }] }
          ]
        }
      ]
    }
  ]
};

const resolveUrl = (url: string) => {
  if (url === 'bs://entry/01JPOST001') {
    return '/blog/getting-started';
  }
  return url;
};

const html = renderToHtml(testDoc, { resolveUrl });
assert(html.includes('<h2>Fast Headless CMS on Shared Hosting</h2>'));
assert(html.includes('<strong>PHP 8.2+</strong>'));
assert(html.includes('<em>MySQL/MariaDB</em>'));
assert(html.includes('<s>strikethrough</s>'));
assert(html.includes('<code>inline code</code>'));
assert(html.includes('<a href="/blog/getting-started" rel="noopener noreferrer">internal link</a>'));
assert(html.includes('<blockquote><p>Simplicity is prerequisite for reliability.</p></blockquote>'));
assert(html.includes('<pre><code class="language-php">&lt;?php\ndeclare(strict_types=1);</code></pre>'));
assert(html.includes('<li>Zero Node daemons on server</li>'));
assert(html.includes('<ol>\n<li>Step 1: Install</li>'));
assert(html.includes('<li class="task-list-item"><input type="checkbox" disabled checked /> Checked item</li>'));
assert(html.includes('<hr />'));
assert(html.includes('loading="lazy"'));
assert(html.includes('width="1200"'));
assert(html.includes('height="675"'));
assert(html.includes('srcset="https://example.com/diagram-600.webp 600w, https://example.com/diagram.webp 1200w"'));
assert(html.includes('data-blurhash="LEHV6n002y00$5xU9F-o#R.7s."'));
assert(html.includes('data-media-id="01JMED123"'));
assert(html.includes('<div class="callout callout-tip" role="note">'));
assert(html.includes('<p class="callout-title"><strong>Performance Tip</strong></p>'));
assert(html.includes('<details class="faq-item">'));
assert(html.includes('<summary>What is BS CMS?</summary>'));
assert(html.includes('<dl class="key-facts">'));
assert(html.includes('<dt>Publish Speed</dt><dd>26s <a href="https://example.com/benchmarks" class="fact-source" rel="noopener noreferrer">(Source)</a></dd>'));
assert(html.includes('<div class="bookmark-card">'));
assert(html.includes('<div class="embed-placeholder"'));
assert(html.includes('<a href="https://example.com/whitepaper.pdf" class="button btn-primary" role="button">Download Whitepaper</a>'));
assert(html.includes('<div class="table-container">'));
assert(html.includes('<th>Feature</th>'));
console.log('✓ HTML rendering tests passed.');

const md = renderToMarkdown(testDoc, { resolveUrl });
assert(md.includes('## Fast Headless CMS on Shared Hosting'));
assert(md.includes('**PHP 8.2+**'));
assert(md.includes('*MySQL/MariaDB*'));
assert(md.includes('~~strikethrough~~'));
assert(md.includes('`inline code`'));
assert(md.includes('[internal link](/blog/getting-started)'));
assert(md.includes('> Simplicity is prerequisite for reliability.'));
assert(md.includes('```php\n<?php\ndeclare(strict_types=1);\n```'));
assert(md.includes('- Zero Node daemons on server'));
assert(md.includes('1. Step 1: Install'));
assert(md.includes('- [x] Checked item'));
assert(md.includes('- [ ] Unchecked item'));
assert(md.includes('---'));
assert(md.includes('![Architecture Diagram](https://example.com/diagram.webp "System Design")'));
assert(md.includes(':::callout tip [Performance Tip]'));
assert(md.includes(':::faq\n### What is BS CMS?\nAn enterprise headless CMS built for speed and security.\n### How fast is it?\nSub-5-minute static publishes to shared hosting.\n:::'));
assert(md.includes(':::facts\n- Publish Speed | 26s | https://example.com/benchmarks\n- Daemons on Host | Zero\n:::'));
assert(md.includes(':::bookmark https://example.com/article'));
assert(md.includes(':::embed https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ\n:::'));
assert(md.includes(':::button [Download Whitepaper] https://example.com/whitepaper.pdf\n:::'));
assert(md.includes('| Feature | BS CMS |'));
assert(md.includes('| --- | --- |'));
assert(md.includes('| Shared Hosting | Yes (LiteSpeed/Apache) |'));
console.log('✓ Markdown rendering tests passed.');

const plain = renderToPlainText(testDoc);
assert(plain.includes('Fast Headless CMS on Shared Hosting'));
assert(plain.includes('Simplicity is prerequisite for reliability.'));
assert(plain.includes('Zero Node daemons on server'));
assert(plain.includes('Step 1: Install'));
assert(plain.includes('Checked item'));
assert(plain.includes('What is BS CMS?'));
assert(plain.includes('Publish Speed: 26s'));
assert(plain.includes('Download Whitepaper'));
console.log('✓ Plain text extraction tests passed.');

console.log('\n--- Testing Hostile Input & XSS Escaping ---');

const hostileDoc: TiptapDoc = {
  type: 'doc',
  content: [
    {
      type: 'paragraph',
      content: [
        { type: 'text', text: '<script>alert("xss")</script>' },
        {
          type: 'text',
          text: 'evil link',
          marks: [{ type: 'link', attrs: { href: 'javascript:alert(document.cookie)' } }]
        },
        {
          type: 'text',
          text: 'attr quote inject',
          marks: [{ type: 'link', attrs: { href: 'https://example.com" onmouseover="alert(1)' } }]
        }
      ]
    },
    {
      type: 'heading',
      attrs: { level: 2 },
      content: [{ type: 'text', text: '<img src=x onerror=alert(1)>' }]
    },
    {
      type: 'image',
      attrs: {
        src: 'javascript:alert(1)',
        alt: '"><script>alert(1)</script><"',
        title: '"><script>alert(2)</script>'
      }
    },
    {
      type: 'button',
      attrs: {
        text: '<script>alert(1)</script>',
        url: 'javascript:alert(document.domain)'
      }
    },
    {
      type: 'embed',
      attrs: {
        src: 'data:text/html,<script>alert(1)</script>'
      }
    }
  ]
};

const hostileHtml = renderToHtml(hostileDoc);
assert(!hostileHtml.includes('<script>'));
assert(!hostileHtml.includes('href="javascript:'));
assert(!hostileHtml.includes('src="javascript:'));
assert(!hostileHtml.includes('src="data:'));
assert(hostileHtml.includes('&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;'));
assert(hostileHtml.includes('&lt;img src=x onerror=alert(1)&gt;'));
assert(hostileHtml.includes('href="#"'));
assert(hostileHtml.includes('&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;&lt;&quot;'));
console.log('✓ Hostile input and XSS escaping tests passed.');

console.log('\nAll @bs/renderer tests passed successfully!');
