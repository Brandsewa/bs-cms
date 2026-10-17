# Phase 7: Astro starter, publishing pipeline, preview, discoverability outputs

**Goal:** a fast static starter site built from CMS content, a reliable publish-to-live pipeline that works with shared hosting, draft preview, and every discoverability output (SEO, GEO/AEO, agentic indexing, crawler policy) on by default and safe for production.
**Depends on:** Phase 4 (API data) and Phase 6 for admin screens of this phase. **Specs:** `docs/04-crawlers-and-discoverability.md` (all), `docs/05-editor.md` section 4, Phase 0 Spike C decision.

## 7.1 Migrations
`deployments`, `preview_tokens`, `build_tokens`, `crawler_policy_versions`, `index_notifications`, `bot_log` (DDL in `docs/01-data-model.md` sections 6 and "Publishing pipeline tables").

## 7.2 Packages
- `@bs/renderer`: renders the BS document schema to (a) Astro-compatible HTML components, (b) Markdown, (c) plain text; resolves `bs://entry/...` links using a URL map; renders images with `srcset`/`sizes`/width/height/lazy and blurhash background; embeds as click-to-load placeholders; code blocks with build-time highlighting; FAQ and key facts with semantic HTML. One renderer for the site, the `.md` twins, `llms-full.txt` and the admin preview, so outputs cannot diverge.
- `@bs/client`: build-time data fetching with the build token (`Authorization: Bearer`), pagination, retries, and an on-disk cache keyed by `updated_at` so unchanged entries are not re-fetched.

## 7.3 Astro starter (`starter/`)
Structure: `src/pages` (routes), `src/layouts`, `src/components` (documented props), `src/blocks` (block registry: each block has a schema file + Astro component), `src/styles/tokens.css` (shared token structure; the agent edits this to restyle), `src/lib/bs.ts` (client + helpers), `AGENTS.md` (briefing for AI agents, Phase 8 adds MCP details), `bs.config.ts` (CMS URL, collections to routes).
Routes (from collection `route_pattern`s): home (`pages` slug `home`), `[...slug]` pages (hierarchical paths), `/blog`, `/blog/page/[n]`, `/blog/[slug]`, `/blog/category/[...path]` (+ pagination), `/blog/tag/[tag]`, `/authors/[slug]`, 404 page, search page (Pagefind), feeds (`/rss.xml`, `/atom.xml`, `/feed.json`, per category), `sitemap-index.xml` + per-collection sitemaps, `robots.txt`, `llms.txt`, `llms-full.txt`, `.md` twins for every content page, `_preview/` (see 7.6), IndexNow key file.
Rules:
- Zero client JS by default; islands only for search UI, click-to-load embeds, mobile nav toggle (tiny), and preview.
- Fonts self-hosted, subset, `font-display: swap`, preloaded; critical CSS inlined by Astro; images from CMS variants with explicit dimensions.
- Every page outputs: `<title>`, meta description, canonical, robots meta (from `seo_resolved`), Open Graph/Twitter tags, JSON-LD `@graph` from `structured_data` (rendered in the initial HTML), breadcrumbs.
- Semantic HTML with landmarks; one H1 (the title); accessible navigation; skip link; WCAG AA.
- Redirects from `GET /public/redirects` emitted as `.htaccess` rules (Apache/LiteSpeed) and `_redirects` (static hosts) and an nginx snippet.
- `.htaccess` generated for the site: redirects, markdown content negotiation (`RewriteCond %{HTTP_ACCEPT} text/markdown` -> `index.md`, `Header append Vary Accept`, `Content-Type text/markdown; charset=utf-8`), `X-Robots-Tag` for `/_preview/` and staging, long-cache for hashed assets, `no-cache` for HTML, compression, security headers for the public site (CSP allowing only self + configured embed providers, HSTS, nosniff, referrer policy).

