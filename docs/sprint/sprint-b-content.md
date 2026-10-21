# Sprint B: Content Engine Implementation Log

Branch: `sprint/b-content`
Target: Complete scope 1-6 in sprint pass.

## Status Overview
- [x] Initial workspace clean & `sprint/b-content` branch created from `origin/main`
- [x] Merged `feat/entry-editor` cleanly into `sprint/b-content`
- [x] Fixed baseline TypeScript type errors
- [ ] 1. Entry editor completion:
  - Media Picker integration for image insert
  - Internal links with relation search & Link dialog
  - FAQ and key-facts directive blocks
  - Callout, button, embed (click-to-load), tables, code blocks
  - Markdown paste and Markdown source toggle
  - Revisions panel (list, compare, restore)
  - Taxonomy panel (term picker with inline create)
  - Author panel
  - Featured image picker
  - Schedule date-time picker
  - Preview button (`POST /api/v1/preview/token` + starter preview URL)
  - View on site button
  - "Submit for review" for authors
- [ ] 2. Page editor:
  - Blocks field editor using `GET /api/v1/blocks`
  - Backend `GET /api/v1/blocks` endpoint returning registered block definitions & stub for `bs blocks:sync` command
  - Pages tree view in collection list with drag re-parent & keyboard "Move to..." alternative
- [ ] 3. Media library (`docs/02-ui-spec.md` 4.6):
  - Grid / list views
  - Folder tree sidebar
  - Drag-drop and paste upload with chunked uploads (per-file progress, cancel, retry)
  - Multi-select with shift-range
  - Bulk move / delete / download
  - Filters & sorting
  - Detail sheet (alt text prompt, caption, title, focal point, replace, variants, usage list with links, copy URL)
  - Media Picker modal/sheet used by editor and forms
- [ ] 4. Taxonomy manager (`docs/02-ui-spec.md` 4.5):
  - Taxonomy list/editor
  - Term tree with drag/drop and keyboard move
  - Inline add child/sibling, rename, counts
  - Merge dialog
  - Delete with reassign
  - Colour/icon/image, SEO fields
  - Flat table for tags
- [ ] 5. Navigation editor (`docs/02-ui-spec.md` 4.7):
  - Nested drag-and-drop menu items (page/post/category/custom URL)
  - Multiple menus using `navigation` singleton
- [ ] 6. Saved views, bulk actions on all lists, export, and collection list polish:
  - Trash/Restore flows
  - "Include subcategories" category filter
  - Saved views component
  - Export CSV/JSON

## Endpoints Relied On
- `POST /api/v1/preview/token` - create preview token for draft preview
- `GET /api/v1/collections/{handle}/entries/{id}/versions` - list entry revision history
- `GET /api/v1/collections/{handle}/entries/{id}/versions/{version_id}` - fetch specific version
- `POST /api/v1/collections/{handle}/entries/{id}/versions/{version_id}/restore` - restore version
- `GET /api/v1/taxonomies` & `GET /api/v1/taxonomies/{handle}/terms` - taxonomies and terms tree
- `POST /api/v1/taxonomies/{handle}/terms` - create term
- `GET /api/v1/media` & `GET /api/v1/media/folders` - media library and folder hierarchy
- `POST /api/v1/media/chunks/init`, `POST /api/v1/media/chunks/{id}`, `POST /api/v1/media/chunks/{id}/assemble` - chunked upload
- `GET /api/v1/singletons/navigation`, `PUT /api/v1/singletons/navigation` - navigation menus
- `GET /api/v1/blocks` - block registry definitions
