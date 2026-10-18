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

### 4.1 Pinned runtime dependencies (Phase 5, exact versions)

All licences permissive (MIT unless noted). Sizes are approximate gzipped minified.

| Package | Version | Licence | Size (gzip) | Reason |
|---|---|---|---|---|
| `react` / `react-dom` | 19.3.1 | MIT | ~45 KB | Admin UI runtime. |
| `@radix-ui/react-*` (accordion, alert-dialog, avatar, checkbox, context-menu, dialog, dropdown-menu, hover-card, label, popover, progress, radio-group, scroll-area, select, separator, slider, slot, switch, tabs, toggle, toggle-group, tooltip) | see `packages/ui/package.json` | MIT | per-primitive 2–10 KB | Accessible primitives for the shadcn-style components (allow-listed as "@radix-ui/*"). |
| `class-variance-authority` | 0.7.1 | Apache-2.0 | ~2 KB | Variant typing for Button/Badge/Alert. |
| `clsx` | 2.1.1 | MIT | <1 KB | Conditional class joining. |
| `tailwind-merge` | 3.7.0 | MIT | ~7 KB | Resolves Tailwind class conflicts in `cn()`. |
| `lucide-react` | 1.59.0 | ISC | tree-shaken (~1 KB/icon) | The one icon set (docs/02-ui-spec.md 5). |
| `cmdk` | 1.1.1 | MIT | ~5 KB | Command menu primitives (Ctrl/Cmd+K). |
| `sonner` | 2.0.8 | MIT | ~8 KB | Toasts with undo/action buttons. |
| `react-day-picker` | 10.0.2 | MIT | ~20 KB | Calendar/date pickers. |
| `date-fns` | 4.4.0 | MIT | tree-shaken | Date formatting in pickers. |
| `@tanstack/react-router` | 1.170.45 | MIT | ~25 KB | Admin routing, URL state, loaders. Code-based routes (no router-plugin dependency). |
| `@tanstack/react-query` | 5.104.4 | MIT | ~13 KB | Server state cache. |
| `react-hook-form` | 7.90.0 | MIT | ~9 KB | Form state; Zod resolver hand-rolled (see 4.3). |

### 4.2 Dev-only tooling (never shipped)

| Package | Version | Licence | Reason |
|---|---|---|---|
| `vite` | 8.3.4 | MIT | Library + app builds. |
| `@vitejs/plugin-react` | 6.1.2 | MIT | React fast-refresh/JSX. |
| `tailwindcss` / `@tailwindcss/vite` | 4.3.3 | MIT | Token-driven utility CSS. |
| `vitest` | 5.0.5 | MIT | Unit/component/axe test runner. |
| `jsdom` | 30.1.3 | MIT | DOM for vitest. |
| `@testing-library/react` / `-user-event` / `-jest-dom` / `@testing-library/dom` | 16.3.3 / 14.6.8 / 7.0.1 / 10.4.2 | MIT | Component testing. |
| `axe-core` | 4.14.0 | **MPL-2.0** | Accessibility checks. Dev-only: never shipped in the release zip; MPL obligations apply only if its source is modified. Flagged for the owner as the one non-MIT/Apache/BSD/ISC licence; removing it would mean losing automated a11y verification. |
| `zod` | 4.6.7 (dev in `@bs/ui`; runtime dep lands with admin forms in M2/M3) | MIT | Validation shared with the server contract. |

### 4.3 Deliberately NOT added (hand-rolled in `@bs/ui`)

| Common package | Replacement | Reason |
|---|---|---|
| `@hookform/resolvers` | `packages/ui/src/form/zod-resolver.ts` | ~30-line Zod→RHF mapping avoids a dependency. |
| `react-resizable-panels` | `packages/ui/src/components/resizable.tsx` | Pointer + keyboard resizable split with ARIA separator semantics. |
| `input-otp` | `packages/ui/src/components/input-otp.tsx` | OTP cells with auto-advance/paste/arrows. |
| `vaul` | Drawer built on Radix dialog (bottom sheet) | Drag-to-dismiss not required by spec. |
| `jest-axe` | `packages/ui/test/axe.ts` helper | Direct axe-core run with documented jsdom rule overrides. |

Pending approval (not installed): `eslint-plugin-react-hooks` (hook dep linting; requested in PR #1).

---

## 5. Public Starter Dependencies (Static Astro Build, Off-Host)

- `astro`
- `pagefind` (static search)
- `@bs/client` (internal typed API client)
- `@bs/renderer` (internal shared Tiptap -> HTML/Markdown renderer)
