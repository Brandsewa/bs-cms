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
- **Return-To Sanitization (sanitizeReturnTo) & OAuth Flow**: Accepts both same-origin absolute URLs (as currently emitted by the backend: https://<origin>/oauth/authorize?...) and relative paths. Verifies url.origin === window.location.origin, forbids credentials (user:pass@), control characters (raw and %0d%0a), backslashes, percent-encoded traversal tokens (%2e%2e, %2f), and restricts paths to /admin/* and /oauth/authorize*. Navigates to /oauth/authorize via window.location.assign for full-page server rendering.

### 11.3 Performance & Bundle Budget
- Code-split chunking keeps initial JS gzipped bundle well under the 200 KB budget limit (~162.7 KB total gzipped JS, well below 200 KB).
- Zero gallery or mock code in production build (guarded by dmin/scripts/check-size.mjs).

### 11.4 Real-Browser Verification
- 28 Playwright browser tests covering boot, axe light/dark sweeps across all components and auth/shell screens, 2FA challenge, lockout handling, return-to safety with same-origin absolute OAuth consent URLs, session expiry redirects, role filtering, and 360px mobile viewport stability.

---

## 12. Phase 6: Milestone 6.1 — Dashboard Home Screen (v1)

### 12.1 Overview & Architecture (admin/src/dashboard/)
- **Route Integration**: Replaced placeholder stub with code-split DashboardRoute in admin/src/dashboard/dashboard-route.tsx.
- **Top Greeting & Environment**: Displays personalized greeting based on time of day, active user display name, and environment badge (Production, Staging, Local Development).
- **Quick Actions Widget (QuickActionsWidget)**:
  - Contextual create triggers for "New post", "New page", "Upload media".
  - Role-aware: Page creation is hidden for authors and contributors who lack page editing permissions.
- **Onboarding Card (OnboardingCard)**:
  - Dismissible setup checklist with progress bar tracking completed steps (Site title & tagline, First post, Navigation menu, Media asset, Social links, Live preview).
  - Persists dismissed state via localStorage key bs-dashboard-onboarding-dismissed.
- **Content Queue & Recent Edits**:
  - RecentEditsWidget: Shows recently modified entries with status badges, updated timestamps, author info, and direct editor navigation.
  - ContentQueueWidget: Segregates drafts awaiting review, scheduled publications with release badges, and pending AI changes awaiting review.
- **System & Site Health Widget (SystemAndHealthWidget)**:
  - Displays site health status (SEO audit score, cron status, background mail delivery, storage usage, PHP runtime telemetry).
  - Recent audit/operations activity log.
  - Visible only to users with administrative roles (Owner, Admin).
- **Keyboard Shortcuts Card (ShortcutsCard)**: Quick reference for global hotkeys (Ctrl+K, Ctrl+S, Ctrl+Shift+P, GD, GE, GM).

### 12.2 Backend Contract & Fallback Strategy
- Documented GET /api/v1/dashboard in docs/ui/BACKEND-REQUESTS.md Item #8 with full JSON response specification.
- Production route fetches from apiClient if available; gracefully falls back to mock fixture in development/gallery mode or when the endpoint returns 404/not yet implemented.

### 12.3 Contrast & Accessibility Fixes
- Added --success-text token (hsl(150, 85%, 25%) in light mode for 5.7:1 contrast on white cards; hsl(142, 71%, 45%) in dark mode for 8.6:1 contrast) resolving real-browser Axe failure in MetricCard trends.
- Responsive design uses min-w-0 container bounds to maintain layout stability down to 360px mobile viewports without horizontal overflow.

### 12.4 Performance & Size Budget
- Production JS total gzipped: 171.0 KB (comfortably within the 200 KB ceiling).
- Baseline updated in admin/scripts/size-budget.json (175,106 bytes).

### 12.5 Test Verification
- 6 Playwright browser tests in admin/tests/browser/dashboard.spec.ts:
  - Full widget rendering and metric cards check
  - Quick action role-awareness
  - Dismissible onboarding checklist
  - Author vs editor vs admin role widget permissions
  - Zero serious/critical Axe accessibility violations across light and dark modes
  - 360px mobile viewport horizontal overflow check
- All 34 browser tests passing cleanly.

---

## 13. Supabase Alignment & Studio Architecture (Phase 5 Refinement)

### 13.1 Background & Source of Truth
The admin UI has been aligned to match the Supabase Studio design system (`https://supabase.com/design-system` and open-source repo `github.com/supabase/supabase`).
Sources analyzed and adopted directly:
- `apps/design-system/content/docs/typography.mdx` & `packages/ui/src/lib/constants.ts` (Type scale, sizing constants)
- `apps/design-system/content/docs/color-usage.mdx`, `tailwind-classes.mdx`, `theming.mdx`
- `packages/ui/build/css/source/semantic.css` & `packages/ui/build/css/themes/dark.css`, `light.css` (Layered surfaces, foreground levels, neutral scales)
- `apps/design-system/content/docs/icons.mdx` (Lucide 16px default stroke 1.5, optically centered)
- `apps/design-system/content/docs/ui-patterns/layout.mdx`, `tables.mdx` (Breadcrumbs, PageNav, sticky dense tables)
- `apps/design-system/content/docs/fragments/*.mdx` (PageHeader, PageContainer, PageSection, PageNav, PageBreadcrumbs, MetricCard, EmptyStatePresentational, FilterBar)
- `packages/ui/src/components/shadcn/ui/sidebar.tsx` (Studio sidebar rail: ~56px collapsed icon rail, ~224px expanded, section labels, active item styling, tooltips)

### 13.2 Exact Adopted Token Values & Semantics

| Token Dimension | Supabase Studio Value Adopted | Source Reference |
|---|---|---|
| **Font Stack** | `ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif` | Self-hosted system stack, CSP-compliant |
| **Type Scale** | Default body: `text-sm` (13px / 14px), labels & meta: `text-xs` (12px), page title: `text-base` (16px, semibold), section titles: `text-sm` / `text-13` | `packages/ui/src/lib/constants.ts` (`SIZE.text`) |
| **Control Heights** | `tiny: 26-28px`, `small: 32px`, `medium: 36px` (Studio controls are tight: buttons, inputs, selects default to 28-32px) | `packages/ui/src/lib/constants.ts` (`SIZE.height`) |
| **Table Rows** | Dense table rows ~36px (`h-9`), sticky table headers (`bg-surface-200` / `bg-200`) with 12px uppercase meta | `apps/design-system/content/docs/ui-patterns/tables.mdx` |
| **Icon Sizing** | Default 16px (`size={16}` or `h-4 w-4`) with `strokeWidth={1.5}` | `apps/design-system/content/docs/icons.mdx` |
| **Corner Radius** | Base `rounded-md` ~6px (`--radius-md: 6px`, `--radius-sm: 4px`, `--radius-lg: 8px`) | `packages/ui/src/lib/raised-control-surface.ts` |
| **Brand Hue** | Blue `221` (preserving Brand Sewa identity while adopting Supabase's neutral ramp and semantics) | `packages/ui/src/tokens.css` |
| **Dark Neutrals** | Neutral near-black gray scale (`--background: hsl(0, 0%, 8.5%)` / `#161616`, `--surface-100: hsl(0, 0%, 11%)` / `#1c1c1c`, `--surface-200: hsl(0, 0%, 14%)`, `--surface-300: hsl(0, 0%, 17%)`) instead of navy | `packages/ui/build/css/themes/dark.css` |
| **Light Neutrals** | Off-white surfaces (`--background: hsl(0, 0%, 99%)`, `--surface-100: hsl(0, 0%, 97%)`, `--surface-200: hsl(0, 0%, 94%)`) | `packages/ui/build/css/themes/light.css` |
| **Foreground Levels** | `foreground` (default text ~95% L), `foreground-light` (light text), `foreground-lighter` (secondary text ~65-70% L), `foreground-muted` (muted text) | `apps/design-system/content/docs/tailwind-classes.mdx` |
| **Borders** | Subtle 1px borders (`--border: hsla(0, 0%, 100%, 0.08)` in dark mode, `hsl(0, 0%, 88%)` in light mode; `--border-strong: hsla(0, 0%, 100%, 0.14)`) | `packages/ui/build/css/source/semantic.css` |
| **Focus Ring** | Accessible brand-blue outline (`outline: 2px solid var(--ring); outline-offset: 2px`) | WCAG 2.2 AA compliant |

### 13.3 Attribution Notice
In accordance with Supabase's Apache-2.0 / MIT licensing, no proprietary logos, SVG trademarks, or assets are copied. Structural CSS variables, semantic layouts, and fragment hierarchy patterns are referenced and noted in `DEPENDENCIES.md`.

### 13.4 Surface Token Hierarchy & OpenLiteSpeed Charset Notice (PR 2)

#### Layered Surface Hierarchy & Separation
Supabase Studio utilizes a layered neutral surface architecture rather than flat, single-shade dark backgrounds:
- **Base Canvas (App Background)**: `hsl(0, 0%, 9%)` (`#171717` / `rgb(23, 23, 23)`)
- **App Sidebar Rail**: `hsl(0, 0%, 9%)` (`#171717` / `rgb(23, 23, 23)`)
- **Raised Panel / Cards / Tables (`--surface-100` / `--card`)**: `hsl(0, 0%, 12%)` (`#1e1e1e` / `rgb(31, 31, 31)`)
- **Active Rows / Hover States (`--surface-200`)**: `hsl(0, 0%, 15%)` (`#262626` / `rgb(38, 38, 38)`)
- **Control Insets & Popovers (`--surface-300`)**: `hsl(0, 0%, 19%)` (`#303030` / `rgb(48, 48, 48)`)
- **1px Border Separation (`--border`)**: `hsl(0, 0%, 15%)` (`#262626` / `rgb(38, 38, 38)`)

In dark mode, the dashboard content and workspace panels appear slightly raised (`rgb(31, 31, 31)`) compared to the app/sidebar base (`rgb(23, 23, 23)`). These distinct surface layers are cleanly delineated by the subtle 1px border (`rgb(38, 38, 38)`).

#### OpenLiteSpeed Charset Handling Notice
Under Hostinger / OpenLiteSpeed, static JavaScript assets are served as `Content-Type: text/javascript` without an explicit `charset` parameter, bypassing Apache `.htaccess` directives like `AddCharset` or `AddDefaultCharset`. 
Therefore, **all non-ASCII glyphs (e.g., keyboard shortcuts like `\u2318` for cmd key, special arrows, and math symbols) must always be Unicode-escaped in JavaScript/TypeScript source files** to prevent client decoding mojibake.

### 13.5 Cross-Cutting UI Polish Pass (M4 Polish)
The following cross-cutting refinements have been integrated:
1. **Unified Hotkeys Hook (`useHotkeys`)**:
   - Single top-level `keydown` listener in `@bs/ui`.
   - Handles single hotkeys (`Ctrl+K`, `Ctrl+S`) and 2-key sequences (`G` then `D`/`E`/`M`).
   - Suppresses execution during input/textarea typing.
2. **Command Menu Palette (`CommandMenu`)**:
   - Built with the compact `cmdk` atom and Radix `Dialog`.
   - Quick navigation routes mapped from permissions and sidebar groups.
   - Quick create actions ("New Post", "New Page", "Upload Media").
   - Integrated theme switching (Light / Dark / System) with active status indicators.
3. **Problem Details Toast Helper (`problemToast`)**:
   - Translates RFC 9457 JSON errors into user-friendly toasts.
   - Automatically provides a "Copy Request ID" action button whenever `request_id` is present.
4. **Global System Banner Area (`ShellBannerArea`)**:
   - Displays real-time operational alerts (pending AI approvals, maintenance mode, deployment failures).
5. **Shell Accessibility & Responsive Drawer**:
   - Accessible "Skip to main content" link for keyboard users.
   - Mobile sidebar navigation drawer (`<Sheet side="left">`) for viewports below 768px.
   - Accessible screen reader state announcements on sidebar expand/collapse.

