/**
 * @bs/renderer — Tiptap JSON to HTML, Markdown, and Plain Text
 * Shared canonical generator for static site, .md twins, llms-full.txt, and admin preview.
 */

export interface TiptapMark {
  type: string;
  // eslint-disable-next-line @typescript-eslint/no-explicit-any -- dynamic JSON attribute values
  attrs?: Record<string, any>;
}

export interface TiptapNode {
  type: string;
  // eslint-disable-next-line @typescript-eslint/no-explicit-any -- dynamic JSON attribute values
  attrs?: Record<string, any>;
  content?: TiptapNode[];
  text?: string;
  marks?: TiptapMark[];
}

export interface TiptapDoc {
  type: string;
  content?: TiptapNode[];
  schema_version?: number;
}

export type UrlResolver = (url: string) => string;

export interface RenderOptions {
  /** Optional URL resolver callback to rewrite internal URLs like bs://entry/... */
  resolveUrl?: UrlResolver;
}

const SAFE_URL_SCHEMES = ['http', 'https', 'mailto', 'tel'];

/**
 * Validates whether a URL is safe against javascript:, vbscript:, data: XSS attacks.
 * Allows safe protocols (http, https, mailto, tel), internal canonical URIs (bs://...),
 * and safe relative URLs (starting with /, #, ?, or path segment).
 * Protocol-relative URLs starting with // to arbitrary origins are rejected.
 */
export function isSafeUrl(url: string | null | undefined): boolean {
  if (!url || typeof url !== 'string') {
    return false;
  }
  const trimmed = url.trim();
  if (trimmed === '') {
    return false;
  }

  // Reject protocol-relative URLs (//evil.com)
  if (trimmed.startsWith('//')) {
    return false;
  }

  // Safe relative paths, hashes, query params
  if (trimmed.startsWith('/') || trimmed.startsWith('#') || trimmed.startsWith('?')) {
    return true;
  }

  // Internal canonical CMS URIs
  if (trimmed.startsWith('bs://')) {
    return true;
  }

  const colonPos = trimmed.indexOf(':');
  if (colonPos === -1) {
    // Relative slug or file path without leading slash
    return true;
  }

  const scheme = trimmed.substring(0, colonPos).toLowerCase();
  return SAFE_URL_SCHEMES.includes(scheme);
}

/**
 * Sanitizes and resolves a URL. If the URL fails safety checks, '#' is returned.
 */
export function sanitizeUrl(url: string | null | undefined, resolveUrl?: UrlResolver): string {
  if (!url || typeof url !== 'string') {
    return '#';
  }
  const trimmed = url.trim();
  if (!isSafeUrl(trimmed)) {
    return '#';
  }
  if (resolveUrl) {
    const resolved = resolveUrl(trimmed);
    if (isSafeUrl(resolved)) {
      return resolved;
    }
    return '#';
  }
  return trimmed;
}

/**
 * HTML attribute and text escaping.
 */
export function escapeHtml(text: string): string {
  if (typeof text !== 'string') return '';
  return text
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

/**
 * Renders a Tiptap document to clean, semantic, accessible HTML.
 */
export function renderToHtml(doc: TiptapDoc | null | undefined, options: RenderOptions = {}): string {
  if (!doc || !doc.content || !Array.isArray(doc.content)) {
    return '';
  }
  return doc.content
    .map((node) => renderNodeToHtml(node, options))
    .filter((chunk) => chunk.trim() !== '')
    .join('\n');
}

/**
 * Renders a Tiptap document to canonical GitHub Flavored Markdown with BS directives.
 */
export function renderToMarkdown(doc: TiptapDoc | null | undefined, options: RenderOptions = {}): string {
  if (!doc || !doc.content || !Array.isArray(doc.content)) {
    return '';
  }
  return doc.content
    .map((node) => renderNodeToMarkdown(node, options))
    .filter((chunk) => chunk.trim() !== '')
    .join('\n\n');
}

/**
 * Extracts plain text from a Tiptap document (useful for search index, excerpts, word count).
 */
export function renderToPlainText(doc: TiptapDoc | null | undefined): string {
  if (!doc || !doc.content || !Array.isArray(doc.content)) {
    return '';
  }
  return doc.content
    .map(renderNodeToPlainText)
    .filter((text) => text.trim() !== '')
    .join('\n\n')
    .trim();
}

/* ========================================================================= */
/* HTML Rendering                                                            */
/* ========================================================================= */

function renderMarksToHtml(text: string, marks?: TiptapMark[], options: RenderOptions = {}): string {
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
      case 'underline':
        result = `<u>${result}</u>`;
        break;
      case 'code':
        result = `<code>${result}</code>`;
        break;
      case 'highlight':
        result = `<mark>${result}</mark>`;
        break;
      case 'link': {
        const rawHref = mark.attrs?.href || '';
        const safeHref = escapeHtml(sanitizeUrl(rawHref, options.resolveUrl));
        const targetAttr = mark.attrs?.target ? ` target="${escapeHtml(mark.attrs.target)}"` : '';
        const relAttr = mark.attrs?.rel ? ` rel="${escapeHtml(mark.attrs.rel)}"` : ' rel="noopener noreferrer"';
        result = `<a href="${safeHref}"${relAttr}${targetAttr}>${result}</a>`;
        break;
      }
    }
  }
  return result;
}

