import { strict as assert } from 'node:assert';
import { renderToHtml, renderToMarkdown } from '../src/index.js';

const sampleDoc = {
  type: 'doc',
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
        { type: 'text', text: '. Check the ' },
        { type: 'text', text: 'documentation', marks: [{ type: 'link', attrs: { href: 'https://example.com/docs' } }] },
        { type: 'text', text: '.' }
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
      type: 'callout',
      attrs: { type: 'tip' },
      content: [
        {
          type: 'paragraph',
          content: [{ type: 'text', text: 'All HTML and Markdown render from the same stored AST.' }]
        }
      ]
    }
  ]
};

console.log('Testing renderToHtml...');
const html = renderToHtml(sampleDoc);
assert(html.includes('<h2>Fast Headless CMS on Shared Hosting</h2>'));
assert(html.includes('<strong>PHP 8.2+</strong>'));
assert(html.includes('<em>MySQL/MariaDB</em>'));
assert(html.includes('<a href="https://example.com/docs" rel="noopener noreferrer">documentation</a>'));
assert(html.includes('<pre><code class="language-php">&lt;?php\ndeclare(strict_types=1);</code></pre>'));
assert(html.includes('<li>Zero Node daemons on server</li>'));
assert(html.includes('<div class="callout callout-tip">'));
console.log('HTML Rendering Passed!\n');
console.log(html);

console.log('\n----------------------------------------\n');

console.log('Testing renderToMarkdown...');
const md = renderToMarkdown(sampleDoc);
assert(md.includes('## Fast Headless CMS on Shared Hosting'));
assert(md.includes('**PHP 8.2+**'));
assert(md.includes('*MySQL/MariaDB*'));
assert(md.includes('[documentation](https://example.com/docs)'));
assert(md.includes('```php\n<?php\ndeclare(strict_types=1);\n```'));
assert(md.includes('- Zero Node daemons on server'));
assert(md.includes(':::tip'));
console.log('Markdown Rendering Passed!\n');
console.log(md);

console.log('\nAll @bs/renderer tests passed successfully!');
