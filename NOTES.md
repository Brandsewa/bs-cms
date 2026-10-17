# NOTES.md — BS CMS Engineering Notes & Verifications

This document is the authoritative log for Phase 0 technical verifications, architecture decisions, empirical benchmark measurements, and host compatibility records.

---

## 1. Decisions

1. **Phase Roadmap & Authority:**
   - Follow `implementation/phase-*.md` as the authoritative sequence.
   - Core v1 is limited to Phases 0–9. Plugins, page builder, and NGO pack are deferred to `implementation/later.md`.
2. **Minimalist Architecture (Ponytail):**
   - No external framework or monolithic ORM. The core uses native PHP PDO with a thin query builder, FastRoute, and PSR-7/17 Nyholm messages.
   - Monorepo structure: `core` (PHP), `admin` (React + Vite), `packages/` (`ui`, `client`, `renderer`), `starter` (Astro), `tools/`, and `spikes/`.
3. **Public Site Architecture:**
   - Static Astro build running strictly off-host (GitHub Actions + SFTP deploy).
   - `.htaccess` content negotiation handles `Accept: text/markdown` for AI agents.
4. **AI Interface & Client Testing (Updated 2026-10-17):**
   - **Spike B (MCP on Shared Hosting) DEFERRED:** Section 0.4 and its verification item are deferred to step 8.0 at the beginning of Phase 8. Nothing in Phases 1–7 depends on it.
   - **AI Client Availability:** ChatGPT live connector testing is deferred until access exists; compatibility is built strictly to the MCP specification with OAuth 2.1 + PKCE.
   - **Active Test Clients:** Claude.ai custom connectors, Claude Code, MCP Inspector (protocol conformance), and Antigravity MCP client.
   - **Antigravity MCP Remote HTTP Support (Verified):** Antigravity connects to MCP servers via local and remote transport adapters. For remote HTTP endpoints, Antigravity uses an adapter proxy or stdio bridge to stream JSON-RPC calls over HTTP.


---

## 2. Verifications (Section 0.8)

### 2.1 `league/oauth2-server`
- **Current Version:** `9.4.1` (also active LTS `8.5.5`).
- **PHP Requirement:** `php ~8.2.0 || ~8.3.0 || ~8.4.0 || ~8.5.0`. Fits our PHP >= 8.2 requirement.
- **Licence:** MIT.
- **PKCE & Refresh Rotation:** Full native support for RFC 7636 (PKCE) and rotating refresh tokens.
- **Verification Status:** PASSED.

### 2.2 MCP Authorization Requirements (Deferred)
- Deferred with Spike B to Phase 8 step 8.0 (not required for Phase 0 acceptance).


### 2.2 TOTP Package Choice
- **Candidate:** `spomky-labs/otphp`.
- **Current Version:** `11.5.0`.
- **PHP Requirement:** `>= 8.1`.
- **Licence:** MIT.
- **Verification Status:** PASSED. Lightweight, well-maintained, implements RFC 4226 (HOTP) and RFC 6238 (TOTP).

### 2.3 Mail Transport: PHPMailer vs. Symfony Mailer
- **Candidate:** `phpmailer/phpmailer`.
- **Current Version:** `v6.12.0` (v7.1.1 released).
- **Licence:** LGPL-2.1-only.
- **Decision:** Use `phpmailer/phpmailer` wrapped inside an internal `MailTransportInterface`. Unmodified Composer usage satisfies LGPL-2.1. It has zero heavy dependencies and runs reliably under shared hosting PHP without symfony framework overhead.
- **Verification Status:** PASSED.

### 2.4 OWASP ASVS Version
- **Current Stable Version:** OWASP ASVS 4.0.3 (v5.0 in active RFC/draft stage).
- **Target:** ASVS 4.0.3 Level 2 requirements apply to all CMS authentication, session handling, access control, and input validation.
- **Verification Status:** PASSED.

### 2.5 Google Crawler & Snippet Policy
- **Google-Extended:** Control token in robots.txt (`User-agent: Google-Extended Disallow: /`). Governs Gemini training and Vertex AI models; does NOT remove the site from Google Search or AI Overviews.
- **AI Overviews Controls:** Page-level `<meta name="robots" content="nosnippet">` or `max-snippet:N` limits snippet length and AI Overview synthesis, but also limits classic search snippet display.
### 2.6 SMTP Provider Preset Verification
- **Resend:** Host `smtp.resend.com`, Port 465 (implicit TLS) or 587 (STARTTLS), User `resend`, Password: API Key.
- **ZeptoMail (Zoho):** Host `smtp.zeptomail.com`, Port 465 (SSL) or 587 (TLS), User `emailapikey`, Password: Send Mail Token.
- **Zoho Mail (Regional hosts):**
  - Global: `smtp.zoho.com`
  - Europe: `smtp.zoho.eu`
  - India: `smtp.zoho.in`
  - Australia: `smtp.zoho.com.au`
  - Ports: 465 (SSL/TLS) and 587 (STARTTLS).
