# Phase 0: Foundations, decisions and spikes

**Goal:** prove the risky assumptions on a real shared host before writing the product, and set up the repository so every later phase has CI, standards and budgets from day one.
**Depends on:** nothing. **Duration guide:** 1-2 weeks.
**Output:** a repository skeleton with CI, a written `NOTES.md` with decisions and measurements, and throwaway spike code in `spikes/` (deleted at the end of the phase, results kept).

## 0.1 Repository and tooling
1. Create the monorepo layout from `README.md` section 4 (empty packages with `README.md` each).
2. `core/composer.json`: PHP `>=8.2`, PSR-4 `BsCms\\` -> `src/`, scripts `test`, `stan`, `cs`, `cs-fix`. Install dev tools only.
3. Root `package.json` with npm workspaces for `admin`, `packages/*`, `starter`. Node LTS pinned in `.nvmrc` (build machines only).
4. CI workflow (`.github/workflows/ci.yml`): matrix PHP 8.2 / 8.3 / 8.4 x MySQL 8.0 / 8.4 x MariaDB 10.6 / 10.11 / 11.4 (service containers); jobs: PHP lint, PHPStan, PHPUnit, Node lint/typecheck/test, `composer audit`, `npm audit --omit=dev`.
5. `DEPENDENCIES.md` with every allow-listed package from `README.md` section 6 pinned to its latest stable version, licence and purpose. Confirm licences from each package's repository.
6. `NOTES.md` with sections: Decisions, Verifications, Measurements, Open issues.
7. `.editorconfig`, `.gitignore` (ignore `core/storage/*` except `.gitkeep`, `core/vendor`, `node_modules`, `dist`, `.env*`, keys).

## 0.2 Shared-host test accounts
1. Arrange at least two real shared hosting accounts representative of the market (e.g. one cPanel + Apache/LiteSpeed, one other control panel; ideally one with MariaDB and one with MySQL). Record PHP version, extensions, `memory_limit`, `max_execution_time`, `upload_max_filesize`, `post_max_size`, OPcache/APCu availability, whether cron is available, whether the document root can be changed, whether files above `public_html` are allowed, and whether `.htaccess` `Header` and `RewriteCond %{HTTP_ACCEPT}` work.
2. Write the results into `NOTES.md` as the **hosting matrix**. Every later phase's manual test runs on these accounts.

## 0.3 Spike A: storage model benchmark
Build a throwaway PHP script that creates `bs_c_posts` per `docs/01-data-model.md` (table-per-collection with real columns + `data` JSON + generated column for one filterable field), inserts 50,000 rows with realistic JSON (2-5 KB), and measures on each test host:
- list query with status filter + sort by `published_at`, page size 25 (offset and cursor),
- filter on a JSON-backed generated column (indexed) and on a non-indexed JSON path,
- term filter via `term_relationships` join with 20 categories,
- single entry fetch with 2 relations populated,
- insert/update latency, and `ALTER TABLE ... ADD COLUMN ... GENERATED` duration on 50k rows.
Record numbers. **Pass:** list/filter queries < 50 ms DB time on the shared hosts. If `ALTER TABLE` on the host is slow or not permitted, record it: Phase 3 must then run schema changes as background jobs with progress and allow them only when the user confirms.
Also verify on MariaDB: generated column syntax with `JSON_UNQUOTE(JSON_EXTRACT(...))`, index on virtual column, `JSON` type behaviour. Record in Verifications.

## 0.4 Spike B: stateless MCP on shared hosting
1. A single PHP file handling `POST /mcp` JSON-RPC: `initialize`, `tools/list`, `tools/call` with one tool `ping` and one `list_posts` (reading the spike table). No sessions, no SSE; respond with `application/json`.
2. Put it behind HTTPS on a test host. Connect from Claude (custom connector) and ChatGPT (developer mode) with (a) no auth on a throwaway URL, then (b) a minimal OAuth flow, documenting exactly what each client requires: protected resource metadata, authorization server metadata, dynamic client registration vs client ID metadata documents, scopes, redirect URIs.
3. Read the current MCP specification (authorization and Streamable HTTP transport sections) and record the protocol version the CMS will target.
4. Evaluate whether an existing PHP MCP SDK supports stateless Streamable HTTP; if not, the CMS uses its own small JSON-RPC handler (expected).
**Pass:** both clients list tools and call one tool against the shared host. Record every requirement found in `NOTES.md` (it becomes the spec for Phase 8).