function renderInlineToHtml(content?: TiptapNode[], options: RenderOptions = {}): string {
  if (!content || !Array.isArray(content)) return '';
  return content
    .map((node) => {
      if (node.type === 'text') {
        return renderMarksToHtml(node.text || '', node.marks, options);
      }
      if (node.type === 'hardBreak') {
        return '<br />';
      }
      if (node.type === 'image') {
        return renderImageToHtml(node, options);
      }
      return '';
    })
    .join('');
}

function renderImageToHtml(node: TiptapNode, options: RenderOptions = {}): string {
  const attrs = node.attrs || {};
  const rawSrc = attrs.src || '';
  const src = escapeHtml(sanitizeUrl(rawSrc, options.resolveUrl));
  const alt = escapeHtml(attrs.alt || '');
  const title = attrs.title ? ` title="${escapeHtml(attrs.title)}"` : '';
  const width = attrs.width ? ` width="${escapeHtml(String(attrs.width))}"` : '';
  const height = attrs.height ? ` height="${escapeHtml(String(attrs.height))}"` : '';
  const srcset = attrs.srcset ? ` srcset="${escapeHtml(String(attrs.srcset))}"` : '';
  const sizes = attrs.sizes ? ` sizes="${escapeHtml(String(attrs.sizes))}"` : '';
  const blurhash = attrs.blurhash ? ` data-blurhash="${escapeHtml(String(attrs.blurhash))}"` : '';
  const mediaId = attrs.media_id || attrs.mediaId ? ` data-media-id="${escapeHtml(String(attrs.media_id || attrs.mediaId))}"` : '';

  return `<img src="${src}" alt="${alt}"${title}${width}${height}${srcset}${sizes}${blurhash}${mediaId} loading="lazy" decoding="async" />`;
}

