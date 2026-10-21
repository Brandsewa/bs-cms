# BS CMS Astro Starter

The static public website starter for BS CMS. Designed for pure static publishing with zero Node daemons in production.

## Features
- **Astro Static Site Generation**: High-performance, pure static HTML output.
- **Canonical Agentic Twins**: Generates identical `.md` twins for every page alongside HTML using `@bs/renderer`.
- **Crawler & AI Discoverability**: Automatic `robots.txt` (Balanced preset), `sitemap.xml`, `llms.txt`, and `llms-full.txt`.
- **CSP & Security**: Zero runtime Node daemons. Inline scripts eliminated or hashes pinned; embeds loaded via safe self-hosted `embed.js` loader.
- **Complete Design System**: Modern, clean editorial typography, responsive layouts, cards, and dark mode support using CSS variables (`tokens.css`).
- **Pages & Blog Support**:
  - Home (`src/pages/index.astro`): Hero introduction and recent featured articles.
  - Blog Index (`src/pages/blog/index.astro`): Clean article listing with metadata and excerpts.
  - Blog Posts (`src/pages/blog/[slug].astro`): Full post rendering with structured data (Schema.org) and breadcrumbs.
  - Standalone Pages (`src/pages/[slug].astro`): Support for generic CMS pages (e.g. `/about`, `/contact`, `/terms`).
  - Draft Preview (`src/pages/preview.astro`): Short-lived preview mode (`/preview/?collection=...&id=...&token=...`) with noindex robots and strict cache headers.
  - 404 Page (`src/pages/404.astro`): Branded not-found error page.

## Page Schema & Field Types

The `pages` collection (`core/resources/schema/pages.json`) defines hierarchical static pages:

| Field | Type | Required | Description |
|---|---|---|---|
| `title` | `text` | Yes | Page headline/title (core column). |
| `slug` | `slug` | Auto / Yes | URL slug generated from title (`/{slug}/`). |
| `template` | `enum` | Yes (default: `default`) | Layout template (`default`, `landing`, `full_width`). |
| `summary` | `textarea` | No | Answer summary / sub-heading (up to 500 characters). |
| `show_in_nav` | `boolean` | No (default: true) | Whether to display the page in navigation menus. |
| `nav_label` | `text` | No | Short label to display in navigation menus (up to 64 characters). |
| `blocks` | `json` | No | Flexible page content blocks (e.g. `[{"type": "hero", "heading": "...", "text": "..."}, {"type": "text", "content": "..."}]`). |
| `featured_image` | `media` | No | Featured media asset ID. |

### Minimal Page Creation via API
When creating a page via `POST /api/v1/collections/pages/entries`, `template` defaults to `'default'`. A minimal payload requires only `title`:
```json
{
  "title": "About Us",
  "summary": "About our team and mission",
  "show_in_nav": true,
  "blocks": [
    {
      "type": "hero",
      "heading": "Welcome to our Story",
      "text": "Building high-performance static publishing tools."
    },
    {
      "type": "text",
      "content": "Founded in 2026, BS CMS eliminates server-side overhead."
    }
  ]
}
```

## Commands

### 1. Build Static Site
To build the static site against a local BS CMS instance:
```bash
# Set environment variables for the CMS instance and build token:
export BS_CMS_URL="https://localhost:8481"
export BS_BUILD_TOKEN="bsbuild_..."
export SITE_URL="https://example.com"
export NODE_TLS_REJECT_UNAUTHORIZED="0" # For local self-signed certificates

npm run build
```
This runs `astro build` followed by `scripts/generate-artifacts.mjs`, producing the full static bundle in `starter/dist/` with `deploy-manifest.json`.

### 2. Local Preview
To preview the built static site locally:
```bash
npm run preview -- --port 8181
```

### 3. Local Development Server
To run Astro's live-reload development server:
```bash
npm run dev
```

### 4. Running Tests
Run the Vitest test suite (including hermetic mock CMS tests and output verification):
```bash
npm test
```

### 5. Automated Build & Preview via Dev Simulation
If using the Hostinger local simulation (`dev/sim` or `dev/sim.ps1`):
```bash
# Build and serve against running simulation instance 'ga1':
./dev/sim site ga1

# In PowerShell:
.\dev\sim.ps1 site ga1
```
This automatically extracts a build token from the container database, builds the starter, and launches a local static preview server.
