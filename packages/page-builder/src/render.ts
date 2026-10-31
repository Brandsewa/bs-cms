import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { Render, type Config } from '@puckeditor/core';
import { sanitizePuckData } from './sanitize.js';
import { puckConfig } from './config.js';

/** Component type names declared by a config; used to filter unknown rows. */
function configComponentTypes(config: Config): Set<string> {
  return new Set(Object.keys(config.components));
}

/**
 * Render sanitized page data to a static HTML string.
 *
 * Unknown component rows are dropped and malformed props are normalized
 * first (see {@link sanitizePuckData}), so this is safe to call directly on
 * untrusted database rows. Output is plain server-rendered HTML — no
 * hydration runtime is emitted.
 */
export function renderPuckToHtml(data: unknown, config: Config = puckConfig): string {
  const sanitized = sanitizePuckData(data, configComponentTypes(config));
  return renderToStaticMarkup(createElement(Render, { config, data: sanitized }));
}
