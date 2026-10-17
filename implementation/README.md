# BS CMS: Implementation plan for Google Antigravity

This folder is the build instruction set. Hand the whole repository (this folder, `PLAN.md` and `docs/`) to the agent. Work **one phase at a time**, in order, and do not start a phase until the previous phase's acceptance checklist is fully ticked.

## 1. What we are building (the core, in one paragraph)

BS CMS is a **lightweight, secure, fast headless CMS written in PHP 8.2+ on MySQL 8 / MariaDB 10.6+ that installs from a zip on ordinary shared hosting** (no Node, no daemons, no Redis, no SSH required on the server). It manages pages, blog posts with categories and tags, and custom collections. Editors use a fast React admin (shipped as prebuilt static files). The public website is a static **Astro** site built from the CMS content. AI agents (Claude, ChatGPT and others) can manage the site safely through MCP. SEO, AI-search discoverability and safe crawler policy are on by default.

Everything else (plugins, page builder, NGO pack, marketplace) comes **after v1** (`later.md`).

Priority order when trade-offs appear: **1. security, 2. runs on shared hosting, 3. performance, 4. features.**

## 2. Documents and their authority

| File | Role |
|---|---|
| `implementation/phase-XX-*.md` | **Build instructions.** Authoritative for build order, scope per phase, file layout, tasks and acceptance criteria |
| `docs/01-data-model.md` | **Authoritative database schema.** Every phase lists which of its tables to create. Use its DDL exactly, plus the conventions in its section 0 (table prefix, ascii ids, naming) |
| `docs/02-ui-spec.md` | Authoritative dashboard UI/UX rules and per-screen checklists |
| `docs/03-auth-and-email.md` | Authoritative auth, permissions, OAuth and email behaviour |
| `docs/04-crawlers-and-discoverability.md` | Authoritative SEO / crawler / discoverability behaviour |
| `docs/05-editor.md` | Authoritative post editor behaviour |
| `PLAN.md` | Product rationale and decisions. Read once for context |

If two documents disagree: phase file > docs > PLAN. Report the conflict in the phase's `NOTES.md` instead of guessing.

## 3. Rules for the implementing agent

1. **No skipped items.** Checklists are acceptance tests. If something cannot be done, stop and write why in `implementation/NOTES.md`; do not silently drop it or leave a placeholder.
2. **No fake implementations.** No mocked data in production code, no `TODO` in place of a required feature, no hard-coded credentials, no disabled security checks "for now".
3. **Small vertical steps.** Each task in a phase file is one commit (or a few). Run tests and static analysis before each commit.
4. **Dependencies are allow-listed** (section 6). Adding any other dependency needs an entry in `DEPENDENCIES.md` with name, version, licence, size and reason, and must not be needed on the public request path.
5. **Every table, endpoint and screen gets tests** as described in the phase file.
6. **Ask, don't invent,** when a spec is ambiguous about security or data. For cosmetic choices, pick the simplest option consistent with `docs/02-ui-spec.md`.
7. **Items marked (verify)** in the docs must be checked against the primary source, and the result written into `implementation/NOTES.md`, before the code depends on them.
8. **Keep the docs true.** If implementation changes a schema or behaviour, update the doc in the same commit.
9. **Never commit secrets.** Keys, tokens, `.env` files and private keys are git-ignored; tests use generated test values.
10. **Write code that a shared host can run:** no `exec`, `shell_exec`, `proc_open`, `pcntl`, long-running loops, sockets servers or background daemons in PHP; respect `max_execution_time` (assume 30 s) and `memory_limit` (assume 128 MB).

## 4. Repository layout (monorepo)

```
bs-cms/
├── core/                       # PHP application (the CMS server)
│   ├── public/                 # the ONLY web-exposed directory (document root)
│   │   ├── index.php           # front controller
│   │   ├── .htaccess           # rewrites, security headers, deny dotfiles
│   │   ├── admin/              # built admin SPA (copied from admin/dist at release)
│   │   └── media/              # public media files (served statically, PHP execution disabled)
│   ├── src/                    # namespaced `BsCms\` (PSR-4)
│   ├── config/                 # default config (no secrets)
│   ├── migrations/core/        # numbered migrations
│   ├── resources/              # email templates, bot catalogue, message catalog
│   ├── storage/                # NOT web-exposed: cache/, logs/, keys/, private uploads/, tmp/
│   ├── bin/bs                  # CLI entry
│   ├── tests/                  # PHPUnit: Unit/, Integration/, Http/
│   └── composer.json
├── admin/                      # React + Vite admin app
├── packages/
│   ├── ui/                     # @bs/ui design system (tokens, atoms, fragments)
│   ├── client/                 # @bs/client typed API client + codegen from OpenAPI/schema
│   └── renderer/               # @bs/renderer Tiptap JSON -> HTML / Markdown (shared by admin preview and Astro)
├── starter/                    # Astro starter site + CI deploy workflow template
├── tools/                      # release packaging, signing, benchmark scripts
├── docs/  implementation/  PLAN.md
├── DEPENDENCIES.md  NOTES.md (created by agent)  CHANGELOG.md
└── .github/workflows/          # CI for this repo
```