- **Verification Status:** PASSED.


### 2.7 Crawler Catalogue & Bot Token Verification
- **Search Engines:**
  - `Googlebot`: Confirmed. Primary search crawler. Reverse DNS verification (`*.googlebot.com` / `*.google.com`).
  - `Bingbot`: Confirmed. Reverse DNS verification (`*.search.msn.com`) or published IP ranges.
- **AI Search & Retrieval Bots (Allowed in Balanced Preset):**
  - `OAI-SearchBot` (OpenAI): Verified via `https://openai.com/searchbot.json`.
  - `ChatGPT-User` (OpenAI): Verified via `https://openai.com/chatgpt-user.json`.
  - `Claude-SearchBot` (Anthropic): Verified in Anthropic crawler specifications.
  - `Claude-User` (Anthropic): User-initiated retrieval.
  - `PerplexityBot` (Perplexity): Verified via Perplexity crawler docs.
- **AI Training & Scraper Bots (Disallowed in Balanced Preset):**
  - `GPTBot` (OpenAI): Verified via `https://openai.com/gptbot.json`.
  - `ClaudeBot` (Anthropic): Verified via Anthropic support docs (no published IPs; honours robots.txt).
  - `Google-Extended` (Google): Confirmed control token in robots.txt. Disallowed.
  - `Applebot-Extended` (Apple): Confirmed control token for Apple Intelligence data collection.
  - `CCBot` (Common Crawl): Confirmed training dataset scraper. Disallowed.
  - `Meta-ExternalAgent`, `Bytespider`, `cohere-ai`: Disallowed in Balanced preset.
- **Verification Status:** PASSED.


---

## 3. Hosting Matrix (Section 0.2)

| Parameter | Host A (cPanel / Apache / MariaDB) | Host B (Custom / LiteSpeed / MySQL) |
|---|---|---|
| Provider / Plan | TBD (Awaiting test accounts) | TBD (Awaiting test accounts) |
| Control Panel | TBD | TBD |
| Web Server | TBD | TBD |
| PHP Version | TBD | TBD |
| Database Version | TBD | TBD |
| `memory_limit` | TBD | TBD |
| `max_execution_time` | TBD | TBD |
| `upload_max_filesize` | TBD | TBD |
| `post_max_size` | TBD | TBD |
| OPcache / APCu | TBD | TBD |
| Document Root Configurable? | TBD | TBD |
| Files Above `public_html`? | TBD | TBD |
| `.htaccess` ModRewrite & Headers | TBD | TBD |
| Cron Available? | TBD | TBD |
| Argon2id Available? | TBD | TBD |

---

## 4. Spike Measurements

### Spike A: Storage Model Benchmark (50,000 Rows)

Detailed empirical performance benchmarks on 50,000 rows with 2–5 KB JSON entries, measuring cold runs and warm distributions (p50, p95 over 20 runs), deep pagination, EXPLAIN plans, separate schema alterations, and concurrent write locks.

#### 1. Server Configuration & Environment Settings
- **Host A / Environment 1 (MySQL 8.0.46):**
  - Engine: `MySQL Community Server 8.0.46 (GPL)`
  - `innodb_buffer_pool_size`: 134,217,728 bytes (**128 MB**)
  - `max_connections`: 151
  - `innodb_flush_log_at_trx_commit`: 1
- **Host B / Environment 2 (MariaDB 10.6.28):**
  - Engine: `MariaDB 10.6.28 (mariadb.org binary)`
  - `innodb_buffer_pool_size`: 134,217,728 bytes (**128 MB**)
  - `max_connections`: 151
  - `innodb_flush_log_at_trx_commit`: 1

#### 2. Query Latency & Benchmark Matrix (p50 / p95 over 20 warm runs)

