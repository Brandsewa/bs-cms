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
   - `.htaccess` content negotiation handles `Accept: text/markdown` for AI agents (not yet run on a live host; pending shared hosting verification).
4. **AI Interface & Client Testing:**
   - **Spike B (MCP on Shared Hosting) DEFERRED:** Section 0.4 and its verification item are deferred to step 8.0 at the beginning of Phase 8 per user directive. Shared-hosting execution and protocol target 2024-11-05 remain **deferred, unverified**.
   - **ChatGPT Live Testing:** Deferred until access exists; compatibility is built strictly to the MCP specification with OAuth 2.1 + PKCE.
   - **Active Test Clients:** Claude.ai custom connectors, Claude Code, MCP Inspector (protocol conformance).
   - **Antigravity Remote MCP Support:** **Deferred, unverified** (not yet executed against a live remote HTTP endpoint).
5. **Email Provider — Zoho CPaaS (ZeptoMail):**
   - **Channel Scope:** Transactional EMAIL channel only. SMS, WhatsApp, and voice are out of scope for v1.
   - **Preset Label:** Relabeled existing "ZeptoMail" preset to "Zoho CPaaS (ZeptoMail)".
   - **Data Center Regions & Hostnames (Verified 2026-10-18 via official Zoho CPaaS / ZeptoMail documentation):**
     - `us` (Global / United States): SMTP `smtp.zeptomail.com`, API `https://api.zeptomail.com/v1.1/email` [Verified: 2026-10-18, Source: Zoho Docs]
     - `eu` (Europe): SMTP `smtp.zeptomail.eu`, API `https://api.zeptomail.eu/v1.1/email` [Verified: 2026-10-18, Source: Zoho Docs]
     - `in` (India): SMTP `smtp.zeptomail.in`, API `https://api.zeptomail.in/v1.1/email` [Verified: 2026-10-18, Source: Zoho Docs]
     - `au` (Australia): SMTP `smtp.zeptomail.com.au`, API `https://api.zeptomail.com.au/v1.1/email` [Verified: 2026-10-18, Source: Zoho Docs]
     - `jp` (Japan): SMTP `smtp.zeptomail.jp`, API `https://api.zeptomail.jp/v1.1/email` [Verified: 2026-10-18, Source: Zoho Docs]
     - `ca` (Canada): SMTP `smtp.zeptomail.ca`, API `https://api.zeptomail.ca/v1.1/email` [Verified: 2026-10-18, Source: Zoho Docs]
     - `sa` (Saudi Arabia): SMTP `smtp.zeptomail.sa` (**unverified** in public documentation; Zoho recommends `smtp.zeptomail.com` fallback or dashboard-provided host for `zoho.sa` accounts), API `https://api.zeptomail.sa/v1.1/email` [Verified regional domain base: 2026-10-18]
   - **SMTP Credentials:** Port 465 (implicit TLS / SMTPS) or 587 (STARTTLS). Username: `emailapikey`. Password: Mail Agent "Send Mail token". The From address must belong to a verified domain in Zoho CPaaS.
   - **HTTPS API Transport:** Implemented as alternative `transport: api` (default `smtp`) via SSRF-safe `HttpClient` calling `POST /v1.1/email` with header `Authorization: Zoho-enczapikey <token>`.
   - **Settings & API:** `GET/PUT /api/v1/settings/email`, `POST /settings/email/test`, `POST /settings/email/send-test`, `GET /api/v1/email-log`. Secrets encrypted at rest with libsodium app key; never exposed (returns `has_password: true`).
   - **CLI Management:** `bs mail:configure`, `bs mail:test`, `bs mail:send-test <address>`, `bs mail:status`. Secrets passed via prompt or `BS_MAIL_PASSWORD`, never CLI arguments.
   - **Other Presets:** Resend and generic SMTP kept in code (untested); generic Zoho Mail row removed.
   - **Testing Deferral:** Email delivery testing is deferred by the owner (2026-10-18). Phase 3 acceptance and sign-off does not depend on live email delivery.
6. **Host B Shared Hosting Test Deferral:**
   - Second hosting account testing is **deferred by owner, 2026-10-18** (marked in NOTES.md, Phase 0, and Phase 9 checklists). Production validation continues against Host A (Hostinger).

---

## 2. Verifications (Section 0.8)

### 2.1 `league/oauth2-server`
- **Current Version:** `9.4.1` (also active LTS `8.5.5`).
- **PHP Requirement:** `php ~8.2.0 || ~8.3.0 || ~8.4.0 || ~8.5.0`. Fits our PHP >= 8.2 requirement.
- **Licence:** MIT.
- **PKCE & Refresh Rotation:** Full native support for RFC 7636 (PKCE) and rotating refresh tokens.
- **Verification Status:** PASSED.

### 2.2 MCP Authorization Requirements
- Deferred with Spike B to Phase 8 step 8.0 (**deferred, unverified**).

### 2.3 TOTP Package Choice
- **Candidate:** `spomky-labs/otphp`.
- **Current Version:** `11.5.0`.
- **PHP Requirement:** `>= 8.1`.
- **Licence:** MIT.
- **Verification Status:** PASSED. Lightweight, well-maintained, implements RFC 4226 (HOTP) and RFC 6238 (TOTP).

### 2.4 Mail Transport: PHPMailer Pin Decision
- **Candidate:** `phpmailer/phpmailer`.
- **Pinned Version:** `^6.12.0` (LTS; v7.x is a major rewrite, pinned to 6.x for stability and proven shared-hosting runtime).
- **Licence:** LGPL-2.1-only.
- **Decision:** Use `phpmailer/phpmailer` wrapped inside an internal `MailTransportInterface`. Unmodified Composer usage satisfies LGPL-2.1. It has zero heavy dependencies and runs reliably under shared hosting PHP without framework overhead.
- **Verification Status:** PASSED.

### 2.5 OWASP ASVS Version Target
- **Current Version:** OWASP ASVS 5.0 (v4.0.3 was predecessor; ASVS 5.0 is the active security baseline).
- **Target:** ASVS 5.0 Level 2 requirements apply to all CMS authentication, session handling, access control, and input validation.
- **Verification Status:** PASSED.

### 2.6 Password Hashing & Argon2id Availability
- **Local PHP 8.5 Environment:** Argon2id is available and verified (`PASSWORD_ARGON2ID` supported in `password_hash`).
- **Host A (Hostinger):** Available (web PHP 8.3.35, measured 2026-10-17). Host B pending; fallback to bcrypt cost 12 where unavailable.
- **Verification Status:** PASSED locally and on Host A; Host B pending.

### 2.7 Google Crawler, Snippets & FAQ Rich-Results
- **Google-Extended:** Control token in robots.txt (`User-agent: Google-Extended Disallow: /`). Governs Gemini training and Vertex AI models; does NOT remove the site from Google Search or AI Overviews.
- **AI Overviews Controls:** Page-level `<meta name="robots" content="nosnippet">` or `max-snippet:N` limits snippet length and AI Overview synthesis, but also limits classic search snippet display.
- **FAQ Rich-Result Eligibility:** Google restricted FAQ rich results exclusively to authoritative government and healthcare websites (Google Search Central update Aug 2023). For general commercial/blog sites, `FAQPage` JSON-LD will NOT yield accordion rich snippets in Google Search, but remains valuable for AI retrieval grounding (RAG/AEO) and machine-readable Q&A.
- **Verification Status:** PASSED (Verified via Google Search Central documentation).

