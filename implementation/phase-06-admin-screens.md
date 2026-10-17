# Phase 6: Admin screens (v1)

**Goal:** every v1 screen in `docs/02-ui-spec.md` section 4, built only from `@bs/ui`, each passing the Definition of Done (`docs/02-ui-spec.md` section 1) and its own checklist.
**Depends on:** Phases 4 and 5.

## 6.1 Build order and per-screen requirements
Build in this order; each screen is a separate pull request with its checklist copied into the PR description and ticked.

1. **Dashboard home** (`docs/02-ui-spec.md` 4.2): role-aware widgets; data from `GET /api/v1/dashboard` (add this aggregate endpoint: recent edits, drafts, scheduled, pending AI changes count, site-health summary, system status, onboarding state).
2. **Collection list** (4.3): DataTable with collection-specific default columns; status tabs with counts; Trash tab; posts filters incl. category with "include subcategories"; pages tree view with drag re-parent (keyboard alternative "Move to..."); bulk actions mapped to `/entries/bulk` actions; export.
3. **Entry editor** (4.4): main column with field groups/tabs from schema; right sidebar panels in this order: Publish (status, save draft, publish/update, schedule, unpublish, trash, last saved, preview, view on site), Taxonomies (term picker with inline create), Author(s), Featured image, Slug/URL, SEO panel (title/description counters, SERP preview, social preview, canonical, noindex, answer summary, structured-data preview from the API, SEO/GEO checks list), Relations, Revisions (list, compare, restore), Activity. Autosave (30 s + blur) via autosave versions; conflict handling with `If-Match` (reload / view diff / overwrite with confirm); unsaved-changes guard; keyboard save/publish; preview button (Phase 7 provides the preview URL; until then the admin-side preview via `@bs/renderer`).
4. **Post editor body**: Tiptap `article` profile per `docs/05-editor.md` section 5 checklist, every item; image insert uses Media Picker and uploads into the library; internal links use Relation search; FAQ/key facts blocks map to the document schema; Markdown paste converts client-side to the same schema; editor chunk lazy with a skeleton.
5. **Page editor**: page fields generated from schema; `blocks` field with the block editor whose block types come from the starter's block registry (`GET /api/v1/blocks` returns registered block definitions; the Astro starter publishes them at build time via `bs blocks:sync` or the schema-as-code files); text regions use the `minimal` profile.
6. **Taxonomy manager** (4.5): taxonomy list and editor (hierarchical, max depth, archive, route, attach to collections); term tree with drag/drop + keyboard move, inline add sibling/child, rename, counts, bulk delete/merge/change parent, merge dialog, delete with reassign, colour/icon/image, SEO fields; flat table for tags.
7. **Media library** (4.6): grid/list, folder tree, drag-drop and paste upload with chunked uploads and per-file progress/cancel/retry, multi-select with checkboxes and shift-range, bulk move/delete/download, filters, detail sheet (alt text prominent, caption, title, focal point picker, replace, variants, usage list, copy URL), basic crop/rotate (client-side canvas -> upload as replacement variant), blurhash placeholders.
8. **Navigation editor** (4.7) for the `navigation` singleton: nested drag-and-drop menu items (page/post/category/custom URL), multiple menus.
9. **Redirects** (4.8): DataTable, add/edit dialog, CSV import/export, loop/chain warnings.
10. **Users, roles and permissions** (4.11): users table + bulk actions, invite dialog, user detail with sessions and tokens; roles list; permission matrix editor (allow/deny/inherit cells, own-entries toggle, field-level drill-down), "Preview as role", clone; last-owner protection messaging.
11. **Settings** (4.13): General, Email (presets, test connection, send test, status, deliverability checklist, email log), Security (password policy, 2FA enforcement per role, session lengths, rate limits), API tokens (create with role/scopes/mode/expiry, copy once, revoke), Webhooks (table, create, test, deliveries with retry), Audit log (DataTable, filters, diff viewer, export), System (PHP/extension info, cron status, disk usage, version, update check placeholder until Phase 9, backups placeholder until Phase 9).
12. **Schema builder** (4.10): collection list and create wizard; field list with drag reorder; add-field dialog with type picker; per-type settings panels; validation and conditional logic builders; filterable/sortable/searchable toggles with index-impact note; taxonomy attach panel; relation builder; live form preview; JSON view with copy/export/import; **review step** showing the plan from `POST /schema/plan` (changes, SQL, risks) then apply with step-up; history with status; "managed in code" badge and drift warning.
13. **Profile** (4.14) and **error/utility pages** (4.15).
14. **Onboarding checklist** on the dashboard (first page, first post, site details, email, connect Astro (Phase 7), connect AI agent (Phase 8)).

## 6.2 Content-editor experience (role-limited dashboard)
- Verify each seeded role sees exactly what `docs/02-ui-spec.md` section 7 describes (editor, author, contributor, viewer); technical fields (slug advanced options, schema_org, raw JSON) behind an "Advanced" disclosure for roles without developer permission.
- Author: own entries only; "Submit for review" instead of Publish; editor gets an in-app notification + email (`entry_in_review`).

## 6.3 Tests
- Each screen: component tests for loading/empty/error/forbidden states; Playwright flows:
  - create a post with category + subcategory + tags, featured image, FAQ block, SEO fields; save draft; autosave; publish; view revisions; restore an old version.
  - bulk-select 30 posts across two pages using "select all matching", bulk add a tag, bulk unpublish with partial failure (one locked by permission) and see the result dialog.
  - create a "Campaigns" collection in the schema builder with a new hierarchical taxonomy, apply, add entries, filter by term including descendants.
  - media: chunked upload of a 20 MB video on a host with 8 MB upload limit; alt-text warning; delete blocked when in use.
  - editor role: cannot see Settings/Developer/AI; direct URL shows Forbidden.
  - keyboard-only run of the post creation flow.
- axe checks on every screen; visual regression snapshots (light/dark) for key screens.

## 6.4 Acceptance checklist
- [ ] Every screen in 6.1 done with its `docs/02-ui-spec.md` checklist fully ticked in its PR.
- [ ] Definition of Done (`docs/02-ui-spec.md` section 1) verified per screen.
- [ ] Playwright flows in 6.3 green on CI and run manually on a shared host.
- [ ] Usability round with 3-5 developers connecting no AI yet: each completes "create collection with taxonomy" and "write and publish a post" without help; findings fixed or logged.
