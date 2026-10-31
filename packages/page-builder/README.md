# @bs/page-builder

Shared Puck (https://github.com/puckeditor/puck) page components for BS CMS — the single
source of truth for the visual-editor blocks. Consumed by:

- **admin** — imports `createPuckConfig()` to drive the React editor canvas; injects a
  custom media-library picker via `options.imageField`.
- **starter (Astro)** — imports `renderPuckToHtml()` for static rendering and
  `./styles.css` (subpath export `@bs/page-builder/styles.css`) for presentation.

A parallel PHP migration serializes legacy content into the same component/prop contract
defined here, so edited pages and migrated pages are interchangeable.

## API

| Export | Purpose |
| --- | --- |
| `createPuckConfig(options?)` | Puck `Config` factory. `options.imageField` replaces the default URL text field on `ImageBlock.src` (e.g. an admin media picker). |
| `puckConfig` | Default config; `imageField` falls back to a plain text field. |
| `sanitizePuckData(data, knownTypes?)` | Defensive normalizer for untrusted rows. Drops unknown types, ensures object props with a string `id` (generated as `` `${type}-${index}` ``), coerces non-array `content` to `[]`, passes `root`/`zones` through only as objects (zone contents filtered). Never throws. |
| `renderPuckToHtml(data, config?)` | `sanitizePuckData` → `renderToStaticMarkup(<Render …>)`. Safe on raw database rows. |
| `PuckData` | Re-export of Puck's `Data` (`{ root, content, zones? }`). |
| `defineIsland(name, component)` | Future-proofing helper for Astro hydration (see below). |
| `Hero`, `RichText`, `Callout`, `Faq`, `CtaBanner`, `FeaturesGrid`, `ImageBlock`, `Spacer` | The individual component configs (also their props types and `defaultImageField`). |

## Component contract

Component keys and props are camelCase; `id` is managed by Puck. Stable CSS classes per
block (see `src/styles.css`):

| Key | Props | Markup |
| --- | --- | --- |
| `Hero` | `heading, text, ctaLabel, ctaUrl` | `section.bs-hero` |
| `RichText` | `html` (trusted CMS HTML, rendered verbatim) | `div.bs-richtext` |
| `Callout` | `variant ('info'\|'tip'\|'warning'\|'note'), title, content` | `aside.bs-callout.bs-callout--{variant}` |
| `Faq` | `items: {question, answer}[]` | `section.bs-faq` |
| `CtaBanner` | `heading, text, buttonLabel, buttonUrl, align ('left'\|'center')` | `section.bs-cta.bs-cta--{align}` |
| `FeaturesGrid` | `columns ('2'\|'3'\|'4'), items: {title, description}[]` | `section.bs-features.bs-features--{cols}` |
| `ImageBlock` | `src` (injectable image field), `alt`, `caption?` | `figure.bs-image` |
| `Spacer` | `height` (px) | `div.bs-spacer` |

## Styling

`src/styles.css` styles every `.bs-*` class using the branding custom properties the
starter generates (`--color-primary`, `--color-heading`, `--color-text`, `--color-bg`,
`--color-surface`, `--btn-*`, `--card-*`, `--image-*`, `--font-serif`, `--font-sans`,
`--site-width`), each with a hard fallback so blocks render presentably standalone and on
the admin editor canvas. All rules are scoped under `.bs-*`; nothing leaks.

## Island convention (Astro hydration)

Static pages are plain HTML; when a block needs client behavior, the starter marks a mount
point with `data-bs-island="{name}"` (optionally `data-bs-island-props` for serialized
props) and hydrates the component registered under that name:

```ts
import { defineIsland } from '@bs/page-builder';

export const islands = [defineIsland('faq', FaqIsland)] as const;
```

`defineIsland` only normalizes the `{ name, component }` pair — the loader that walks
`[data-bs-island]` lives (or will live) in the starter.

## Development

From the repo root:

```sh
npm run test -w @bs/page-builder       # vitest (node env)
npm run typecheck -w @bs/page-builder  # tsc --noEmit
npx eslint packages/page-builder/src packages/page-builder/test
```
