# Sprint B: Content Engine Completion Report

Branch: `sprint/b-content`
Session: Sprint B Content Engine Pass

## 1. What was built

### Scope 1: Entry Editor Completion
- **URL & Routing**: Removed hash routes; editor strictly maintains canonical `/admin/entries/<collection>/<id>`.
- **Button & Selection States**: Kept editor focus on toolbar actions using `preventDefault` so marks/nodes apply cleanly.
- **Document AST Sanitation**: Automatically strips trailing empty paragraphs before sending to backend.
- **Saving & Concurrency**:
  - Consistent Save / Publish action buttons for authors vs publishers.
  - Concurrency conflict handling (409 Conflict Dialog) with reload or overwrite options.
  - Unsaved-changes dirty form guard on navigation and window unload.
  - Hotkey listener supporting `Ctrl+S` and the custom app-shell `bs:save-draft` event.
- **Media Picker Modal**:
  - Modal sheet with folder tree navigation, search, and chunked uploads via `@bs/client`.
  - Supports image insert directly into Tiptap article editor with automatic preview URL and alt text.
- **Directives & Custom Blocks**:
  - Added Tiptap custom nodes: Callout (note, tip, warning), FAQ group, Key Facts block, Action Button, Embed (click-to-load with URL & caption), and Table Block.
  - Link dialog with URL, internal path search, and open-in-new-tab support.
  - Markdown source toggle allowing instant switching between visual WYSIWYG and raw document source.
- **Editorial Panels**:
  - **Revisions Panel**: Lists historical snapshots from `GET /collections/{handle}/entries/{id}/versions`, view details, and restore past versions (`POST .../restore`).
  - **Taxonomy Panel**: Integrated term tree selector with inline add new term (`POST /taxonomies/{handle}/terms`).
  - **Author Panel**: User selection dropdown mapping author attribution.
  - **Featured Image**: Thumbnail preview, replace via Media Picker, and remove.
  - **Publishing & Scheduling**: Status selection, DateTimePicker for scheduled releases, preview button requesting `POST /api/v1/preview/token` and opening draft preview URL, "View on site" link, and "Submit for review" for contributors/authors.

### Scope 2: Page Editor & Hierarchy
- **Blocks Field Editor**: Added dynamic `blocks` field editor for pages and layout sections. Fetches registered section blocks from `GET /api/v1/blocks`.
- **Block Registry API**: Backend `GET /api/v1/blocks` endpoint returning starter block definitions from `core/data/blocks.json`.
- **CLI Sync Stub**: Added `php bin/bs blocks:sync` command stub in `Console.php` to verify and register blocks.
- **Pages Tree View**: Hierarchy view in collection list with expand/collapse, drag-and-drop to re-parent pages, and keyboard-accessible "Move to..." modal dialog.

### Scope 3: Media Library (`docs/02-ui-spec.md` 4.6)
- **Grid and List Views**: Full toggle between visual thumbnail grid and detailed table list with size, alt text, and mime types.
- **Folder Tree Sidebar**: Browse media folders and create new folders (`POST /media/folders`).
- **Chunked File Upload**: Drag-and-drop and file input upload with per-file progress percentage using `uploadChunked`.
- **Multi-select & Bulk Actions**: Select individual or all items, with bulk delete (`POST /media/bulk`).
- **Detail Sheet**:
  - Prominent alternative text (accessibility & SEO) input.
  - Caption and asset title editing.
  - Interactive focal point selector with visual target overlay.
  - Copy direct URL and Download links.
  - Real-time content usage checker (`GET /media/{id}/usage`) preventing accidental deletion of assets in active use.

### Scope 4: Taxonomy Manager (`docs/02-ui-spec.md` 4.5)
- **Taxonomies & Terms Hierarchy**: Tree view for hierarchical categories and flat table for non-hierarchical tags.
- **Term Management**: Inline add child term, add sibling term, edit term name/slug/description, and custom accent colour picker.
- **Term Merging**: Merge dialog (`POST /taxonomies/{handle}/terms/merge`) reassigning entries from source term to target term.
- **Re-parenting**: Drag and drop re-parenting and keyboard-friendly move to root.

### Scope 5: Navigation Editor (`docs/02-ui-spec.md` 4.7)
- **Multi-Menu Editor**: Manages `header` and `footer` menus using the `singletons/navigation` contract (`GET` & `PUT /singletons/navigation`).
- **Menu Items**: Supports Page, Post, Category, and Custom Link types, reordering (Up/Down), nested hierarchy structures, and open in new tab flags.