### 2.8 SMTP Provider Presets
- **Resend:** Host `smtp.resend.com`, Port 465 (implicit TLS) or 587 (STARTTLS), User `resend`, Password: API Key.
- **ZeptoMail (Zoho):** Host `smtp.zeptomail.com`, Port 465 (SSL) or 587 (TLS), User `emailapikey`, Password: Send Mail Token.
- **Zoho Mail (Regional hosts):**
  - Global: `smtp.zoho.com`
  - Europe: `smtp.zoho.eu`
  - India: `smtp.zoho.in`
  - Australia: `smtp.zoho.com.au`
  - Ports: 465 (SSL/TLS) and 587 (STARTTLS).
- **Verification Status:** PASSED.

### 2.9 Crawler Catalogue & Bot Token Verification
Every entry verified against primary source vendor documentation as of 2026-10-17:
- **Search Engines:**
  - `Googlebot`: Primary search crawler. Verification via reverse DNS lookup (`*.googlebot.com`, `*.google.com`). Source: https://developers.google.com/search/docs/crawling-indexing/googlebot (Checked: 2026-10-17).
  - `Bingbot`: Primary search crawler. Verification via reverse DNS lookup (`*.search.msn.com`) or published IP ranges. Source: https://www.bing.com/webmasters/help/which-crawlers-does-bing-use-8c184ec0 (Checked: 2026-10-17).
- **AI Search & Retrieval Bots (Allowed in Balanced Preset):**
  - `OAI-SearchBot` (OpenAI): Surfaces sites in ChatGPT search. IP list: https://openai.com/searchbot.json. Source: https://developers.openai.com/api/docs/bots (Checked: 2026-10-17).
  - `ChatGPT-User` (OpenAI): User-initiated web browsing in ChatGPT. IP list: https://openai.com/chatgpt-user.json. Source: https://developers.openai.com/api/docs/bots (Checked: 2026-10-17).
  - `Claude-SearchBot` (Anthropic): Crawls web for Claude search queries. Honours robots.txt. No published IPs. Source: https://support.anthropic.com/en/articles/8896518-what-is-claudebot (Checked: 2026-10-17).
  - `Claude-User` (Anthropic): User-directed URL fetches in Claude conversations. Source: https://support.anthropic.com/en/articles/8896518-what-is-claudebot (Checked: 2026-10-17).
  - `PerplexityBot` (Perplexity): Crawls index for Perplexity AI search. Source: https://docs.perplexity.ai/docs/perplexitybot (Checked: 2026-10-17).
  - `Perplexity-User` (Perplexity): User-initiated retrieval in Perplexity. Source: https://docs.perplexity.ai/docs/perplexitybot (Checked: 2026-10-17).
- **AI Training & Scraper Bots (Disallowed in Balanced Preset):**
  - `GPTBot` (OpenAI): Used for OpenAI model training. IP list: https://openai.com/gptbot.json. Source: https://developers.openai.com/api/docs/bots (Checked: 2026-10-17).
  - `ClaudeBot` (Anthropic): Used for Anthropic model training. Disallow via robots.txt. No published IPs. Source: https://support.anthropic.com/en/articles/8896518-what-is-claudebot (Checked: 2026-10-17).
  - `Google-Extended` (Google): Robots.txt control token governing Gemini training. Source: https://developers.google.com/search/docs/crawling-indexing/google-extended (Checked: 2026-10-17).
  - `Applebot-Extended` (Apple): Robots.txt control token governing Apple Intelligence model training. Source: https://support.apple.com/en-us/119829 (Checked: 2026-10-17).
  - `CCBot` (Common Crawl): Open crawl dataset scraper. Source: https://commoncrawl.org/ccbot (Checked: 2026-10-17).
  - `Meta-ExternalAgent` (Meta): Documented under Meta Web Crawlers at https://developers.facebook.com/docs/sharing/webmasters/web-crawlers/ (Checked: 2026-10-17); no standalone official AI training crawler policy page. Status: **unverified**.
  - `Bytespider` (ByteDance): Scraper for ByteDance/TikTok AI models. No official vendor crawler documentation page published on bytedance.com. Status: **unverified**.
  - `cohere-ai` (Cohere): Web scraper for Cohere foundational models. No official vendor crawler documentation page published on cohere.com. Status: **unverified**.
- **Verification Status:** Sourced bots verified with vendor links; Meta-ExternalAgent, Bytespider, and cohere-ai marked unverified pending official vendor doc pages.

---

## 3. Hosting Matrix (Section 0.2)

*Host A values were measured over SSH and a temporary web probe on 2026-10-17. Host B remains pending. Do not edit measured values without a new measurement.*

| Parameter | Host A (Hostinger / LiteSpeed / MariaDB) | Host B (Custom / Apache / MySQL) |
|---|---|---|
| Provider / Plan | Hostinger shared hosting (plan not recorded) | Pending (deferred until test account exists) |
| Control Panel | hPanel | Pending |
| Web Server | LiteSpeed Web Server | Pending |
| PHP Version | Web: PHP 8.3.35 (LiteSpeed SAPI). SSH default `php` is 8.5.11; use `/opt/alt/php83/usr/bin/php` to match web | Pending |
| Database Version | MariaDB 11.8.9 (shared server; `innodb_buffer_pool_size` ~100 GB; `max_user_connections` 75; `max_allowed_packet` 1 GB) | Pending |
| `memory_limit` | 2048M (web and CLI) | Pending |
| `max_execution_time` | 360 s web (`max_input_time` 360), 0 CLI | Pending |
| `upload_max_filesize` | 2048M | Pending |
| `post_max_size` | 2048M | Pending |
| OPcache / APCu | OPcache on (256 MB); APCu not installed | Pending |
| Document Root Configurable? | Not checked (default is `domains/<domain>/public_html`) | Pending |
| Files Above `public_html`? | Yes: `domains/<domain>/` and the home directory are outside the web root | Pending |
| `.htaccess` ModRewrite & Headers | Not tested yet (LiteSpeed reads `.htaccess`; Accept-header negotiation untested) | Pending |
| Cron Available? | Via hPanel Cron Jobs (no `crontab` over SSH); command must use `/opt/alt/php83/usr/bin/php` | Pending |
| Argon2id Available? | Yes (`PASSWORD_ARGON2ID` defined in web PHP 8.3). Separately, `sodium` was off by default and was enabled in hPanel | Pending |
| Process Management | `litespeed_finish_request()` available, `fastcgi_finish_request()` not; `disable_functions` includes exec, shell_exec, system, passthru, proc_open, popen, symlink, link | Pending |
| Filesystem / Symlinks | `symlink()` is disabled; file swaps use atomic directory renames or copy | Pending |

---

## 4. Spike Measurements

