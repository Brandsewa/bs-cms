# UI notes (Phase 5+ — design system, admin shell)

UI-owned engineering notes. The root `NOTES.md` belongs to the backend
phases; UI decisions and measurements live here to avoid merge conflicts
(owner instruction, M1 review defect 6).

## 7. Phase 5: Design System (`@bs/ui`) and Admin Shell — Decisions & Measurements

### 7.1 Token corrections (WCAG AA, 2026-10-18)

Spike E's audit covered primary and body/muted pairs but inherited near-white
foreground values on mid-lightness solid backgrounds from the shadcn starter.
Recomputed with the relative-luminance formula and corrected in
`packages/ui/src/tokens.css`:

| Pair | Before | After (light) | Measured ratio |
|---|---|---|---|
| `--warning` / `--warning-foreground` | #f59e0b on pale yellow text (~2.5:1, fails) | dark ink `hsl(30,70%,14%)` on #f59e0b | **6.7:1** |
| `--success` / `--success-foreground` | green-500 with pale text (~2.7:1, fails) | dark ink `hsl(150,85%,12%)` on #22c55e-class green | **6.5:1** |
| `--destructive` / `--destructive-foreground` (light) | red-500 with white (3.6:1, fails for 14px text) | deepened to `hsl(0,72%,51%)` with white | **4.8:1** |
| `--info` / `--info-foreground` (light) | added | `hsl(210,90%,45%)` with white | **4.7:1** |
| `--ai` / `--ai-foreground` | added; violet flips to bright + dark ink in dark mode | light `hsl(262,83%,50%)`+white (5.7:1); dark `hsl(262,83%,66%)`+ink (6.8:1) | passes |

Dark mode `--info` stays deep (`hsl(211,75%,45%)`, white 4.8:1) instead of a
bright blue, which cannot carry white text at AA. jsdom cannot compute
contrast, so the axe runs exclude `color-contrast` (rule documented in
`packages/ui/test/axe.ts`); contrast is enforced at the token level here.

### 7.2 Theme persistence

