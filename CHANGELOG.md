# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Monorepo skeleton (`core`, `admin`, `packages/ui`, `packages/client`, `packages/renderer`, `starter`, `tools`, `spikes`).
- Core PHP test and analysis suite: PHPUnit 11, PHPStan level 8, PHP-CS-Fixer (PSR-12 strict).
- Multi-version CI workflow in `.github/workflows/ci.yml` (PHP 8.2/8.3/8.4 x MySQL 8.0/8.4 x MariaDB 10.6/10.11/11.4).
- Allow-listed dependency pins in `DEPENDENCIES.md`.
- Architectural decisions and primary source verification log in `NOTES.md`.
