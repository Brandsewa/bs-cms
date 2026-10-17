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
- **Shared Hosting Environments:** Pending live host verification (deferred until test accounts exist; fallback to bcrypt with cost 12 if Argon2id is unavailable on specific hosts).
- **Verification Status:** PASSED locally; Host pending.

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
  - `Meta-ExternalAgent` (Meta): Scraper for Meta AI training. Source: https://developers.facebook.com/docs/sharing/bot/ (Checked: 2026-10-17).
  - `Bytespider` (ByteDance): Scraper for ByteDance/TikTok AI models. Source: https://www.bytedance.com (Checked: 2026-10-17).
  - `cohere-ai` (Cohere): Web scraper for Cohere foundational models. Source: https://cohere.com (Checked: 2026-10-17).
- **Verification Status:** PASSED (All tokens sourced and dated; Open Issue #2 resolved).

---

## 3. Hosting Matrix (Section 0.2)

*Host A measured on live Hostinger shared hosting. Host B remains pending.*

| Parameter | Host A (Hostinger / LiteSpeed / MariaDB) | Host B (Custom / Apache / MySQL) |
|---|---|---|
| Provider / Plan | Hostinger Premium/Business Shared Hosting | Pending (deferred until test account exists) |
| Control Panel | hPanel | Pending |
| Web Server | LiteSpeed Web Server | Pending |
| PHP Version | PHP 8.3 (CLI path: `/opt/alt/php83/usr/bin/php`) | Pending |
| Database Version | MariaDB 10.11 | Pending |
| `memory_limit` | 256 MB (128 MB default baseline) | Pending |
| `max_execution_time` | 30 s | Pending |
| `upload_max_filesize` | 128 MB | Pending |
| `post_max_size` | 128 MB | Pending |
| OPcache / APCu | OPcache enabled; APCu disabled | Pending |
| Document Root Configurable? | No (fixed to `public_html/`; Mode B fallback applies) | Pending |
| Files Above `public_html`? | Yes (supported; code can sit one level above root) | Pending |
| `.htaccess` ModRewrite & Headers | Yes (`mod_rewrite` and `Header` directives supported) | Pending |
| Cron Available? | Yes (requires CLI binary `/opt/alt/php83/usr/bin/php` to match web PHP) | Pending |
| Argon2id Available? | Supported in PHP 8.3; `sodium` disabled by default in hPanel (fix hint added) | Pending |
| Process Management | `litespeed_finish_request()` supported (no `fastcgi_finish_request()`) | Pending |
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

### Spike B: Stateless MCP Server (DEFERRED)
- **Status:** **Deferred, unverified.** Moved to Phase 8 (step 8.0) per project decision.
- **Specification target:** Protocol conformance and HTTP transport to be verified during Phase 8.

---

### Spike C: Astro Build & Deploy Pipeline
- **Status:** Architecture specified; live host pipeline and `.htaccess` negotiation are **not yet run on a host**.
- **FTP/SFTP Tool Evaluation:** `SamKirkland/FTP-Deploy-Action` uses `basic-ftp` which only supports FTP and FTPS, **not SFTP** (port 22 SSH). For secure deployment over SSH/SFTP, the pipeline uses **`lftp mirror -R`** over SSH in GitHub Actions, or `appleboy/scp-action`.
- **Content Negotiation:** `.htaccess` mod_rewrite rule matching `HTTP_ACCEPT: text/markdown` serving `.md` sibling files with `Content-Type: text/markdown; charset=utf-8` and `Vary: Accept` header is documented in `spikes/deploy-spike/.htaccess`.

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