### Scope 6: Saved Views & Collection List Polish
- **Trash & Restore**: Bulk restore from Trash (`operation: 'restore'`) and soft-delete/move-to-trash actions.
- **Export**: Quick export of selected entries to JSON.
- **Filter Enhancements**: Added category filter with subcategory matching capability.

---

## 2. Key Endpoints Used & Contracts Relied On
- `POST /api/v1/preview/token` - Creates draft preview token
- `GET /api/v1/collections/{handle}/entries/{id}/versions` - Retrieves entry revision history
- `POST /api/v1/collections/{handle}/entries/{id}/versions/{version_id}/restore` - Restores revision
- `GET /api/v1/blocks` - Block definition registry
- `GET /api/v1/media` & `GET /api/v1/media/folders` - Media asset list and folder structure
- `POST /api/v1/media/folders` - Create folder
- `PATCH /api/v1/media/{id}` & `DELETE /api/v1/media/{id}` - Update/delete media asset
- `GET /api/v1/media/{id}/usage` - Verify asset references across content
- `POST /api/v1/media/chunks/init`, `POST /api/v1/media/chunks/{id}`, `POST /api/v1/media/chunks/{id}/assemble` - Chunked media upload
- `GET /api/v1/taxonomies` & `GET /api/v1/taxonomies/{handle}/terms` - Taxonomies and terms list
- `POST /api/v1/taxonomies/{handle}/terms` & `PATCH /api/v1/taxonomies/{handle}/terms/{id}` - Create/update term
- `POST /api/v1/taxonomies/{handle}/terms/merge` - Merge terms
- `GET /api/v1/singletons/navigation` & `PUT /api/v1/singletons/navigation` - Navigation menus

---

### Scope 7: Post-Integration Alignment & Polish
- **Schema-Driven Panel Rendering**:
  - The entry editor dynamically inspects the collection schema fields (`hasField`) so that collections like `pages` only render schema-defined fields, hiding `excerpt`, `is_featured`, `categories`, and `author` when not declared in the collection definition.
- **Taxonomy Singularization**:
  - Fixed plural replacement bug (`.replace(/s$/, '')`) producing "Add New Categorie" / "Create Categorie" for "Categories". Replaced with `getSingularTermName()` mapping "Categories" -> "Category" correctly across all dialogs and buttons.
- **Rich Text Headings Styling**:
  - Added explicit typography styles in `admin/src/styles/main.css` for `.tiptap h1`, `.tiptap h2`, `.tiptap h3`, `.tiptap h4`, lists, blockquotes, code blocks, and preformatted text so H2/H3 render with distinct font sizes and weights in the editor.
- **Author Mapping**:
  - Resolved author select remaining blank after save by handling the `/users` response envelope (`items` array) and populating `author_id` from entry attributes.
- **Block Registry Shape Alignment & 422 Handling**:
  - Updated `BlocksFieldEditor` to query `GET /api/v1/blocks` from Session C's `BlockRegistry` (`name`, `label`, `fields`, `category`).
  - Formats blocks payload as `{ type: block.name, ...data }` on entry save and gracefully surfaces RFC 9457 Problem Details / 422 validation errors via `problemToast`.
- **Media Filter Design System Alignment**:
  - Replaced native HTML `<select>` for "All Types" filter in the Media Library with `@bs/ui`'s `Select`, `SelectTrigger`, `SelectValue`, `SelectContent`, and `SelectItem`.

---

## 3. Verification & Checks
- PHP lint check: `php -l core/src/Routing/ApiRoutes.php`, `php -l core/src/Cli/Console.php`, `php -l core/src/Http/Controllers/BlocksController.php` all passed cleanly with 0 errors.
- Admin type check: `npx tsc --noEmit -p admin` passed with exit code 0.
- Simulation API verification: Verified `POST /api/v1/auth/login`, `GET /api/v1/blocks`, and `GET /api/v1/users` against the live simulation environment (port 8081).
- PHP lint check: `php -l core/src/Routing/ApiRoutes.php`, `php -l core/src/Cli/Console.php`, `php -l core/src/Http/Controllers/BlocksController.php` all passed cleanly with 0 errors.
- Admin type check: `npx tsc --noEmit -p admin` passed with exit code 0.
