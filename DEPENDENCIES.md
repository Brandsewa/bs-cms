# DEPENDENCIES.md

This document tracks all third-party dependencies used in BS CMS. Any addition beyond the allow-list defined in `implementation/README.md` Section 6 requires explicit approval and must not be required on the public request path.

---

## 1. PHP Runtime Dependencies (Shipped in `vendor/`)

| Package | Pinned / Target Version | Licence | Size (approx) | Purpose & Rationale |
|---|---|---|---|---|
| `nikic/fast-route` | `^1.3.1` | BSD-3-Clause | ~35 KB | High-performance regex-based URL routing for API and admin endpoints. |
| `nyholm/psr7` | `^1.8.2` | MIT | ~40 KB | Lightweight PSR-7 / PSR-17 HTTP message implementation. Required by OAuth server. |
| `nyholm/psr7-server` | `^1.1.0` | MIT | ~10 KB | Factory to create PSR-7 `ServerRequest` from PHP superglobals without framework bloat. |
| `league/commonmark` | `^2.6.2` | BSD-3-Clause | ~450 KB | Converts Markdown from AI agents and authors into AST / document nodes. |
| `phpmailer/phpmailer` | `^6.12.0` | LGPL-2.1-only | ~280 KB | Robust SMTP email delivery with TLS, header-injection protection. Unmodified dependency. |
| `league/oauth2-server` | `^9.4.1` | MIT | ~180 KB | RFC-compliant OAuth 2.1 authorization server (PKCE, refresh rotation) for AI clients. Loaded only on `/oauth/*` and `/mcp`. |
| `spomky-labs/otphp` | `^11.5.0` | MIT | ~25 KB | RFC 6238 TOTP two-factor authentication for user security. |
| `psr/http-server-middleware` | `^1.0.2` | MIT | ~2 KB | Standard PSR-15 Middleware interface for HTTP request pipeline. |
| `psr/log` | `^3.0.0` | MIT | ~3 KB | Standard PSR-3 Logger interface for FileLogger. |

---

## 2. PHP Development Tools (Dev-Only, Never Shipped)

| Package | Pinned Version | Licence | Purpose |
|---|---|---|---|
| `phpunit/phpunit` | `^11.5.57` | BSD-3-Clause | Unit, integration, and HTTP test suites. |
| `phpstan/phpstan` | `^2.3.1` | MIT | Static analysis enforced at Level 8 strictness. |
| `friendsofphp/php-cs-fixer` | `^3.95.27` | MIT | PSR-12 and strict typing formatting. |

---

## 3. Required PHP Extensions

Verified by the browser installer and checked at startup:
- **Mandatory:** `pdo_mysql`, `mbstring`, `json`, `openssl`, `sodium`, `fileinfo`, `curl`, `zip`, `ctype`, `filter`.
- **Image processing:** `gd` or `imagick`.
- **Recommended:** `opcache`, `apcu`, `intl`, `exif`.

---

## 4. Admin Frontend Dependencies (Built Ahead of Time, Never Run on Server)

Shipped as static assets in `core/public/admin/`:
- `react`, `react-dom`
- `vite`, `typescript`, `tailwindcss`
- `@radix-ui/*` primitives, `lucide-react`
- `@tanstack/react-query`, `@tanstack/react-router`, `@tanstack/react-table`, `@tanstack/react-virtual`
- `react-hook-form`, `zod`, `cmdk`, `sonner`
- `@tiptap/core`, `@tiptap/starter-kit`, `@tiptap/extension-*` (MIT extensions only)
- `date-fns`, `react-day-picker`, `@dnd-kit/*`

---

## 5. Public Starter Dependencies (Static Astro Build, Off-Host)

- `astro`
- `pagefind` (static search)
- `@bs/client` (internal typed API client)
- `@bs/renderer` (internal shared Tiptap -> HTML/Markdown renderer)