function renderNodeToHtml(node: TiptapNode, options: RenderOptions = {}): string {
  switch (node.type) {
    case 'paragraph':
      return `<p>${renderInlineToHtml(node.content, options)}</p>`;

    case 'heading': {
      const level = Math.min(6, Math.max(1, node.attrs?.level || 2));
      return `<h${level}>${renderInlineToHtml(node.content, options)}</h${level}>`;
    }

    case 'blockquote': {
      const children = (node.content || []).map((n) => renderNodeToHtml(n, options)).join('\n');
      return `<blockquote>${children}</blockquote>`;
    }

    case 'codeBlock': {
      const lang = escapeHtml(node.attrs?.language || '');
      const code = escapeHtml((node.content || []).map((n) => n.text || '').join(''));
      const classAttr = lang ? ` class="language-${lang}"` : '';
      return `<pre><code${classAttr}>${code}</code></pre>`;
    }

    case 'bulletList': {
      const items = (node.content || []).map((n) => renderNodeToHtml(n, options)).join('\n');
      return `<ul>\n${items}\n</ul>`;
    }

    case 'orderedList': {
      const items = (node.content || []).map((n) => renderNodeToHtml(n, options)).join('\n');
      const start = node.attrs?.start && node.attrs.start !== 1 ? ` start="${Number(node.attrs.start)}"` : '';
      return `<ol${start}>\n${items}\n</ol>`;
    }

    case 'listItem': {
      const inner = (node.content || [])
        .map((n) => (n.type === 'paragraph' ? renderInlineToHtml(n.content, options) : renderNodeToHtml(n, options)))
        .join('');
      return `<li>${inner}</li>`;
    }

    case 'taskList': {
      const items = (node.content || []).map((n) => renderNodeToHtml(n, options)).join('\n');
      return `<ul class="task-list">\n${items}\n</ul>`;
    }

    case 'taskItem': {
      const checked = Boolean(node.attrs?.checked);
      const inner = (node.content || [])
        .map((n) => (n.type === 'paragraph' ? renderInlineToHtml(n.content, options) : renderNodeToHtml(n, options)))
        .join('');
      const checkbox = `<input type="checkbox" disabled${checked ? ' checked' : ''} /> `;
      return `<li class="task-list-item">${checkbox}${inner}</li>`;
    }

    case 'horizontalRule':
      return '<hr />';

    case 'image':
      return renderImageToHtml(node, options);

    case 'callout': {
      const variant = escapeHtml(node.attrs?.type || node.attrs?.variant || 'info');
      const title = node.attrs?.title ? `<p class="callout-title"><strong>${escapeHtml(node.attrs.title)}</strong></p>\n` : '';
      const body = (node.content || []).map((n) => renderNodeToHtml(n, options)).join('\n');
      return `<div class="callout callout-${variant}" role="note">\n${title}${body}\n</div>`;
    }

    case 'faq': {
      const items = Array.isArray(node.attrs?.items) ? node.attrs?.items : [];
      if (items.length === 0) return '';
      const renderedItems = items
        // eslint-disable-next-line @typescript-eslint/no-explicit-any -- items array schema
        .map((item: any) => {
          const q = escapeHtml(item.question || '');
          const a = escapeHtml(item.answer || '');
          return `<details class="faq-item">\n  <summary>${q}</summary>\n  <div class="faq-answer">\n    <p>${a}</p>\n  </div>\n</details>`;
        })
        .join('\n');
      return `<div class="faq-group">\n${renderedItems}\n</div>`;
    }

    case 'keyFacts':
    case 'facts': {
      const facts = Array.isArray(node.attrs?.facts) ? node.attrs?.facts : [];
      if (facts.length === 0) return '';
      const listItems = facts
        // eslint-disable-next-line @typescript-eslint/no-explicit-any -- facts array schema
        .map((fact: any) => {
          const stmt = escapeHtml(fact.statement || '');
          const val = escapeHtml(fact.value || '');
          let srcPart = '';
          if (fact.source_url) {
            const safeSource = escapeHtml(sanitizeUrl(fact.source_url, options.resolveUrl));
            srcPart = ` <a href="${safeSource}" class="fact-source" rel="noopener noreferrer">(Source)</a>`;
          }
          return `  <div class="fact-item"><dt>${stmt}</dt><dd>${val}${srcPart}</dd></div>`;
        })
        .join('\n');
      return `<dl class="key-facts">\n${listItems}\n</dl>`;
    }

    case 'bookmark': {
      const rawUrl = node.attrs?.url || '';
      const url = escapeHtml(sanitizeUrl(rawUrl, options.resolveUrl));
      const title = escapeHtml(node.attrs?.title || rawUrl);
      const desc = node.attrs?.description ? `<p class="bookmark-desc">${escapeHtml(node.attrs.description)}</p>` : '';
      const img = node.attrs?.image ? `<img src="${escapeHtml(sanitizeUrl(node.attrs.image, options.resolveUrl))}" alt="" class="bookmark-image" loading="lazy" />` : '';
      return `<div class="bookmark-card"><a href="${url}" rel="noopener noreferrer" class="bookmark-link">${img}<div class="bookmark-content"><p class="bookmark-title"><strong>${title}</strong></p>${desc}</div></a></div>`;
    }

    case 'embed': {
      const rawSrc = node.attrs?.src || '';
      const src = escapeHtml(sanitizeUrl(rawSrc, options.resolveUrl));
      // Privacy-friendly click-to-load placeholder
      return `<div class="embed-placeholder" data-embed-src="${src}">\n  <button type="button" class="embed-load-button" onclick="this.parentElement.innerHTML='<iframe src=\\'${src}\\' frameborder=\\'0\\' allowfullscreen></iframe>'">Load interactive embed (${src})</button>\n</div>`;
    }

    case 'button': {
      const text = escapeHtml(node.attrs?.text || 'Click Here');
      const rawUrl = node.attrs?.url || '#';
      const url = escapeHtml(sanitizeUrl(rawUrl, options.resolveUrl));
      return `<a href="${url}" class="button btn-primary" role="button">${text}</a>`;
    }

    case 'details': {
      const body = (node.content || []).map((n) => renderNodeToHtml(n, options)).join('\n');
      return `<details>\n${body}\n</details>`;
    }

    case 'detailsSummary': {
      return `<summary>${renderInlineToHtml(node.content, options)}</summary>`;
    }

    case 'detailsContent': {
      return `<div class="details-content">\n${(node.content || []).map((n) => renderNodeToHtml(n, options)).join('\n')}\n</div>`;
    }

    case 'table': {
      const rows = (node.content || []).map((n) => renderNodeToHtml(n, options)).join('\n');
      return `<div class="table-container">\n<table>\n<tbody>\n${rows}\n</tbody>\n</table>\n</div>`;
    }

    case 'tableRow': {
      const cells = (node.content || []).map((n) => renderNodeToHtml(n, options)).join('');
      return `<tr>${cells}</tr>`;
    }

    case 'tableHeader': {
      const inner = (node.content || [])
        .map((n) => (n.type === 'paragraph' ? renderInlineToHtml(n.content, options) : renderNodeToHtml(n, options)))
        .join('');
      return `<th>${inner}</th>`;
    }

    case 'tableCell': {
      const inner = (node.content || [])
        .map((n) => (n.type === 'paragraph' ? renderInlineToHtml(n.content, options) : renderNodeToHtml(n, options)))
        .join('');
      return `<td>${inner}</td>`;
    }

    default:
      return '';
  }
}