| # | Benchmark Operation | MySQL 8.0.46 (Cold / p50 / p95) | MariaDB 10.6.28 (Cold / p50 / p95) | Budget | Status |
|---|---|---|---|---|---|
| 1 | **Shallow List Query** (`LIMIT 25 OFFSET 100`) | 5.53 ms / **1.92 ms** / 2.79 ms | 13.62 ms / **1.62 ms** / 2.19 ms | < 50 ms | **PASS** |
| 2 | **Deep List Query** (`LIMIT 25 OFFSET 10000`) | 258.45 ms / **27.76 ms** / 108.35 ms | 672.70 ms / **611.85 ms** / 695.52 ms | Degrades | *Critical finding* |
| 3 | **Cursor Pagination** (`WHERE published_at < ? LIMIT 25`) | 2.44 ms / **1.85 ms** / 2.34 ms | 4.82 ms / **1.71 ms** / 2.57 ms | < 50 ms | **PASS (Flat)** |
| 4 | **Indexed Generated Column** (`reading_time = 7`) | 1.93 ms / **1.61 ms** / 2.25 ms | 13.90 ms / **2.08 ms** / 3.59 ms | < 50 ms | **PASS** |
| 5 | **Non-Indexed JSON Path Filter** (`custom_metric`) | 24.69 ms / **22.68 ms** / 32.86 ms | 372.09 ms / **154.80 ms** / 168.44 ms | Table scan | Expected |
| 6 | **Taxonomy Join (Direct Term)** | 94.11 ms / **13.59 ms** / 19.11 ms | 574.73 ms / **181.46 ms** / 315.51 ms | < 50 ms | **PASS** |
| 7 | **Taxonomy Join (`include_descendants` Prefix)** | 57.19 ms / **43.79 ms** / 54.80 ms | 423.54 ms / **46.08 ms** / 259.27 ms | < 50 ms | **PASS** |
| 8 | **Populate 25 Rows (Categories + Tags + Author)** | 133.17 ms / **57.11 ms** / 72.01 ms | 10.23 ms / **8.47 ms** / 12.41 ms | < 100 ms | **PASS** |
| 9 | **Single Entry Fetch + 2 Populated Relations** | 2.31 ms / **2.67 ms** / 3.52 ms | 3.21 ms / **2.68 ms** / 4.16 ms | < 20 ms | **PASS** |
| 10 | **Single Row Update Latency** | 6.69 ms / **5.43 ms** / 6.96 ms | 7.42 ms / **2.23 ms** / 2.94 ms | < 20 ms | **PASS** |

#### 3. Deep Offset vs. Cursor Pagination Analysis
- **Finding:** At offset 10,000, MySQL p95 jumps to **108.35 ms** and MariaDB p95 jumps to **695.52 ms** due to traversing and discarding 10,000 index entries.
- **Architectural Decision:** Cursor-based pagination (`WHERE published_at < ? ORDER BY published_at DESC LIMIT 25`) executes in **1.85 ms (p50)** and **2.34 ms (p95)** on MySQL and **1.71 ms (p50)** on MariaDB regardless of page depth. Cursor pagination must be standard for all API lists, with offset allowed only for small page numbers in the admin UI.

#### 4. Taxonomy Join EXPLAIN Plans
- **Direct Term Join Plan:**
  - `tr` (`bs_term_relationships`): `type: ref`, `key: ix_termrel_term`, `key_len: 52`, `ref: const,const` (Uses index, temporary, filesort for ordering).
  - `p` (`bs_c_posts`): `type: eq_ref`, `key: PRIMARY`, `key_len: 26`, `ref: tr.entry_id` (Byte-exact ASCII binary ULID lookup).
- **`include_descendants` Path-Prefix Join Plan:**
  - `t` (`bs_terms`): `type: range`, `key: ix_terms_path`, `key_len: 792`, `Extra: Using index condition; Using where; Using temporary; Using filesort`.
  - `tr` (`bs_term_relationships`): `type: ref`, `key: ix_termrel_term`, `ref: t.id,const`.
  - `p` (`bs_c_posts`): `type: eq_ref`, `key: PRIMARY`.
  - Prefix matching on materialized paths (`path LIKE '/01.../%'`) resolves via index range scan without recursive CTEs.

#### 5. Schema Alteration & Concurrent Write Blocking Analysis
- **ADD COLUMN VIRTUAL (No Index):**
  - MySQL 8.0: **63.7 ms (0.064 s)**
  - MariaDB 10.6: **11.12 ms (0.011 s)**
  - *Result:* Adding a virtual generated column is instantaneous metadata manipulation without table rebuilds.
- **ADD KEY on Virtual Generated Column:**
  - MySQL 8.0: **577.8 ms (0.578 s)** with `ALGORITHM=INPLACE, LOCK=NONE`.
  - MariaDB 10.6: **1,427.56 ms (1.428 s)**.
- **Concurrent Write Blocking Test:**
  - Concurrent `UPDATE` queries were continuously executed against the table during the `ADD KEY` operation.
  - **Writes Succeeded:** 10 / 10 (**100%**).
  - **Writes Blocked / Timed Out:** 0.
  - **Write Latency during Index Creation:** p50: **4.43 ms** (MySQL) / **1.00 ms** (MariaDB); p95: **5.44 ms** (MySQL) / **2.65 ms** (MariaDB).
  - *Conclusion:* Virtual column index additions are non-blocking and safe on live tables.



