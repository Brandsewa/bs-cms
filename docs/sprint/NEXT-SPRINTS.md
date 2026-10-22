# Next sprints: pending work and build plan

Written by the verifier after the A-D integration (PR #43). Status at that point: engine, admin shell, content editor, media, taxonomies, menus, redirects, site health, settings, AI governance, jobs, schema builder, MCP, backups and crawler-policy API are built and were verified over HTTP and in a browser on the local Hostinger simulation. An external tester will do screen-by-screen end-to-end testing later; this plan removes the known gaps first so that pass is not wasted on known defects.

## 1. What is pending

### Missing screens (backend exists, no UI)
- **SEO and discoverability settings (spec 4.9):** crawler policy editor (presets, bot catalogue, versions, rollback, canary, IndexNow), sitemap and llms.txt settings, organisation schema.org data. API: `/api/v1/crawler-policy*`.
- **System screen:** maintenance mode, backups (create, list, download, delete), self-update (check, verify, apply, roll back), doctor output. API: `/api/v1/system/*`, `bs doctor`.
- **Content-editor limited dashboard (spec 7).**
- **Review queue:** in-review entries, approve and reject.
- **Scheduling calendar** and preview-token management UI.

### Built but not yet verified end to end
Webhook delivery and retry, API-token reveal and revoke in the UI, redirect CSV import and export in the UI, custom role editing and Preview-as-role, schema builder plan and apply, notification preferences UI, email settings test send, user invitations and acceptance, audit log filters, saved views, bulk actions, revision restore, scheduled publish, 2FA login challenge and recovery codes, email change flow, OAuth consent and MCP client connect (real client), IndexNow with a key, uploads over 10 MB, backup restore on a real site, self-update.

### Known defects
- Schema builder shows doubled plurals ("authorss", "pagess", "postss"): it appends "s" to a label that is already plural.
- Any 5xx caused by over-long input on not-yet-covered endpoints (found repeatedly; add a generic max-length guard in the request validation layer).
- Role matrix decisions to confirm with the owner: admin cannot use maintenance or backup (developer can); viewers can read maintenance status.
- Public redirect resolution lives in the starter (`/api/v1/public/redirects/match`); the PHP site does not redirect by itself. Confirm that is intended.
- `/robots.txt` is not served by PHP; the starter generates it. Canary needs a live site.
- Lint warnings (6) and the `INEFFECTIVE_DYNAMIC_IMPORT` build warnings.

### Owner-side actions
1. Hostinger cron for the queue worker (NOTES.md section 6.8).
2. Decide on a second-host test before Phase 9 sign-off.
3. SMTP credentials and sender domain (SPF, DKIM) for real email.
4. Redeploy the live site (bskickstarter.com) with the integrated build once this PR is merged.
5. GitHub Actions secrets for the Astro build pipeline (already added) and a deploy target for the starter.
6. Brief the external tester; give them the sim credentials and this document's "verified" and "not verified" lists.

## 2. Sprint plan (same sprint-mode rules as before)

Rules for every session: own branch off the latest main; marked blocks (`// SPRINT-X BEGIN/END`) in shared wiring files; build against real API shapes from the simulation, never assumed ones; before handing back, `tsc`, eslint, `php -l`, php-cs-fixer, phpstan and the unit suites must pass (CI runs all of them; sprint 1 skipped them and cost a full fix round); one deployer on the sim at a time; push and report the hash.

### Session A (S1): SEO, discoverability and System
1. SEO and discoverability screen (spec 4.9): crawler policy editor with bot catalogue and presets, diff against the live robots.txt, versions and rollback with step-up, canary result, IndexNow key and submit, sitemap and llms.txt toggles, organisation and person schema.org settings.
2. System screen: maintenance mode toggle with a warning that explains what is blocked, backup list/create/download/delete, update check and apply with progress and rollback, doctor report.
3. Fix the schema builder plural labels. Complete the settings pages that were only smoke-tested (webhooks delivery log and retry, token reveal, email test send).
4. Browser-test every screen you own on the sim at 1440 and 390 px.

### Session B (S3): Content workflow
1. Review workflow: in-review queue, approve and reject with notes, author notifications.
2. Scheduling calendar and scheduled-publish UI; preview-token management.
3. Revisions: side-by-side diff, restore with confirmation. Autosave of drafts with conflict handling.
4. Collection list: saved views, bulk actions (publish, unpublish, trash, restore, move, taxonomy assign) with permissions.
5. Content-editor limited dashboard (spec 7).
6. Media: replace file, regenerate variants, bulk move, alt-text coverage report; taxonomy term SEO fields.
7. Browser-test every screen you own.

### Session C (S2): Hardening, operations and Phase 9
1. Self-update end to end: fetch release, verify checksum and signature, pre-update backup, staged swap, automatic rollback; works without exec on Hostinger.
2. Scheduled backups through the queue, retention, download streaming, restore path for a fresh site.
3. Security pass: CSP and headers, upload handling, SSRF on every outbound fetch (webhooks, canary, IndexNow), rate limits on auth and write endpoints, audit completeness, session fixation, CSRF, enumeration, log redaction. Add a generic input max-length guard (no 5xx from long input).
4. Performance: indexes and N+1 review, list endpoints at 100k entries, cache headers, a benchmark script and results in docs.
5. MCP: verify resources, prompts, pagination and error mapping with a real client; pending-changes approval end to end for each ai_mode.
6. Queue and cron reliability on Hostinger (lazy runner and real cron), failure and retry visibility.
7. Documentation: install guide, upgrade guide, security guide, API reference check, release checklist.

### Session D (S4): UI fidelity, accessibility and test harness
1. Supabase design-system fidelity audit of every screen: tokens, spacing, typography, dark and light; fix drift.
2. Accessibility: keyboard paths, focus management, axe clean on every route, reduced motion.
3. Loading, empty, error and forbidden states on every screen; skeletons with no layout shift.
4. Playwright end-to-end suites for the core journeys (login, create and publish a post, upload media, edit menu, redirect, settings, AI connect, account) so the external tester starts from a working baseline.
5. Fix the 6 lint warnings and the dynamic-import warnings.

### Session E (new, optional): Astro starter
Public site template: layouts, block renderer, SEO components, JSON-LD, sitemap, llms.txt, RSS, search, preview mode, redirects, error pages, GitHub Actions build and deploy, theming. Verify a full publish round trip from the admin to a built site.

### Verifier
After each session hands back: merge into an integration branch, build the release zip, deploy to the sim, rerun the HTTP suites, take production-build screenshots of every route, report defects per session. Final gate before the external tester: full pass, then redeploy the live site.

## 3. Hand-off to the external tester
Provide: the sim (`dev\sim.ps1 up|deploy`), role accounts (owner, admin, developer, editor, author, contributor, viewer), the verified and unverified lists above, `docs/02-ui-spec.md` as the acceptance reference, and the rule that every defect is reported with route, role, steps, expected, actual and a screenshot.
