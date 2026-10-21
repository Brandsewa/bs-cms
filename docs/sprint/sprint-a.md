# Sprint A: System & Developer Implementation Log

Branch: `sprint/a-system`

## Scope Overview
1. **Fold-in / Hardening**:
   - Site Health scanner & report view folded from PR #42 with dark-theme contrast fix on the Progress track.
   - Redirects management view: full DataTable, create/edit dialog with API validation messages, bulk delete, CSV import with per-row result breakdown, CSV export, hit counts, and loop/chain warnings.
2. **Settings (docs/02-ui-spec.md 4.13)**:
   - Security Settings: password policy, 2FA enforcement per role, session lengths, login rate limits.
   - API Tokens: list, create token modal with role/scopes/mode/expiry, copy-once reveal, revoke, last used timestamp.
   - Webhooks: webhook list table, create/edit, test ping, delivery log with retry, auto-disable warning status.
   - Email Log: searchable email logs, status badges, recipient, subject, provider, error details, resend action.
   - Publishing Settings: target provider, repository, token, webhook, debounce, url patterns, build tokens list/create/revoke, deployments list with "Deploy now" trigger, IndexNow key status.
   - AI Connections & Governance: grants list with revoke, kill switch status banner/disconnect/reconnect with step-up verification, ai_mode defaults, tool allowlist, pending AI changes queue with diff review and approve/reject actions.
   - Notification Preferences: event notification channels, user notification toggles.
3. **Developer Area**:
   - Jobs: background queue monitor, job status, duration, failed jobs inspector with retry trigger.
   - Schema Builder: collection list and create wizard, field list with drag/order controls, add-field dialog with field-type picker, per-type settings, validation rules, filterable/sortable toggles with index-impact note, taxonomy attach, relation builder, live form preview, schema JSON/YAML code viewer, and plan preview with confirmation.
   - OpenAPI / API Explorer: interactive API documentation and link to `/api/v1/openapi.json`.
   - Roles Matrix & "Preview as role": matrix previewer simulating permissions across subjects, plus custom role editing (allow/deny/inherit matrix, own-entries toggle).
4. **Backend Additions**:
   - Security settings endpoints: GET/PUT `/api/v1/settings/security`
   - Jobs management endpoints: GET `/api/v1/jobs` queue list, POST `/api/v1/jobs/{id}/retry`
   - AI grants endpoint: GET `/api/v1/ai/grants`, DELETE `/api/v1/ai/grants/{id}`

---
*Status: Initializing Sprint A execution.*
