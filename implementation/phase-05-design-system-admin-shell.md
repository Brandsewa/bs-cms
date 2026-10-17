# Phase 5: Design system (`@bs/ui`) and admin shell

**Goal:** a complete, documented component library and the admin application shell, so every screen in Phase 6 is assembled from shared, fully featured components. This is where previous attempts failed (missing bulk select, checkboxes, states), so components are built and accepted **before** any screen.
**Depends on:** Phase 3 API contract (can start in parallel with Phase 4). **Spec:** `docs/02-ui-spec.md` (all of it), tokens from Phase 0 Spike E.

## 5.1 Packages and setup
- `packages/ui` (`@bs/ui`): React + TypeScript + Tailwind; shadcn/ui components generated into the package and adapted; exports atoms, fragments, hooks, tokens CSS. Built with Vite library mode; tree-shakable ESM; no runtime CSS-in-JS.
- `packages/client` (`@bs/client`): typed API client generated from `docs/api/openapi-v1.json` (codegen script in `tools/`), plus TanStack Query hooks (`useEntries`, `useEntry`, `useMutationOperation` ...), problem-details error type, CSRF handling, `If-Match` versioning, retry rules (no retry on 4xx).
- `admin/`: Vite React app using TanStack Router (file-based routes, route-level code splitting, loaders that prefetch via Query), served from `/admin/` with hashed assets.
- Gallery: `admin` route `/admin/_gallery` (only in development builds) or Storybook-free in-repo gallery listing every component with all states, light/dark.

## 5.2 Tokens and theming
- CSS variables per `docs/02-ui-spec.md` section 5: blue `--primary-hue`, primary ink/solid/bright, surfaces 100-300, overlay, borders muted/default/strong, text/muted, destructive/warning/success/info scales, radius, spacing (4 px), shadows, focus ring, z-index, motion.
- Light, dark, system; theme stored per user (API) with localStorage cache; no flash of wrong theme (inline script in `index.html` allowed only as a hashed CSP script).
- Typography and density per Phase 0 decisions; tabular numbers in tables.

## 5.3 Components to build (each with all states, keyboard, a11y, dark mode, tests, gallery entry)
Everything in `docs/02-ui-spec.md` section 8 (atoms + fragments) and section 3, in this order:
1. Primitives: Button (variants, sizes, loading, icon-only with label, split button), Input, Textarea (auto-grow, counter), Label, Checkbox (with indeterminate), Switch, Radio Group / Radio Cards, Select, Combobox (async, search, create option), Multi Select (chips, select all, clear), Tag Input, Slider, Toggle, Toggle Group, Badge, Avatar, Tooltip, Popover, Hover Card, Dropdown Menu, Context Menu, Dialog, Alert Dialog, Sheet, Drawer, Tabs, Accordion, Separator, Scroll Area, Resizable, Progress, Skeleton, Spinner, Sonner toasts, Kbd.
2. Date/time: Calendar, Date Picker, Date Range Picker (presets), Date-Time Picker (timezone label, "now"), Time Input, Relative Time.
3. Form system: `Form`, `FormItemLayout` (horizontal/vertical), field error, form-level error summary, dirty-state hook (`useDirtyGuard` for route leave, sheet/dialog close and tab close), submit button with loading, field arrays (single value, key/value), conditional fields.
4. Layout: App sidebar (collapsible, permission-filtered items), Top bar, Breadcrumbs, PageContainer (small/default/full), PageHeader (title, description, meta, actions), PageNav tabs, PageSection, Card, Empty State, Error State, Forbidden State, Not Found State, Banner area, Metric Card, Status Badge, Admonition, Stepper.
5. **DataTable** with every item in `docs/02-ui-spec.md` section 3.1 (selection + indeterminate header, shift-range, select all matching, sticky bulk action bar with progress and partial-failure dialog, column visibility/reorder/resize persisted, cell renderers per field type, density toggle, sortable headers with `aria-sort`, debounced search, Filter Bar with per-type filters and chips, saved views tabs, status quick tabs with counts, row links + middle-click, row action menu, inline quick edit, drag reorder mode, footer with selection count/total/page size/page nav/go-to-page, cursor and page modes, skeleton rows, both empty states, error state, refresh + last updated, export, virtualisation over ~200 rows, responsive card mode, keyboard row navigation). URL-synced state via router search params.
6. Pickers: Media Picker (sheet with library, upload, filter by mime), Term Picker (tree, search, inline create with parent), Relation Picker (async search across a collection, chips, reorder), User Picker, Slug Input (auto from source, lock, uniqueness check, full URL preview).
7. Feedback: Confirmation Modal, Type-to-Confirm Dialog, Undo toast helper, Diff Viewer (field-level and block-level), Activity Feed, Notification Popover, Shortcut Cheat Sheet, Command Menu (cmdk).
8. Editor wrappers (lazy chunk): Rich text editor with `article` and `minimal` profiles per `docs/05-editor.md` sections 5-6 (Tiptap); Code/JSON editor (lightweight, lazy); Block editor for `blocks` fields.

## 5.4 Admin shell
- Login, 2FA challenge, forgot/reset, accept invitation, verify email screens (Phase 2 API).
- Session bootstrap: `GET /auth/session` -> user, CSRF token, permissions map; 401 anywhere -> session-expired dialog with re-login that keeps unsaved work.
- Sidebar navigation from permissions (`docs/02-ui-spec.md` section 2): hidden when not permitted; direct URL -> Forbidden state.
- Top bar: breadcrumbs, command menu (Ctrl/Cmd+K) with navigation, entry search across collections, create actions, recent items; notifications popover; theme switcher; user menu; environment badge (Production / Staging).
- Global shortcuts and cheat sheet (`?`).
- System banners: email not configured, cron not running (no job for 15 min), staging indexable warning, PHP/extension warnings, pending AI changes (Phase 8), failed deployments (Phase 7).
- Job tick: while the admin is open, `POST /api/v1/system/tick` every 60 s (Phase 1 lazy-runner fallback).
- Error boundary per route with Error State and request id; offline detection.

## 5.5 Performance rules
- App shell (router, query client, layout, sidebar, top bar, auth screens) < 200 KB gzipped JS; everything else lazy per route; editor, schema builder, date range picker, code editor, charts are separate chunks loaded on demand; `vite build --report` checked in CI with a size-limit test that fails on regression > 5%.
- Prefetch route chunks and data on link hover/focus; TanStack Query stale-while-revalidate; optimistic updates for status changes, reorder, term moves.
- No layout shift: skeletons sized like content; images with width/height and blurhash placeholders.

## 5.6 Tests
- Unit/component tests (Vitest + Testing Library) for each component's states and keyboard behaviour; axe accessibility checks on every gallery story in CI.
- DataTable acceptance test suite: one test per checklist item in `docs/02-ui-spec.md` section 3.1 (selection, indeterminate, shift-range, select-all-matching, bulk bar actions and partial failure dialog, sorting with aria-sort, filters per type, saved views, pagination, density, column persistence, empty/error/loading states, keyboard navigation, responsive card mode).
- Playwright smoke: login -> dashboard -> command menu -> logout; session expiry dialog; forbidden page for an editor visiting Settings.

## 5.7 Acceptance checklist
- [ ] Every component in `docs/02-ui-spec.md` section 8 exists in the gallery with all states in light and dark.
- [ ] DataTable acceptance suite green (every item of section 3.1).
- [ ] axe: zero serious/critical violations in the gallery.
- [ ] Admin shell works against the Phase 2-4 API on a shared host; editors see only permitted navigation.
- [ ] Bundle budget met and recorded.
