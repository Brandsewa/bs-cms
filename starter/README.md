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
  - 404 Page (`src/pages/404.astro`): Branded not-found error page.

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
