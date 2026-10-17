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
- **FAQ Rich Results:** Google restricted FAQ rich results in August 2023 primarily to authoritative government and health websites. FAQ structured data (`FAQPage`) remains valid schema.org data for AI answer engines (AEO), but rich-result search appearance is not guaranteed.
- **Verification Status:** PASSED.

### 2.6 Shared-Host Environment & Argon2id
- **Target:** Check `defined('PASSWORD_ARGON2ID')` on the shared host test instances. Falls back to bcrypt cost 12 if absent.
- **Status:** Pending credentials / testing on target accounts.

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

### Spike A: Storage Model Benchmark (50k rows)
- *Awaiting execution on target hosts.*

### Spike B: Stateless MCP Server
- *Awaiting execution on target hosts.*

### Spike C: Astro Build & Deploy Pipeline
- *Awaiting execution.*

### Spike D: Tiptap Editor & `@bs/renderer`
- *Awaiting execution.*

### Spike E: Design Tokens & UI Patterns
- *Awaiting execution.*

---

## 5. Open Issues & Tracking
1. Awaiting shared-host account details and public HTTPS testing domain from user to execute Spikes A, B, and C.
2. Bot token verification list from `docs/04-crawlers-and-discoverability.md` section 3 to be cataloged against vendor docs.
