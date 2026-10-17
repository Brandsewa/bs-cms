# BS CMS: Build Plan (draft v3, for discussion only, not yet started)

A lightweight, AI-native, headless CMS on PHP + MySQL that installs on shared hosting, with a dashboard whose UX is a first-class product goal and search / AI discoverability built in by default.
Working name only. Items marked **(verify)** come from thin or secondary sources and need checking before they are relied on.
The build itself is out of scope for this document: implementation will be done separately (in Antigravity).

## 0. Decisions made so far

| Topic | Decision |
|---|---|
| Stack | PHP + MySQL core, React + shadcn admin, Astro public sites |
| Target users (first phases) | **Developer-first.** Anyone can download the app, connect their own chat agent (Claude, ChatGPT, etc.) and instruct it to design and build the site. Because agents now produce good page designs, a visual theme builder is not needed early. |
| Content editors | **Same dashboard, limited by roles and permissions.** Technical areas (schema, settings, AI, developer tools) are hidden from editors, not just disabled. Editors are not a v1 focus, but the permission model is built from the start so this works (`docs/02-ui-spec.md` section 7, `docs/03-auth-and-email.md` section 2.3). |
| Product name and brand | **BS CMS**, primary colour **blue** |
| Public site rendering | **Astro only.** No PHP-rendered theme option. |
| Distribution | **Self-hosted only.** No managed/hosted offering planned. |
| Language | **English only** in v1; all UI text kept in a message catalog so languages can be added later. No RTL work in v1. |
| Authentication | Own small auth module on native PHP (Argon2id, sessions, TOTP) + `league/oauth2-server` for AI clients; no full auth framework (`docs/03-auth-and-email.md`) |
| Email | Basic SMTP integration with presets for Resend, ZeptoMail, Zoho Mail and generic SMTP, for resets, invitations and notifications |
| Editor | **Blog posts:** Notion / Ghost-like block editor built on **Tiptap (MIT core)**. **Decision is final**, no alternative spike. **Pages:** layout generated in code (Astro); a small `minimal` editor profile for text regions. Storage is editor JSON with a node allow-list; AI agents can send Markdown (`docs/05-editor.md`) |
| Taxonomies | Generic taxonomy system. Posts get hierarchical categories (with subcategories) and flat tags; pages get none; custom collections can attach or create taxonomies and link through relations (`docs/01-data-model.md` section 4) |
| Theme editor / page builder | Later, on top of the developer-first base |
| Licence | Proprietary / private development for now. Possibly public and open source later, so avoid dependencies and code copying that would block that (check third-party licences as we go). |
| Discoverability | SEO, GEO, AEO and agentic indexing are core and on by default (section 6, `docs/04-crawlers-and-discoverability.md`) |
| AI crawler default | **Balanced preset:** allow search engines, AI search and user-triggered agents; disallow training bots; changeable in settings, with safety rails for production sites |
| Design reference | Supabase design system for the dashboard (section 5, `docs/02-ui-spec.md`) |

## 0.1 Specification documents (read these before building)

| Document | Contents |
|---|---|
| `implementation/README.md` and `implementation/phase-*.md` | **The build instructions for Google Antigravity**: rules, repo layout, dependency allow-list, budgets, and ten phases with tasks, endpoints, tests and acceptance checklists. Authoritative for build order |
| `PLAN.md` (this file) | Goals, architecture, phases, risks |
| `docs/01-data-model.md` | Every table (SQL), field types, built-in collections, taxonomies, seed data |
| `docs/02-ui-spec.md` | Dashboard design rules, shared components, every screen, per-screen checklists and Definition of Done (bulk select, filters, skeletons, empty states, date pickers, etc.) |
| `docs/03-auth-and-email.md` | Auth decisions and libraries, flows, roles matrix, OAuth for AI clients, SMTP and email templates |
| `docs/04-crawlers-and-discoverability.md` | Crawler policy research and rules, robots.txt, sitemaps, IndexNow, JSON-LD, markdown views, site-health lint rules |
| `docs/05-editor.md` | Blog post editor (Notion / Ghost-like): editor comparison, recommendation (Tiptap), document model, Markdown for AI agents, block and UX checklist, minimal profile for pages |

Rules for the implementer (Antigravity or any agent):
1. Do not skip items. The checklists in `docs/02-ui-spec.md` are acceptance tests. Earlier attempts forgot basics such as row checkboxes and bulk actions; every table must use the shared DataTable that includes them.
2. Build in the phase order below. Do not build a screen before the shared components it needs.
3. Anything marked **(verify)** must be checked against primary sources before relying on it.
4. When this plan and a spec document disagree, the spec document is more detailed and wins; fix the plan afterwards.
5. Each phase ends with its exit criteria met and tests passing before the next begins.

## 1. Goals

1. Informational site with pages and a blog, working on day one.
2. Custom collections (content types) that non-developers can create later, e.g. NGO "Campaigns".
3. Runs on cheap shared hosting: no Node, no daemons, no Redis required.
4. Extensible through plugins (e.g. donations / payments) sold or shipped separately.
5. AI-native: any chatbot (Claude, ChatGPT, others) can connect and manage the whole site, safely.
6. Fast public sites: the public site is static (Astro) and never touches the AI or admin code paths.
7. **Discoverable by default:** classic SEO, plus generative-engine (GEO) and answer-engine (AEO) optimisation, plus agentic indexing, with no plugin or setup needed (section 6).
8. **Developer-first, agent-driven building:** a developer can connect their own AI agent and have it design and manage the whole site (section 4.6).
9. **A dashboard that is fast, compact, modern and easy to use.** Most CMS products lose people in the admin UI. This is a core selling point, not polish, and it follows the Supabase design system (section 5).