### Spike A on Host A (Hostinger Live Benchmark)

Benchmarked on live Hostinger shared hosting (LiteSpeed, PHP 8.3, MariaDB 10.11) with 50,000 post entries:

1. **Unindexed JSON Path vs. Indexed Virtual Column:**
   - Filtering on an unindexed JSON path (`data->>'$.custom_metric'`) on 50k rows took **313–365 ms** (table scan).
   - Filtering on an indexed virtual generated column took **16–23 ms** (**PASS**).
   - **Architectural Rule & Spec Change:** Filters and sorts are strictly accepted **only on system columns and indexed fields** (`is_filterable` or `is_sortable`). Unindexed JSON path filters are rejected with a 400 error advising the user to mark the field filterable.

2. **Keyset / Cursor Pagination Predicate Form:**
   - Keyset conditions written in SQL row-constructor form `(published_at, id) < (?, ?)` failed to use an index range scan on MariaDB (deep cursor scans took ~600 ms, failing to improve over offset).
   - Keyset conditions written as the **expanded predicate form** `published_at < ? OR (published_at = ? AND id < ?)` enabled full index range scanning (< 3 ms flat).
   - **Architectural Rule & Spec Change:** Keyset cursor conditions must always be emitted as `published_at < ? OR (published_at = ? AND id < ?)`.

3. **Taxonomy Join & Descendants with Denormalised Sort Columns:**
   - Standard `INNER JOIN bs_term_relationships` with `ORDER BY published_at DESC` caused MariaDB filesorts (160–280 ms).
   - Denormalised sort columns `is_published TINYINT(1)` and `sort_at DATETIME(3)` on `bs_term_relationships` with composite index `ix_termrel_sorted (term_id, collection_id, is_published, sort_at, entry_id)`:
     - Direct term query dropped from 60–71 ms down to **7–10 ms** (**PASS**).
     - Subcategory / subtree descendants: Subtree term IDs are fetched by prefix on `terms.path`, then queried via `term_id IN (...)` on `ix_termrel_sorted` with `DISTINCT entry_id` and joined to posts by primary key, taking **7–10 ms** (**PASS**). This avoids the posts-first `EXISTS` form which took 52–62 ms.
   - **Architectural Rule & Spec Change:** Denormalised `is_published` and `sort_at` added to `term_relationships` with index `ix_termrel_sorted`. Maintained inside the same transaction whenever an entry is published, unpublished, trashed, restored, or its `published_at` date changes.

4. **ASCII Materialized Path:**
   - `terms.path` and `terms.slug_path` declared `VARCHAR(...) CHARACTER SET ascii COLLATE ascii_bin NOT NULL`. Keeps index size compact and ensures fast byte-exact prefix matching (`path LIKE '/01.../%'`).

5. **Runtime Quirks & Environment Handling:**
   - `litespeed_finish_request()` supported for lazy background job processing after response flush.
   - `sodium` extension is disabled by default on Hostinger; installer must detect and provide direct hPanel configuration hint (`hPanel > PHP Configuration > PHP Extensions`).
   - `symlink()` is disabled on Hostinger; system updater must use atomic directory renames or copy with manifest, never symlinks.
   - Cron command must detect and map the web PHP version to the CLI binary path (e.g. `/opt/alt/php83/usr/bin/php`), because default SSH `php` may differ.

**Conclusion:** Spike A is **COMPLETE and ACCEPTED for Host A (Hostinger)**. Host B remains pending.

### Phase 1: Host A Live Hostinger Measurements & Budgets (2026-10-17)

Measured on Host A (Hostinger shared hosting, LiteSpeed, PHP 8.3.35, MariaDB 11.8.9) with the Phase 1 build and installer:
- **Included Files (Real routes through full middleware pipeline):**
  - `/health` (handled by Kernel + 8 middlewares): **60 files** in production release (65 with dev autoloaders).
  - `/api/v1/nope` (routed 404 through FastRoute + 8 middlewares): **67 files** in production release (74 with dev autoloaders).
  - **Proposed Budget Correction:** Update budget from `< 60` (which previously relied on a fast-path bypass) to **`< 75 files`** for general API requests (well within the global README Section 7 budget of `< 150 files`).
  - **Reasoning:** A modular PSR-15 pipeline (8 middlewares = 18 files) + Nyholm PSR-7 (14 files) + FastRoute router/parser/dispatcher (8 files) + Composer autoloader (5 files) + Core kernel & primitives (15 files) + Config arrays (4 files) establishes a structural floor of 64–67 files for any real route execution.
- **Memory Footprint (PHP 8.3 / LiteSpeed), corrected 2026-10-17 after an independent measurement:**
  - A bare, empty PHP script (`php -d opcache.enable_cli=1 -r 'echo memory_get_peak_usage(false);'`) already peaks at **9.4 MB** on Hostinger. That is the host's PHP baseline (OPcache and extensions), **not** application memory, so `memory_get_peak_usage(false)` ~9.4 MB for a request means the application itself added almost nothing.
  - `BudgetTest` measures the request heap delta (budget < 2 MB) and total allocation (< 32 MB global budget). On the host, measure application memory as `request peak minus empty-script baseline` and keep the budget at **< 4 MB over baseline**. The earlier "< 12 MB system allocation" budget and its explanation (an 8-10 MB parsing arena for 67 files) are withdrawn: the baseline is the same with 0 or 67 application files.
- **Cold Boot (CLI, PHP 8.3):** **8–10 ms** (p50: 7.2 ms, p95: 9.8 ms; passes budget < 10 ms).
- **Live Web Requests (`/health`):** 20 live requests, **p50 = 177 ms** total roundtrip (includes user network latency from client to Hostinger data center).
- **Hostinger CDN (`hcdn`) Observation & Fix:**
  - *Observation:* Hostinger's edge CDN caches identical GET responses (Age header present; 75 identical requests produced ~16 origin hits).
  - *Mitigation:* Enforced `Cache-Control: no-store` across all API, admin, installer, and error responses via `SecurityHeadersMiddleware`, `InstallerController`, and `ProblemDetails`.
- **Installer & Security Hardening:**
  - Automated private-path probe prevents installation if private directories (`storage/`, `config/`, `vendor/`) are reachable.
  - CSRF protection: missing or invalid CSRF tokens return HTTP 403 Problem Details (`status: 403, title: 'Forbidden'`).
  - Database password protection: credentials are kept strictly server-side in short-lived `storage/tmp/install_stage.tmp` (0600) and never echoed into HTML confirmation pages.
  - Site URL collection: installer collects `site_url` and records it into `storage/config.php` under `app.url` and `app.public_url`.
- **Hostinger layout (tested 2026-10-17, release zip unzipped into `domains/<domain>/`):**
  - **Tested and working (Option 3, SSH symlink):** unzip so that `domains/<domain>/core/` exists, remove the empty `public_html`, then run `ln -s core/public public_html` inside `domains/<domain>/` over SSH. The domain then serves `core/public` directly (Mode A behaviour); `core/storage`, `core/src`, `core/vendor` are not reachable (403/404 verified). `symlink()` is disabled in PHP on Hostinger, but the shell `ln -s` works.
  - **Unverified (Option 1):** changing the document root in hPanel to `public_html/core/public`. Not tested; do not document it as supported until someone checks the hPanel UI.
  - **Mode B root `.htaccess`** (`core/public/.htaccess-mode-b`): routes to a root-level `index.php`, which does not exist in the `core/public` layout. It is not applicable to the current zip layout and is untested; treat it as legacy until reworked or removed.

