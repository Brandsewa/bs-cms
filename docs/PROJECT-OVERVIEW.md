# BS CMS: project overview, build record and hand-over guide

Audience: anyone picking up this project (developer, tester, product owner, or an AI agent). It explains what BS CMS is, what was planned, what has been built, how it was built, with which technologies, how it was verified, what is still open, and how to run it.

Snapshot: 22 October 2026. `main` contains the merge of PR #43 (commit cd2ae47), which brought in all four sprint branches plus the verifier fixes. Anything not marked "verified" below should be treated as built but not yet proven.

Related documents (read in this order after this one):
1. `PLAN.md`: the original plan, decisions, risks and phase list (superseded for build order by `implementation/`).
2. `implementation/README.md` and `implementation/phase-*.md`: the build instructions given to the coding agents.
3. `docs/01-data-model.md`, `02-ui-spec.md`, `03-auth-and-email.md`, `04-crawlers-and-discoverability.md`, `05-editor.md`: the detailed specifications.
4. `NOTES.md`: the engineering log (decisions, spikes, measurements, host quirks). Long, chronological, authoritative for "why".
5. `DEPENDENCIES.md`: every third-party dependency with version, licence and reason.
6. `docs/dev-environment.md`: the local Hostinger simulation.
7. `docs/sprint/*.md`: what each sprint session built, stubbed and left open. `docs/sprint/NEXT-SPRINTS.md`: pending work and the next sprint plan.
8. `docs/ui/*`: UI contribution rules, UI notes, requests from UI to backend.

---

## 1. What BS CMS is

A lightweight, secure, AI-native headless CMS.

- Backend: PHP 8.2+ with MySQL 8 or MariaDB 10.6+. No framework, no daemons, no Node on the server. It installs from a single zip on cheap shared hosting (the reference host is Hostinger, LiteSpeed).
- Admin: a React single-page app (shadcn/Radix components, Tailwind v4, Supabase-style design system with a blue brand colour) shipped as static files inside the zip.
- Public site: a static Astro site that is built off the PHP host (GitHub Actions by default) and published to the host. Visitors never touch PHP or the admin code.
- AI-native: any MCP-capable assistant (Claude, ChatGPT, Cursor, Claude Code) can connect over OAuth 2.1 and manage the site safely through the same operations layer that the REST API and the admin use.
- Discoverability by default: SEO, GEO and AEO outputs, crawler policy management, sitemaps, `llms.txt`, markdown views, JSON-LD, IndexNow.

Product decisions (from `PLAN.md` section 0): developer-first (bring your own agent), English only in v1 with a message catalogue, Astro-only rendering, self-hosted only, proprietary for now, Tiptap for the post editor, balanced AI-crawler default, Supabase design system as the dashboard reference.

---

## 2. How the project was run

This was built by several AI coding sessions in parallel under a verifier.

- **Specification first.** The plan, data model, UI spec, auth spec, crawler spec and editor spec were written before any code, and the build instructions were split into ten phase files under `implementation/` with acceptance checklists.
- **Builders.** Antigravity (Gemini) sessions did the implementation: three "GA" sessions (system and backend, backend and publishing, admin UI) and a design session (S4) for the design system and polish. They worked in separate git worktrees on separate branches.
- **Verifier.** Claude acted as the independent verifier and reviewer. For every delivery it pulled the branch, built the real release zip, deployed it to a local copy of the production host, tested over HTTP and in a real browser with screenshots, then either reported defects or approved. Merges to `main` only happened after explicit owner approval, one PR at a time, as merge commits.
- **Rules that grew out of repeated defects** (now standing rules): prove behaviour over HTTP through `public/index.php`; use real API shapes captured from the running system, never invented ones; strict presenters with key-set tests; never return exception text to clients; production-build screenshots, not the dev server; one deployer at a time on the shared live host; timeouts on every command; `GIT_EDITOR=true` to stop git from waiting on an editor.
- **Sprint mode.** After the first dozen PRs the owner switched to "sprint mode": four sessions build everything in one pass without per-feature testing, then the verifier integrates and tests once. This was fast but pushed defects to the end: the integration pass found dozens (see section 9). The next sprints therefore require each session to run type checks, lint, static analysis and tests before handing back.

---

## 3. Plan versus what exists

Phases from `PLAN.md` and `implementation/`, with current state.

