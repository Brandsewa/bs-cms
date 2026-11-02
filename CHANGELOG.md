# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] — 2026-11-02

### Added
- **Puck visual page builder** replaces the block system end-to-end: full-screen designer at `/design/pages/{id}` (drag/drop/nest, per-page draft preview, Save Draft/Publish toolbar), shared `@bs/page-builder` component package (Hero, RichText, Callout, FAQ, CTA banner, Features grid, Image, Spacer, Contact form embed), side drawer for issue/details review patterns, and the pages `content` field (`puck` type, migration 0012 converts legacy blocks).
- **Plugin marketplace + contact form plugin**: install/enable/uninstall from `/admin/marketplace`; form builder, public form embed (`data-bs-form` hydrated by embed.js), inquiry inbox with read/unread; endpoints gated on plugin installation.
- **Site Health triage**: issues render as a compact DataTable with severity tabs and a detail drawer (rule, entity, recommended fix, Edit entry); findings can be dismissed/restored individually or in bulk (persisted server-side).
- **Audit log bulk delete**: checkbox selection with type-to-confirm, owner-gated `POST /audit/delete`.
- **Drag-to-reorder** across the admin: navigation menus (nested tree — drag to reorder and nest children) and schema-builder fields, via dnd-kit grips with keyboard support; arrow-button reordering removed.
- **Admin design pass**: Geist typeface (self-hosted), documented type hierarchy, data-table header/body alignment fix + row affordances, spinner-only loading states, dark-mode pre-paint background (no white flash), persistent admin shell (sidebar/topbar no longer remount per section).
- **Entry editor (pages)**: compact columns — Title, editable Page URL, Customize hand-off to the builder, SEO card (meta title/description, SERP preview); the entry form never writes page content, preventing designer clobbering.
- **Starter**: Astro upgraded 5.18 → 7.4.4 with `@astrojs/react` islands support; Puck content renders server-side (zero client JS on content pages); draft preview page as a client island; per-page hashed CSP; node 24 in CI/deploy.

### Changed
- Navigation singleton storage flattened to schema-valid repeater rows (`menu`/`parent_id`/`position`); public site nav composes children from `parent_id`.
- Admin bundles Geist + builds under Vite 8; size budget rebalanced to the new shell.

### Fixed
- Menu editor saves actually persist now (previous `menus` payload was schema-invalid — every save 422'd).
- DataTable header/body column alignment drift (actions column width), padding mismatch, pointer cursor on clickable rows, tooltips on truncated cells.
- Dark mode no longer flashes white before first paint; DataTable `onStateChange` now merges patches (tab/filter state no longer wiped by the search debounce).
- Menus page editor drag state persists to Save; date-picker tests no longer break at month rollover.

## [Unreleased]

### Added
- **Branding** (`/admin/branding`): logo/favicon (media library), light + dark color palettes, button/card/image border & radius groups, font pairing and responsive H1–H6 scale, site width (px/%). Tokens are exposed via `GET /api/v1/public/site` and applied on the static site as CSS variables (WCAG auto-contrast, dark mode via `prefers-color-scheme`).
- **Collection builder**: Schema Builder now creates/edits collections, typed fields, and taxonomy attachments against the live API with a reviewed plan/apply flow.
- **Entry editor**: schema-driven custom-field panel and per-taxonomy term pickers for any collection.
- **Collections sidebar accordion + Collections index page**; singletons deep-link to their dedicated screens.
- **AI Studio** pending-changes review screen (`/admin/ai`, read-only).
- **Developer hub** page linking Schema Builder, Background Jobs and API Explorer.
- **Installer redesign**: themed step-by-step wizard (requirements → database → owner → finish) with verification panels, back navigation, themed error/retry pages and copy-cron; MySQL 8.4 verified end-to-end.
- **Sitemap upgrades**: per-URL lastmod, image extensions, noindex/utility exclusions, chunked index over 10k URLs, XML escaping; canonical base URL resolves from CMS settings.
- **Public site theme**: light botanical palette with dark-green brand accents, redesigned header/footer, 1380px site width, dark-mode palette support.

### Fixed
- Media security scanner no longer false-positives on GD-re-encoded raster images (WebP/JPEG binary bytes).
- Singleton saves no longer fail with "Entry not found" after a soft-delete (auto-restore in `SingletonsController`).
- Entry editor: saved slugs are no longer clobbered by auto-derive; editor no longer opens flagged as unsaved.
- Navigation menus screen saves the payload shape consumed by the public site API.
- Media library alt text no longer renders the literal string "null".
- Route loading and dashboard widgets show themed skeleton loaders (contained width) instead of a round spinner or flashed empty states.


### Added
- Monorepo skeleton (`core`, `admin`, `packages/ui`, `packages/client`, `packages/renderer`, `starter`, `tools`, `spikes`).
- Core PHP test and analysis suite: PHPUnit 11, PHPStan level 8, PHP-CS-Fixer (PSR-12 strict).
- Multi-version CI workflow in `.github/workflows/ci.yml` (PHP 8.2/8.3/8.4 x MySQL 8.0/8.4 x MariaDB 10.6/10.11/11.4).
- Allow-listed dependency pins in `DEPENDENCIES.md`.
- Architectural decisions and primary source verification log in `NOTES.md`.