Shared-host install modes (both must work; installer detects which):
- **Mode A (preferred):** the domain or subdomain document root points at `core/public`.
- **Mode B (fallback):** the host forces `public_html` as root; the zip is laid out so `public_html/` contains `public/` contents and the rest sits one level above `public_html` when possible; if the host allows nothing above `public_html`, a hardened `.htaccess` denies every path except the front controller, `admin/` and `media/`, and the installer verifies by HTTP that `storage/`, `config/` and `vendor/` return 403/404. Installation refuses to finish if any private path is reachable.

## 5. Coding standards

PHP
- `declare(strict_types=1);` everywhere, PHP 8.2 syntax features allowed (readonly classes, enums, first-class callables). PSR-12 style via PHP-CS-Fixer. **PHPStan level 8** clean. PSR-4 autoload, Composer **classmap-authoritative** and `--no-dev` in releases.
- No global state except the container; no static service locators in domain code.
- All SQL goes through `Database\Connection` (PDO, `ERRMODE_EXCEPTION`, `EMULATE_PREPARES=false`, `utf8mb4`) and the query builder; raw SQL only inside migrations and the schema manager, with identifiers from the registry only.
- Errors: domain exceptions mapped to RFC 9457 `application/problem+json` responses; never leak stack traces when `APP_DEBUG=false`.
- Time: `DateTimeImmutable` in UTC everywhere; convert only for display.

TypeScript (admin, packages, starter)
- `strict: true`, ESLint + Prettier, no `any` without a comment, Vitest for units, Playwright for end-to-end.
- Components from `@bs/ui` only; no ad-hoc tables, dialogs or pickers inside screens.

Git
- Conventional commits (`feat:`, `fix:`, `chore:` ...). One phase per branch, merged when its acceptance checklist passes.

## 6. Dependency allow-list (pin the latest stable version at the start of Phase 0 and record it in `DEPENDENCIES.md`)

PHP runtime (shipped in `vendor/`):
| Package | Purpose | Notes |
|---|---|---|
| `nikic/fast-route` | Routing | small, BSD-3 |
| `nyholm/psr7`, `nyholm/psr7-server` | PSR-7/17 messages | small, MIT; required by the OAuth server too |
| `league/commonmark` | Markdown -> AST for AI Markdown input | BSD-3 |
| `phpmailer/phpmailer` | SMTP | LGPL-2.1, unmodified dependency only |
| `league/oauth2-server` | OAuth for AI clients (Phase 8) | MIT; loaded only on `/oauth/*` and `/mcp` |
| One TOTP package (e.g. `spomky-labs/otphp`) | 2FA | confirm licence and maintenance in Phase 0 **(verify)** |
Dev only: `phpunit/phpunit`, `phpstan/phpstan`, `friendsofphp/php-cs-fixer`.
Do **not** add: full frameworks (Laravel, Symfony full-stack), ORMs (Doctrine ORM, Eloquent), template engines for the public site, Guzzle (use a small cURL wrapper), Monolog (use a small PSR-3 file logger).

PHP extensions required (installer checks): `pdo_mysql`, `mbstring`, `json`, `openssl`, `sodium`, `fileinfo`, `curl`, `zip`, `gd` **or** `imagick`, `ctype`, `filter`. Recommended: `opcache`, `apcu`, `intl`, `exif`.

Admin (built, never runs on the server): `react`, `react-dom`, `vite`, `typescript`, `tailwindcss`, shadcn/ui generated components on `@radix-ui/*`, `@tanstack/react-query`, `@tanstack/react-router`, `@tanstack/react-table`, `@tanstack/react-virtual`, `react-hook-form`, `zod`, `cmdk`, `sonner`, `lucide-react`, `react-day-picker`, `date-fns`, `@dnd-kit/*`, `@tiptap/*` (MIT extensions only).
Starter site: `astro`, `pagefind`, `@bs/renderer`, `@bs/client`; nothing else by default.

## 7. Global budgets (enforced by CI from the phase where they become measurable)

