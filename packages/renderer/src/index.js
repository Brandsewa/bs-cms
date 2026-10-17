/**
 * @bs/renderer — Tiptap JSON to HTML and Markdown
 * Shared between Admin preview and Astro static rendering.
 */

export function renderToHtml(doc) {
  if (!doc || !doc.content || !Array.isArray(doc.content)) {
    return '';
  }
  return doc.content.map(renderNodeToHtml).join('\n');
}

export function renderToMarkdown(doc) {
  if (!doc || !doc.content || !Array.isArray(doc.content)) {
    return '';
  }
  return doc.content.map(renderNodeToMarkdown).join('\n\n');
}

function escapeHtml(text) {
  if (typeof text !== 'string') return '';
  return text
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function renderMarksToHtml(text, marks) {
  if (!marks || marks.length === 0) return escapeHtml(text);
  let result = escapeHtml(text);
  for (const mark of marks) {
    switch (mark.type) {
      case 'bold':
        result = `<strong>${result}</strong>`;
        break;
      case 'italic':
        result = `<em>${result}</em>`;
        break;
      case 'strike':
        result = `<s>${result}</s>`;
        break;
      case 'code':
        result = `<code>${result}</code>`;
        break;
      case 'link':
        const href = escapeHtml(mark.attrs?.href || '');
        result = `<a href="${href}" rel="noopener noreferrer">${result}</a>`;
        break;
    }
  }
  return result;
}

function renderMarksToMarkdown(text, marks) {
  if (!marks || marks.length === 0) return text;
  let result = text;
  for (const mark of marks) {
    switch (mark.type) {
      case 'bold':
        result = `**${result}**`;
        break;
      case 'italic':
        result = `*${result}*`;
        break;
      case 'strike':
        result = `~~${result}~~`;
        break;
      case 'code':
        result = `\`${result}\``;
        break;
      case 'link':
        const href = mark.attrs?.href || '';
        result = `[${result}](${href})`;
        break;
    }
  }
  return result;
}

function renderInlineToHtml(content) {
  if (!content || !Array.isArray(content)) return '';
  return content.map(node => {
    if (node.type === 'text') {
      return renderMarksToHtml(node.text || '', node.marks);
    }
    return '';
  }).join('');
}

function renderInlineToMarkdown(content) {
  if (!content || !Array.isArray(content)) return '';
  return content.map(node => {
    if (node.type === 'text') {
      return renderMarksToMarkdown(node.text || '', node.marks);
    }
    return '';
  }).join('');
}

function renderNodeToHtml(node) {
  switch (node.type) {
    case 'paragraph':
      return `<p>${renderInlineToHtml(node.content)}</p>`;
    case 'heading':
      const level = Math.min(6, Math.max(1, node.attrs?.level || 2));
      return `<h${level}>${renderInlineToHtml(node.content)}</h${level}>`;
    case 'blockquote':
      return `<blockquote>${(node.content || []).map(renderNodeToHtml).join('\n')}</blockquote>`;
    case 'codeBlock':
      const lang = escapeHtml(node.attrs?.language || '');
      const code = escapeHtml((node.content || []).map(n => n.text).join(''));
      return `<pre><code class="language-${lang}">${code}</code></pre>`;
    case 'bulletList':
      return `<ul>\n${(node.content || []).map(renderNodeToHtml).join('\n')}\n</ul>`;
    case 'orderedList':
      return `<ol>\n${(node.content || []).map(renderNodeToHtml).join('\n')}\n</ol>`;
    case 'listItem':
      return `<li>${(node.content || []).map(n => n.type === 'paragraph' ? renderInlineToHtml(n.content) : renderNodeToHtml(n)).join('')}</li>`;
    case 'horizontalRule':
      return '<hr />';
    case 'image':
      const src = escapeHtml(node.attrs?.src || '');
      const alt = escapeHtml(node.attrs?.alt || '');
      const title = node.attrs?.title ? ` title="${escapeHtml(node.attrs.title)}"` : '';
      return `<img src="${src}" alt="${alt}"${title} loading="lazy" />`;
    case 'callout':
      const calloutType = escapeHtml(node.attrs?.type || 'note');
      return `<div class="callout callout-${calloutType}">\n${(node.content || []).map(renderNodeToHtml).join('\n')}\n</div>`;
    default:
      return '';
  }
}

function renderNodeToMarkdown(node) {
  switch (node.type) {
    case 'paragraph':
      return renderInlineToMarkdown(node.content);
    case 'heading':
      const level = Math.min(6, Math.max(1, node.attrs?.level || 2));
      const hashes = '#'.repeat(level);
      return `${hashes} ${renderInlineToMarkdown(node.content)}`;
    case 'blockquote':
      const quoteBody = (node.content || []).map(renderNodeToMarkdown).join('\n\n');
      return quoteBody.split('\n').map(line => `> ${line}`).join('\n');
    case 'codeBlock':
      const lang = node.attrs?.language || '';
      const code = (node.content || []).map(n => n.text).join('');
      return `\`\`\`${lang}\n${code}\n\`\`\``;
    case 'bulletList':
      return (node.content || []).map(item => `- ${renderInlineToMarkdown(item.content?.[0]?.content || [])}`).join('\n');
    case 'orderedList':
      return (node.content || []).map((item, idx) => `${idx + 1}. ${renderInlineToMarkdown(item.content?.[0]?.content || [])}`).join('\n');
    case 'horizontalRule':
      return '---';
    case 'image':
      const src = node.attrs?.src || '';
      const alt = node.attrs?.alt || '';
      return `![${alt}](${src})`;
    case 'callout':
      const calloutType = node.attrs?.type || 'note';
      const body = (node.content || []).map(renderNodeToMarkdown).join('\n\n');
      return `:::${calloutType}\n${body}\n:::`;
    default:
      return '';
  }
}
