# 02. Dashboard UI/UX specification (BS CMS)

Status: draft for implementation. Product name **BS CMS**. Primary colour: **blue** (one hue variable). Language: **English only** in v1, but all user-facing text lives in a message catalog (`en.json`) so languages can be added later without code changes. No RTL work in v1.

Design reference: the Supabase design system (https://supabase.com/design-system) for structure, density, layout rules and form rules. Foundation: React + TypeScript + Tailwind + shadcn/ui (Radix). Re-implement patterns; do not copy Supabase code, logo or green without checking its licence.

## 0. How to use this document (important for the implementer)

1. Every screen and component below has a **checklist**. A screen is not done until every box in its checklist exists and works. Previous experience: simple controls (bulk select, checkboxes, empty states) get forgotten. Treat the checklists as acceptance tests.
2. Build the shared components first (section 3), then screens. Do not hand-roll a table, form, dialog or picker inside a screen; always use the shared component.
3. Every screen must implement the **Definition of Done** in section 1.
4. Where this spec says "must", it is required. "Should" is required unless a reason is written in the pull request.

## 1. Definition of Done for every screen and component

- [ ] **Loading:** skeleton that matches the final layout (no spinner over blank page, no layout shift).
- [ ] **Empty:** designed empty state with illustration/icon, one-line explanation, and the primary action button.
- [ ] **Error:** inline error state with message, retry button, and error id for support; never a blank screen.
- [ ] **Permission denied:** 403 state; actions the user cannot perform are **hidden** (not just disabled) unless seeing them helps (then disabled with a tooltip explaining why).
- [ ] **Not found:** 404 state with link back.
- [ ] **Offline / request failed:** toast + retry; optimistic updates roll back.
- [ ] **Dirty form guard:** leaving a dirty form (navigation, closing sheet or dialog, browser tab close) asks to confirm.
- [ ] **Keyboard:** all actions reachable by keyboard, visible focus ring, logical tab order, Escape closes overlays, Enter submits forms, documented shortcuts shown in tooltips.
- [ ] **Accessibility:** WCAG AA contrast, labels on every control, `aria-*` on custom components, `aria-sort` on sortable headers, `aria-live` for toasts, reduced-motion respected, screen-reader text for icon-only buttons.
- [ ] **Responsive:** works from 360 px wide up; tables scroll horizontally with sticky first column or collapse to cards; sidebar collapses to a drawer.
- [ ] **Themes:** correct in light, dark and system themes.
- [ ] **URL state:** filters, sort, page, selected tab, and open sheet are in the URL so links are shareable and refresh keeps state.
- [ ] **Feedback:** every mutation shows a toast (success) or inline error; destructive actions can be undone where possible (undo toast) or require confirmation.
- [ ] **Audit:** every mutation is written to the audit log (server side).
- [ ] **Performance:** route-level code split; no request waterfalls; list data cached with TanStack Query; no more than one skeleton flash on navigation.
- [ ] **Tests:** component test for each state above; end-to-end test for the main flow.

## 2. App shell

- [ ] Left sidebar (collapsible to icons, remembers state): logo + "BS CMS", site switcher placeholder (single site in v1), navigation groups: **Content** (Pages, Posts, other collections, Singletons), **Taxonomies** (Categories, Tags, custom), **Media**, **Site** (Navigation, Redirects, SEO and discoverability), **AI** (Connections, Pending changes), **Developer** (Schema builder, Webhooks, API tokens, Plugins), **Settings** (General, Email, Security, Users and roles, Audit log).
- [ ] Navigation is **built from the user's permissions**: items the user has no access to are not rendered. An Editor sees Content, Taxonomies (terms), Media and maybe Navigation; they do not see Developer, AI, Settings or Schema builder. Direct URL access to a hidden area shows the 403 page; the API enforces permissions independently.
- [ ] Top bar: breadcrumbs, global search / command menu trigger (Ctrl/Cmd+K), notifications bell with unread count and popover list, theme switcher (Light, Dark, System), user menu (profile, security, sign out), environment badge (`Production` red-tinted / `Staging` amber) so people never confuse sites.
- [ ] Command menu (Ctrl/Cmd+K): navigate to any screen, search entries by title across collections, create new entry in a collection, run actions ("Publish current entry", "Open site health", "Switch theme"), recent items. Results grouped, keyboard navigable.
- [ ] Global keyboard shortcuts: `?` opens shortcut cheat sheet, `g` then `p` go to Posts, `c` create, `/` focus search, Ctrl/Cmd+S save, Ctrl/Cmd+Enter publish. Shortcuts are listed in a dialog.
- [ ] Toasts (Sonner style): success, error, info, with action button (Undo, View) and auto-dismiss; errors persist until dismissed.
- [ ] Banner area for system warnings: staging site indexable?, email not configured, cron not running, PHP version/extension warnings, pending upgrade, AI changes waiting.
- [ ] First-run onboarding checklist card on the dashboard home (dismissible): create first page, write first post, set site details, configure email, connect an AI agent, connect the Astro site.

## 3. Shared components (build and document these first)

Each component needs: all states (default, hover, focus, active, disabled, loading, error), dark mode, keyboard behaviour, a gallery page with props table.

### 3.1 DataTable (the most important component)
The table is used for every list (entries, terms, media list view, users, roles, tokens, audit log, redirects, webhooks...). It must include **all** of the following. Missing items are bugs.

**Selection and bulk actions**
- [ ] First column: checkbox per row; header checkbox is select-all-on-page with **indeterminate** state when some rows are selected.
- [ ] Shift-click selects a range; Ctrl/Cmd-click toggles; clicking a row's checkbox never navigates.
- [ ] After selecting all rows on a page, a banner offers "Select all N matching results" (across pages) and "Clear selection".
- [ ] A **bulk action bar** appears (sticky at the top or bottom of the table) showing "N selected" and actions relevant to the collection: Publish, Unpublish, Move to draft, Archive, Delete (with confirmation), Add/remove category or tag, Change author, Change status, Export selected (CSV/JSON), Duplicate. Actions are permission-aware and hidden if not allowed.
- [ ] Bulk actions run as one request with a per-item result; the UI shows a progress indicator for long jobs and a summary dialog for partial failures ("12 succeeded, 2 failed: reasons") with retry for failed ones.
- [ ] Bulk delete uses a type-to-confirm dialog when more than 10 items or when items are published; soft delete shows an Undo toast.
- [ ] Selection persists while changing sort or page within the same query, and clears when filters change (with the count shown before clearing if the selection is large).

**Columns**
- [ ] Column visibility menu ("Columns") with reset; choice saved per user per collection.
- [ ] Column reorder (drag) and resize (drag handle), saved per user. Sticky first data column and sticky header.
- [ ] Cell renderers per field type: text (truncate with tooltip), status badge (colour-coded), date/datetime (relative with exact time tooltip), boolean (icon), number (aligned right), relation (chips, "+N" overflow), taxonomy terms (chips with colour), media (thumbnail), user (avatar + name), URL (link), tags.
- [ ] Row density toggle (Compact 36 px, Default 44 px, Comfortable 52 px); default Compact for developers.

**Sorting, search and filters**
- [ ] Sortable headers cycle ascending, descending, none with a visible arrow and `aria-sort`. Multi-sort with Shift-click (optional).
- [ ] Search input (debounced, clear button, `/` to focus) searching the title and searchable fields.
- [ ] **Filter bar**: add-filter button opens a field picker; filter types per field type: text contains/equals, number range, date range (calendar popover with presets: today, last 7 days, last 30 days, this month, custom), enum multi-select, boolean, relation picker, taxonomy picker with "include subcategories" toggle, status multi-select, author picker, "has no value". Active filters show as removable chips; "Clear all".
- [ ] **Saved views**: save current filters + sort + columns + density as a named view; view tabs above the table (e.g. All, Drafts, Scheduled, Mine); shared views for roles with permission.
- [ ] Quick filter tabs by status with counts: All, Published, Draft, In review, Scheduled, Archived, Trash.

**Rows**
- [ ] Row click opens the entry (or preview sheet, configurable); middle-click/Ctrl-click opens in new tab (rows are real links).
- [ ] Row action menu (kebab): Edit, Quick edit, Preview, View on site, Duplicate, Copy link, Copy ID, Publish/Unpublish, Move to trash/Restore, Delete permanently (only in Trash), View history. Permission aware.
- [ ] Inline quick edit for title, slug and status without leaving the list (sheet or inline), with validation.
- [ ] Drag handle for manual ordering when the collection has `manual_order` (disabled while sorted/filtered, with a tooltip).
- [ ] Hover shows secondary info (excerpt, last editor) where useful; keyboard navigation up/down through rows with Enter to open and Space to toggle selection.

**Footer and pagination**
- [ ] Footer shows "N of M selected", total count, rows-per-page selector (10, 25, 50, 100), page navigation (first, previous, page numbers or cursor next/previous, next, last) and "Go to page".
- [ ] Cursor-based pagination for large collections with an accurate or approximate total.

**States and extras**
- [ ] Skeleton rows while loading (same number as page size, same column widths).
- [ ] Empty states: "No entries yet" with create button; "No results match your filters" with Clear filters button.
- [ ] Error state with retry.
- [ ] Refresh button and "last updated" time; optional auto-refresh while jobs are running.
- [ ] Export: all or filtered results to CSV/JSON (queued job for large exports with a notification when ready).
- [ ] Virtualised rendering when more than about 200 rows are on screen.
- [ ] Responsive: horizontal scroll with sticky first column; on narrow screens switch to a card list that still supports selection and actions.

### 3.2 Form system
- [ ] One `FormItemLayout` for every field: label, required marker, help text, control, error message, character counter, copy button for read-only.
- [ ] Layouts: horizontal (label left) for settings pages, vertical for sheets/dialogs and the entry editor.
- [ ] Field controls (all with validation messages): text, password (show/hide), textarea (auto-grow, counter), number (steppers, units), switch, checkbox, checkbox group, radio group, radio cards, select, **combobox** (searchable, async), **multi-select** (chips, search, select all/clear), **tag input** (create on enter), **date picker**, **date range picker**, **date-time picker** (with timezone label and "now" button), time input, colour picker, slug input (auto from title until edited, lock icon, uniqueness check, shows full URL), URL, email, phone, file/image upload (drag and drop, progress, preview), media picker (opens media library sheet), rich text, markdown, JSON code editor, key/value array, single-value array, repeater, block editor, relation picker, term picker (tree with search and "create new term" inline), user picker, FAQ list.
- [ ] Dirty state: Save disabled until changed; Cancel/Reset appears when dirty; unsaved-changes guard; Save button shows loading; errors inline per field and a summary at the top for long forms (click to focus the field).
- [ ] Autosave drafts in the entry editor (every 30 s and on blur) with "Saved 10 s ago" indicator; conflict detection if another user saved newer (offer reload, view diff, or overwrite).
- [ ] Validation: client-side from the same schema as the server; server errors mapped to fields.

### 3.3 Overlays
- [ ] Dialog (confirmations, short forms), Alert dialog (destructive), **type-to-confirm dialog** (type the name to delete), Sheet/side panel (edit related items, quick edit, filters on mobile), Drawer (mobile), Popover, Dropdown menu, Context menu (right-click on rows and media), Tooltip, Hover card, Command menu.
- [ ] Focus trap, return focus on close, Escape and overlay click behaviour consistent; dirty guard on sheets/dialogs with forms.

### 3.4 Feedback and status
- [ ] Badge variants (status colours: draft grey, in review amber, scheduled blue, published green, archived slate, AI-created purple tag), Alert/Admonition (info, warning, error, success), Progress bar, Skeleton primitives, Spinner (for button-level only), Empty state component, Error state component, Status dot with tooltip, Avatar with fallback initials, Metric card.

### 3.5 Navigation and layout
- [ ] Page container with width tiers `small` (settings, forms), `default` (lists, details), `full` (editors, wide tables); breadcrumb row; section nav tabs; page header with title, description, meta and actions aside; page sections with title/description; Cards; Tabs; Accordion; Resizable panels; Scroll area; Tree view; Pagination component; Stepper (installer, onboarding).
- [ ] Action placement rules: parent pages with sub-navigation put actions on the breadcrumb row; child pages with header put actions in the header aside; tables with a filter row put actions at the right of that row.

### 3.6 Date and time
- [ ] Calendar-based date picker with month/year dropdowns, keyboard navigation, min/max, disabled dates, presets; range picker with presets; date-time picker with time input and timezone label; relative time component ("3 hours ago" with exact tooltip). No native browser date inputs.

## 4. Screens

### 4.1 Authentication screens (public)
Login (email + password, "remember this device", show/hide password, error states, lockout message), Forgot password, Reset password (strength meter, rules), Accept invitation (set name + password), Two-factor challenge (6-digit input, recovery code link), Verify email, Session expired dialog. See `docs/03-auth-and-email.md`.
- [ ] Each has loading state on submit, field-level errors, accessible labels, and does not reveal whether an email exists.

### 4.2 Dashboard home
- [ ] Greeting + environment, quick actions (New post, New page, Upload media), recent edits (table), drafts waiting, scheduled items, pending AI changes (count + list), site health score with top issues, recent AI/API activity, system status (cron, mail, disk, PHP), onboarding checklist, shortcuts card. Role-aware: editors see only content-related widgets.

### 4.3 Collection list (Pages, Posts, custom)
Uses DataTable (3.1) with every item. Extra:
- [ ] Header with collection name, count, "New" button (split button: New, Import).
- [ ] Posts list columns default: select, title (with featured thumbnail + slug), status, categories, tags, author(s), published date, updated, SEO score badge. Filters include category (with subcategories toggle), tag, author, status, date.
- [ ] Pages list: tree view option showing hierarchy with indent and drag to re-parent, plus table view.
- [ ] Trash tab with restore and delete permanently.

### 4.4 Entry editor
- [ ] Layout: full-width page, main column (fields in tabs/sections as configured by `group_key`) and right sidebar (collapsible): **Publish box** (status select, Save draft, Publish/Update, Schedule with date-time picker, Unpublish, Move to trash, last saved, "Preview" button, "View on site" link), **Taxonomies** (categories tree picker with search and "Add new category" inline incl. parent choice; tags input), **Author(s)**, **Featured image** (media picker), **Slug / URL**, **SEO panel** (title and description with live length counters and SERP preview, canonical, noindex, social card preview, answer summary, structured data preview, checklist of passed/failed SEO and GEO checks), **Relations**, **Revisions** (list, compare diff, restore), **Activity** (who changed what, including AI actions).
- [ ] Top bar: back, title (editable inline), status badge, dirty indicator, autosave status, actions, "..." menu (duplicate, copy link, delete, export JSON, view API response).
- [ ] Rich text editor: the full checklist lives in `docs/05-editor.md` (slash menu, bubble toolbar, drag handles, blocks, media, embeds, FAQ, paste cleaning, autosave, keyboard and mobile support). Posts use the `article` profile; pages and short fields use the `minimal` profile.
- [ ] Block editor for `blocks` fields: add block menu with search and categories, reorder, duplicate, collapse, delete with undo, per-block validation.
- [ ] Conditional fields, field groups/tabs, character counters, required markers, unsaved changes guard, keyboard save, conflict handling.
- [ ] Scheduling: date-time picker with timezone; scheduled badge; list of scheduled items.
- [ ] Preview: opens the Astro preview URL with a token (draft content) in a new tab or side panel.

### 4.5 Taxonomy manager
- [ ] List of taxonomies (table) with create/edit (name, key, hierarchical, max depth, archive on/off, route pattern, attach to collections).
- [ ] Term manager per taxonomy: **tree view** (hierarchical) with expand/collapse all, search, drag and drop to reorder and re-parent (with keyboard alternative: "Move to..." dialog), inline add (Enter to add sibling, Tab to add child), inline rename, entry counts, row checkboxes with bulk actions (delete, merge, change parent), flat table for non-hierarchical taxonomies, colour/icon/image per term, description, SEO fields, slug editing with redirect warning.
- [ ] Merge terms dialog (choose target; entries reassigned); delete with reassign step; blocked delete when children exist with explanation.
- [ ] Empty state with "Add your first category".
- [ ] Posts editor and list filters use the same term picker component.

### 4.6 Media library
- [ ] Grid and list toggle; folder tree sidebar (create, rename, move, delete folders); breadcrumb path; drag-and-drop upload anywhere with queue and per-file progress, cancel, retry; paste-to-upload; multi-select with checkboxes (select all, shift-range), bulk move, bulk delete (blocked if in use, with usage list), bulk download; filters (type, date, size, folder, unused only); sort; search by name/alt/caption.
- [ ] Detail sheet: preview, file info, **alt text (required prompt with "generate with AI" when enabled)**, caption, title, focal point picker, replace file, variants list, usage list ("Used in 3 entries"), copy URL, download, delete.
- [ ] Image editing basics: crop, rotate, focal point.
- [ ] Media picker (used inside forms): same library in a sheet with single or multi select, upload inline, filter by allowed mime types.
- [ ] Skeleton thumbnails with blurhash/dominant colour placeholders.

### 4.7 Navigation (menus) editor
- [ ] Tree of menu items with drag-and-drop nesting, add (page, post, category, custom URL), label, open in new tab, rel attributes, visibility; multiple menus (header, footer); preview.

### 4.8 Redirects
- [ ] Table (DataTable) with bulk select/delete, import/export CSV, add dialog (from, to, type 301/302/307/308, match type), hit counts, detection of loops and chains, auto-redirects from slug changes flagged.

### 4.9 SEO and discoverability settings
- [ ] Sections: Site identity (name, description, logo, organization details), Defaults (title template, default social image, robots meta defaults), **Environment** (production/staging with big warning), **Search engines** (sitemap status, IndexNow key + status, verification codes), **AI crawlers and agents** (policy preset cards Balanced/Open/Private/Custom, per-bot table with allow/deny and category labels, preview of generated robots.txt, diff against current, version history with rollback, "Test with a URL", export/import policy JSON), **AI access** (llms.txt on/off, markdown views on/off, public read-only MCP on/off), **Structured data** (organization, defaults per collection mapping), **Site health** (run lint, list issues with fix links).
- [ ] Changes that could block search engines require a type-to-confirm dialog. Details in `docs/04-crawlers-and-discoverability.md`.

### 4.10 Schema builder (Developer)
- [ ] Collection list + create wizard (name, key, singular/plural, icon, public pages yes/no, route pattern, features toggles: drafts, versions, scheduling, SEO, hierarchy, manual order), field list with drag-to-reorder, add-field dialog with field-type picker (icons + descriptions), field settings panel per type, validation rules builder, conditional logic builder, filterable/sortable/searchable toggles with index impact note, taxonomy attach panel ("Add taxonomy": choose existing or create new), relation builder (target collection, cardinality, inverse field), live preview of the resulting editor form, JSON/YAML view of the schema (copy/export) and "Import schema".
- [ ] Review step before applying: diff of changes, SQL impact, dry-run result, warnings (data loss, long-running), then Apply; history of schema changes with rollback where possible.
- [ ] Safe-by-default: destructive actions hidden/purge requires type-to-confirm.

### 4.11 Users, roles and permissions
- [ ] Users table (DataTable with bulk actions: change role, suspend, resend invite, delete), invite dialog (email, role, message), user detail (profile, roles, sessions with revoke, API tokens, 2FA status, activity).
- [ ] Roles list; role editor with **permission matrix**: rows = collections/taxonomies/media/areas, columns = actions (read, create, update, delete, publish...), cell states allow/deny/inherit; row-level "own entries only" toggle; field-level drill-down; "Preview as role" mode; presets; clone role.
- [ ] Prevent lockout: cannot remove the last owner; warnings when editing own role.

### 4.12 AI connections
- [ ] Connected clients table (client name, user, role, mode, last used, revoke), "Connect an agent" guide (copy MCP URL, per-client instructions), grant review (scopes and mode), activity feed, **Pending changes** queue (DataTable with bulk approve/reject, diff viewer side by side, approve/reject with note, expiry), AI mode explanations (draft only, approval required, full), kill switch ("Disconnect all AI access").

### 4.13 Settings
- General (site details, timezone, date format), Email (see doc 03), Security (password policy, 2FA enforcement, session length, login rate limits, allowed domains), API tokens (create with scopes/expiry, copy once, revoke), Webhooks (table, create, test delivery, delivery log with retry), Plugins (installed list, enable/disable, settings), Audit log (DataTable with filters by actor/action/date, diff viewer, export), System (PHP info, extensions, cron status, disk, version, update check, backup/export).

### 4.14 Profile
- Name, avatar, email change (verification), password change, 2FA setup (QR code + manual key + recovery codes), sessions list with revoke, notification preferences, theme.

### 4.15 Error and utility pages
- 403, 404, 500, maintenance, "browser not supported", offline.

## 5. Visual design rules

- Tokens (CSS variables): `--primary-hue` set to blue (e.g. ~250 in HSL terms; final value chosen in the design spike), semantic colours (background, surface-100/200/300, overlay, border-muted/default/strong, text, text-muted, destructive, warning, success, info), radius scale, spacing scale (4 px base), shadow scale, focus ring, z-index scale.
- Typography: system UI font stack plus a mono font for code/IDs; compact scale (12/13/14/16/20/24), tabular numbers in tables. Final font choice in the design spike (a free font such as Geist or Inter, licence checked).
- Density: compact by default; 44 px default rows, 36 px headers as a starting point from the Supabase data-grid demo.
- Icons: one set (Lucide), 16 px in dense UI.
- Motion: 120-200 ms, ease-out, disabled under reduced motion.
- Dark mode first-class: every token has a dark value; never hard-code colours.

## 6. Performance rules for the dashboard

- App shell under about 200 KB gzipped JS (target); lazy-load rich text editor, block editor, schema builder, charts, date pickers, JSON editor.
- TanStack Query for server state, stale-while-revalidate, prefetch on link hover, optimistic updates for status changes and reordering.
- Virtualise long lists; cursor pagination; debounce search (250 ms) and cancel in-flight requests.
- Skeletons equal final size to avoid layout shift. Images lazy with blurhash placeholders.

## 7. Content editor role (limited dashboard)

The same dashboard, with permissions deciding what is visible.
- Editor: Content (all collections or selected), Taxonomies terms, Media, Navigation optional. Hidden: Developer, AI, Settings, Schema, Users, Redirects (optional), Plugins, Webhooks, Audit log.
- Author: only own entries; cannot publish (submits for review) unless allowed.
- Contributor: create drafts only.
- Viewer: read only.
- Simplified editor experience: hide technical fields (slug advanced options, schema_org, raw JSON) behind "Advanced" for non-developers; field-level permissions can hide SEO panel parts.
- A later optional separate simplified editor app can reuse the same components and API.

## 8. Component inventory checklist (implementer sign-off)

Atoms: Accordion, Alert, Alert Dialog, Avatar, Badge, Button (variants, loading, icon-only, split), Calendar, Card, Checkbox, Combobox, Command, Context Menu, Date Picker, Date Range Picker, Dialog, Drawer, Dropdown Menu, Form, Hover Card, Input, Input OTP, Label, Multi Select, Pagination, Popover, Progress, Radio Group, Resizable, Scroll Area, Select, Separator, Sheet, Sidebar, Skeleton, Slider, Sonner toasts, Switch, Table, Tabs, Tag Input, Textarea, Time Input, Toggle, Toggle Group, Tooltip, Tree View.
Fragments: Page Container/Header/Nav/Section, Breadcrumbs, Filter Bar, Bulk Action Bar, Confirmation Modal, Text Confirm Dialog, Data Input, Empty State, Error State, Metric Card, Status Badge, Diff Viewer, Activity Feed, Media Picker, Term Picker, Relation Picker, User Picker, Slug Input, SEO Panel, SERP Preview, Block Editor, Rich Text Editor, Code Editor, Permission Matrix, Schema Field Picker, Shortcut Cheat Sheet, Notification Popover.