| Phase | Scope | State |
|---|---|---|
| 0 Spikes | Benchmarks on a real host, stateless MCP, Tiptap check, design tokens | Done. Results in `NOTES.md` section 4 (Hostinger measurements, 50k-row benchmark, MCP spike, Astro pipeline, editor, tokens) |
| 1 Kernel | Router, container, config, migrations, cache, jobs, installer, operations layer | Done and measured on the live host |
| 2 Auth, roles, audit, email | Login, reset, invitations, TOTP, sessions, roles matrix, API tokens, audit, SMTP | Done |
| 3 Content engine | Collections, fields, versions, query API, taxonomies, media, redirects, SEO, schema as code, OpenAPI | Done |
| 4 Taxonomy, media, documents | Term trees and merge, media pipeline with variants and chunked upload, documents | Done |
| 5 Design system and shell | `@bs/ui` package, tokens, DataTable, shell, command menu, auth screens | Done |
| 6 Admin screens | Dashboard, collection list, editor, taxonomies, media, menus, redirects, users and roles, settings, AI connections, schema builder, site health, audit | Built; most verified (section 8) |
| 7 Astro starter and publishing | Starter site, build pipeline, preview, webhooks, crawler policy, sitemap, llms.txt, IndexNow | Starter and publishing API built; crawler policy has API only, no admin screen |
| 8 AI interface | OAuth 2.1 server, MCP, pending changes, agent briefing pack, kill switch | Done; rate limits fixed during integration |
| 9 Hardening and release | Backups, self-update, security review, performance, docs, release checklist | Partly: backups, restore, maintenance mode, signature-verifying updater skeleton. Security review, real update flow, perf and release checklist are pending |
| Later | Plugin system, NGO pack, theme builder, localisation | Not started, by design |

The detail of what is still missing is in section 10 and `docs/sprint/NEXT-SPRINTS.md`.

---

## 4. Technology and architecture

### 4.1 Backend (`core/`)

- PHP 8.2+, strict types, no framework. Own kernel on PSR-7/15/17 (`nyholm/psr7`, `psr/http-server-middleware`), `nikic/fast-route` for routing, PDO with a thin query layer, file cache, queue/job runner that works from cron or lazily.
- **One operations layer.** Every capability (create entry, publish, upload media, change schema) is a typed operation with an input schema, permission check, validation, audit entry and hooks (`core/src/Operations`, 31 operations). The REST API, the admin and the MCP server are all thin adapters over it, so permissions and audit behave identically for people and AI. There is no AI-only back door.
- **Storage model.** Table per collection (`c_<handle>`) with real columns for system and filterable fields, one JSON column for the rest, indexed through generated columns. The schema is defined as data (and as files, "schema as code") and applied through versioned migrations with a plan and apply step.
- **Content.** Versions with optimistic concurrency (`If-Match`), keyset pagination, soft delete and restore, scheduling, slugs, SEO field group, JSON-LD, document schema v1 (editor JSON with a node allow-list, Markdown in and out through `league/commonmark`).
- **Errors.** RFC 9457 problem details everywhere; no exception text in responses; request ids in logs.
- **Security.** Argon2id passwords, opaque hashed tokens, CSRF, strict headers and CSP, step-up re-authentication for sensitive operations, rate limiting, lockout, audit log, SSRF guards on outbound requests, MIME sniffing and re-encoding of images, `nosniff`, `Cache-Control: no-store` on API responses (the host CDN caches identical GETs otherwise).
- **Auth.** Sessions, TOTP 2FA with recovery codes (`spomky-labs/otphp`), invitations, email verification, API tokens, and an OAuth 2.1 authorization server (`league/oauth2-server`) with PKCE S256, refresh rotation and reuse revocation. Grants are bound to a role and an `ai_mode` (`draft_only`, `approval_required`, `full`).
- **Roles.** Nine seeded roles: owner, admin, developer, editor, author, contributor, viewer, ai_draft, ai_publisher, with subject/action/effect rules and own-entries restrictions, evaluated by a single `Authorizer`.
- **MCP.** Stateless Streamable HTTP at `/mcp` (one POST in, one JSON out): 25 generic tools that take a `collection` argument, resources (schema, settings, agent briefing pack, style guide, SEO checklist, directives spec), prompts, tool allow-list, untrusted-content wrapping of CMS data, per-grant rate limits (60/min burst, 1000/day, 20 writes/min), response size cap with continuation cursor, and a one-click AI kill switch (`/api/v1/ai/disconnect`).
- **Email.** PHPMailer with presets for Resend, ZeptoMail, Zoho and generic SMTP, queued sending, templates, email log.
- **Publishing.** Debounced deployments table, build tokens, preview tokens (30 minute TTL), URL patterns, IndexNow, `GET /api/v1/public/site`, `GET /api/v1/frontend/status`, GitHub Actions dispatch.
- **Discoverability.** Crawler bot catalogue with presets (balanced, open, private, custom), versioned policy with rollback, robots.txt generator with lint rules, canary check against the live robots.txt.
- **Operations.** `bin/bs` CLI: migrate, blocks sync, backup create and restore, doctor, jobs run. Pure-PDO backups (no `exec`), maintenance mode lock file, Ed25519 plus SHA256 verification in the self-updater.
- **Contract.** `GET /api/v1/openapi.json` is generated from the routes and frozen as `docs/api/openapi-v1.json`; a test fails if they drift. About 214 route registrations, 151 documented operations.