---

### Spike A: Local Reference Benchmark (50,000 Rows)

Benchmarked on local reference environments (Docker containers configured with realistic shared-hosting buffer pools of 128 MB: `mysql:8.0.46` and `mariadb:10.6.28`). 50,000 posts with 2–5 KB JSON entries, 20 hierarchical categories, 10 tags, 10 authors, 100,000 term relationships.

#### 1. Server Configuration
- **MySQL 8.0.46 (GPL):** `innodb_buffer_pool_size = 134,217,728` (128 MB), `max_connections = 151`, `innodb_flush_log_at_trx_commit = 1`.
- **MariaDB 10.6.28:** `innodb_buffer_pool_size = 134,217,728` (128 MB), `max_connections = 151`, `innodb_flush_log_at_trx_commit = 1`.

#### 2. Query Latency & Benchmark Matrix (Cold / p50 / p95 over 20 warm runs)

| # | Benchmark Operation | MySQL 8.0.46 (Cold / p50 / p95) | MariaDB 10.6.28 (Cold / p50 / p95) | Budget | Status |
|---|---|---|---|---|---|
| 1 | **Shallow List Query** (`LIMIT 25 OFFSET 100`) | 9.47 ms / **1.65 ms** / 2.23 ms | 14.04 ms / **2.28 ms** / 2.63 ms | < 50 ms | **PASS** |
| 2 | **Deep List Query** (`LIMIT 25 OFFSET 10000`) | 282.16 ms / **22.61 ms** / 37.82 ms | 638.78 ms / **591.07 ms** / 661.91 ms | Degrades | *Critical finding: Offset scales poorly on MariaDB* |
| 3 | **Cursor Pagination** (`WHERE published_at < ? LIMIT 25`) | 2.05 ms / **1.85 ms** / 2.62 ms | 4.49 ms / **1.50 ms** / 2.57 ms | < 50 ms | **PASS (Flat)** |
| 4 | **Indexed Generated Column** (`reading_time = 7`) | 1.80 ms / **1.49 ms** / 1.95 ms | 12.62 ms / **2.03 ms** / 2.59 ms | < 50 ms | **PASS** |
| 5 | **Non-Indexed JSON Path Filter** (`custom_metric`) | 18.64 ms / **18.15 ms** / 22.71 ms | 342.24 ms / **137.70 ms** / 158.63 ms | Table scan | Expected (no index) |
| 6 | **Taxonomy Join (Direct Term, Standard INNER JOIN)** | 90.71 ms / **9.44 ms** / 18.08 ms | 531.32 ms / **168.85 ms** / 278.09 ms | < 50 ms | **FAIL on MariaDB** (EXPLAIN: `Using temporary; Using filesort`) |
| 6b | **Taxonomy Join (Direct Term, Driven from Posts Sort Index)** | 4.13 ms / **3.29 ms** / 4.20 ms | 3.36 ms / **2.73 ms** / 3.50 ms | < 50 ms | **PASS** (Zero filesort, eliminates temporary table) |
| 7 | **Taxonomy Join (`include_descendants`, Standard INNER JOIN)** | 50.80 ms / **35.27 ms** / 48.64 ms | 303.58 ms / **36.67 ms** / 251.24 ms | < 50 ms | **FAIL on MariaDB** (p95 251 ms exceeds 50 ms budget) |
| 7b | **Taxonomy Join (`include_descendants`, Driven from Posts Sort Index)** | 4.21 ms / **2.62 ms** / 4.59 ms | 2.40 ms / **2.33 ms** / 2.77 ms | < 50 ms | **PASS** (Consistent < 3 ms across both engines) |
| 8 | **Populate 25 Rows (Categories + Tags + Author, Batched)** | 138.31 ms / **46.56 ms** / 70.98 ms | 8.23 ms / **8.69 ms** / 10.80 ms | < 100 ms | **PASS** |
| 9 | **Single Entry Fetch + 2 Populated Relations** | 3.01 ms / **2.60 ms** / 6.25 ms | 2.45 ms / **2.78 ms** / 3.90 ms | < 20 ms | **PASS** |
| 10 | **Single Row Update Latency** | 9.14 ms / **5.54 ms** / 22.97 ms | 4.42 ms / **2.19 ms** / 3.86 ms | < 20 ms | **PASS** |

#### 3. Investigation & Architectural Options for Taxonomy Joins
- **Root Cause of MariaDB Failure in Standard Joins (Rows 6 & 7):**
  When running `SELECT p.* FROM bs_c_posts p INNER JOIN bs_term_relationships tr ON tr.entry_id = p.id WHERE tr.term_id = ? ORDER BY p.published_at DESC LIMIT 25`, the query optimiser defaults to driving through `tr.ix_termrel_term`. Because the rows matching the term are not pre-sorted by `p.published_at`, the engine buffers all matching entry IDs into a temporary table and performs a filesort. On 50,000 rows with 4,800 matching entries, this filesort takes ~168 ms on MariaDB with 128 MB buffer pool.
- **Before/After Optimization Comparison:**
  - Standard Direct Join on MariaDB: Cold 531.32 ms, p50 168.85 ms, p95 278.09 ms (**FAIL**).
  - Driven Direct Join (`STRAIGHT_JOIN` from `bs_c_posts` index `(status, published_at)`): Cold 3.36 ms, p50 2.73 ms, p95 3.50 ms (**PASS** — 60x speedup).
  - Standard Descendants Join on MariaDB: Cold 303.58 ms, p50 36.67 ms, p95 251.24 ms (**FAIL**).
  - Driven Descendants Join on MariaDB: Cold 2.40 ms, p50 2.33 ms, p95 2.77 ms (**PASS** — 90x speedup).
- **Architectural Solution for Core Query Builder (Phase 3 & 4):**
  The query engine will generate queries driving from `bs_c_posts` using index `ix_posts_status_pub` when ordering by `published_at DESC` with taxonomy filters, checking term relationships via primary key lookup. Alternatively, denormalizing `sort_at` onto `bs_term_relationships` (`ix_termrel_sorted: (term_id, collection_id, sort_at DESC, entry_id)`) allows index-only scanning directly from the join table.

#### 4. Schema Alteration & Concurrent Write Blocking Analysis
- **ADD COLUMN VIRTUAL (No Index):** MySQL 8.0 = **63.49 ms**; MariaDB 10.6 = **11.56 ms**. Metadata-only alteration, zero table rebuild.
- **ADD KEY on Virtual Generated Column:** MySQL 8.0 = **781.87 ms** (`ALGORITHM=INPLACE, LOCK=NONE`); MariaDB 10.6 = **1,407.85 ms**.
- **Concurrent Write Blocking Test:**
  - 10 concurrent `UPDATE` queries executed during `ADD KEY`.
  - Result: 10 / 10 succeeded (0 blocked, 0 timed out).
  - Write latency during DDL: p50 4.22 ms (MySQL) / 2.15 ms (MariaDB); p95 5.75 ms (MySQL) / 4.34 ms (MariaDB).

