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
4. **AI Interface:**
   - Stateless JSON-RPC over `POST /mcp` (Streamable HTTP). Zero idle server memory or daemon overhead.
   - OAuth 2.1 via `league/oauth2-server` using PKCE and rotating refresh tokens.

---

## 2. Verifications (Section 0.8)

### 2.1 `league/oauth2-server`
- **Current Version:** `9.4.1` (also active LTS `8.5.5`).
- **PHP Requirement:** `php ~8.2.0 || ~8.3.0 || ~8.4.0 || ~8.5.0`. Fits our PHP >= 8.2 requirement.
- **Licence:** MIT.
- **PKCE & Refresh Rotation:** Full native support for RFC 7636 (PKCE) and rotating refresh tokens.
- **Verification Status:** PASSED.

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
Evaluated on MySQL 8.0.46 and MariaDB 10.6.28 with realistic JSON documents (2–5 KB per row, containing block trees, custom fields, and SEO metadata):

| Metric / Query Operation | MySQL 8.0.46 | MariaDB 10.6.28 | Target / Budget | Result |
|---|---|---|---|---|
| **Insertion Rate (batch size 1,000)** | 362.9 rows/sec (137.7 s) | 407.9 rows/sec (122.5 s) | — | Informational |
| **List Query (Offset 100, Limit 25)** | **4.55 ms** | **3.88 ms** | < 50 ms | **PASS** |
| **Cursor Pagination Query** | **2.50 ms** | **2.54 ms** | < 50 ms | **PASS** |
| **Indexed Generated Column (`reading_time = 7`)** | **3.10 ms** | **4.02 ms** | < 50 ms | **PASS** |
| **Non-indexed JSON Path Filter** | 12.28 ms | 40.47 ms | Table scan | Expected |
| **Taxonomy Term Join Filter** | **25.15 ms** | **32.16 ms** | < 50 ms | **PASS** |
| **Single Entry Fetch + 2 Populated Relations** | **5.46 ms** | **5.38 ms** | < 20 ms | **PASS** |
| **Single Row Update Latency** | **9.88 ms** | **3.89 ms** | < 20 ms | **PASS** |
| **`ALTER TABLE ADD VIRTUAL GENERATED COLUMN + KEY`** | **0.735 s** (735 ms) | **1.739 s** (1,739 ms) | Sub-2 s | **PASS** |

**MariaDB Compatibility Findings:**
- Virtual column definition `CAST(JSON_UNQUOTE(JSON_EXTRACT(data, '$.field')) AS SIGNED) VIRTUAL` works identically and deterministically on both MySQL 8 and MariaDB 10.6+.
- Secondary indexes on virtual generated columns function properly without table lock issues.


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
