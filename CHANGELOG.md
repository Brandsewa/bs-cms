# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