Data model: 40 tables created by 10 migrations (`core/migrations`): settings, jobs, rate limits, users, roles, user roles, role permissions, sessions, password resets, email verifications, invitations, API tokens, audit log, email log, notifications, collections, fields, schema changes, entry versions, saved views, webhooks and deliveries, entry relations, taxonomies, collection taxonomies, terms, term relationships, media folders, media, media variants, media usage, redirects, deployments, preview tokens, build tokens, crawler policy versions, index notifications, bot log, OAuth clients, grants, auth codes, access tokens, refresh tokens, pending changes. Full SQL and rationale in `docs/01-data-model.md`.

### 4.2 Admin (`admin/`, `packages/ui`, `packages/client`, `packages/renderer`)

- Vite 8, React 19, TypeScript, Tailwind v4 (needs an explicit `@source` for `packages/ui`), Radix primitives, `lucide-react`, TanStack Router (code-based, lazy routes), TanStack Query, TanStack Table and Virtual, react-hook-form with a hand-rolled Zod resolver, cmdk command menu, Sonner toasts, react-day-picker, dnd-kit, Tiptap 3 for the editor.
- `@bs/ui`: tokens, atoms and fragments (DataTable with row checkboxes, bulk actions, density, saved views; filter bar; form layout; page container, header, nav; pickers including a slug input; skeletons and states; i18n catalogue). Several components are hand-written instead of adding dependencies (resizable, OTP input, drawer, zod resolver); see `DEPENDENCIES.md` 4.3.
- `@bs/client`: typed API client generated from the frozen OpenAPI file (`npm run generate-client -w @bs/client`), request retries, multipart and chunked upload.
- `@bs/renderer`: shared Tiptap-JSON to HTML and Markdown renderer used by the admin and the starter.
- Admin app areas (`admin/src`): auth, dashboard (metrics, onboarding checklist, welcome dialog), entries (collection list, entry editor, pages tree, blocks field editor), media, taxonomies, menus, redirects, users and roles (roles matrix, role preview), settings (general, security, tokens, webhooks, publishing, email, email log, AI connections, notifications), system (audit, site health), developer (jobs, schema builder, API explorer), account (profile, password, email, 2FA, sessions), shell (sidebar, command menu, notifications drawer, shortcuts, banners, error boundary, offline banner), gallery (component gallery used by the axe suite).
- Budgets: initial admin JS must stay under 200 KB gzipped (currently about 174 KB); a committed baseline in `admin/scripts/size-budget.json` guards regressions.
- Production serving: the SPA is served by PHP for `/admin/*` so security headers and CSP are applied; static assets are served directly; missing assets return 404, never the SPA shell.

### 4.3 Public site (`starter/`)

Astro (static output), Pagefind search generated at build time, RSS, sitemap and `llms.txt` generation, IndexNow key file, blocks as JSON definitions, preview page, search page, typed content client from `@bs/client`, `AGENTS.md` briefing for coding agents. Built off-host, deployed over SFTP or static hosting; GitHub Actions workflow in `.github/workflows/deploy.yml`.

### 4.4 Hosting and the local simulation