M1 persists `bs-theme` in localStorage only (owner instruction at M1 kickoff;
at that time no general preferences endpoint existed). During the pre-PR
rebase, main @ 7498e28 added `GET/PATCH /api/v1/me/preferences`, so API
persistence is unblocked: `ThemeProvider` gets read-on-bootstrap /
PATCH-on-change wired through `@bs/client` in M2/M3, keeping localStorage as
the cache and the pre-paint fallback (owner updated via PR #1).
No-flash bootstrap ships as an external file (`admin/public/theme-init.js`)
because the admin CSP is `script-src 'self'` (no inline scripts) — owner
decision, 2026-10-18.

### 7.3 Phase 2 response types

At M1 kickoff `docs/api/openapi-v1.json` documented no Phase 2 response
bodies; shapes were taken from the committed controllers. During the pre-PR
rebase the backend agent backfilled full response schemas (main @ 7498e28),
so the M2 client generates types from the document and the interim
hand-written shapes serve only as documentation of the login
success/requires_2fa split.

### 7.4 Accessibility test strategy

Two layers, per the M1 review (defect 4):
1. **jsdom (every CI run):** per-component axe checks plus the gallery
   acceptance suite rendering all 31 entries in light and dark
   (`admin/src/gallery/axe.test.tsx`), failing on serious/critical
   violations. `color-contrast` is disabled there because jsdom has no
   layout engine; `region` because fixtures lack the app shell.
2. **Real browser (`ui-real-browser` CI job):** Playwright + axe-core opens
   every gallery section in light and dark at desktop width and fails on
   serious/critical violations **including color-contrast**
   (`admin/tests/browser/gallery-axe.spec.ts`).

Findings from the first real-browser sweep (fixed in the M1-fixes PR):
- dark `--destructive` as text on the dark background measured **1.99:1** →
  new `--destructive-text` token (light 4.8:1, dark `hsl(0,95%,63%)` at
  7.0:1); all error/text/icon usages switched, the solid keeps white-text
  contrast for buttons.
- Radix ScrollArea viewport was not keyboard-focusable
  (`scrollable-region-focusable`) → `tabIndex={0}` on the viewport in the
  ScrollArea component.

### 7.6 Build base (M1 review defect 3)

`base` is `'/admin/'` for production builds and `'/'` for dev: the PHP front
controller answers every `/admin/*` path with index.html (SPA fallback), so
relative `./assets/…` URLs resolved against deep-link paths and 404'd.
`admin/tests/browser/admin-boot.spec.ts` loads `/admin/a/b/c` against the
built bundle (served by `admin/scripts/serve-dist.mjs`, which reproduces the
SPA fallback) and fails on any failed request, 4xx/5xx, page error, or asset
outside `/admin/`. The equivalent check joins the release smoke test when
the admin ships in the zip (M3).

### 7.5 Bundle budget (measured, 2026-10-18)

Vite production build of the M1 admin (router + query client + theme +
intermediate entry screen, gallery excluded):
- JS **100.1 KB gzip** (budget 200 KB — pass, 50% headroom)
- CSS 4.69 KB gzip
- Baseline committed at `admin/scripts/size-budget.json`; CI fails on >5%
  regression or any gallery/mock leak (`admin/scripts/check-size.mjs`).
Editor/schema-builder/date-range/code-editor chunks are verified as separate
lazy chunks from M2/M3 onward.

### 7.6 Deviations recorded

- Resizable panels, OTP input and the Zod resolver are hand-rolled; the
  common libraries are not on the allow-list (DEPENDENCIES.md §4.3).
- axe-core and @axe-core/playwright are MPL-2.0 (dev-only; justification in
  DEPENDENCIES.md §4.2).
- Radix ToggleGroup renders single-mode items with `role="radio"`; radio
  arrow-to-select is native-browser behaviour, so jsdom tests assert focus
  movement + Space selection (test comment in `radio-group.test.tsx`).
- Sonner keeps dismissed toasts until its exit animation completes; jsdom
  fires no animation events, so tests assert `data-removed` instead of
  unmount (`feedback.test.tsx`).

### 7.7 Deviations recorded

- react-day-picker's month/year caption dropdowns are overridden with the
  @bs/ui Select — the library's defaults are native `<select>` elements,
  which the spec bans even in library-rendered markup (defect 1; enforced by
  `admin/src/gallery/no-native-inputs.test.tsx`).

---

## 9. Milestone M2b: Typed API Client (`@bs/client`)

### 9.1 Hand-rolled Generator Decision (vs openapi-typescript)
- **Zero External Runtime/Generator Dependencies:** Hand-rolled generator in `packages/client/scripts/generate-client.mjs` directly parses `docs/api/openapi-v1.json` (frozen as `api-v1`).
- Generates 27 clean TypeScript component schemas in `src/generated/schema.ts` and 147 typed operation interfaces in `src/generated/operations.ts`.
- Avoids large generator dependencies (`openapi-typescript`, `openapi-fetch`), preserving zero-bloat monorepo posture.
- Guarded in CI via `check-generated` script (`node scripts/generate-client.mjs && git diff --exit-code -- src/generated`).

### 9.2 Envelope Discovery: `items` and `meta.cursor`
- Verified against frozen OpenAPI contract:
  - Standard list envelope is `{ items: T[], meta: { total, count, cursor } }` with cursor pagination via `cursor[after]`.
  - Not `{ data: ..., meta: { pagination: ... } }` as older initial prose assumed; spec wins.
  - Helpers `cursor.ts` provide `nextCursor`, `buildListQuery` (mapping `cursorAfter` to `cursor[after]`), and `listAll` async pagination.

### 9.3 Chunked Upload Contract
- `POST /media/chunks/init`: returns `{ session_id, expires_at }` per frozen spec (`session_id` adhered to strictly over prompt mention of `upload_id`; flagged in `BACKEND-REQUESTS.md`).
- `POST /media/chunks/{session_id}`: multipart upload with chunk binary and chunk index.
- `POST /media/chunks/{session_id}/assemble`: produces final `Media` entity.
- Handled with typed progress callback and `AbortSignal` in `packages/client/src/upload.ts`.

### 9.4 Preconditions & Retries
- Preconditions (ETag / If-Match): 428 and 409 responses return typed `{ ok: false, status: 428 | 409, kind: 'precondition', problem }` when `ifMatch` is provided. If `ifMatch` is omitted, 428 returns as a normal `ApiError` (`kind` is undefined).
- Mutation requests automatically include `X-CSRF-Token` header.
- Retries:
  - Idempotent methods (`GET`, `PUT`, `DELETE`) retry on network errors and 5xx responses with linear backoff (default 2 attempts).
  - Mutating methods (`POST`, `PATCH`) are never retried by default to prevent duplicate resource creation, unless caller passes `idempotencyKey` (sent via `Idempotency-Key` header).
  - `requestMultipart` converts network errors to `ApiResult` with `status: 0`, and chunk uploads opt-in to retry because re-uploading the same chunk index for a session is idempotent.
  - Aborted requests are never retried.
  - 4xx client errors are never retried.

---

## 10. Milestone M2b: Pickers & Gallery Integration

### 10.1 Pickers Suite (`packages/ui/src/pickers/`)
- **MediaPicker**: Folder filtering, filename search, responsive thumbnail grid (accessible keyboard listbox buttons), upload handler with progress and error state.
- **TermPicker**: Searchable hierarchical taxonomy tree with multi-select chips, "include subcategories" subtree selection toggle.
- **RelationPicker**: Debounced async search, keyboard navigation (Arrow Up/Down, Enter, Backspace chip removal), duplicate prevention.
- **UserPicker**: Searchable combobox with avatar badges, single-select clear, accessible roving focus.
- **SlugInput**: Auto-derivation from title (source) while locked, unlocks on edit, debounced uniqueness validation (`available` / `taken` / `error`), full URL preview.

### 10.2 Gallery Acceptance & Axe Coverage
- Pickers showcase added in `admin/src/gallery/sections/pickers.tsx` with dedicated mock fixtures in `admin/src/gallery/mocks/fixtures.ts`.
- Registered in `admin/src/gallery/registry.ts` and `admin/src/gallery/section-list.ts` (guarded against drift by `section-list.test.ts`).
- Full axe accessibility acceptance verified in jsdom (`admin/src/gallery/axe.test.tsx`) and real-browser Playwright sweeps across light and dark themes with zero serious/critical violations.

---

## 11. Milestone M3: Admin Shell & Authentication Screens

### 11.1 App Shell Architecture
- **Router**: Built on @tanstack/react-router with route code-splitting for auth and admin views.
- **Session Bootstrap & CSRF**: SessionProvider loads GET /api/v1/auth/session on startup, stores user profile and roles, bootstraps piClient.setCsrfToken(csrf_token).
- **Session Expiry**: ApiClient calls onUnauthorized callback on any HTTP 401 response; redirects to /admin/login?return_to=<sanitized current path>.
- **Navigation & Permission Filter**: ADMIN_SIDEBAR_GROUPS categorizes features across Content, Site, and System. Renders only items matching the user permissions or owner/admin role.
- **Top Bar**: Breadcrumbs, global search / command menu trigger shortcut, ThemeToggle, and User profile dropdown with sign-out action.
- **Resilience & Errors**: ShellErrorBoundary catches runtime failures; ForbiddenRoute (403) and NotFoundRoute (404) offer intuitive recovery paths.

### 11.2 Auth Screens Suite (dmin/src/auth/)
- **Login**: Email + password with remember-me checkbox, lockout state (HTTP 423) handling, generic error messages preventing user/email enumeration.
- **Two-Factor Challenge**: Supports 6-digit TOTP and 8-character recovery code switching. Submits single code parameter adhering to backend contract.
- **Forgot Password**: Submits email to POST /auth/password/forgot and renders generic confirmation notice.
- **Reset Password**: Validates token and email from query params, enforces 8-character password minimum and confirmation matching.
- **Accept Invitation**: Validates token on mount via GET /auth/invitations/{token}, registers name and password via POST /auth/invitations/{token}/accept.
- **Step-up Dialog**: Reusable modal for password or 2FA challenge on sensitive administrative actions (POST /auth/step-up).
- **Return-To Sanitization (sanitizeReturnTo)**: Permits only same-origin /admin/* and /oauth/authorize* paths; rejects protocol-relative URLs (//), backslashes, schemes, and control characters.

### 11.3 Performance & Bundle Budget
- Code-split chunking keeps initial JS gzipped bundle well under the 200 KB budget limit (~162.7 KB total gzipped JS, well below 200 KB).
- Zero gallery or mock code in production build (guarded by dmin/scripts/check-size.mjs).

### 11.4 Real-Browser Verification
- 27 Playwright browser tests covering boot, axe light/dark sweeps across all components and auth/shell screens, 2FA challenge, lockout handling, return-to safety, session expiry redirects, role filtering, and 360px mobile viewport stability.