## 2. Stack

| Layer | Choice |
|---|---|
| Runtime | PHP 8.2+ (PHP-FPM / CGI both fine) |
| Core | Own small kernel on PSR-7/11/15/17, FastRoute-style router, PDO, thin query builder (no full ORM) |
| Database | MySQL 8 / MariaDB 10.6+ |
| Storage model | Table per collection: real columns for system and filterable fields + one JSON column for the rest, indexed through generated columns |
| Admin | Vite + React + TypeScript + Tailwind + shadcn/ui (Radix), patterns from the Supabase design system, built ahead of time to static files in `/admin` |
| Public site | Astro (static output). The build runs **off the PHP host** (GitHub Actions by default, or a developer machine / static host) and is deployed to the shared host over SFTP; the CMS triggers and tracks builds (see section 12) |
| Cache | File cache by default; APCu / Redis if present |
| AI interface | MCP over stateless Streamable HTTP + REST/OpenAPI, both thin adapters over one operations layer |

## 3. Architecture principle: one operations layer

Every capability (create entry, publish, change schema, upload media) is a single typed **operation** with:
input schema, permission check, validation, audit entry, hooks.

REST API, the admin dashboard and the MCP server are all generated from the same operations. Consequences:
- Permissions, validation and the audit log behave identically for humans and AI.
- New collections and plugin operations appear in REST, MCP and the admin forms with no extra code.
- No AI-only back door exists.

## 4. AI-native design

### 4.1 Interfaces

| Interface | Use |
|---|---|
| **MCP (primary)** | Claude, ChatGPT (developer-mode connectors), Claude Code, Cursor and other MCP clients |
| **REST + auto-generated OpenAPI** | Custom GPT actions, scripts, any client without MCP |
| **llms.txt, markdown views, JSON-LD on the public site** | AI crawlers and AI search read the site itself |

MCP is the better default because tools, descriptions and permissions are discoverable by the client. REST/OpenAPI is the fallback for clients that lack MCP.

### 4.2 MCP server design (performance-safe)

- Endpoint `/mcp`, **stateless**: each call is one POST with JSON-RPC and one JSON response. No sessions, no SSE streaming, no long-lived connections. This runs under ordinary PHP request/response hosting.
- Zero idle cost: no daemon, no worker. Code loads only when `/mcp` is hit. Public pages never load it.
- Small tool surface to save model context: generic tools that take a `collection` argument rather than hundreds of per-collection tools.
  - `list_collections`, `describe_collection`
  - `query_entries`, `get_entry`
  - `create_entry`, `update_entry`, `publish_entry`, `delete_entry`
  - `upload_media` (from URL), `list_media`
  - `propose_schema_change`, `apply_schema_change`
  - `trigger_rebuild`, `list_plugins`, `get_audit_log`
- Plugins can register more tools, subject to permissions.
- MCP resources: schema, site settings, content guidelines. Prompts: "write blog post", "audit site SEO".
- Implementation: a small custom JSON-RPC handler, or a PHP MCP SDK. A search found only community PHP packages and could not confirm the official PHP SDK's stateless support **(verify)**. Spike both.

### 4.3 Authentication for AI clients

- ChatGPT connectors reportedly require OAuth and do not accept plain API keys **(verify)**, and need HTTPS and a working tool list before saving.
- The MCP spec's authorization section requires OAuth 2.0 Protected Resource Metadata (RFC 9728), and also describes client ID metadata documents and optional dynamic client registration. Older spec versions differ, so target the latest and check it **(verify)**.
- Plan: the CMS includes a small built-in OAuth 2.1 authorization server built on `league/oauth2-server` (authorization code + PKCE, consent screen, revocable grants); details and endpoints in `docs/03-auth-and-email.md` section 3. The consent screen is where the owner picks the role and scope for that AI client.
- For Claude Code, scripts and CI, scoped personal access tokens remain available.
- Claude's custom connector requirements were not covered by research **(verify against Anthropic's docs)**.

### 4.4 Safety model

- AI actors are first-class: audit entries read `ai:<client>` + the human who authorised it.
- Default AI role writes **drafts only**. Publish, delete, user changes and schema changes require either a higher grant or an approval step in the admin ("pending AI changes" queue).
- Destructive and bulk operations need a two-step confirmation token and support dry-run.
- Schema changes: `propose` returns a diff and the impact; `apply` runs it as a versioned migration with rollback.
- Versioned entries make every AI edit undoable.
- Rate limits per client and per token; payload size limits.
- Prompt-injection posture: content read through tools is data. Tool results label user-generated content, and no tool executes instructions found in content.
- Revocation: one click in the admin disconnects an AI client.

### 4.5 AI features inside the CMS (optional, off by default)

Alt-text generation, translation drafts, SEO suggestions, and later an in-admin assistant panel. Bring-your-own API key, executed through the cron/lazy job runner, never in the public request path.