- Live reference host: Hostinger shared hosting (LiteSpeed, PHP 8.3, MariaDB 11.8), site `bskickstarter.com`. The docroot is a symlink `public_html -> core/public`. Disabled functions include `exec`, `shell_exec`, `proc_open`, `popen`, `symlink`; APCu is absent. Host quirks that shaped the code: LiteSpeed strips the `Authorization` header unless rewritten in `.htaccess`; static files bypass `mod_headers`; JavaScript is served without a charset so non-ASCII glyphs are `\u`-escaped; the CDN caches identical GETs.
- Local simulation (`dev/hostinger-sim`, driven by `dev\sim.ps1`): OpenLiteSpeed 1.8.3 with lsphp83, MariaDB 11.8 (profiles for MySQL 8.4 and MariaDB 10.6), a Caddy edge on HTTPS emulating the host CDN, a cron container and an SFTP target. Details and parity notes in `docs/dev-environment.md` and `NOTES.md` section 17.

### 4.5 Quality gates and CI (`.github/workflows/ci.yml`)

- PHP: `php-cs-fixer`, `phpstan` (level 8 via `phpstan.neon`), `composer audit`, PHPUnit unit and integration suites on a matrix of PHP 8.2, 8.3 and 8.4 against MySQL 8.0, 8.4 and MariaDB 10.6, 10.11 and 11.4.
- UI: ESLint, TypeScript for admin, ui, client and starter, generated-client drift check, Vitest suites (ui 288 tests, admin 104, client 23, renderer 34, starter 16), npm audit of production dependencies, bundle size budget, axe accessibility suite on the component gallery, and Playwright real-browser tests (SPA deep-link boot and axe on every route in light and dark, plus screen specs).
- Release: `tools/build-release <version>` produces `build/bs-cms-<version>.zip` (about 1.3 MB) and verifies permissions and absence of dev files.

---

## 5. Repository map

```
PLAN.md, NOTES.md, DEPENDENCIES.md, CHANGELOG.md   planning and engineering log
implementation/        phase-by-phase build instructions for the coding agents
docs/                  specs, API contract, sprint reports, UI docs
core/                  PHP application
  src/                 Ai Audit Auth Authz Blocks Cache Cli Content CrawlerPolicy Dashboard Database
                       Document Events Health Http Install Jobs Kernel Log Mail Mcp Media Notifications
                       OAuth OpenApi Operations PendingChanges Publishing Redirects Routing Schema
                       Security Seo Settings Support Taxonomy Users Webhooks
  migrations/ config/ resources/ (bots.json, blocks/, schema/) public/ (index.php, .htaccess)
  tests/               Unit and Integration (83 test files)
admin/                 React admin app (src/, tests/browser, scripts/)
packages/ui|client|renderer   shared TypeScript packages
starter/               Astro public site template
tools/                 build-release, OpenAPI generation, audit capture and screenshot helpers
dev/                   hostinger-sim (Docker), sim.ps1 driver
spikes/                Phase 0 spike code
.github/workflows/     ci.yml, deploy.yml
```

---

## 6. How to run it locally

Prerequisites: Docker Desktop, Node 22+, PHP 8.3 CLI with Composer (only for backend development), Git Bash on Windows.

```bash
npm ci
npm run build -w admin                  # admin bundle
bash tools/build-release 0.9.0-dev      # produces build/bs-cms-0.9.0-dev.zip
```

```powershell
.\dev\sim.ps1 up dev 8081               # start the Hostinger simulation (HTTP 8081, HTTPS 8481)
.\dev\sim.ps1 deploy dev build\bs-cms-0.9.0-dev.zip   # unzip, install and seed users
.\dev\sim.ps1 reset dev                 # destroy volumes (always `up` again before `deploy`)
```

Admin: `https://localhost:8481/admin/` (accept the self-signed certificate).

Seeded local-only test users (created by the sim deploy, never use on a real site), password for all: `TestUser2026!Secure`

| Role | Email |
|---|---|
| owner | owner@example.com |
| admin | admin@example.com |
| developer | developer@example.com |
| editor | editor@example.com |
| author | author@example.com |
| contributor | contributor@example.com |
| viewer | viewer@example.com |

Useful checks: `cd core && composer install && vendor/bin/phpunit --testsuite Unit && vendor/bin/phpstan analyse && vendor/bin/php-cs-fixer fix --dry-run --allow-risky=yes`; `npm run typecheck`, `npm run lint`, `npm run test`, `npm run test:browser`, `node admin/scripts/check-size.mjs`.

Tips that cost time before: on Git Bash set `MSYS_NO_PATHCONV=1` for `docker exec` with Linux paths; open the admin through `https://localhost:8481` (the port after `up`), not a bare `/admin` URL; the integration tests need a MySQL or MariaDB server and run in CI.

