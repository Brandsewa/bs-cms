# Contributing to BS CMS UI (`@bs/ui` and Admin Shell)

This document establishes the UI component engineering standards, styling discipline, accessibility guidelines, and cross-cutting patterns across BS CMS.

---

## 1. Minimal Complexity & YAGNI Guardrail (Ponytail)
- Always implement the minimum necessary abstraction that satisfies correctness, accessibility, and security.
- Reuse existing primitives and tokens from `@bs/ui` before adding new dependencies or custom components.
- Do not introduce redundant UI libraries, icon packs, or heavy layout engines.

---

## 2. Token Discipline & No Hard-Coded Styling
- **No hard-coded colors**: Never hard-code hex values, RGB values, or ad-hoc Tailwind colors (e.g. `bg-zinc-800`, `text-[#123456]`). Always use semantic tokens:
  - Backgrounds: `bg-background`, `bg-card`, `bg-surface-100`, `bg-surface-200`, `bg-surface-300`, `bg-popover`.
  - Foreground / Text: `text-foreground`, `text-foreground-light`, `text-foreground-lighter`, `text-foreground-muted`, `text-muted-foreground`.
  - Borders: `border-border`, `border-border-muted`, `border-border-strong`.
  - Accents: `bg-primary`, `text-primary`, `bg-secondary`, `bg-accent`, `border-destructive`.
- **No hard-coded control dimensions**: Use standardized control sizing classes (`h-7`, `h-8`, `h-9`) matching Studio compact densities (28px - 36px).
- **Layered surfaces**:
  - Base canvas / Sidebar: `hsl(0, 0%, 9%)` (`rgb(23, 23, 23)`).
  - Raised cards / tables: `hsl(0, 0%, 12%)` (`rgb(31, 31, 31)`).
  - Hover / active rows: `hsl(0, 0%, 15%)` (`rgb(38, 38, 38)`).
  - Control insets / dropdowns: `hsl(0, 0%, 19%)` (`rgb(48, 48, 48)`).

---

## 3. Charset & Safe Glyph Handling (Hostinger / OpenLiteSpeed)
- OpenLiteSpeed serves `.js` files without an explicit `charset=utf-8` header.
- **Never include raw unicode glyphs** (such as `⌘`, `✓`, `→`, `•`) directly in `.ts` / `.tsx` files.
- Always use explicit JavaScript Unicode escape sequences:
  - Command key: `'\u2318'`
  - Checkmark: `'\u2713'`
  - Bullet: `'\u2022'`
  - Right arrow: `'\u2192'`

---

## 4. Global Hotkeys Layer
- All global shortcuts must route through the unified `useHotkeys` hook (`packages/ui/src/hooks/use-hotkeys.ts`).
- Single top-level event listener; automatically suppresses triggers when typing in `input`, `textarea`, `select`, or `contenteditable` elements unless explicitly allowed.
- Supported patterns:
  - Modifier combos: `'ctrl+k'`, `'ctrl+s'`, `'ctrl+shift+p'`.
  - Two-key sequences: `'g d'` (Dashboard), `'g e'` (Entries), `'g m'` (Media).
- Dispatches custom event `bs:save-draft` on `Ctrl+S` so active editors can save without coupling.

---

## 5. Global Feedback & Error Standards (RFC 9457)
- Use `problemToast(error)` (`packages/ui/src/feedback/problem-toast.ts`) to handle API error responses.
- Automatically parses RFC 9457 Problem Details (`title`, `detail`, `errors`, and `request_id`).
- When `request_id` is present, renders an actionable "Copy Request ID" button for user diagnostics.
- Global system alerts and banners render inside `ShellBannerArea` above the workspace (`admin/src/shell/banner-area.tsx`).

---

## 6. Page States & Error Boundaries
- Every screen must support the standardized states from `@bs/ui`:
  - `EmptyState`: Decorative icon, title, description, and primary action.
  - `ErrorState`: Friendly explanation with retry handler and request id copy button.
  - `ForbiddenState`: Permission denial fallback with back / return home options.
  - Skeletons: Component-specific loading skeletons that match the final page structure (avoid generic central spinners).

---

## 7. Accessibility & Theming Guardrails
- **Skip link**: Admin layout includes `<a href="#main-content">Skip to main content</a>`.
- **Keyboard navigation**: Visible focus rings (`focus-visible:ring-2 focus-visible:ring-ring`).
- **Screen readers**: Dynamic states (e.g. sidebar collapse/expand) must announce changes via polite `aria-live` containers.
- **Theme switching**:
  - Persisted in `localStorage` under `bs-theme` (`'system' | 'light' | 'dark'`).
  - Follows OS `prefers-color-scheme` by default.
  - Pre-paint theme bootstrap script (`admin/public/theme-init.js`) prevents theme flashes.
