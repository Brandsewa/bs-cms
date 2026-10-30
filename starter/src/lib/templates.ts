import type { EntryItem } from '@bs/client';
import { cmsClient } from './bs';

/**
 * Minimal Mustache-like templating for CMS-authored frontend templates.
 *
 * Supported syntax:
 *   {{ field }}          — HTML-escaped value (dot paths allowed)
 *   {{{ field }}}        — raw value (not escaped)
 *   {{#each items}}      — iterate an array; inside, names resolve against
 *     ...                  the item first, then the outer context
 *   {{/each}}
 *
 * Deliberately no conditionals or helpers: templates are authored by
 * coding agents/developers and must stay predictable and safe.
 */

export function escapeHtml(value: unknown): string {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function resolvePath(path: string, ctx: Record<string, unknown>): unknown {
  return path
    .split('.')
    .reduce<unknown>((node, key) => (node && typeof node === 'object' ? (node as Record<string, unknown>)[key] : undefined), ctx);
}

export function renderTemplate(template: string, ctx: Record<string, unknown>): string {
  const eachRe = /\{\{#each\s+([\w.]+)\}\}([\s\S]*?)\{\{\/each\}\}/g;
  const ifRe = /\{\{#if\s+([\w.]+)\}\}([\s\S]*?)\{\{\/if\}\}/g;
  const rawRe = /\{\{\{\s*([\w.]+)\s*\}\}\}/g;
  const escRe = /\{\{\s*([\w.]+)\s*\}\}/g;

  const render = (source: string, context: Record<string, unknown>): string => {
    // Expand #each (loop, item fields override outer context) and #if
    // (truthy check) blocks, then interpolate tokens.
    let out = source.replace(eachRe, (_m, path: string, body: string) => {
      const list = resolvePath(path, context);
      if (!Array.isArray(list)) return '';
      return list
        .map((item) =>
          render(
            body,
            item && typeof item === 'object'
              ? { ...(context as object), ...(item as object) }
              : { ...(context as object), this: item },
          ),
        )
        .join('');
    });
    out = out.replace(ifRe, (_m, path: string, body: string) => {
      const value = resolvePath(path, context);
      return value ? render(body, context) : '';
    });
    return out
      .replace(rawRe, (_m, path: string) => String(resolvePath(path, context) ?? ''))
      .replace(escRe, (_m, path: string) => escapeHtml(resolvePath(path, context)));
  };

  return render(template, ctx);
}

/**
 * Loads all published templates from the CMS, keyed by their `tpl_handle`
 * (e.g. "blog-archive", "hotels-single"). Missing collection resolves to {}.
 */
export async function loadTemplates(): Promise<Record<string, string>> {
  try {
    const entries = await cmsClient.fetchAllEntries('templates');
    const map: Record<string, string> = {};
    for (const entry of entries as Array<EntryItem & Record<string, unknown>>) {
      const handle = String(entry.tpl_handle || entry.slug || '').trim();
      const html = entry.html_code;
      if (handle && typeof html === 'string' && html.trim()) {
        map[handle] = html;
      }
    }
    return map;
  } catch {
    return {};
  }
}

/** Entry context for single-page templates: top-level fields plus computed url. */
export function singleContext(entry: Record<string, unknown>, url: string): Record<string, unknown> {
  return { ...(entry.data as Record<string, unknown>), ...entry, url };
}