/* ========================================================================= */
/* Markdown Rendering (Directives matching DocumentConverter.php)            */
/* ========================================================================= */

function renderMarksToMarkdown(text: string, marks?: TiptapMark[], options: RenderOptions = {}): string {
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
      case 'link': {
        const rawHref = mark.attrs?.href || '';
        const href = sanitizeUrl(rawHref, options.resolveUrl);
        result = `[${result}](${href})`;
        break;
      }
    }
  }
  return result;
}

function renderInlineToMarkdown(content?: TiptapNode[], options: RenderOptions = {}): string {
  if (!content || !Array.isArray(content)) return '';
  return content
    .map((node) => {
      if (node.type === 'text') {
        return renderMarksToMarkdown(node.text || '', node.marks, options);
      }
      if (node.type === 'hardBreak') {
        return '  \n';
      }
      if (node.type === 'image') {
        const alt = node.attrs?.alt || '';
        const rawSrc = node.attrs?.src || '';
        const src = sanitizeUrl(rawSrc, options.resolveUrl);
        return `![${alt}](${src})`;
      }
      return '';
    })
    .join('');
}

function renderNodeToMarkdown(node: TiptapNode, options: RenderOptions = {}): string {
  switch (node.type) {
    case 'paragraph':
      return renderInlineToMarkdown(node.content, options);

    case 'heading': {
      const level = Math.min(6, Math.max(1, node.attrs?.level || 2));
      const hashes = '#'.repeat(level);
      return `${hashes} ${renderInlineToMarkdown(node.content, options)}`;
    }

    case 'blockquote': {
      const body = (node.content || []).map((n) => renderNodeToMarkdown(n, options)).join('\n\n');
      return body
        .split('\n')
        .map((l) => `> ${l}`)
        .join('\n');
    }

    case 'codeBlock': {
      const lang = node.attrs?.language || '';
      const code = (node.content || []).map((n) => n.text || '').join('');
      return `\`\`\`${lang}\n${code}\n\`\`\``;
    }

    case 'bulletList':
      return (node.content || [])
        .map((item) => {
          const inner = (item.content || []).map((c) => renderNodeToMarkdown(c, options)).join(' ');
          return `- ${inner}`;
        })
        .join('\n');

    case 'orderedList': {
      const start = Number(node.attrs?.start || 1);
      return (node.content || [])
        .map((item, idx) => {
          const inner = (item.content || []).map((c) => renderNodeToMarkdown(c, options)).join(' ');
          return `${start + idx}. ${inner}`;
        })
        .join('\n');
    }

    case 'taskList':
      return (node.content || [])
        .map((item) => {
          const checked = item.attrs?.checked ? 'x' : ' ';
          const inner = (item.content || []).map((c) => renderNodeToMarkdown(c, options)).join(' ');
          return `- [${checked}] ${inner}`;
        })
        .join('\n');

    case 'horizontalRule':
      return '---';

    case 'image': {
      const rawSrc = node.attrs?.src || '';
      const src = sanitizeUrl(rawSrc, options.resolveUrl);
      const alt = node.attrs?.alt || '';
      const title = node.attrs?.title ? ` "${node.attrs.title}"` : '';
      return `![${alt}](${src}${title})`;
    }

    case 'callout': {
      const variant = node.attrs?.type || node.attrs?.variant || 'info';
      const title = node.attrs?.title ? ` [${node.attrs.title}]` : '';
      const body = (node.content || []).map((n) => renderNodeToMarkdown(n, options)).join('\n\n');
      return `:::callout ${variant}${title}\n${body}\n:::`;
    }

    case 'faq': {
      const items = Array.isArray(node.attrs?.items) ? node.attrs?.items : [];
      if (items.length === 0) return '';
      const lines = [':::faq'];
      // eslint-disable-next-line @typescript-eslint/no-explicit-any -- items array schema
      for (const item of items as any[]) {
        if (item.question && item.answer) {
          lines.push(`### ${item.question}`);
          lines.push(item.answer);
        }
      }
      lines.push(':::');
      return lines.join('\n');
    }

    case 'keyFacts':
    case 'facts': {
      const facts = Array.isArray(node.attrs?.facts) ? node.attrs?.facts : [];
      if (facts.length === 0) return '';
      const lines = [':::facts'];
      // eslint-disable-next-line @typescript-eslint/no-explicit-any -- facts array schema
      for (const fact of facts as any[]) {
        if (fact.statement && fact.value) {
          const source = fact.source_url ? ` | ${fact.source_url}` : '';
          lines.push(`- ${fact.statement} | ${fact.value}${source}`);
        }
      }
      lines.push(':::');
      return lines.join('\n');
    }

    case 'bookmark': {
      const rawUrl = node.attrs?.url || '';
      const url = sanitizeUrl(rawUrl, options.resolveUrl);
      const lines = [`:::bookmark ${url}`];
      if (node.attrs?.title) lines.push(`title: ${node.attrs.title}`);
      if (node.attrs?.description) lines.push(`description: ${node.attrs.description}`);
      if (node.attrs?.image) lines.push(`image: ${node.attrs.image}`);
      lines.push(':::');
      return lines.join('\n');
    }

    case 'embed': {
      const rawSrc = node.attrs?.src || '';
      const src = sanitizeUrl(rawSrc, options.resolveUrl);
      return `:::embed ${src}\n:::`;
    }

    case 'button': {
      const text = node.attrs?.text || 'Click Here';
      const rawUrl = node.attrs?.url || '#';
      const url = sanitizeUrl(rawUrl, options.resolveUrl);
      return `:::button [${text}] ${url}\n:::`;
    }

    case 'table': {
      const rows = (node.content || []).filter((n) => n.type === 'tableRow');
      if (rows.length === 0) return '';
      const lines: string[] = [];
      let isFirst = true;

      for (const row of rows) {
        const cells = (row.content || []).map((cell) => {
          return (cell.content || []).map((c) => renderNodeToMarkdown(c, options)).join(' ');
        });
        lines.push(`| ${cells.join(' | ')} |`);
        if (isFirst) {
          lines.push(`| ${cells.map(() => '---').join(' | ')} |`);
          isFirst = false;
        }
      }
      return lines.join('\n');
    }

    default:
      return '';
  }
}