## 7.4 Publishing pipeline
- **Settings > Publishing** (admin screen): target type (`github_actions` default, `webhook` generic, `manual`), repository + workflow + fine-grained token (encrypted), build token management, debounce window (default 60 s), status of last deployments.
- On `entry.published/updated/unpublished/deleted`, term, navigation, redirect, site settings or crawler-policy changes: enqueue a `deploy.request` with the changed URL set; the `deploy_debounce` scheduler task merges requests within the window and creates one `deployments` row, then dispatches (`repository_dispatch` with deployment id + callback URL; or POST to the generic webhook).
- CI workflow template in `starter/.github/workflows/deploy.yml`: checkout, install with lockfile, `astro build`, Pagefind index, sync to the host over SFTP (FTPS fallback) uploading changed files and deleting removed ones, then `POST /api/v1/deployments/{id}/status` with the one-time callback token (`building`, `succeeded` + changed URLs, or `failed` + log URL). Secrets only in CI secret store.
- On `succeeded`: send IndexNow for changed URLs (debounced, logged in `index_notifications`), run the live canary checks (7.7), notify on failure. Scheduled posts publish via the scheduler and trigger the same flow.
- Admin shows a deployment status pill ("Live", "Building...", "Failed: view log") on the dashboard and in the entry editor after publish ("Your change will be live in about N minutes").
- Manual mode: the dashboard shows "Changes pending deploy" with the command to run locally (`npm run build && npm run deploy`, the starter's deploy script uses the same SFTP sync).

## 7.5 Build-time API needs
- `GET /api/v1/public/build/manifest` (build token): collections, routes, taxonomies, site settings, navigation, redirects, crawler policy output, IndexNow key, `updated_at` per entry for incremental fetching.
- Public API returns `seo_resolved`, `structured_data`, rendered-ready documents and media variant URLs, so the starter has no business logic to duplicate.

## 7.6 Draft preview (works with static hosting)
- The starter ships a static page `/_preview/index.html` (noindex, disallowed in robots) with a small client bundle that uses `@bs/renderer` and the same CSS to render a post or page from JSON.
- Admin "Preview" creates a `preview_tokens` row (30 min) and opens `<site>/_preview/?collection=posts&id=...&token=...`; the page fetches `GET /api/v1/preview/{collection}/{id}` (CORS allowed only from the configured site origin; token checked; returns draft data incl. `seo_resolved`).
- Limitation (documented): custom Astro-only components in page blocks render with a fallback placeholder unless the block also provides a client renderer; posts render fully.
- Developers can also point the admin preview URL setting at a local `astro dev` server.

## 7.7 Crawler policy and discoverability outputs
Implement `docs/04-crawlers-and-discoverability.md` sections 2-12 exactly:
- Bot catalogue file `core/resources/bots.json` with verified entries from Phase 0; unverified entries flagged.
- Presets (Balanced default, Open, Private, Custom); per-bot overrides; Search engines locked as allowed except in confirmed Private mode.
- Policy editor screen (from `docs/02-ui-spec.md` 4.9) with preview of generated robots.txt, diff against active version, lint results, type-to-confirm for risky changes, step-up auth, version history + rollback, export/import JSON. Email to owners on every change (`crawler_policy_changed`).
- Environment handling: `staging` builds output `Disallow: /`, `noindex` meta and headers, no sitemap, and a banner; switching requires step-up + confirm.
- Lint rules blocking dangerous policies (section 5 of the doc).
- Sitemaps with honest `lastmod` (from substantive content change timestamps; add `content_updated_at` to entries if `updated_at` changes for non-substantive reasons, via a schema migration in this phase), no `changefreq`/`priority`, only indexable canonical URLs, image entries.
- IndexNow key generation, key file, batching, logging; Search Console / Bing verification codes in settings.
- `llms.txt`, `llms-full.txt`, `.md` twins with `Vary: Accept`, feeds.
- Live canary checks after each deploy: fetch live `robots.txt` (compare with active version), sample pages (status, canonical, robots meta, `X-Robots-Tag`), `sitemap-index.xml` validity; raise `tech.robots.live_differs`, `tech.noindex.header`, `tech.environment.staging_in_production` issues.
- Output-based site-health rules from `docs/04-crawlers-and-discoverability.md` section 11 (`tech.*`, `geo.schema.mismatch`, `tech.markdown.mismatch` by comparing rendered Markdown to stored document text).
- Optional (off by default): access-log import to fill `bot_log` with verified/unverified labels using published IP lists (OpenAI JSON lists) and reverse DNS for search engines.

## 7.8 Tests
- Renderer: snapshot tests HTML/Markdown/plain text for every node type; link resolution; image markup; XSS attempts in documents produce safe output.
- Starter: Playwright on the built site (routes, pagination, category with subcategories, tag pages, author pages, 404, search, feeds, sitemap validity, robots.txt, llms.txt, `.md` twins, content negotiation via Apache in CI container), JSON-LD validated against required properties, Lighthouse CI budgets (performance >= 95 mobile, accessibility >= 95, SEO 100, best practices >= 95), JS size on text pages.
- Pipeline: debounce merges 10 publishes into 1 deployment; callback token single use; failed build surfaces in admin; IndexNow sent only after success; staging never produces an indexable build (test).
- Crawler policy: lint blocks `Disallow: /` for `*` in production and Googlebot blocks; rollback restores previous output; preset outputs match snapshots.

## 7.9 Acceptance checklist
- [ ] Publish a post in the admin -> live on the shared host via GitHub Actions + SFTP within 5 minutes; IndexNow logged; canary checks green.
- [ ] Manual mode documented and tested from a developer machine.
- [ ] Preview of a draft post renders on the live site's `/_preview/` with identical styles.
- [ ] Lighthouse budgets met on the starter with 200 posts.
- [ ] Balanced policy output matches `docs/04-crawlers-and-discoverability.md` section 6.1 (with verified bot list); staging site fully noindex.
- [ ] Site health shows zero errors on the starter demo content.