## 0.5 Spike C: Astro build and deploy pipeline (critical decision)
The public site is static Astro. Shared hosting cannot run Node, so **the build must run elsewhere**. Prove the default pipeline:
1. Minimal Astro site fetching posts from the spike API with a build token, outputting static HTML and `.md` twins.
2. GitHub Actions workflow triggered by `repository_dispatch` (the CMS calls the GitHub API with a fine-grained token) and by manual dispatch; builds the site; deploys to the shared host over **SFTP** (fallback **FTPS**) using a sync tool that uploads only changed files and deletes removed ones; then `POST`s a status callback to the CMS.
3. Measure: time from "publish" to live page for a 200-page site; bytes uploaded for a one-post change.
4. Document two alternatives for users without GitHub: **(a) local build** (`npm run build && npm run deploy` from the developer machine), **(b) static hosts** (Cloudflare Pages / Netlify / similar) with the same workflow.
5. Verify `.htaccess` content negotiation: `RewriteCond %{HTTP_ACCEPT} text/markdown` serves `index.md` with `Content-Type: text/markdown; charset=utf-8` and `Vary: Accept`; record nginx/LiteSpeed equivalents.
**Pass:** publish-to-live under 5 minutes for the test site via GitHub Actions; record the decision "default pipeline = GitHub Actions + SFTP; alternatives documented".

## 0.6 Spike D: Tiptap check
Per `docs/05-editor.md` section 3: pin Tiptap versions, confirm which extensions needed in `docs/05-editor.md` section 5 are MIT (especially DragHandle), build a prototype editor route with slash menu, bubble toolbar, image node and drag handle, and measure gzipped chunk size, time to interactive, and typing latency in a 10,000-word document. Record the editor budget in `NOTES.md`.
Also prototype `@bs/renderer`: render a Tiptap JSON document to HTML and to Markdown in Node (for Astro) with identical structure.

## 0.7 Spike E: design tokens
Build three static screens with shadcn/ui + Tailwind using Supabase design-system patterns (`docs/02-ui-spec.md`): a collection list with full DataTable features (selection + bulk bar visible), an entry editor layout, a settings page. Choose the exact blue (`--primary-hue`), font (free licence), radius and density; check WCAG AA contrast in light and dark. Record tokens in `NOTES.md`.

## 0.8 Verifications to complete (write results in NOTES.md)
- [ ] MCP authorization requirements of Claude and ChatGPT (from 0.4).
- [ ] `league/oauth2-server` current version and its PHP requirement; confirm PKCE and refresh rotation support.
- [ ] TOTP package choice and licence.
- [ ] PHPMailer licence and current release; decide PHPMailer vs Symfony Mailer.
- [ ] Zoho Mail SMTP hosts and ports (per region); Resend and ZeptoMail settings unchanged.
- [ ] Bot tokens in `docs/04-crawlers-and-discoverability.md` section 3 marked unverified: check each vendor's documentation; update the catalogue with sources and dates.
- [ ] Google's current guidance on Google-Extended, `nosnippet`/`max-snippet` for AI features, and FAQ rich-result eligibility.
- [ ] Argon2id availability (`PASSWORD_ARGON2ID` defined) on the test hosts.
- [ ] OWASP ASVS current version.

## 0.9 Acceptance checklist
- [ ] Repository skeleton, CI green on an empty test in every matrix cell.
- [ ] Hosting matrix recorded for at least two shared hosts.
- [ ] Spike A numbers recorded and pass criteria met (or a documented mitigation).
- [ ] Spike B: Claude and ChatGPT both call a tool on the shared host; requirements recorded.
- [ ] Spike C: publish-to-live pipeline works end to end; decision recorded; markdown negotiation verified.
- [ ] Spike D: Tiptap versions pinned, licences confirmed, budget recorded; renderer prototype works.
- [ ] Spike E: tokens decided and recorded with contrast results.
- [ ] All verifications in 0.8 recorded.
- [ ] `spikes/` deleted; nothing from spikes is copied into product code without review.