/* ========================================================================= */
/* Plain Text Extraction                                                     */
/* ========================================================================= */

function renderInlineToPlainText(content?: TiptapNode[]): string {
  if (!content || !Array.isArray(content)) return '';
  return content
    .map((node) => {
      if (node.type === 'text') {
        return node.text || '';
      }
      if (node.type === 'hardBreak') {
        return ' ';
      }
      return '';
    })
    .join('');
}

function renderNodeToPlainText(node: TiptapNode): string {
  switch (node.type) {
    case 'paragraph':
    case 'heading':
      return renderInlineToPlainText(node.content);

    case 'blockquote':
    case 'callout':
      return (node.content || []).map(renderNodeToPlainText).join('\n');

    case 'codeBlock':
      return (node.content || []).map((n) => n.text || '').join('');

    case 'bulletList':
    case 'orderedList':
    case 'taskList':
      return (node.content || [])
        .map((item) => (item.content || []).map(renderNodeToPlainText).join(' '))
        .join('\n');

    case 'faq': {
      const items = Array.isArray(node.attrs?.items) ? node.attrs?.items : [];
      // eslint-disable-next-line @typescript-eslint/no-explicit-any -- faq items schema
      return items.map((i: any) => `${i.question || ''}\n${i.answer || ''}`).join('\n\n');
    }

    case 'keyFacts':
    case 'facts': {
      const facts = Array.isArray(node.attrs?.facts) ? node.attrs?.facts : [];
      // eslint-disable-next-line @typescript-eslint/no-explicit-any -- facts schema
      return facts.map((f: any) => `${f.statement || ''}: ${f.value || ''}`).join('\n');
    }

    case 'button':
      return node.attrs?.text || '';

    case 'bookmark':
      return `${node.attrs?.title || ''} ${node.attrs?.description || ''}`.trim();

    case 'table': {
      const rows = (node.content || []).filter((n) => n.type === 'tableRow');
      return rows
        .map((r) =>
          (r.content || [])
            .map((cell) => (cell.content || []).map(renderNodeToPlainText).join(' '))
            .join(' | ')
        )
        .join('\n');
    }

    default:
      return '';
  }
}