---

## 7. How verification was done

- HTTP suites (Python, scratch scripts, not committed) against the simulation for auth, permissions per role, media security, taxonomy merge, crawler policy, maintenance, backup and restore, MCP limits, security settings enforcement, block validation, account endpoints and the role matrix.
- Playwright against the production build for every route, with screenshots read visually, plus the committed browser specs.
- Live host checks on `bskickstarter.com` earlier in the project (deploy, installer, cron, CDN behaviour). The live site currently runs an older build.
- Measured budgets recorded in `NOTES.md` (cold boot 8 to 10 ms, request memory, files per request, 50k-row benchmark, MCP latency).

---

## 8. What is verified, and what is not

Verified over HTTP and in the browser on the integrated build: login and lockout, role matrix, step-up, password policy (including the configurable settings), account endpoints and 2FA setup, media upload and security, chunked upload, taxonomy merge, entry create and save, page blocks validation, redirects and loop rejection, webhooks SSRF rejection, security settings, jobs, AI grants, crawler policy (step-up, validation, rollback), maintenance mode, backup and restore, MCP tools, limits and kill switch, site health, and a visual pass of the admin screens.

Built but not yet proven end to end: webhook delivery and retry, API token reveal in the UI, redirect CSV import and export, custom role editing and Preview as role, schema builder apply, email settings test send, invitations and acceptance, audit filters, saved views and bulk actions, revision restore, scheduled publish, the 2FA login challenge and recovery codes, email change, real MCP client connection, IndexNow with a key, uploads over 10 MB, self-update, a restore on a clean second host.

---

## 9. Notable defects found and fixed during integration

Useful as a checklist of what to look for in any future agent output:
- Admin screens written against assumed API shapes (media list wrapper and field names, taxonomy labels, entry payload fields, session shape): fixed by normalising to the real responses; rule: capture shapes from the running system.
- Dead code paths that looked fine: the MCP rate limiter was never registered in the container and the write cap read a property that does not exist; the blocks validator never ran because the field was typed `json`; the crawler step-up accepted any header value.
- Security gaps: no password policy on change, invite or reset; crawler policy input not validated (robots.txt injection); maintenance mode locked out the operator; backup files were world-readable; the saved security settings were not enforced.
- Operational: restore failed on generated columns; the admin hid recovery codes because a session refresh remounted the screen.
- Process: sessions skipped type checks and static analysis in sprint mode, producing about 130 type errors and 32 static-analysis errors that CI then surfaced.

---

## 10. Pending work and next steps

Authoritative list and sprint plan: `docs/sprint/NEXT-SPRINTS.md`. Summary:

- Missing screens: SEO and crawler policy settings (spec 4.9), a System screen (maintenance, backups, updates, doctor), content-editor dashboard, review queue, scheduling calendar.
- Phase 9: real self-update flow, scheduled backups and retention, security review, performance work, documentation and release checklist.
- Known defects: schema builder shows doubled plurals; generic max-length guard for inputs; confirm role decisions (developer can run maintenance and backups, admin cannot).
- Owner actions: Hostinger cron for the queue worker (`NOTES.md` 6.8), SMTP credentials and sender domain, decision on a second-host test, redeploy of the live site after the merge, brief the external tester.
- Next sprint sessions: A SEO and System screens, B content workflow, C hardening and Phase 9, D UI fidelity, accessibility and Playwright baseline, optional E the Astro starter round trip.

---

## 11. Working agreements for whoever continues

1. Specs win over plans; checklists in `docs/02-ui-spec.md` are acceptance tests.
2. Before handing work back: `tsc`, ESLint, `php -l`, php-cs-fixer, phpstan and the unit suites must pass; CI runs all of them.
3. Prove backend behaviour through `core/public/index.php` over HTTP with real fixtures; add presenter key-set tests; never return exception messages.
4. Build and look at the production bundle in a browser before calling a screen done; test at 1440 px and 390 px, light and dark.
5. Change shared wiring files (`ApiRoutes.php`, `CoreServiceProvider.php`, `index.php`, `route-tree.tsx`, `nav-groups.ts`) only inside marked blocks (`// SPRINT-X BEGIN` / `END`) to avoid merge conflicts.
6. Add dependencies only with approval and record them in `DEPENDENCIES.md`.
7. One deployer at a time on any shared host; never deploy over another session's lock.