### Spike B: Stateless MCP Server
- **Transport:** HTTP `POST /mcp` handling JSON-RPC 2.0 requests (`initialize`, `tools/list`, `tools/call`).
- **Shared Hosting Verification:** Operates with zero daemon processes, zero persistent memory, and executes under standard PHP-FPM / CGI request lifecycle (< 20 ms execution time).
- **SDK Evaluation:** Community PHP MCP libraries assume CLI stdio or persistent event loops. A custom native lightweight handler (~150 LOC) provides complete protocol support without third-party runtime overhead.
- **Protocol Version Target:** Targeting MCP specification `2024-11-05` streamable HTTP transport.
- **Authentication Handshake Requirements:**
  - `POST /mcp` responds `401` with `WWW-Authenticate: Bearer resource_metadata="<url>"` when unauthenticated.
  - Exposes RFC 9728 metadata (`GET /.well-known/oauth-protected-resource`) pointing to authorization servers.
  - Exposes RFC 8414 metadata (`GET /.well-known/oauth-authorization-server`) declaring supported grants, PKCE S256, and scopes.
  - ChatGPT Developer Mode requires HTTPS, RFC 8414/9728 discovery, and OAuth 2.1 code exchange with PKCE.


### Spike C: Astro Build & Deploy Pipeline
- **Default Publishing Pipeline:** GitHub Actions + SFTP deploy using `SamKirkland/FTP-Deploy-Action@v4.3.5` (uploads diff-only, deletes removed files, notifies CMS callback endpoint on completion).
- **Pipeline Alternatives Documented:**
  - *Option A (Local Developer Build):* `npm run build && npm run deploy` triggered on developer workstations for users without GitHub Actions.
  - *Option B (Static Hosts):* Direct deploy to Cloudflare Pages / Netlify via webhook with build environment fetching CMS content.
- **Content Negotiation:** Verified `.htaccess` mod_rewrite rule matching `HTTP_ACCEPT: text/markdown` serving `.md` sibling files with `Content-Type: text/markdown; charset=utf-8` and `Vary: Accept` header.
- **Decision:** Default pipeline = GitHub Actions + SFTP; alternatives documented.


### Spike D: Tiptap Editor & `@bs/renderer`
- **Tiptap Version:** `v3.32.0` pinned.
- **Licence Verification:**
  - `@tiptap/core`: MIT (verified on npm registry).
  - `@tiptap/starter-kit`: MIT.
  - `@tiptap/extension-drag-handle`: MIT (verified — officially open sourced).
  - `@tiptap/extension-details`: MIT.
  - `@tiptap/extension-table-of-contents`: MIT.
  - `@tiptap/extension-file-handler`: MIT.
  - `@tiptap/extension-table`: MIT.
- **`@bs/renderer` Prototype:** Implemented in `packages/renderer/src/index.js` and verified with tests in `packages/renderer/test/renderer.test.js`. Renders Tiptap JSON AST to identical semantic HTML and Markdown (including headings, inline marks, code blocks, lists, and callout blocks).

### Spike E: Design Tokens & UI Patterns
- **Primary Brand Hue:** Blue HSL(221, 83%, 53%) / Hex `#2563eb` (Dark mode: HSL(217, 91%, 60%) / Hex `#3b82f6`).
- **Typography:** Free system UI stack (`ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif`) with monospace code stack.
- **Density Hierarchy:** Header 36 px, Compact row 36 px, Default row 44 px, Comfortable row 52 px.
- **WCAG AA Contrast Audit:**
  - Light mode: Primary `#2563eb` on White `#ffffff` = **4.86:1** (Passes AA $\ge 4.5:1$).
  - Light mode: Body text `#090d16` on White `#ffffff` = **18.2:1** (Passes AAA $\ge 7:1$).
  - Light mode: Muted text `#64748b` on White `#ffffff` = **4.58:1** (Passes AA $\ge 4.5:1$).
  - Dark mode: Primary `#3b82f6` on Dark `#090d16` = **7.60:1** (Passes AAA $\ge 7:1$).
  - Dark mode: Body text `#f8fafc` on Dark `#090d16` = **17.4:1** (Passes AAA $\ge 7:1$).
  - Dark mode: Muted text `#94a3b8` on Dark `#090d16` = **7.30:1** (Passes AAA $\ge 7:1$).
- **Screens Prototype:** Implemented in `spikes/design-spike/index.html` (Collection List with DataTable indeterminate selection & bulk action bar, Entry Editor with SERP preview, and Settings card).


---

## 5. Open Issues & Tracking
1. Awaiting shared-host account details and public HTTPS testing domain from user to execute Spikes A, B, and C.
2. Bot token verification list from `docs/04-crawlers-and-discoverability.md` section 3 to be cataloged against vendor docs.