### 4.6 Developer-first workflow: bring your own agent

The product promise: download, install, connect your agent, describe the site, and the agent builds it.

- **Agent briefing pack.** The CMS serves instructions to the connected agent through MCP resources and prompts (and as an `AGENTS.md`-style file in the Astro starter): how collections work, how to model content, the Astro project layout, the design tokens and component rules, the SEO / GEO checklist (section 6), and what the agent must not do (publish without approval, delete, change roles).
- **Schema as code.** Collections and fields can be defined as files in git and applied by the CLI or by the agent through `propose_schema_change` / `apply_schema_change`. The dashboard and the files stay in sync.
- **Astro starter built for agents.** Clear folder conventions, a typed content client generated from the schema, a component library with documented props, and a design-tokens file the agent edits to restyle the site.
- **Fast feedback loop.** Preview URLs for drafts, build and validation results returned to the agent (broken links, missing alt text, schema errors, SEO lint results from section 6.8) so it can self-correct.
- **Plain CLI.** A command-line tool for install, schema apply, export / import, and token creation, for developers who prefer a terminal to the dashboard (runs where PHP CLI exists; the browser installer remains for shared hosts).
- **Later:** a theme editor / visual builder, and a simplified editor experience for content writers, built on the same operations layer.

## 5. Dashboard design system and UX

### 5.1 Why this is a core section

Admin UX is the most common complaint about CMS products, so the dashboard gets its own design system, UX rules, performance budget and user testing, not just "use shadcn".

### 5.2 Design reference: the Supabase design system

Source: https://supabase.com/design-system. Findings from reading the site:

- It credits Radix, shadcn/ui and Geist as inspirations, which is the same foundation we chose, so adopting its patterns is a natural fit.
- It is organised as: Getting Started (accessibility, color usage, copywriting, icons, Tailwind classes, theming, typography), UI Patterns, Fragment components and Atom components.
- **Atoms** include: Accordion, Alert, Alert Dialog, Avatar, Badge, Button, Calendar, Card, Checkbox, Combobox, Command / Command Menu (cmdk), Context Menu, Date Picker, Dialog, Drawer, Dropdown Menu, Form, Input, Input OTP, Pagination, Popover, Progress, Radio Group, Resizable, Scroll Area, Select, Sheet, Sidebar, **Skeleton**, Slider, Sonner (toasts), Switch, Table, Tabs, Textarea, Toggle, Tooltip, Tree View.
- **Fragments** (composed building blocks): Page Header, Page Nav, Filter Bar, Multi Select, Metric Card, Confirmation Modal, Text Confirm Dialog, Data Input, Admonition, Assistant Chat, Logs Bar Chart, Status Codes.
- **Patterns** documented: Charts, Empty States, Forms, Layout, Markdown, Modality, Navigation, Tables.

Rules we will adopt (taken from its pages):

**Layout**
- Every page: breadcrumb row, then optional section nav (`PageNav`), then optional `PageHeader`, then sections.
- Three width tiers: `small` (settings, forms), `default` (lists, tables, detail pages), `full` (dense content: editors, logs, wide tables).
- Action placement is fixed by page type: breadcrumb row for parent pages with sub-navigation, header aside for child pages, right side of the filter row for tables, so users always know where the primary action lives.

**Forms**
- One form layout component for all fields. Side panels (sheets) use vertical field layout; wide pages use horizontal.
- Cancel appears only when the form is dirty and Save is disabled until it is. Dismissing a dirty dialog or sheet asks for confirmation.
- Validation errors appear inline at the field; submit failures appear near the actions; toasts only for non-blocking feedback. Submit buttons show a loading state.
- Field types to support from day one: text, password, copyable read-only, number, input with units, textarea, file upload, switch, checkbox, select, multi-select, radio group, **date picker**, field arrays (single-value and key/value).

**Tables**
- Sortable headers with three states and `aria-sort`; column visibility menu; filter input or filter bar; per-row action menu; selection with "N of M selected" footer; pagination controls; "No results" empty state.
- The Supabase data-grid demo uses 44 px rows and a 36 px header. Use as a starting density, to be tuned.
- For large datasets use a virtualized data grid (column resize, cell editing), which Supabase also separates from its simpler data table.

**Color and theming**
- Semantic tokens, not raw colors: background, surface levels (surface-100 to 300), overlay, borders from muted to strong, status scales for destructive and warning, and Radix-style 1 to 12 color scales.
- A single primary hue variable recolors text, buttons, selected controls and focus rings. Primary comes in three forms (readable ink, solid button fill, bright focus fill) chosen for WCAG AA contrast.
- Light, dark and system themes from day one, built on Tailwind classes backed by CSS variables.

### 5.3 What we copy and what we do not

- Use the **patterns, structure and token approach**. Build on shadcn/ui + Radix + Tailwind, which are open components we can own.
- Do **not** reuse Supabase branding. Its green and logo are brand assets; we choose our own primary hue (their system supports changing one hue variable).
- Before copying any code from the Supabase repository (`apps/design-system`), check its licence **(verify)**. Safe default: re-implement the layout and form components ourselves from the documented rules.
- Result: a small internal package, `@bs/ui`, with tokens + atoms + fragments, documented in a Storybook-style gallery inside the repo.

### 5.4 Modern components required (no legacy controls)

