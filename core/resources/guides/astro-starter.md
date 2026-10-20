# BS CMS Astro Starter Guide

The Astro starter (`starter/`) is the default static frontend for BS CMS. It consumes content from the BS CMS Headless REST API (`/api/v1`), generates static HTML at build time, and delivers zero-JavaScript content with zero Node.js daemons in production.

---

## 1. Architecture & Design Principles

- **Zero Runtime Node Daemons**: Astro builds statically to HTML/CSS/JS files placed in the web server's document root. Apache or LiteSpeed serves the public site directly.
- **Islands Architecture**: Pages are pure static HTML by default. Interactive components (such as click-to-load video embeds) hydrate on demand without blocking initial paint.
- **Strict CSP Compliant**: All script and style tags adhere to strict Content Security Policy directives (`script-src 'self'`).

---

## 2. Directory Layout

```
starter/
├── public/                # Static public assets (favicons, robots.txt, embed.js)
├── src/
│   ├── layouts/
│   │   └── BaseLayout.astro  # Global page shell (meta tags, JSON-LD, header/footer)
│   ├── lib/
│   │   └── bs.ts             # Typed API client for BS CMS REST endpoints
│   ├── pages/
│   │   ├── index.astro       # Homepage template
│   │   └── blog/
│   │       ├── index.astro   # Post archive list template
│   │       └── [slug].astro  # Dynamic single entry detail template
│   └── styles/
│       └── tokens.css        # Central design tokens and component styling
├── astro.config.mjs       # Astro configuration
└── package.json           # Dependencies and build scripts
```

---

## 3. Theming via `tokens.css`

The styling system is driven entirely by CSS custom properties defined in `src/styles/tokens.css`. Agents and developers can customize colors, typography, and spacing without altering markup.

### Core Design Tokens
```css
:root {
  /* Typography */
  --font-sans: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
  --font-mono: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;

  /* Palette */
  --color-bg: #ffffff;
  --color-text: #1a1a1a;
  --color-muted: #666666;
  --color-border: #e5e5e5;
  --color-primary: #0066cc;
  --color-primary-hover: #0052a3;

  /* Directives & Callouts */
  --color-callout-info: #0284c7;
  --color-callout-tip: #16a34a;
  --color-callout-warning: #ea580c;
  --color-callout-note: #6b7280;
}
```

### Dark Mode
A dark mode theme is built in via `@media (prefers-color-scheme: dark)` and overrides the relevant CSS variables automatically.

---

## 4. Content Fetching & API Integration (`bs.ts`)

The helper library `starter/src/lib/bs.ts` wraps the BS CMS REST API:
- `fetchEntries(collectionHandle, params)`: Retrieves paginated lists of published entries.
- `fetchEntryBySlug(collectionHandle, slug)`: Fetches a single published entry with parsed document body, derived fields, and metadata.
- `getSiteMetadata()`: Retrieves global site settings (site title, description, language, social defaults).
- `escapeJsonLd(data)`: Sanitizes structured data to prevent XSS when embedding `<script type="application/ld+json">`.

---

## 5. Build and Deploy Cycle

1. Content is edited and published in the BS CMS admin or via MCP tools.
2. A publish event triggers static site generation via `trigger_deploy` or a webhook.
3. The build command `npm run build` generates the production output in `dist/`.
4. The output HTML is immediately served to visitors with sub-50ms TTFB on commodity shared hosting.