| Budget | Target |
|---|---|
| PHP API request, typical shared host, warm OPcache | p95 < 150 ms for list/get; < 300 ms for writes |
| PHP memory per request | < 32 MB (API), < 96 MB (image processing job). **Measure as the increase over an empty PHP script on the same host** (an empty script already peaks at about 9.4 MB on Hostinger's PHP 8.3), with the application's own increase under 4 MB for a normal API request |
| PHP files loaded per request (`get_included_files()`, production release, Hostinger PHP 8.3) | `/health` < 60 (58 measured); any normal API route < 75 (67 measured); hard ceiling 150 |
| Cold boot of the front controller (no DB) | < 10 ms on a dev machine |
| Release zip (without starter) | < 10 MB |
| Admin initial JS (app shell, gzipped) | < 200 KB; editor and schema builder lazy chunks measured separately |
| Public starter page | Lighthouse performance ≥ 95 mobile; LCP < 2.0 s on throttled 4G; CLS < 0.05; JS on a text-only page < 15 KB gzipped (ideally 0) |
| MCP read call | < 200 ms server time; zero idle cost |

## 8. Global security baseline (applies to every phase)

- OWASP ASVS level 2 used as the review checklist **(verify the current ASVS version)**.
- HTTPS required in production (installer warns and refuses secure cookies on plain HTTP except `localhost`).
- Security headers on all CMS responses: `Content-Security-Policy` (admin: `default-src 'self'`; no inline scripts), `Strict-Transport-Security`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy`, `frame-ancestors 'none'`, `X-Robots-Tag: noindex, nofollow` on the whole CMS host.
- Input validated against the field registry; output JSON only; no HTML rendering of user content on the CMS host except in the sandboxed preview.
- Uploads: validate by magic bytes, re-encode images, random file names, stored under `public/media/` with `.htaccess` that disables PHP/CGI execution and sets `Content-Disposition` for non-images; SVG disabled by default.
- Outbound HTTP (webhooks, IndexNow, bookmark previews, OAuth metadata documents): one `HttpClient` with SSRF protection (resolve DNS, block private/loopback/link-local ranges, max 3 redirects re-checked each hop, 5 s timeout, 2 MB cap).
- Secrets encrypted with libsodium (`crypto_secretbox`) using an app key in `storage/keys/app.key` (or an environment variable), never in the database in plain text, never returned by the API.
- Every mutation passes the `Authorizer` and writes the audit log.
- Dependencies scanned with `composer audit` and `npm audit` in CI; release blocked on high/critical advisories.

## 9. Phase map

| Phase | File | Outcome |
|---|---|---|
| 0 | `phase-00-foundations.md` | Repo, CI, decisions and spikes proven on a real shared host |
| 1 | `phase-01-core-kernel.md` | PHP kernel, database layer, migrations, installer, CLI, jobs, cache, logging |
| 2 | `phase-02-auth-email.md` | Users, sessions, 2FA, roles/permissions, API tokens, audit, SMTP email |
| 3 | `phase-03-content-engine.md` | Schema engine, operations layer, REST API, entries, versions, built-in collections |
| 4 | `phase-04-taxonomy-media-documents.md` | Taxonomies, media pipeline, rich-text documents + Markdown conversion, SEO data, redirects |
| 5 | `phase-05-design-system-admin-shell.md` | `@bs/ui` design system and admin shell |
| 6 | `phase-06-admin-screens.md` | All v1 admin screens incl. post editor |
| 7 | `phase-07-astro-starter-publishing.md` | Astro starter, build/deploy pipeline, preview, discoverability outputs |
| 8 | `phase-08-ai-interface.md` | MCP server, OAuth, approvals, agent briefing pack |
| 9 | `phase-09-hardening-release.md` | Security audit, performance, updater, backups, docs, v1.0 |
| after v1 | `later.md` | Plugins, NGO pack, page builder, etc. |

Phases 5-6 (admin) may run in parallel with Phase 4 once Phase 3's API contract is frozen; everything else is sequential.

## 10. Definition of Done for a phase

- [ ] Every task and checklist item in the phase file is complete.
- [ ] `composer test`, `composer stan`, `composer cs`, `npm test`, `npm run lint`, `npm run typecheck` pass in CI.
- [ ] New tables match `docs/01-data-model.md` exactly (a schema-diff test compares the live schema with the spec).
- [ ] Budgets in section 7 that apply to this phase are measured and recorded in `NOTES.md`.
- [ ] Security checks in the phase file are tested (not just implemented).
- [ ] The phase's manual test script is run on the real shared-host test account and the result recorded.
- [ ] Docs updated for any change.
