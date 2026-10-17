# Phase 4: Taxonomies, media pipeline, rich-text documents, SEO data, redirects

**Goal:** complete the content model: categories with subcategories and tags (and custom taxonomies), a safe and light media pipeline, rich-text documents that agents can write as Markdown, SEO/structured-data data, and redirects.
**Depends on:** Phase 3. **Specs:** `docs/01-data-model.md` sections 4-6, `docs/05-editor.md` section 4, `docs/04-crawlers-and-discoverability.md` sections 7-11.

## 4.1 Migrations
`taxonomies`, `collection_taxonomies`, `terms`, `term_relationships`, `entry_relations` (if not already created in Phase 3), `media_folders`, `media`, `media_variants`, `media_usage`, `redirects`. Then a schema migration (through the planner) that adds the Phase 4 fields to built-ins: `posts.body` (richtext, article profile), `posts.featured_image`, `posts.categories` (taxonomy `categories`, hierarchical, multiple, primary category supported), `posts.tags` (taxonomy `tags`), `posts.faq`, `posts.key_facts`, `pages.blocks`, `pages.featured_image`, `authors.avatar`, plus seeded taxonomies `categories` (with default term "Uncategorized") and `tags`.

## 4.2 Taxonomy engine (`core/src/Taxonomy/`)
- Operations: `taxonomies.list/get/create/update/delete`, `taxonomies.attach` / `detach` (to collections, creating the taxonomy field), `terms.list` (flat or tree, with counts), `terms.get`, `terms.create`, `terms.update`, `terms.move` (new parent and/or position), `terms.reorder` (sibling order), `terms.merge` (source -> target, reassign relationships, create redirect for archive URL), `terms.delete` (requires `reassign_to` when it has entries; blocked when it has children), `terms.bulk` (delete, merge, change parent).
- Materialized path maintenance: on create, `path = parent.path + id + '/'`, `depth = parent.depth + 1`, `slug_path = parent.slug_path + '/' + slug`; on move, update the whole subtree in one transaction with `UPDATE ... SET path = CONCAT(:newPrefix, SUBSTRING(path, :oldLen + 1)) WHERE taxonomy_id = ? AND path LIKE :oldPrefix%`; reject moves into own subtree; enforce `max_depth`.
- **Denormalised sort columns** `term_relationships.is_published` and `sort_at` are updated in the same transaction whenever an entry is published, unpublished, trashed, restored or its `published_at` changes (Hostinger benchmark: subcategory listing fell from 60-71 ms to 7-10 ms with them). Term listings query `ix_termrel_sorted` and join posts by primary key; subcategory listings first fetch the subtree's term ids by path prefix, then use `term_id IN (...)` on the sorted index with `DISTINCT entry_id` and `LIMIT`. Never use the posts-first `EXISTS` form for descendants (52-62 ms).
- `entry_count` maintained on publish/unpublish/delete and term reassignment (counts only published entries); nightly integrity job recalculates.
- Entry integration: taxonomy fields write `term_relationships`; exactly one `is_primary` per entry for hierarchical taxonomies (defaults to the first chosen); validation that terms belong to the right taxonomy; `required` enforced; default term applied when none is chosen.
- Query support: `filter[categories][in]=<id|slug>&include_descendants=true` -> join `terms` on path prefix; `filter[tags][all]=a,b` (all of) supported for flat taxonomies.
- REST: `GET/POST /api/v1/taxonomies`, `GET/PATCH/DELETE /taxonomies/{t}`, `POST /taxonomies/{t}/attach`, `GET/POST /taxonomies/{t}/terms`, `GET/PATCH/DELETE /terms/{id}`, `POST /terms/{id}/move`, `POST /taxonomies/{t}/terms/reorder`, `POST /terms/merge`, `POST /taxonomies/{t}/terms/bulk`, public `GET /api/v1/public/taxonomies/{t}/terms` (tree, counts, SEO).