- No native `<select>`: use Select, Combobox and Multi Select (searchable, keyboard-friendly, async options for relations).
- Date and time: a real calendar-based date picker, range picker and time input; never a plain browser date field.
- Command menu (Ctrl/Cmd+K) for navigation and actions: "new post", "go to media", "switch collection".
- Sheets for editing related entries or quick edit; dialogs for confirmations; destructive actions use a type-to-confirm dialog.
- Toasts (Sonner-style), tooltips with shortcuts, resizable panels, tree view for hierarchical content and folders, drag-and-drop reordering and uploads.
- Skeleton loaders shaped like the real content on every page and table (no spinners over blank screens), plus designed empty states with a clear next action, and error states with retry.

### 5.5 CMS-specific screens

| Screen | UX approach |
|---|---|
| Collection list | Table pattern with saved views, filter bar, bulk actions, inline status badges |
| Entry editor | Full-width editor with main content left; right sidebar for status, publish / schedule, slug, SEO, relations, version history; autosave draft; unsaved-changes guard |
| Schema builder | Visual field list with drag-to-reorder, field-type picker with previews, live preview of the resulting form, change summary before applying |
| Media library | Grid and list toggle, folders, drag-and-drop upload with progress, detail sheet with alt text, usage tracking |
| Roles and permissions | Matrix view (role × collection × action) with field-level drill-down and a "what can this role see" preview |
| AI connections | Connected clients, granted scope, activity feed, pending AI changes queue with diff and approve / reject |
| Dashboard home | Recent edits, drafts waiting, pending AI changes, site health, quick actions |
| Settings | Single-column small-width pages grouped in cards |
| Onboarding | First-run checklist: create page, add blog post, connect Astro, connect an AI client |

### 5.6 Dashboard performance

Targets (not measured yet; enforce with CI checks once the admin exists):
- Initial admin JS under about 200 KB gzipped for the app shell; everything else loaded per route.
- Heavy parts (rich text editor, charts, schema builder, date range picker) lazy-loaded.
- Server state with TanStack Query: cache, background refresh, optimistic updates for status changes and reordering, prefetch on link hover.
- Virtualized long lists; pagination is cursor-based for big collections.
- No layout shift: skeletons match final dimensions.
- Keyboard-first: shortcuts for save, publish, search, next and previous entry.
- Accessibility: Radix primitives for focus management, WCAG AA contrast, full keyboard support, reduced-motion respect.

### 5.7 Usability validation

- First-phase users are developers, so early testing is with developers connecting their own agent; test with non-technical editors once the limited editor role / dashboard exists.
- Define task benchmarks: "create a page and publish it", "add a custom collection with 5 fields", "upload and insert an image", "give an editor access to one collection".
- Test with about 5 non-technical users before v1 and after each major admin change; fix the top friction points before adding features.
- Success target to agree on: a first-time non-developer completes the first two tasks without help.

### 5.8 Future public theme (demo front end)

- Design tokens live in one shared token file (colors, type scale, spacing, radius) used by both the admin and the Astro starter.
- The demo Astro theme reuses the token structure and some component logic (buttons, forms, date picker for events) but is styled differently, with editorial typography suited to NGO and content sites, so it does not look like the admin.
- A theme is defined as: tokens + block components + page templates. This keeps themes swappable and gives the NGO pack a base to extend.

## 6. Discoverability by default: SEO, GEO, AEO and agentic indexing

### 6.1 Principle

Every public page the CMS produces is correct for search engines, AI answer engines and AI agents without configuration. The CMS handles the mechanical parts (metadata, structured data, sitemaps, crawler policy, change notification, machine-readable views). The content-quality parts (answer-first writing, evidence, entity consistency) are enforced through field design, lint warnings and the agent briefing pack, because no software can add quality that the content lacks.

### 6.2 What the research says (and how reliable it is)

Most sources here are vendor blogs, so numbers are treated as unverified.

