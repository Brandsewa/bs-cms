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

const MAX_RENDER_DEPTH = 32;

const SAFE_URL_SCHEMES = ['http', 'https', 'mailto', 'tel'];

const ALLOWED_CALLOUT_VARIANTS = new Set([
  'info',
  'warning',
  'tip',
  'note',
  'success',
  'error',
  'danger',
]);

const ALLOWED_EMBED_HOSTS = [
  'youtube.com',
  'www.youtube.com',
  'youtube-nocookie.com',
  'www.youtube-nocookie.com',
  'youtu.be',
  'vimeo.com',
  'player.vimeo.com',
  'twitter.com',
  'x.com',
  'open.spotify.com',
  'soundcloud.com',
  'w.soundcloud.com',
  'codepen.io',
];

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

  // Remove leading/trailing and inner ASCII control characters (null, newline, tab, etc.)
  // and decode numeric entities for scheme detection
  let normalized = url.replace(/[\u0000-\u001F\u007F-\u009F\s]/g, '');

  // Check for HTML entity obfuscation like &#106;avascript: or &#x6a;avascript:
  normalized = normalized.replace(/&#(\d+);?/g, (_, dec) => String.fromCharCode(Number(dec)));
  normalized = normalized.replace(/&#x([0-9a-fA-F]+);?/g, (_, hex) => String.fromCharCode(parseInt(hex, 16)));
  // Re-clean control chars that may have been decoded
  normalized = normalized.replace(/[\u0000-\u001F\u007F-\u009F\s]/g, '');

  if (normalized === '') {
    return false;
  }

  // Explicitly check for dangerous protocols anywhere or after obfuscation
  const lower = normalized.toLowerCase();
  if (
    lower.startsWith('javascript:') ||
    lower.startsWith('vbscript:') ||
    lower.startsWith('data:') ||
    lower.startsWith('file:') ||
    lower.startsWith('//')
  ) {
    return false;
  }

  const trimmed = url.trim();

  // Reject protocol-relative URLs (//evil.example)
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
 * Validates whether an embed URL is safe (https only, no credentials, in allow-list).
 */
export function isAllowedEmbedUrl(url: string | null | undefined): boolean {
  if (!url || typeof url !== 'string') {
    return false;
  }
  const trimmed = url.trim();
  if (!trimmed.startsWith('https://')) {
    return false;
  }
  try {
    const parsed = new URL(trimmed);
    if (parsed.protocol !== 'https:') {
      return false;
    }
    const hostname = parsed.hostname.toLowerCase();
    return ALLOWED_EMBED_HOSTS.some(
      (allowed) => hostname === allowed || hostname.endsWith(`.${allowed}`)
    );
  } catch {
    return false;
  }
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
 * Escapes text for Markdown representation:
 * - Escapes raw HTML tags & entities (<, >, &)
 * - Escapes Markdown syntax characters ([ ] ( ) * _ ~ ` # \) to prevent formatting/link injection.
 */
export function escapeMarkdownText(text: string): string {
  if (typeof text !== 'string') return '';
  return text
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/\[/g, '\\[')
    .replace(/\]/g, '\\]')
    .replace(/\(/g, '\\(')
    .replace(/\)/g, '\\)');
}

/**
 * Sanitizes a destination URL for Markdown links/images.
 */
export function sanitizeMarkdownUrl(url: string | null | undefined, resolveUrl?: UrlResolver): string {
  const safe = sanitizeUrl(url, resolveUrl);
  if (safe === '#') return '#';
  // Percent-encode parens and spaces to prevent Markdown link breakout
  return safe.replace(/ /g, '%20').replace(/\(/g, '%28').replace(/\)/g, '%29');
}

/**
 * Renders a Tiptap document to clean, semantic, accessible HTML.
 */
export function renderToHtml(doc: TiptapDoc | null | undefined, options: RenderOptions = {}): string {
  if (!doc || !doc.content || !Array.isArray(doc.content)) {
    return '';
  }
  return doc.content
    .map((node) => renderNodeToHtml(node, options, 0))
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
    .map((node) => renderNodeToMarkdown(node, options, 0))
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
    .map((node) => renderNodeToPlainText(node, 0))
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

function renderInlineToHtml(content?: TiptapNode[], options: RenderOptions = {}, depth = 0): string {
  if (!content || !Array.isArray(content) || depth > MAX_RENDER_DEPTH) return '';
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

  // Width & height must be numeric digits only
  const widthVal = attrs.width ? String(attrs.width).replace(/[^\d]/g, '') : '';
  const heightVal = attrs.height ? String(attrs.height).replace(/[^\d]/g, '') : '';
  const width = widthVal ? ` width="${widthVal}"` : '';
  const height = heightVal ? ` height="${heightVal}"` : '';

  // Sanitized srcset: ensure every URL part is safe
  let safeSrcset = '';
  if (typeof attrs.srcset === 'string' && attrs.srcset.trim() !== '') {
    const candidateParts = attrs.srcset.split(',').map((p: string) => p.trim());
    const validCandidates: string[] = [];
    for (const cand of candidateParts) {
      const [u, descriptor] = cand.split(/\s+/, 2);
      if (u && isSafeUrl(u)) {
        const desc = descriptor ? ` ${escapeHtml(descriptor)}` : '';
        validCandidates.push(`${escapeHtml(u)}${desc}`);
      }
    }
    if (validCandidates.length > 0) {
      safeSrcset = ` srcset="${validCandidates.join(', ')}"`;
    }
  }

  const sizes = attrs.sizes ? ` sizes="${escapeHtml(String(attrs.sizes))}"` : '';
  const blurhash = attrs.blurhash ? ` data-blurhash="${escapeHtml(String(attrs.blurhash))}"` : '';
  const mediaId = attrs.media_id || attrs.mediaId ? ` data-media-id="${escapeHtml(String(attrs.media_id || attrs.mediaId))}"` : '';

  return `<img src="${src}" alt="${alt}"${title}${width}${height}${safeSrcset}${sizes}${blurhash}${mediaId} loading="lazy" decoding="async" />`;
}

function normalizeCalloutVariant(variantRaw: unknown): string {
  if (typeof variantRaw === 'string') {
    const v = variantRaw.trim().toLowerCase();
    if (ALLOWED_CALLOUT_VARIANTS.has(v)) {
      return v;
    }
  }
  return 'info';
}

function renderNodeToHtml(node: TiptapNode, options: RenderOptions = {}, depth = 0): string {
  if (depth > MAX_RENDER_DEPTH) {
    return '';
  }

  switch (node.type) {
    case 'paragraph':
      return `<p>${renderInlineToHtml(node.content, options, depth + 1)}</p>`;

    case 'heading': {
      const level = Math.min(6, Math.max(1, Number(node.attrs?.level) || 2));
      return `<h${level}>${renderInlineToHtml(node.content, options, depth + 1)}</h${level}>`;
    }

    case 'blockquote': {
      const children = (node.content || []).map((n) => renderNodeToHtml(n, options, depth + 1)).join('\n');
      return `<blockquote>${children}</blockquote>`;
    }

    case 'codeBlock': {
      const lang = escapeHtml(node.attrs?.language || '');
      const code = escapeHtml((node.content || []).map((n) => n.text || '').join(''));
      const classAttr = lang ? ` class="language-${lang}"` : '';
      return `<pre><code${classAttr}>${code}</code></pre>`;
    }

    case 'bulletList': {
      const items = (node.content || []).map((n) => renderNodeToHtml(n, options, depth + 1)).join('\n');
      return `<ul>\n${items}\n</ul>`;
    }

    case 'orderedList': {
      const items = (node.content || []).map((n) => renderNodeToHtml(n, options, depth + 1)).join('\n');
      const start = node.attrs?.start && node.attrs.start !== 1 ? ` start="${Number(node.attrs.start)}"` : '';
      return `<ol${start}>\n${items}\n</ol>`;
    }

    case 'listItem': {
      const inner = (node.content || [])
        .map((n) => (n.type === 'paragraph' ? renderInlineToHtml(n.content, options, depth + 1) : renderNodeToHtml(n, options, depth + 1)))
        .join('');
      return `<li>${inner}</li>`;
    }

    case 'taskList': {
      const items = (node.content || []).map((n) => renderNodeToHtml(n, options, depth + 1)).join('\n');
      return `<ul class="task-list">\n${items}\n</ul>`;
    }

    case 'taskItem': {
      const checked = Boolean(node.attrs?.checked);
      const inner = (node.content || [])
        .map((n) => (n.type === 'paragraph' ? renderInlineToHtml(n.content, options, depth + 1) : renderNodeToHtml(n, options, depth + 1)))
        .join('');
      const checkbox = `<input type="checkbox" disabled${checked ? ' checked' : ''} /> `;
      return `<li class="task-list-item">${checkbox}${inner}</li>`;
    }

    case 'horizontalRule':
      return '<hr />';

    case 'image':
      return renderImageToHtml(node, options);

    case 'callout': {
      const variant = normalizeCalloutVariant(node.attrs?.variant || node.attrs?.type);
      const title = node.attrs?.title ? `<p class="callout-title"><strong>${escapeHtml(node.attrs.title)}</strong></p>\n` : '';
      const body = (node.content || []).map((n) => renderNodeToHtml(n, options, depth + 1)).join('\n');
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
      const rawSrc = node.attrs?.url || node.attrs?.src || '';
      const provider = escapeHtml(node.attrs?.provider || '');
      // Validate allow-list and https
      if (!isAllowedEmbedUrl(rawSrc)) {
        // Safe fallback when unallowed or invalid
        const safeLink = sanitizeUrl(rawSrc, options.resolveUrl);
        if (safeLink !== '#') {
          return `<div class="embed-fallback"><a href="${escapeHtml(safeLink)}" rel="noopener noreferrer" target="_blank">View embedded content (${escapeHtml(safeLink)})</a></div>`;
        }
        return '';
      }
      const safeSrc = escapeHtml(rawSrc);
      const providerAttr = provider ? ` data-embed-provider="${provider}"` : '';
      // Pure semantic placeholder with data attributes, zero inline event handlers or scripts
      return `<div class="embed-placeholder" data-embed-src="${safeSrc}"${providerAttr}>\n  <button type="button" class="embed-load-button">Load interactive embed (${safeSrc})</button>\n</div>`;
    }

    case 'button': {
      const label = escapeHtml(node.attrs?.label || node.attrs?.text || 'Click Here');
      const rawUrl = node.attrs?.url || '#';
      const url = escapeHtml(sanitizeUrl(rawUrl, options.resolveUrl));
      return `<a href="${url}" class="button btn-primary" role="button">${label}</a>`;
    }

    case 'details': {
      const body = (node.content || []).map((n) => renderNodeToHtml(n, options, depth + 1)).join('\n');
      return `<details>\n${body}\n</details>`;
    }

    case 'detailsSummary': {
      return `<summary>${renderInlineToHtml(node.content, options, depth + 1)}</summary>`;
    }

    case 'detailsContent': {
      return `<div class="details-content">\n${(node.content || []).map((n) => renderNodeToHtml(n, options, depth + 1)).join('\n')}\n</div>`;
    }

    case 'table': {
      const rows = (node.content || []).map((n) => renderNodeToHtml(n, options, depth + 1)).join('\n');
      return `<div class="table-container">\n<table>\n<tbody>\n${rows}\n</tbody>\n</table>\n</div>`;
    }

    case 'tableRow': {
      const cells = (node.content || []).map((n) => renderNodeToHtml(n, options, depth + 1)).join('');
      return `<tr>${cells}</tr>`;
    }

    case 'tableHeader': {
      const inner = (node.content || [])
        .map((n) => (n.type === 'paragraph' ? renderInlineToHtml(n.content, options, depth + 1) : renderNodeToHtml(n, options, depth + 1)))
        .join('');
      return `<th>${inner}</th>`;
    }

    case 'tableCell': {
      const inner = (node.content || [])
        .map((n) => (n.type === 'paragraph' ? renderInlineToHtml(n.content, options, depth + 1) : renderNodeToHtml(n, options, depth + 1)))
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
  if (!marks || marks.length === 0) return escapeMarkdownText(text);
  let result = escapeMarkdownText(text);
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
        const href = sanitizeMarkdownUrl(rawHref, options.resolveUrl);
        result = `[${result}](${href})`;
        break;
      }
    }
  }
  return result;
}

function renderInlineToMarkdown(content?: TiptapNode[], options: RenderOptions = {}, depth = 0): string {
  if (!content || !Array.isArray(content) || depth > MAX_RENDER_DEPTH) return '';
  return content
    .map((node) => {
      if (node.type === 'text') {
        return renderMarksToMarkdown(node.text || '', node.marks, options);
      }
      if (node.type === 'hardBreak') {
        return '  \n';
      }
      if (node.type === 'image') {
        const alt = escapeMarkdownText(node.attrs?.alt || '');
        const rawSrc = node.attrs?.src || '';
        const src = sanitizeMarkdownUrl(rawSrc, options.resolveUrl);
        return `![${alt}](${src})`;
      }
      return '';
    })
    .join('');
}

function renderNodeToMarkdown(node: TiptapNode, options: RenderOptions = {}, depth = 0): string {
  if (depth > MAX_RENDER_DEPTH) {
    return '';
  }

  switch (node.type) {
    case 'paragraph':
      return renderInlineToMarkdown(node.content, options, depth + 1);

    case 'heading': {
      const level = Math.min(6, Math.max(1, Number(node.attrs?.level) || 2));
      const hashes = '#'.repeat(level);
      return `${hashes} ${renderInlineToMarkdown(node.content, options, depth + 1)}`;
    }

    case 'blockquote': {
      const body = (node.content || []).map((n) => renderNodeToMarkdown(n, options, depth + 1)).join('\n\n');
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
          const inner = (item.content || []).map((c) => renderNodeToMarkdown(c, options, depth + 1)).join(' ');
          return `- ${inner}`;
        })
        .join('\n');

    case 'orderedList': {
      const start = Number(node.attrs?.start || 1);
      return (node.content || [])
        .map((item, idx) => {
          const inner = (item.content || []).map((c) => renderNodeToMarkdown(c, options, depth + 1)).join(' ');
          return `${start + idx}. ${inner}`;
        })
        .join('\n');
    }

    case 'taskList':
      return (node.content || [])
        .map((item) => {
          const checked = item.attrs?.checked ? 'x' : ' ';
          const inner = (item.content || []).map((c) => renderNodeToMarkdown(c, options, depth + 1)).join(' ');
          return `- [${checked}] ${inner}`;
        })
        .join('\n');

    case 'horizontalRule':
      return '---';

    case 'image': {
      const rawSrc = node.attrs?.src || '';
      const src = sanitizeMarkdownUrl(rawSrc, options.resolveUrl);
      const alt = escapeMarkdownText(node.attrs?.alt || '');
      const title = node.attrs?.title ? ` "${escapeMarkdownText(node.attrs.title)}"` : '';
      return `![${alt}](${src}${title})`;
    }

    case 'callout': {
      const variant = normalizeCalloutVariant(node.attrs?.variant || node.attrs?.type);
      const title = node.attrs?.title ? ` [${escapeMarkdownText(node.attrs.title)}]` : '';
      const body = (node.content || []).map((n) => renderNodeToMarkdown(n, options, depth + 1)).join('\n\n');
      return `:::callout ${variant}${title}\n${body}\n:::`;
    }

    case 'faq': {
      const items = Array.isArray(node.attrs?.items) ? node.attrs?.items : [];
      if (items.length === 0) return '';
      const lines = [':::faq'];
      // eslint-disable-next-line @typescript-eslint/no-explicit-any -- items array schema
      for (const item of items as any[]) {
        if (item.question && item.answer) {
          lines.push(`### ${escapeMarkdownText(item.question)}`);
          lines.push(escapeMarkdownText(item.answer));
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
          const stmt = escapeMarkdownText(fact.statement);
          const val = escapeMarkdownText(fact.value);
          const source = fact.source_url ? ` | ${sanitizeMarkdownUrl(fact.source_url, options.resolveUrl)}` : '';
          lines.push(`- ${stmt} | ${val}${source}`);
        }
      }
      lines.push(':::');
      return lines.join('\n');
    }

    case 'bookmark': {
      const rawUrl = node.attrs?.url || '';
      const url = sanitizeMarkdownUrl(rawUrl, options.resolveUrl);
      const lines = [`:::bookmark ${url}`];
      if (node.attrs?.title) lines.push(`title: ${escapeMarkdownText(node.attrs.title)}`);
      if (node.attrs?.description) lines.push(`description: ${escapeMarkdownText(node.attrs.description)}`);
      if (node.attrs?.image) lines.push(`image: ${sanitizeMarkdownUrl(node.attrs.image, options.resolveUrl)}`);
      lines.push(':::');
      return lines.join('\n');
    }

    case 'embed': {
      const rawSrc = node.attrs?.url || node.attrs?.src || '';
      if (!isAllowedEmbedUrl(rawSrc)) {
        return '';
      }
      const src = sanitizeMarkdownUrl(rawSrc, options.resolveUrl);
      return `:::embed ${src}\n:::`;
    }

    case 'button': {
      const label = escapeMarkdownText(node.attrs?.label || node.attrs?.text || 'Click Here');
      const rawUrl = node.attrs?.url || '#';
      const url = sanitizeMarkdownUrl(rawUrl, options.resolveUrl);
      return `:::button [${label}] ${url}\n:::`;
    }

    case 'table': {
      const rows = (node.content || []).filter((n) => n.type === 'tableRow');
      if (rows.length === 0) return '';
      const lines: string[] = [];
      let isFirst = true;

      for (const row of rows) {
        const cells = (row.content || []).map((cell) => {
          return (cell.content || []).map((c) => renderNodeToMarkdown(c, options, depth + 1)).join(' ');
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

function renderInlineToPlainText(content?: TiptapNode[], depth = 0): string {
  if (!content || !Array.isArray(content) || depth > MAX_RENDER_DEPTH) return '';
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

function renderNodeToPlainText(node: TiptapNode, depth = 0): string {
  if (depth > MAX_RENDER_DEPTH) {
    return '';
  }

  switch (node.type) {
    case 'paragraph':
    case 'heading':
      return renderInlineToPlainText(node.content, depth + 1);

    case 'blockquote':
    case 'callout':
      return (node.content || []).map((n) => renderNodeToPlainText(n, depth + 1)).join('\n');

    case 'codeBlock':
      return (node.content || []).map((n) => n.text || '').join('');

    case 'bulletList':
    case 'orderedList':
    case 'taskList':
      return (node.content || [])
        .map((item) => (item.content || []).map((c) => renderNodeToPlainText(c, depth + 1)).join(' '))
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
      return node.attrs?.label || node.attrs?.text || '';

    case 'bookmark':
      return `${node.attrs?.title || ''} ${node.attrs?.description || ''}`.trim();

    case 'table': {
      const rows = (node.content || []).filter((n) => n.type === 'tableRow');
      return rows
        .map((r) =>
          (r.content || [])
            .map((cell) => (cell.content || []).map((c) => renderNodeToPlainText(c, depth + 1)).join(' '))
            .join(' | ')
        )
        .join('\n');
    }

    default:
      return '';
  }
}