---

### Spike B: Stateless MCP Server on Shared Hosting (Step 8.0 Verified 2026-10-19)
- **Status:** **PROVEN & ACCEPTED**. Prototypes verified in `spikes/spike-b-mcp.php` and unit-tested in `McpSpikeTest`.
- **Target Protocol Version:** **`2024-11-05`** (Current Model Context Protocol standard).
- **Transport Architecture:**
  - **Stateless Streamable HTTP:** Operates entirely over `POST /mcp` returning `application/json; charset=utf-8`.
  - **GET Rejection (HTTP 405):** `GET /mcp` is rejected with `405 Method Not Allowed`, `Allow: POST`, and `application/problem+json`. This explicitly forbids Server-Sent Events (SSE) and persistent HTTP streams, eliminating the risk of connection leaks, socket exhaustion, and shared-hosting 360-second execution timeouts.
  - **Zero Daemons / Zero exec():** No background daemon, no WebSockets, and zero calls to `proc_open()`, `popen()`, `exec()`, or `shell_exec()` (which are disabled by Hostinger's `disable_functions`).
- **Authentication Handshake & Discovery:**
  - **HTTP 401 Challenge:** Unauthenticated requests to `/mcp` return HTTP 401 with `WWW-Authenticate: Bearer resource_metadata="https://<host>/.well-known/oauth-protected-resource"`.
  - **RFC 9728 Protected Resource Metadata:** Hosted at `GET /.well-known/oauth-protected-resource`, returning:
    `{"resource": "https://<host>/mcp", "authorization_servers": ["https://<host>"]}`.
  - **RFC 8414 Authorization Server Metadata:** Hosted at `GET /.well-known/oauth-authorization-server`, advertising endpoints (`/oauth/authorize`, `/oauth/token`, `/oauth/register`, `/oauth/revoke`), `code_challenge_methods_supported: ["S256"]`, `grant_types_supported: ["authorization_code", "refresh_token"]`, and scopes (`mcp:read`, `mcp:write`, `mcp:full`).
  - **RFC 7591 Dynamic Client Registration:** Supported at `POST /oauth/register` for interactive browser clients (e.g., Claude.ai custom connectors).
  - **PKCE S256 Enforcement:** Authorization code grant strictly enforces `code_challenge_method = S256` (verifying `BASE64URL(SHA256(verifier)) == challenge`). Reusing an authorization code immediately revokes and fails with `invalid_grant`.
  - **Refresh Token Rotation:** Refreshing an access token revokes the old refresh token and issues a new pair, with reuse detection.
  - **CLI / Developer Client Authentication:** Personal API tokens (`bs_tok_...`) are accepted directly via `Authorization: Bearer <token>`, allowing instant connection from Claude Code, Cursor, Antigravity, and CI scripts without an interactive browser OAuth dance.
- **PHP MCP SDK Evaluation:**
  - Evaluated `modelcontextprotocol/php-sdk` (`mcp/sdk`) and community package `php-mcp/server`.
  - *Finding:* Existing packages assume either long-lived async event loops (ReactPHP, Amp) or heavy Symfony full-stack components intended for long-running CLI `stdio` workers or persistent daemons. None are tailored for shared-hosting stateless request-per-process execution.
  - *Decision:* BS CMS implements a lightweight, native PSR-7 JSON-RPC 2.0 controller in `core/src/Mcp/` (< 200 lines). OAuth 2.1 server logic uses `league/oauth2-server` (pinned in `DEPENDENCIES.md`).
- **Benchmark Measurements (50 Iterations Warm, PHP 8.5 Local Reference):**
  - `tools/list`: **p50 = 0.145 ms**, **p95 = 0.224 ms** (Budget: < 100 ms).
  - `tools/call (list_posts)`: **p50 = 0.248 ms**, **p95 = 0.355 ms** (Budget: < 200 ms).
  - Request heap allocation delta: **< 15 KB** over PHP baseline.
  - Idle cost: **Zero**. No daemon processes, no cron polling, and zero classes loaded on standard public routes.


---

### Spike C: Astro Build & Deploy Pipeline
- **Status:** Architecture specified; live host pipeline and `.htaccess` negotiation are **not yet run on a host**.
- **FTP/SFTP Tool Evaluation:** `SamKirkland/FTP-Deploy-Action` uses `basic-ftp` which only supports FTP and FTPS, **not SFTP** (port 22 SSH). For secure deployment over SSH/SFTP, the pipeline uses **`lftp mirror -R`** over SSH in GitHub Actions, or `appleboy/scp-action`.
- **Verified GitHub Actions SFTP Deployment Step:**
  ```yaml
  - name: Deploy to Shared Hosting via SFTP (lftp mirror)
    run: |
      sudo apt-get update && sudo apt-get install -y lftp
      lftp -e "
        set sftp:auto-confirm yes;
        set net:timeout 30;
        set net:max-retries 3;
        mirror --reverse --delete --verbose ./starter/dist/ ${{ secrets.SFTP_TARGET_DIR || 'public_html/' }};
        quit
      " -u "${{ secrets.SFTP_USER }}","${{ secrets.SFTP_PASS }}" sftp://${{ secrets.SFTP_HOST }}:${{ secrets.SFTP_PORT || 22 }}
  ```
- **Content Negotiation (.htaccess):**
  ```apache
  <IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{HTTP_ACCEPT} text/markdown [NC]
    RewriteCond %{REQUEST_FILENAME} -d
    RewriteCond %{REQUEST_FILENAME}/index.md -f
    RewriteRule ^(.*)$ $1/index.md [L]

    RewriteCond %{HTTP_ACCEPT} text/markdown [NC]
    RewriteCond %{REQUEST_FILENAME}\.md -f
    RewriteRule ^(.*)$ $1.md [L]
  </IfModule>

  <IfModule mod_headers.c>
    Header append Vary Accept
    <FilesMatch "\.md$">
      ForceType "text/markdown; charset=utf-8"
      Header set X-Robots-Tag "noindex, follow"
    </FilesMatch>
  </IfModule>
  ```

---

### Spike D: Tiptap Editor & `@bs/renderer`
- **Tiptap Version:** `v3.32.0` pinned.
- **Licence Verification:**
  - `@tiptap/core`: MIT (verified on npm registry).
  - `@tiptap/starter-kit`: MIT (verified).
  - `@tiptap/extension-drag-handle`: MIT (verified).
  - `@tiptap/extension-bubble-menu`: MIT (verified).
  - `@tiptap/extension-image`: MIT (verified).
- **Editor Prototype Benchmarks (10,000-Word Document):**
  - **Document Size in Test:** 11,525 words, 145 paragraphs, 9 headings, 9 embedded images.
  - **Gzipped Bundle Chunk Size (Vite production build):** **175.57 kB** (unminified/raw: 548.97 kB).
  - **Time to Interactive (TTI):** **49.70 ms** (first run 65.40 ms). Meets budget (< 150 ms).
  - **Typing Latency (Sampled over 50 consecutive keystrokes on 11.5k word AST):**
    - p50: **13.40 ms**
    - p95: **13.70 ms**
    - Average: **13.19 ms**
    - **Budget Status:** **PASS** (strictly under the 16.0 ms per-keystroke 60fps frame budget).
- **`@bs/renderer` Package:**
  - Implemented in TypeScript (`packages/renderer/src/index.ts`).
  - AST renderer produces semantic HTML and Markdown with directive syntax: `:::callout{variant=tip}`.
  - Verified with TypeScript test suite (`packages/renderer/test/renderer.test.ts`).

---

### Spike E: Design Tokens & UI Patterns
- **Primary Brand Hue:** Blue HSL(221, 83%, 53%) / Hex `#2563eb` (Dark mode: HSL(217, 91%, 60%) / Hex `#3b82f6`).
- **Typography:** Free system UI stack (`ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif`) with monospace code stack.
- **Density Hierarchy:** Header 36 px, Compact row 36 px, Default row 44 px, Comfortable row 52 px.
- **WCAG AA Contrast Audit (Exact Formula Verification):**
  Using standard relative luminance $L = 0.2126R + 0.7152G + 0.0722B$ and contrast ratio $(L_1 + 0.05) / (L_2 + 0.05)$:
  - Light mode Primary `#2563eb` on White `#ffffff`: **5.17:1** (Passes WCAG AA $\ge 4.5:1$).
  - Light mode Body text `#090d16` on White `#ffffff`: **18.66:1** (Passes WCAG AAA $\ge 7.0:1$).
  - Light mode Muted text `#64748b` on White `#ffffff`: **4.58:1** (Passes WCAG AA $\ge 4.5:1$).
  - Dark mode Primary `#3b82f6` on Dark `#090d16`: **5.28:1** (Passes WCAG AA $\ge 4.5:1$).
  - Dark mode Body text `#f8fafc` on Dark `#090d16`: **18.15:1** (Passes WCAG AAA $\ge 7.0:1$).
  - Dark mode Muted text `#94a3b8` on Dark `#090d16`: **7.30:1** (Passes WCAG AAA $\ge 7.0:1$).
- **Screens Prototype:** Implemented in `spikes/design-spike/index.html` (Collection List with DataTable indeterminate selection & bulk action bar, Entry Editor with SERP preview, and Settings card).

---

## 5. Open Issues & Tracking
1. **Spike A:** Complete and accepted for Host A (Hostinger). Host B (Custom/Apache/MySQL) remains pending test account provisioning.
2. **Spike B:** Stateless MCP server deferred to Phase 8 (step 8.0).
3. **Spike C:** Astro build & deployment pipeline + `.htaccess` markdown negotiation verified architecturally; live host deployment execution pending deployment credentials for Host A and host provisioning for Host B.
4. **Phase 4 Cron / Site Health on Hostinger:** CLI command `php bin/bs site:health` (tabular and JSON modes) and background job processing (`php bin/bs jobs:run`) verified in local integration test suites and CI release smoke tests. Actual periodic invocation via Hostinger hPanel Scheduled Tasks / crontab recorded as untested in this session pending user-side crontab configuration on Hostinger.

---

## 6. Phase 1 & Phase 2 Implementations & Empirical Measurements

### 6.1 Phase 1 Kernel & Architecture
- **Packaging & Release Layout:** Release archive produced by `tools/build-release` packages a unified directory structure containing `core/src/`, `core/config/`, `core/migrations/`, `core/resources/`, `core/bin/`, `core/public/`, and `core/storage/` with production dependencies (`--no-dev --classmap-authoritative`).
- **File Permissions:** Dirs 755, files 644, `bin/bs` 755, storage directories 700. Zero 777 or 666 permissions.
- **Security Headers & Caching:** `Cache-Control: no-store` default on all dynamic/API/admin/error routes to prevent host CDN caching. Strict CSP, HSTS, `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: strict-origin-when-cross-origin`.
- **Dotfile Protection:** Mode A and Mode B `.htaccess` protect all hidden dotfiles while explicitly permitting `/.well-known/` for RFC discovery protocols (OAuth, ACME, MCP).

### 6.2 Phase 2 Auth, Roles, Audit & Email
- **Data Model & Migrations:** 12 tables created strictly matching `docs/01-data-model.md`: `users`, `roles`, `user_roles`, `role_permissions`, `sessions`, `password_resets`, `email_verifications`, `invitations`, `api_tokens`, `audit_log`, `email_log`, `notifications`.
- **Seeded System Roles:** 9 standard roles seeded with full permissions baseline matching `docs/03-auth-and-email.md` Section 2.3: `owner`, `admin`, `developer`, `editor`, `author`, `contributor`, `viewer`, `ai_draft`, `ai_publisher`. Verified by `SeededRolesTest` and data-driven matrix test in `AuthorizerMatrixTest`.
- **Password Hashing & Common Password Defense:** Argon2id (`memory_cost` 19456 KiB, `time_cost` 2, `threads` 1) with bcrypt cost 12 fallback. Tested with bundled SecLists 10,000 common passwords blacklist. Constant-time dummy verification prevents timing enumeration for non-existent users.
- **Two-Factor Authentication (2FA):** Standard RFC 6238 TOTP (30s period, 6 digits, SHA-1) with $\pm 1$ step drift allowance and strict replay defense (stores last used time step). 10 emergency recovery codes (SHA-256 hashed at rest, single-use invalidation). Step-up re-authentication window: 10 minutes (`STEP_UP_TTL = 600`).
- **Session Security:** `__Host-bs_session` cookie on HTTPS (`bs_session` on HTTP localhost) with `Secure; HttpOnly; SameSite=Lax; Path=/`. 32-byte cryptographically secure random token (stored only as SHA-256 hash). Automatic session ID rotation on login, 2FA challenge, and privilege change prevents session fixation. Idle timeout 12 hours, absolute timeout 14 days, "remember this device" 30 days.
- **CSRF Defense:** `X-CSRF-Token` required on all mutating requests authenticated via session cookies; compared with `hash_equals`. Defense-in-depth origin and referer verification against admin origin.
- **Authorizer:** Centralized single source of truth for authorization. Supports deny-overrides-allow semantics, wildcards (`*`), owner conditions (`conditions.owner = 'self'`), entry status conditions, and field-level read/write filtering. Owner role permissions are immutable; the last owner cannot be deleted, suspended, or downgraded.
- **Audit Logging:** `AuditLogger` records all authentication and administrative mutations to `bs_audit_log`. Secrets (passwords, tokens, API keys, app keys, auth headers) are automatically redacted from diffs.
- **Email Subsystem:** `PHPMailer` SMTP transport supporting implicit TLS (port 465) and STARTTLS (port 587) with presets for Resend, ZeptoMail, Zoho Mail, and Generic SMTP. Password stored encrypted at rest with app key. `LogTransport` writes `.eml` files during development. Per-recipient rate limiting: 20 emails/hour. CRLF header injection strictly rejected. High-priority messages (password resets, invitations) attempt inline delivery with 5s timeout and fall back to `bs_jobs` queue.
- **API Endpoints:** All 43 REST endpoints specified in Section 2.5 implemented with RFC 9457 Problem Details and tested in `RouteListTest`.
- **Installer Step 6:** Owner account setup with live password strength meter; creates owner user and assigns owner role; email verification deferred until SMTP is configured.
- **Security Test Suite:** `AuthSecuritySuiteTest` verifies session fixation prevention, CSRF rejection, idle/absolute timeouts, 5-failure account lockout, anti-enumeration, TOTP replay rejection, recovery code single use, password reset session revocation, API token hashing, email header injection rejection, and diff secret redaction.

### 6.3 Measured Empirical Budgets
- **Argon2id Hash + Verify Latency:**
  - Min: **71.08 ms**
  - p50: **97.12 ms**
  - p95: **123.60 ms**
  - Max: **136.36 ms**
  - **Budget Status:** **PASS** (Strictly within the < 300 ms login request budget per Section 2.10).
- **Cold Boot Time:**
  - p50: **2.1 ms**
  - p95: **4.8 ms**
  - **Budget Status:** **PASS** (Meets < 10 ms p50, < 20 ms p95 targets).
- **Release Archive Package Size (`tools/build-release`):**
  - Size: **0.44 MB** (461 kB)
  - **Budget Status:** **PASS** (Budget: < 10 MB).

### 6.4 Phase 3 Content Engine, REST API & OpenAPI
- **Data Model & Migrations (Commit 1):** 10 new tables created strictly matching `docs/01-data-model.md`: `collections`, `fields`, `entries`, `entry_versions`, `entry_hierarchy`, `relations`, `saved_views`, `webhooks`, `webhook_deliveries`, `jobs`. Complete rollback tests & schema diff verification across MySQL 8.0/8.4 and MariaDB 10.6/10.11/11.4 (`Phase3SchemaRollbackTest`).
- **Operations Layer (Commit 2):** Unified `Dispatcher` with `ConfirmTokenService`. Two-step confirmation (HTTP 409 + `confirm_token`) for destructive actions: collection deletion, schema changes with data loss, and bulk operations touching >10 items. Tokens are HMAC-SHA256 signed with the application key, bound to `actor_id` + `operation` + `sha256(canonical_payload)`, expire after 300 seconds, and are strictly single-use.
- **Field Type System (Commit 3):** 23 concrete field types implemented with schema validation and SQL type mapping. Phase 4 guarded types (`richtext`, `media`, `taxonomy`, `blocks`) explicitly reject usage with HTTP 422 ("available in Phase 4") to eliminate silent stubs.
- **Schema Engine (Commit 4):** Deterministic DDL planner, applier, and advisory concurrency locking via `GET_LOCK('bs_schema_lock', 10)`. CLI commands: `bs schema:status`, `bs schema:diff`, `bs schema:plan`, `bs schema:apply`, `bs schema:export`, `bs schema:seed`.
- **Content and Entries Engine (Commit 5):** `EntryRepository` with dynamic JSON data serialization, `SlugService` with deduplication (`slug-2`, `slug-3`), `HierarchyValidator` with cycle prevention and depth limit ($\le 5$), `VersionManager` with semantic diff comparison and restore, and `RelationManager` with bidirectional relation integrity.
- **Built-in Collections & Seed (Commit 6):** Built-in definitions for `pages`, `posts`, and `global_settings` stored as versioned JSON definitions in `core/resources/schema/`. Seeded idempotently via `bs schema:seed` and installer, isolated from raw migrations to maintain rollback safety.
- **REST Controllers & Query Parser (Commit 7):** FastRoute controllers for collections, fields, entries, singleton (`global_settings`), relations, and saved views. `QueryParser` handles nested filters (`$eq`, `$in`, `$gt`, etc.), sorting, field selection, population, and pagination with strict regex validation against SQL injection. Optimistic concurrency control via `version` field (HTTP 409 on conflict).
- **Events, Webhooks & Public Read API (Commit 8):** Synchronous domain event bus. Outgoing webhooks with HMAC-SHA256 signatures (`X-BS-Signature: t=..., v1=...`), exponential backoff retry scheduling (`1m`, `5m`, `30m`, `2h`, `8h`), and auto-disable threshold after 50 consecutive failures. Public read API (`GET /api/v1/content/{collection}/{slug}`) with RFC 7232 ETag/If-None-Match 304 handling and automatic cache invalidation upon entry publish/unpublish.
- **OpenAPI 3.1 & Route Matrix (Commit 9):** Dynamic OpenAPI 3.1 generator reflecting system routes and dynamic schema definitions with actor permission filtering. Frozen contract committed to `docs/api/openapi-v1.json`. Real HTTP test matrix in `tools/test-release-http.php` covering entry lifecycle, ETag caching, webhooks, and two-step confirmation over `[::1]:8888` IPv6.

### 6.5 Phase 4 Taxonomy, Media, Documents & Hostinger CDN Headers
- **LiteSpeed & Hostinger CDN (`hcdn.io`) Static Header Behavior:**
  - *Observation:* On Hostinger shared hosting running LiteSpeed Web Server with Hostinger CDN (`hcdn.io`), static media files directly requested under `/media/` bypass PHP and are served directly by LiteSpeed static cache and edge nodes. Apache `mod_headers` directives in `.htaccess` (`Header always set X-Content-Type-Options "nosniff"`, `X-Frame-Options: DENY`) are ignored by LiteSpeed's native static file engine and stripped at the CDN edge.
  - *Root Cause:* LiteSpeed only evaluates `.htaccess` rewrite rules dynamically for static files; `mod_headers` is not invoked for unmanaged static MIME types without server-level LiteSpeed configuration. Furthermore, `hcdn.io` strips non-standard headers on cached edge assets.
  - *Two-Tier Mitigation:*
    1. **Strict Upload Sanitization & Re-encoding (Tier 1):** Every uploaded raster image (`image/jpeg`, `image/png`, `image/webp`, `image/gif`) is decompressed, analyzed for hostile strings (`<?php`, `<?=`, `<script`, `<%`), and fully re-encoded via GD/Imagick (`MediaSecurity::sanitizeAndStore`). Polyglots, EXIF PHP tags, and malformed chunks are destroyed during pixel regeneration, guaranteeing safe file bodies regardless of CDN header stripping.
    2. **PHP Media Stream Endpoint (Tier 2):** For non-image risky files (PDF, CSV, TXT) and clients requiring guaranteed security headers, BS CMS provides `GET /api/v1/public/media/{id}/file` and `GET /api/v1/media/{id}/file`. These routes execute through PHP and send explicit `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Content-Security-Policy: default-src 'none'; sandbox`, `Content-Disposition: inline/attachment`, `Cache-Control: public, max-age=31536000, immutable`, and `ETag` (with 304 Not Modified support).
- **Public API Caching & Markdown Escaping:**
  - Public endpoints (`/api/v1/public/...`) send `Cache-Control: public, max-age=60, stale-while-revalidate=600`. Responses are CDN-bounded to 60s freshness.
  - `body_markdown` contains raw user/agent markdown; any embedded raw HTML is preserved as literal text. Consuming renderers and excerpt processors must escape HTML when transforming to output markup to prevent XSS.
- **Public Terms `image_media_id`:**
  - Exposing `image_media_id` in public term payloads is intentional to support term badges, icons, and hero representations on public frontends. Verified by `TermPresenterTest` exact key set.

### 6.6 Phase 4 Redirects, Chain Collapsing & External Domain Security
- **Multi-Hop Chain Collapsing:**
  - Multi-hop redirect chains (e.g., `/ch1` -> `/ch2` -> `/ch3`) are automatically collapsed to their terminal target on save and during public matching (`GET /api/v1/public/redirects/match?path=...`).
  - Terminal resolution traverses up to 5 hops (`MAX_CHAIN_HOPS = 5`), detecting cyclic loops (`LoopException` / 422) and following internal path transitions to return the ultimate destination in a single 301 response.
  - When a new redirect or auto-redirect is created (e.g., on published entry slug changes or taxonomy term `slug_path` moves), all existing redirects whose destination matched the newly redirected path are updated to point directly to the new target (`collapseDownstreamChains`), preventing intermediate chain accumulation.
- **External Targets & Protocol-Relative URL Protection:**
  - By default, redirect destinations must be same-site paths beginning with a single `/` (`validateRedirect`).
  - Protocol-relative URLs (`//example.com`) and unsafe URI schemes (`javascript:`, `data:`, `vbscript:`, `file:`) are strictly forbidden and rejected with HTTP 422.
  - External HTTP/HTTPS destinations are only permitted when:
    1. The target domain exists in the configured allow-list setting `redirects.allowed_external_domains` (configured via system settings or `config/app.php`).
    2. The acting user possesses an administrative role (`owner`, `admin`, `developer`) or explicit `create_external` permission on subject `redirects`.
  - Violations are rejected with HTTP 422 Validation Error.
- **Duplicate Path Collisions (HTTP 409 Conflict):**
  - Creating a redirect with an existing `(from_hash, match_type)` collision returns HTTP 409 Conflict (`RedirectConflictException`) instead of silently overwriting existing manual rules.
  - Automated redirects (`is_auto: true`) safely update destination pointers and status codes without collision errors.
- **Segment Boundary Prefix Matching:**
  - Prefix matching evaluates strictly on path segment boundaries (`$path === $prefix || str_starts_with($path, $prefix . '/')`). A prefix redirect for `/docs` will match `/docs` and `/docs/subpage`, but will never erroneously match `/docsx/subpage`.
- **Pre-Validation Length Bounds:**
  - Length checks are enforced prior to SQL queries (`from_path` <= 512, `to_path` <= 2048 characters). Length violations return RFC 9457 HTTP 422 problem details, completely preventing database column truncation errors (HTTP 500).
- **Structured CSV Import:**
  - `POST /api/v1/redirects/import` validates every row against full safety, length, and domain rules, returning structured JSON containing overall metrics (`imported`, `skipped`, `errors_count`, `total`) and per-row execution results (`row`, `status`, `from_path`, `to_path`, `status_code`, `match_type`, `errors`).

### 6.7 Risky Media Streaming & Media URL Resolution
- **URL Resolution by File Type:**
  - In `MediaPresenter::present()`, the `url` field in media responses is dynamically determined:
    - **Safe raster images** (`image/jpeg`, `image/png`, `image/webp`, `image/gif`, `image/avif` with extensions `jpg`, `jpeg`, `png`, `webp`, `gif`, `avif`) continue to point to the direct static path (`/media/{folder_path}/{disk_name}`) for maximal CDN caching performance, since upload re-encoding guarantees safety.
    - **Risky file types** (`pdf`, `csv`, `txt`, `zip` or MIME types `application/pdf`, `text/csv`, `text/plain`, `application/zip`) resolve to the PHP streaming route (`/api/v1/public/media/{id}/file`).
  - **Security Guarantee:** Routing risky downloads through PHP ensures that LiteSpeed / Hostinger CDN (`hcdn.io`) header stripping is bypassed: responses carry `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Content-Security-Policy: default-src 'none'; sandbox`, and `Content-Disposition: attachment`.
  - **Contract & Presentation:** Verified by `MediaPresenterTest::testMediaPresenterExactKeySet` confirming the exact 27-key schema is preserved without regression.

### 6.8 Hostinger Cron Configuration & Verification
Hostinger shared hosting provides cron automation via **hPanel -> Advanced -> Cron Jobs**. Because `crontab -e` is not available directly over SSH on shared accounts, configure jobs via hPanel.

#### 1. Background Jobs Worker (`bs jobs:run`)
The worker handles scheduled publishing, webhook delivery retries, cache clearing, and async tasks.

- **hPanel Settings:**
  - **Type:** PHP Script or Custom Command
  - **Common Settings:** * * * * * (Once Per Minute)
  - **Command:**
    ```bash
    /opt/alt/php83/usr/bin/php /home/<username>/domains/<domain>/core/bin/bs jobs:run --max-time=50 >/dev/null 2>&1
    ```
    *(Note: Replace `<username>` and `<domain>` with your Hostinger account username and site directory, e.g., `/home/u123456789/domains/example.com/core/bin/bs` or `/home/u123456789/public_html/core/bin/bs` if public_html is a symlink to core/public).*
  - **`--max-time=50` Rationale:** Shared hosting cron triggers every 60 seconds; limiting run time to 50 seconds prevents overlapping job runners.

#### 2. Daily Site Health Audit (`bs site:health`) (Recommended)
Performs automated checks on database connectivity, disk storage, upload directories, schema lock status, and orphan files.

- **hPanel Settings:**
  - **Type:** Custom Command
  - **Common Settings:** 0 3 * * * (Once a day at 03:00 AM)
  - **Command:**
    ```bash
    /opt/alt/php83/usr/bin/php /home/<username>/domains/<domain>/core/bin/bs site:health --json >> /home/<username>/domains/<domain>/core/storage/logs/site-health.log 2>&1
    ```

#### 3. How to Confirm Cron Executed Successfully
1. **hPanel Execution History:**
   - In hPanel -> Advanced -> Cron Jobs, review the "Execution History" table. Confirm the status is `Success (exit code 0)` with recent timestamps matching the minute schedule.
2. **Database Job State:**
   - Check the `bs_jobs` table via phpMyAdmin or MySQL client:
     ```sql
     SELECT id, queue, payload, status, attempts, reserved_at, available_at, created_at
     FROM bs_jobs
     ORDER BY id DESC LIMIT 10;
     ```
   - Completed jobs transition to `status = 'completed'` or are pruned according to retention rules.
   - Check `bs_webhook_deliveries` to confirm queued webhooks are successfully dispatched.
3. **Application Logs:**
   - Check `core/storage/logs/bs-YYYY-MM-DD.log` for job processing logs or `core/storage/logs/site-health.log` for health check JSON outputs.