- **GEO / AEO guidance converges on a few points:** use JSON-LD structured data, lead with a direct answer, include citable facts and references, keep entity names consistent, and track citations and mentions, not only clicks ([overview](https://gryffin.com/blog/what-is-generative-engine-optimization-explain-geo-for-ai-driven-search), [another guide](https://gen-optima.com/geo/generative-engine-optimization-best-practices-2026)). Some of these guides make unsourced claims (e.g. fixed percentage gains), which we ignore. One notes Google has restricted some schema types for rich results, so rich-result eligibility must be checked, not assumed.
- **llms.txt is cheap but barely used.** An [Ahrefs-based analysis](https://ppc.land/llms-txt-adoption-rises-8-8x-but-97-of-files-get-zero-ai-requests/) reports 97% of llms.txt files got zero requests in May 2026, and that major AI crawlers mostly fetch HTML directly. A practitioner blog says Google does not support it (unverified against a primary source). Decision: generate it automatically because it costs nothing, but do not rely on it, and put the real effort into clean HTML, structured data and markdown views.
- **Markdown for agents is gaining traction.** [Cloudflare's Markdown for Agents](https://developers.cloudflare.com/fundamentals/reference/markdown-for-agents) serves markdown when a client sends `Accept: text/markdown`, with `Vary: Accept`; their test showed 16,180 tokens of HTML versus 3,150 in markdown. SEO commentary raises a cloaking concern if markdown differs from the HTML, so our markdown view must be generated from the same content as the page.
- **AI crawlers have separate roles.** Per secondary sources: OpenAI uses GPTBot (training), OAI-SearchBot (search) and ChatGPT-User (user-triggered fetches); Anthropic has ClaudeBot, Claude-SearchBot and Claude-User; Google-Extended is a control token, not a crawler, and does not remove a site from AI Overviews. A blanket block can remove a site from AI search. Sources disagree on some details, so verify the current tokens in each vendor's docs **(verify)**. Cloudflare's Content-Signal line in robots.txt is a voluntary convention.
- **Change notification:** [IndexNow](https://agilitycms.com/docs/developers/faster-indexing-with-xml-sitemaps-and-indexnow) pushes changed URLs to Bing, Yandex and others but not Google. Google's Indexing API is limited to job-posting and livestream content, so for Google rely on sitemaps with honest `lastmod` values (Google ignores `changefreq` and `priority`, and stamping every URL with the current time teaches it to ignore `lastmod`).
- **NLWeb** is a Microsoft open project that lets a site answer natural-language questions from its schema.org data, with each instance also acting as an MCP server ([NLWeb README](https://cdn.jsdelivr.net/gh/nlweb-ai/nlweb@main/README.md)). Status and adoption are unclear from the sources **(verify)**. Treat as an optional later add-on, not core.

### 6.3 Metadata and on-page SEO (core, always on)

- Every collection that has a public page gets a built-in SEO field group: title, meta description, canonical URL, Open Graph and Twitter card data, `noindex` flag, robots directives, social image. Sensible defaults are derived from the entry (title, excerpt, featured image), so empty fields still produce good output.
- Slugs, redirects (301 table with automatic redirect on slug change), canonical handling, pagination tags and hreflang (when localization arrives).
- Image handling: required alt text (enforced by validation / lint), width and height attributes, responsive `srcset`, modern formats, lazy loading below the fold.
- Heading structure checks (one H1, no skipped levels) in the content lint.
- Clean, semantic HTML from the Astro starter (landmarks, headings, lists, tables), because AI crawlers parse HTML directly.

### 6.4 Structured data (JSON-LD, generated from the schema)

- The CMS generates schema.org JSON-LD per collection from a mapping defined once in the collection config (e.g. Posts → `Article` / `BlogPosting`, Pages → `WebPage`, Campaigns → `Event` or `Organization` + `DonateAction`, FAQ blocks → `FAQPage`, how-to steps → `HowTo`, people → `Person`).
- Site-wide entities: `Organization` / `WebSite` with consistent name, logo, `sameAs` links, so entity identity is the same across pages.
- Validation: structured data is checked in CI and in the editor lint against required properties. Rich-result eligibility is not promised.

### 6.5 AEO / GEO content support

- Optional **answer summary** field on content types (one-to-two sentence direct answer shown at the top and used for description and FAQ data).
- **FAQ and Q&A blocks**, **key facts / statistics** blocks with source fields, author and reviewer fields with bios (`Person` entities), publish and "last reviewed" dates, and references.
- Lint rules and agent prompts that nudge answer-first structure, citations for claims, short extractable paragraphs, descriptive headings, and consistent terminology.
- Internal linking suggestions (related entries) generated from relations and taxonomy.

### 6.6 Agentic indexing and machine-readable access (on by default)

| Feature | Behaviour |
|---|---|
| `sitemap.xml` (and sitemap index) | Auto-generated, split by collection, accurate `lastmod` taken only from real content changes, omitted when unknown |
| `robots.txt` | Generated from settings, with a clear AI crawler policy (section 6.7) and sitemap reference |
| Markdown views | Every public page also available as markdown, at a predictable URL (e.g. `/page.md`) and via `Accept: text/markdown` with `Vary: Accept`; generated from the same content as the HTML to avoid cloaking |
| `llms.txt` and `llms-full.txt` | Auto-generated site map for agents (low expected impact, near-zero cost) |
| RSS / Atom and JSON Feed | Per collection |
| Public read-only content API | Documented, cacheable REST (and OpenAPI) so agents can read structured content directly |
| Site MCP endpoint (public, read-only) | Optional: `search_content`, `get_page`, `list_collections` for visiting agents; separate from the authenticated management MCP in section 4 and disabled unless enabled |
| Change notification | On publish / unpublish / delete, the job runner pings IndexNow (Bing, Yandex and others) and refreshes the sitemap; Google handled through sitemaps and Search Console |
| NLWeb-style `ask` endpoint | Later and optional (section 6.2) |
| Cache and freshness headers | ETag, Last-Modified, sensible Cache-Control, so crawlers and agents revalidate cheaply |

### 6.7 Crawler policy controls (full detail in `docs/04-crawlers-and-discoverability.md`)

- Presets: **Balanced (default)** allows search engines, AI search bots and user-triggered agents and disallows training bots; **Open**; **Private**; **Custom** with per-bot overrides. Changeable any time.
- Research outcome: decide per purpose (search, user-triggered, training), never block Googlebot/Bingbot by accident, treat robots.txt as a request not security, do not rely on IP blocking for vendors that publish no IP ranges (Anthropic), and verify bots with published IP lists or reverse DNS before enforcing anything at the edge.
- Production safety rails: environment flag (staging is automatically noindex), lint that blocks policies which would hide a production site from search, diff + preview + confirm on every change, versioned history with one-click rollback, live canary check of the deployed robots.txt, and an email on every policy change.
- Policy lives in a versioned bot catalogue (data file) that can be updated without a core release; unverified bot tokens are flagged until checked.
- The public site is static, so the CMS cannot log crawls itself; bot visibility comes from optional access-log import and from the dynamic endpoints only.

### 6.8 SEO and GEO lint (for humans and agents)

A single "site health" operation (also exposed as an MCP tool and CLI command) returns a prioritised list: missing titles / descriptions / alt text, duplicate titles, broken internal links, redirect chains, orphan pages, heading problems, invalid JSON-LD, pages missing an answer summary, thin content, and sitemap or robots errors. Agents run it after changes and fix what it reports.

### 6.9 Measurement

- Search Console and Bing Webmaster: sitemap submission guidance and verification-file support in settings.
- Core Web Vitals come from the static Astro output (no work in the CMS beyond not blocking it).
- AI visibility: the bot log (6.7) and an optional "citation check" job that queries configured AI APIs with the site's target questions and records whether the site is cited. Optional, bring-your-own key, off by default.
- The dashboard home shows site health (6.8) and recent bot activity.

### 6.10 Performance and risk notes

- All discoverability outputs are generated at publish time or on the static build, never at request time on visitor pages. Only the markdown negotiation and public API are dynamic, and both are cached.
- Risk: cloaking. Mitigation: markdown and JSON-LD are always derived from the same stored content and hidden text is never generated.
- Risk: standards churn (llms.txt, NLWeb, bot tokens). Mitigation: put each behind a small adapter and a feature flag so they can change without touching core.

## 7. Data model (summary; full SQL in `docs/01-data-model.md`)

The tables below are a summary only. Use `docs/01-data-model.md` for exact columns, indexes, field types, the built-in `pages`, `posts`, `authors` collections, singletons, and the taxonomy system (taxonomies, terms with materialized paths, term relationships, entry relations).

- `users`, `roles`, `role_permissions` (role × collection × action × field-set × optional condition), `api_tokens`, `oauth_clients`, `oauth_grants`
- `collections`, `fields` (schema definitions, also exported to files so they can live in git), `saved_views` (per-user list filters)
- one table per collection: `id` (ULID), `slug`, `status`, `locale` (reserved), `created_at/by`, `updated_at/by`, `published_at`, filterable columns, `data` JSON
- `entry_versions` (revision history), `media`, `media_variants`, `relations`
- `audit_log`, `webhooks`, `webhook_deliveries`, `jobs`, `settings`, `migrations` (namespaced per plugin)
- Discoverability: `redirects`, `seo_overrides` (per-entry SEO field values), `structured_data_maps` (collection → schema.org mapping), `crawler_policy`, `bot_log` (optional, aggregated), `index_notifications` (IndexNow / sitemap ping history)

## 8. Phases

> **Superseded for building:** the build order, scope and tasks now live in `implementation/` (Phases 0-9 plus `later.md`), which re-orders this list (backend first, then design system and admin, then the Astro site and publishing pipeline, then AI, then hardening) and moves the plugin system and NGO pack after v1. The phases below are kept as product history.

### Phase 0: Spikes and decisions (1-2 weeks)
- Benchmark table-per-collection + JSON + generated columns against realistic data on a real cheap shared host.
- MCP spike: stateless `/mcp` with one read tool; connect from Claude and ChatGPT developer mode. Confirms the OAuth requirements for real.
- Tiptap check (not a comparison): confirm the current licences and which Tiptap extensions are free (especially DragHandle), pin versions, and measure the gzipped size of the editor chunk with a prototype post editor (slash menu, bubble toolbar, image, drag handle, 10,000-word document) to set the editor performance budget (`docs/05-editor.md` sections 3, 7 and 9).
- Design spike: build three representative screens (list, entry editor, schema builder) in the planned stack to validate the Supabase-style patterns, density and bundle size before committing.
- Decide: licence (open core vs fully open), name, minimum PHP version, flat-file schema export format, primary hue.
- Exit: written decisions + proof that an AI client can connect to a shared-host install + design spike reviewed.

### Phase 1: Kernel, operations layer, installer
- Router, DI container, config, error handling, migrations runner, file cache, job runner (cron or lazy).
- Operations framework (section 3), hook/event bus, plugin-ready extension points (no plugin loading yet).
- Browser installer (requirements check, DB setup, admin user).
- Exit: install from a zip on shared hosting in under 5 minutes.

### Phase 2: Auth, roles, permissions, audit, email
- Everything in `docs/03-auth-and-email.md` sections 2, 4, 5 and 6: login, logout, forgot / reset password, invitations, email verification, TOTP 2FA with recovery codes, sessions list and revoke, rate limiting, lockout.
- Roles and field-level permissions with the seeded presets (owner, admin, developer, editor, author, contributor, viewer, ai_draft, ai_publisher), one `Authorizer` class, `GET /me/permissions`.
- API tokens, audit log.
- SMTP settings (Resend, ZeptoMail, Zoho Mail, generic), queued mail, templates, test email, email log.
- Exit: permission test suite passes for owner, admin, developer, editor, author, contributor, viewer, AI roles and anonymous; password reset and invitation emails delivered through at least two providers in test mode.

### Phase 3: Content engine
- Collection and field definitions, field types (text, rich text, number, bool, date, enum, JSON, media, relation).
- Validation, relations, drafts/publish, version history, slugs.
- Query API: filter, sort, paginate, field select, populate. Freeze this contract early.
- Media library: upload, variants, alt text, pluggable storage.
- Taxonomy engine (`docs/01-data-model.md` section 4): taxonomies, terms with materialized paths, term relationships, move / merge / delete-with-reassign, term counts, filters with `include_descendants`, public archives data.
- Ship Pages (hierarchical, no taxonomies), Posts (hierarchical categories with subcategories + flat tags), Authors, and the Site settings / Navigation singletons as ordinary collections, each with the built-in SEO field group and answer-summary field (section 6.3, 6.5).
- Redirect table, slug-change redirects, schema.org mapping per collection (6.4), site-health lint operation (6.8).
- Schema-as-code files and CLI apply (section 4.6).
- Exit: Pages and Posts usable end-to-end through the API, with valid metadata and JSON-LD output.

### Phase 4: Design system and admin dashboard
- Source of truth: `docs/02-ui-spec.md`. Every screen's checklist and the Definition of Done are acceptance criteria; the shared DataTable must include row checkboxes, select-all with indeterminate state, bulk action bar, column controls, filter bar, saved views, pagination, skeleton / empty / error states before any list screen is built.
- 4a. `@bs/ui`: tokens (including themes and primary hue, blue), atoms and fragments from section 5.4, layout components (page container, breadcrumbs, page nav, page header, sections), form layout, table and data-grid components, skeleton and empty states; gallery page.
- 4b. App shell: sidebar, command menu, routing, auth screens, theme switch, notifications.
- 4c. Schema-driven forms and lists generated from field definitions; collection list, entry editor, media library. The post editor follows `docs/05-editor.md` (article profile checklist) and the minimal profile for pages; Markdown-to-document conversion for AI input is built in the PHP core in Phase 3 and exposed through REST and MCP.
- 4d. Schema builder, roles matrix, users, settings, audit log, onboarding checklist.
- 4e. Performance pass against section 5.6 budgets; accessibility audit; usability round (section 5.7).
- Exit: a non-developer can create a "Campaigns" collection and add entries; budgets met.

### Phase 5: AI interface (can start after Phase 3; ships here)
- Production MCP server and OAuth 2.1 server, OpenAPI generation, AI role presets, connected-clients screen and pending-changes queue (built with the Phase 4 components).
- Agent briefing pack (MCP resources and prompts, `AGENTS.md` in the starter), site-health tool for self-correction (section 4.6, 6.8).
- Exit: from a chatbot, create a page draft, edit it, request publish, approve it in the admin, and see the audit trail. Done on both Claude and ChatGPT.

### Phase 6: Astro starter and publishing pipeline
- Astro starter site (pages, blog, listings), typed client generated from the schema; first demo theme built from the shared tokens (section 5.8).
- Webhooks with changed-entry info for incremental rebuilds, preview mode, search (Pagefind), image `srcset`.
- Discoverability outputs (section 6): sitemap index with honest `lastmod`, robots.txt with crawler policy presets, markdown views and `Accept: text/markdown` negotiation, `llms.txt`, feeds, JSON-LD rendering, IndexNow pings from the job runner.
- Optional public read-only site MCP endpoint and bot log.
- Exit: edit in admin or via chatbot, and the site updates within a defined target time; structured data validates; site-health lint reports clean on the starter site.

### Phase 7: Hardening and v1 release
- Security review (query building, uploads, auth, OAuth flows), backups and export/import, zip upgrader with migration dry-run and rollback, docs, load tests on shared-host specs.
- Exit: v1 tag; upgrade from the previous build tested.

### Phase 8: Plugin system
- Manifest (name, version, core compatibility, dependencies, permissions), namespaced migrations, route/hook/tool registration, settings pages, front-end block registry.
- Admin extensions: schema-driven first (using `@bs/ui` so plugin screens look native); runtime-loaded bundles second.
- Compatibility check before install; rollback on failure.
- Exit: a sample plugin installs and uninstalls cleanly.

### Phase 9: NGO pack
- Campaigns collection template; Donations plugin (hosted checkout via a gateway interface: Stripe, PayPal, regional gateways; signed idempotent webhooks; gateway-managed recurring donations; receipts; refunds; donor records).
- Never handle card data directly.
- Exit: an NGO site with campaigns and working test-mode donations.

### Later
Theme editor / visual page builder (Puck, stored in your own block format), limited editor dashboard or separate editor app for content writers, localization (with hreflang), live preview, review workflows, scheduled publishing, SSO, GraphQL, in-admin AI assistant, NLWeb-style `ask` endpoint, AI citation tracking, plugin marketplace with licensing, possible open-sourcing.

## 9. Performance and resource budgets (targets, not measured)

- Admin API: p95 under 150 ms on a typical shared host.
- `/mcp` read call: under 200 ms server time; idle resource cost: zero.
- Memory per request: under 32 MB for API calls.
- Release zip: small enough for restricted upload limits (target under 10 MB without media).
- Admin app shell under about 200 KB gzipped JS; route-level splitting for the rest.
- Public site: static, so visitors never hit PHP except for forms and checkout.
- Every budget gets a CI benchmark from Phase 3 (server) and Phase 4 (admin) onward.

## 10. Main risks

| Risk | Mitigation |
|---|---|
| Shared hosts limit `ALTER TABLE`, memory or execution time | Test on real hosts in Phase 0; paginate and stream everything |
| OAuth server is a security-sensitive build | Follow the MCP spec; keep scope small; external review in Phase 7 |
| Chatbot connector rules change quickly | Keep MCP and REST adapters thin; test against live clients each release |
| AI actions damage a live site | Draft-only default, approval queue, versions, audit log |
| Dashboard becomes heavy or inconsistent | Own `@bs/ui` package, bundle budgets in CI, design spike in Phase 0 |
| Copying Supabase code or branding creates a licence or trademark problem | Re-implement patterns, own hue and logo, check licence before any copy |
| Scope creep (page builder, marketplace) | Hold to v1 scope in section 8 |
| GEO / AI-search standards change or prove ineffective (llms.txt, NLWeb) | Put each behind an adapter and feature flag; invest first in clean HTML, structured data, sitemaps and markdown views |
| Markdown or JSON-LD differs from visible content (cloaking) | Always derive from the same stored content; lint for mismatches |
| Plugin UI inside a prebuilt admin | Schema-driven UI first; runtime bundles later |

## 11. Open questions

Settled (see section 0): licence (private for now), first users (developer-first), editor access (same dashboard with limited roles), Astro only, self-hosted only, product name BS CMS, blue brand colour, English only, crawler default (Balanced preset).

Still open:
1. Minimum PHP version: 8.2 (matches `league/oauth2-server`'s current requirement, which starts at 8.2) or 8.3? Leaning 8.2.
2. Exact blue hue, font, and logo (decide in the Phase 0 design spike).
3. TOTP package and PHPMailer vs Symfony Mailer final choice (check current releases and licences).
4. Confirm the unverified bot tokens in the crawler catalogue (`docs/04-crawlers-and-discoverability.md` section 3).
5. Whether to enable the public read-only site MCP by default or leave it off.
6. Hosting target list for Phase 0 testing (which shared hosts to test on).

## 12. Review notes (v4, before handing over to Antigravity)

The whole plan was reviewed against the core goal (a lightweight CMS on shared hosting with PHP + MySQL; security, performance and optimisation first, everything else additional). Findings and how they were resolved:

| # | Finding | Resolution |
|---|---|---|
| 1 | **Astro cannot be built on shared hosting** (no Node). The plan implied rebuilds "from the CMS". | Build runs off-host: GitHub Actions + SFTP by default, manual local build or a static host as alternatives. New tables `deployments`, `build_tokens`, `preview_tokens`; debounced deploys; CI callback; IndexNow after successful deploy (Phase 0 Spike C, Phase 7). |
| 2 | Draft preview is impossible with a purely static site. | Static `/_preview/` page in the starter renders drafts client-side with the shared renderer and a short-lived preview token (Phase 7.6). |
| 3 | Markdown views and headers need server logic on a static host. | Generated `.htaccess` rules (Accept-header negotiation, `Vary`, `X-Robots-Tag`) plus nginx snippets (Phase 7.3). |
| 4 | Reserved words as column names (`key`, `condition`) would cause bugs. | Renamed to `handle` and `conditions` in `docs/01-data-model.md`. |
| 5 | Unique keys including nullable columns (slug + `deleted_at`, term `parent_id`) would not prevent duplicates. | Fixed: slug suffix on soft delete; generated `parent_key` column for terms; redirect uniqueness via `from_hash`. |
| 6 | Shared hosting often shares one database between apps. | Configurable table prefix (`bs_`). |
| 7 | Private files may be web-reachable on hosts that force `public_html`. | Two install modes; the installer verifies private paths return 403/404 over HTTP and refuses to finish otherwise. |
| 8 | Unbounded growth of logs, versions and jobs on small hosting quotas. | Retention rules and pruning jobs (`docs/01-data-model.md` section 0). |
| 9 | Low upload limits on shared hosts. | Chunked, resumable uploads (Phase 4.3). |
| 10 | Image processing can exhaust memory/CPU. | One image per job, memory guard, WebP default, AVIF optional (Phase 4.3). |
| 11 | Index size and speed. | ASCII binary collation for ULID and hash columns. |
| 12 | Per-request overhead in PHP. | No framework, explicit factories, compiled PHP-array caches for config/routes/schema/permissions, OPcache-friendly, included-file budgets enforced by tests (Phase 1). |
| 13 | Dynamic SQL from user-defined schemas is an injection risk. | Identifier regex + reserved list, registry-only identifiers, always-bound values, planner/applier with dry-run and locks (Phase 3). |
| 14 | Self-updates and supply chain. | Signed releases (Ed25519) with checksum verification, backup and automatic rollback (Phase 9.3). |
| 15 | Scope creep threatens "lightweight". | v1 limited to Phases 0-9; plugins, NGO pack, page builder, localization, GraphQL, collaboration moved to `implementation/later.md`. Public read-only MCP, bot-log import and AI citation tracking are off by default. |
| 16 | AI agents write Markdown, not editor JSON. | API and MCP accept Markdown for every rich-text field, converted server-side with round-trip tests (Phase 4.4). |
