# Phase 9: Hardening, performance, updates, backups and v1.0 release

**Goal:** make BS CMS safe to run on real production sites: security review, performance proof on shared hosts, safe self-updates without SSH, backups and restore, documentation, and a signed v1.0 release.
**Depends on:** Phases 0-8.

## 9.1 Security review
- Run the OWASP ASVS level 2 checklist (current version from Phase 0) across the whole system; record each requirement as pass / fail / not applicable with evidence in `docs/security/asvs-review.md`. Fix every fail before release.
- Threat model document (`docs/security/threat-model.md`): assets (content, accounts, secrets, AI grants, crawler policy), actors (anonymous, editor, compromised editor, malicious AI client, malicious plugin later, host neighbour on shared hosting), entry points (admin, API, MCP, OAuth, installer, updater, uploads, webhooks, preview, CI callback), mitigations, residual risks.
- Focused tests and manual review for: SQL injection via filter/sort/field handles and schema planner; XSS through documents, SEO fields, file names, term names (admin and public site); CSRF; SSRF (webhooks, bookmark/metadata fetch, media-from-URL, OAuth metadata documents); file upload bypasses (double extensions, polyglots, SVG, `.htaccess` upload); path traversal (media, export, updater); IDOR across collections and entries (owner=self condition); privilege escalation via role editing and tokens; OAuth redirect/PKCE/refresh attacks; preview token leakage (expiry, scope); deployment callback token reuse; rate-limit bypass with headers like `X-Forwarded-For` (only trust proxies configured in settings); session handling; secrets in logs/backups/exports; error message leakage; clickjacking; dependency advisories.
- Static analysis: PHPStan level 8 + a security-focused ruleset (taint analysis if available); `npm audit`; secret scanning in CI.
- Optional but recommended: an external penetration test before the first production customer site.

## 9.2 Performance proof
- Seed a realistic dataset: 10,000 posts, 500 categories (4 levels), 2,000 tags, 5,000 media, 20 users, 1 custom collection with 20,000 entries.
- Load test the API on both shared hosts (modest concurrency matching shared limits, e.g. 5-10 concurrent): record p50/p95/p99 for list, get, create, update, publish, MCP query; memory peak; included files per route. Must meet `README.md` section 7 budgets.
- Admin: bundle sizes per route, Lighthouse on the admin login and dashboard, interaction latency for DataTable with 100 rows and editor typing in a 10,000-word document.
- Public site: Lighthouse CI on 20 URLs (mobile), Core Web Vitals lab values, JS bytes per page, build time for the seeded site, incremental fetch effectiveness.
- Fix regressions; record final numbers in `docs/performance/v1-baseline.md`.

## 9.3 Self-update without SSH
- Release artifact: `bs-cms-<version>.zip` (core with `vendor/` built `--no-dev --classmap-authoritative`, admin `dist` in `public/admin/`), `SHA256SUMS`, and an **Ed25519 signature** (`sodium_crypto_sign_detached`) over the checksums, made with an offline release key; the public key ships inside the CMS.
- Updater (Settings > System > Updates): checks a configured update URL (private distribution for now), shows changelog, requires step-up; downloads to `storage/tmp`, verifies signature and checksums, checks PHP/DB requirements of the new version, enters maintenance mode, backs up current code (files) and runs a database backup (9.4), swaps files (atomic rename of directories where the host allows; never symlinks, since `symlink` is disabled on Hostinger; otherwise copy with a manifest and verification), runs migrations with `--dry-run` preview first, clears caches, exits maintenance, runs smoke checks (`/health`, login page, one API list); automatic rollback of files and database restore on failure.
- Manual update path documented (upload zip via file manager + `/install/upgrade` page protected by owner login).

## 9.4 Backups and restore
- Database backup job writing a compressed SQL dump in chunks (table by table, batched rows) to `storage/backups/` (not web-reachable), time-sliced to respect `max_execution_time` (resumable across job runs).
- Media backup as incremental zip manifests; optional off-site targets later (S3-compatible).
- Restore from the admin (owner, step-up, maintenance mode) and from CLI; tested on both hosts.
- Content export/import (JSON: schema + entries + terms + media manifest) for migration between hosts.
- Retention settings; size shown on System screen; warnings when disk is low.

## 9.5 Operations polish
- Maintenance mode screen for admin users; `bs doctor` command and System screen check: PHP version/extensions, OPcache, cron freshness, queue backlog, disk space, private paths unreachable, HTTPS, mail configured, keys present, last backup age, last deploy status, crawler policy live check.
- Data retention jobs (from `docs/01-data-model.md` section 0) verified.
- Integrity job: orphan relations/versions/media usage reported and repairable.

## 9.6 Documentation
- `docs/user/`: installation (Mode A/B, cron, HTTPS), first steps, writing posts, taxonomies, media, users and roles, SEO and crawler policy, connecting AI agents (per client), publishing pipeline setup (GitHub Actions + SFTP, manual, static hosts), backups and updates, troubleshooting.
- `docs/developer/`: architecture, operations layer, adding field types, schema as code, API reference (from OpenAPI), MCP tools reference (generated), Astro starter customisation, block registry, renderer.
- All docs in English; screenshots from the real admin.

## 9.7 Release checklist (v1.0)
- [ ] ASVS review complete with no open fails; threat model written.
- [ ] Performance baseline meets every budget on Host A (Hostinger) (Host B test deferred by owner, 2026-10-18).
- [ ] Update from the last release candidate to v1.0 via the updater succeeds on test host; forced failure triggers rollback correctly.
- [ ] Backup and restore tested end to end.
- [ ] Fresh install, full content flow, AI flow (Claude.ai + Claude Code; ChatGPT only if access exists by then), publish pipeline, preview and crawler policy verified on Host A by following only the user docs.
- [ ] Signed artifacts published to the private distribution location; checksums verified.
- [ ] `CHANGELOG.md`, `DEPENDENCIES.md` (with licences) and `NOTES.md` up to date.