## 4.3 Media pipeline (`core/src/Media/`)
- **Uploads:** `POST /api/v1/media` (multipart) for files up to the host's limit; **chunked uploads** (`POST /media/uploads` to start, `PUT /media/uploads/{id}/chunks/{n}` 2 MB chunks, `POST /media/uploads/{id}/complete`) so large files work despite low `upload_max_filesize`; resumable; temporary chunks in `storage/tmp` cleaned after 24 h.
- **Validation:** max size (setting), allowed types by **magic bytes** (`finfo`) not extension: JPEG, PNG, WebP, GIF, AVIF (if decodable), PDF, MP4/WebM, MP3, plain text, CSV, DOCX/XLSX optional; SVG disabled by default (if enabled, sanitise with a strict allow-list and serve with `Content-Security-Policy: default-src 'none'`).
- **Storage:** random file name `<ulid>.<ext>` under `public/media/YYYY/MM/`; original name kept in DB; `content_hash` dedupe offers "use existing"; `StorageDriver` interface (`local` now, S3-compatible later).
- **Processing (job `media.process`):** strip EXIF/GPS (keep orientation applied), re-encode, generate variants from presets (`thumb` 320, `sm` 640, `md` 1024, `lg` 1600, `og` 1200x630 crop at focal point) in **WebP** (+ original format fallback); AVIF only if the host's GD/Imagick supports it and the setting is on (CPU-heavy on shared hosts); compute width/height, dominant colour and blurhash. Memory guard: refuse images whose decoded size exceeds the memory budget (width x height x 4 bytes) with a clear error. Run one image per job to fit time limits.
- **Metadata:** alt text, caption, title, focal point; alt text required by lint for images used in public content (warning in editor, error in site health).
- **Usage tracking:** `media_usage` updated on entry save (from media fields and from rich-text documents' image nodes); deleting media in use is blocked with a usage list (or forced with confirm token, leaving entries with a missing-media warning).
- Folders: CRUD, move, nested; bulk move/delete/download (zip job).
- REST: `GET /api/v1/media` (filters: type, folder, date, unused, search), `GET/PATCH/DELETE /media/{id}`, `POST /media/{id}/replace`, `POST /media/bulk`, `GET/POST /media/folders`, `PATCH/DELETE /media/folders/{id}`, `GET /media/{id}/usage`.

## 4.4 Rich-text documents (`core/src/Documents/`)
- **Document schema (BS document schema v1):** allow-list of node types and attributes matching `docs/05-editor.md` section 5: `doc, paragraph, heading(level 2-4), bulletList, orderedList(start), listItem, taskList, taskItem(checked), blockquote(cite?), callout(variant), horizontalRule, codeBlock(language), image(mediaId, alt, caption, align, width), gallery(items[]), embed(provider, url), bookmark(url, title, description, imageUrl), table, tableRow, tableHeader, tableCell, details, detailsSummary, detailsContent, faq(items[{question, answer(doc)}]), keyFacts(items[{statement, value, sourceUrl}]), tableOfContents, button(label, href, style), customBlock(type, props)`; marks: `bold, italic, underline, strike, code, link(href, target, rel), highlight, textColor(limited palette)`. Stored JSON includes `schema_version`.
- `DocumentValidator`: rejects unknown nodes/marks/attributes, enforces nesting rules, URL schemes (`http, https, mailto, tel`, internal `bs://entry/<collection>/<id>`), max document size (setting, default 2 MB), max depth 20.
- `MarkdownToDocument`: `league/commonmark` (CommonMark + GFM tables, task lists, strikethrough) AST -> document nodes; custom directive syntax for callout (`:::callout{variant=tip}` ... `:::`), FAQ (`:::faq` with `### Question` + answer blocks), key facts (`:::facts` list `- statement :: value :: source`), embed (`::embed{url=...}`), button (`::button[Label]{href=...}`). Images in Markdown referencing remote URLs are imported into the media library by a job (SSRF-safe) or rejected per setting.
- `DocumentToMarkdown`: inverse mapping; round-trip tests guarantee stability for every supported node.
- **API behaviour:** write endpoints accept `body` (document JSON) **or** `body_markdown` for any `richtext` field (`<field>_markdown`); reads return the document, and `?format=markdown` adds `<field>_markdown`.
- `DerivedFields` on save: `excerpt` (if empty: first 160 chars of text), `reading_time` (200 wpm), `word_count`, `outline` (headings with anchors), image media ids (-> `media_usage`), internal links (-> link index for broken-link checks), FAQ items (for JSON-LD), `search_text`.
- Internal links stored as `bs://entry/<collection>/<id>` and resolved to URLs at render time so slug changes never break links.

## 4.5 SEO data and structured data
- `seo` JSON column handled as a built-in field group for collections with feature `seo` (shape in `docs/01-data-model.md` section 6): validation of lengths (title 1-70 recommended, description 50-160 recommended; warnings, not errors), canonical must be absolute same-site or empty, `og_image_media_id` must exist.
- `SeoResolver` computes effective values (entry overrides -> derived defaults -> site defaults), exposed in public API as `seo_resolved` so the Astro build never re-implements the logic.
- `StructuredDataBuilder` produces the JSON-LD `@graph` per entry from the collection's `schema_org` mapping (`docs/04-crawlers-and-discoverability.md` section 9) and site organization data; exposed as `structured_data` in the public API; validated by required-property rules.
- Term archives get `seo_resolved` + `BreadcrumbList` data too.

## 4.6 Redirects
- Operations + REST: `GET/POST /api/v1/redirects`, `PATCH/DELETE /redirects/{id}`, `POST /redirects/bulk`, `POST /redirects/import` (CSV), `GET /redirects/export`.
- Automatic redirects on slug change of published entries and on term slug/parent change (archive URLs), marked `is_auto`. Loop and chain detection on save (reject loops; collapse chains to final target).
- Public `GET /api/v1/public/redirects` for the Astro build, which emits them as `.htaccess` rules / `_redirects` file (Phase 7).

## 4.7 Site-health rules available from data (Phase 7 adds output checks)
Implement the content and GEO rules from `docs/04-crawlers-and-discoverability.md` section 11 that only need stored data: all `seo.*` rules except live-crawl ones, `geo.summary.missing`, `geo.author.missing`, `geo.date.missing_updated`, `geo.faq.empty_answers`, `geo.schema.missing`, `geo.schema.invalid`, `seo.image.alt.missing`, `seo.link.broken_internal`, `seo.page.orphan`. Operation `site.health` returns issues with ids, severity, entity links and fix hints; `bs site:health` CLI prints them.

## 4.8 Tests
- Taxonomy: create tree 4 levels, move subtree (paths updated), move into own descendant rejected, merge with reassignment and redirect, delete with reassign, delete with children blocked, counts accurate after publish/unpublish, filter with and without descendants, primary category rules.
- Media: magic-byte validation (renamed PHP file rejected), oversized image refused with clear error, EXIF GPS stripped, variants generated within memory budget, chunked upload resume, dedupe, usage blocking delete, `.htaccess` in media prevents script execution (HTTP test with a `.php` and `.phtml` file placed manually returns no execution).
- Documents: validator rejects script URLs, unknown nodes, oversized documents; Markdown -> document -> Markdown round trip for every node; directive syntax; internal link resolution; derived fields.
- SEO/JSON-LD snapshots for post, page, author, category; required-property checks.
- Redirects: loop rejection, chain collapse, auto creation on slug change.
- Performance: list posts with `populate=categories,tags,authors` within budget; term tree of 500 terms < 50 ms.

## 4.9 Acceptance checklist
- [ ] Posts support hierarchical categories with subcategories and flat tags; pages have no taxonomy fields.
- [ ] A custom collection can attach an existing taxonomy and a new one; two collections sharing a taxonomy can be filtered together by term.
- [ ] Upload, process and use images on both shared hosts within their limits (record largest image processed).
- [ ] An API client can create a post by sending Markdown only, and read it back as Markdown and as document JSON.
- [ ] `seo_resolved` and `structured_data` returned by the public API for posts, pages, authors and term archives.
- [ ] `site.health` reports seeded test problems correctly.
