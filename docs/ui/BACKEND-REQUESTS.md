# UI / Backend coordination requests

Written by the Phase 5 (design system & admin shell) agent. Each item names
the endpoint/behaviour wanted and why; nothing here is worked around
silently in `packages/` or `admin/`.

## 1. General user-preferences endpoint (theme persistence) — RESOLVED

`GET /api/v1/me/preferences` and `PATCH /api/v1/me/preferences` exist as of
main @ 7498e28 (commit 8cc0af5 added them, documented in the OpenAPI file).
The theme is stored under `preferences.theme` (`light` | `dark` | `system`).

**Remaining UI work (not a backend request):** wire `ThemeProvider`
(`packages/ui/src/theming/theme-provider.tsx`) to read on session bootstrap
and PATCH on change, keeping localStorage as the cache and the pre-CSP-paint
fallback. This lands with the `@bs/client` session work in M2/M3.

## 2. OpenAPI response schemas for the Phase 2 endpoints — RESOLVED

Backfilled on main @ 7498e28 (`docs/api/openapi-v1.json` now documents
responses for `POST /auth/login` (success and `requires_2fa`),
`GET /auth/session`, `GET/PUT /me`, `GET /users`, and more). The M2 client
generates its types from this document instead of the hand-written ones
recorded in NOTES.md §7.3.

## 3. eslint-plugin-react-hooks approval (process, not backend) — RESOLVED

Approved by the owner in the M1 review (defect 5). Installed at 7.1.1 with
`rules-of-hooks` as error and `exhaustive-deps` as warn; recorded in
DEPENDENCIES.md §4.2. The M1-fixes branch lints clean under it (verified
with a deliberate violation that the plugin catches).

## 4. Chunked upload session key confirmation: `session_id` vs `upload_id` — OPEN

In `docs/api/openapi-v1.json` (frozen as `api-v1`), the response of `POST /media/chunks/init`
defines:
```json
"UploadSessionResponse": {
  "type": "object",
  "required": ["session_id", "expires_at"],
  "properties": {
    "session_id": { "type": "string" },
    "expires_at": { "type": "string", "format": "date-time" }
  }
}
```
The client generator and runtime implementation (`packages/client/src/upload.ts`) adhere strictly to the frozen spec using `session_id`. The milestone prompt brief referenced `upload_id`. Asking backend team to confirm that `session_id` remains the canonical contract field for all chunk endpoints (`/media/chunks/{session_id}`).

## 5. Idempotency-Key Header Support for Mutating Requests (`POST`/`PATCH`) — OPEN

To prevent duplicate creates/modifications when transient 5xx or network disconnects occur during mutations, `@bs/client` only retries `POST` and `PATCH` requests if the caller provides an `idempotencyKey` (sent via the standard `Idempotency-Key: <token>` HTTP header).
Requesting backend team confirm/plan:
1. Backend middleware/handler support for caching responses by `Idempotency-Key` (e.g., Redis or DB key table with a short TTL such as 15–60 minutes) so retried requests return the original outcome instead of re-executing.
2. Confirmation that chunk uploads (`POST /media/chunks/{id}` with `chunk` and `chunk_index`) are idempotent on the backend (overwriting/re-saving the same chunk index for the active session without side effects).

## 6. Build integration: bundling admin shell into release zip — RESOLVED

Resolved in `tools/build-release`, `core/public/.htaccess`, and `core/public/index.php`.
1. `tools/build-release` executes `npm run build -w admin` and copies `admin/dist/*` into `core/public/admin/` in the release zip.
2. `core/public/.htaccess` and `core/public/index.php` route `/admin`, `/admin/`, and extension-less client-side routes to PHP front controller (`index.php`), which serves the admin shell with full security headers (CSP, X-Frame-Options, etc.). Static assets with extensions under `/admin/assets/` are served directly.
3. Verified in `tools/test-release-http.php` for `/admin/`, deep links (e.g. `/admin/entries/posts`), static assets (`/admin/theme-init.js`), missing asset 404s, with security headers intact and `/api`, `/oauth`, `/mcp`, and `/.well-known` routes unaffected.

## 7. OAuth return_to handling and sanitizeReturnTo alignment — OPEN

When an unauthenticated user arrives at GET `/oauth/authorize` while logged out, the backend redirects them to `/admin/login?return_to=https%3A%2F%2F<origin>%2Foauth%2Fauthorize%3Fclient_id%3D...`.
Notice that the backend sends an **absolute URL** (e.g. `https://bskickstarter.com/oauth/authorize?client_id=...`) rather than a relative path.
The UI `sanitizeReturnTo` implementation (`admin/src/lib/return-to.ts`) supports both:
1. Same-origin absolute URLs: parses with `new URL(val, window.location.origin)`, verifies `origin === window.location.origin`, checks that userinfo (`user:pass@`) is absent, and reduces to `pathname + search` before checking against the allow-list (`/admin/*` and `/oauth/authorize*`).
2. Same-origin relative paths (`/admin/*` and `/oauth/authorize*`).
3. Navigates to `/oauth/authorize` via full-page navigation (`window.location.assign`), since it is a server-rendered route.

Backend note: GA is separately updating the backend to emit relative paths for consistency; the frontend supports both formats cleanly.

## 8. Dashboard Aggregate Endpoint (`GET /api/v1/dashboard`) — RESOLVED

Implemented in `core/src/Dashboard/DashboardService.php`, `core/src/Http/Controllers/DashboardController.php`, and registered in `core/src/Routing/ApiRoutes.php` / `core/public/index.php`.
1. **Contract Adherence**: Returns the exact schema expected by `admin/src/dashboard/types.ts`: `greeting`, `counts` (drafts, scheduled, pending AI changes), `recent_edits`, `drafts_waiting`, `scheduled_items`, `pending_ai_changes`, `site_health`, `recent_activity`, `system_status`, and `onboarding`.
2. **Role Awareness & Security Hardening**:
   - `owner` / `admin` / `developer`: full payload with coarse telemetry (cron: healthy/warning, mail: healthy/unconfigured/error, disk_usage_percent: coarse int, php_version) and recent audit feed. No paths, env vars, or hostnames are leaked.
   - `editor`: content queues and site health provided; sensitive audit activity (`recent_activity`) and telemetry (`system_status`) are omitted.
   - `author` / `contributor`: content queries (`recent_edits`, `drafts_waiting`, `scheduled_items`, `counts`) are strictly scoped by `created_by = current_user_id` in SQL. Audit activity, system telemetry, and pending AI changes are omitted.
   - `viewer`: read-only summary; mutating quick counts and pending changes omitted.
   - `anonymous`: HTTP 401 Unauthorized (`Authentication is required.`).
   - `oauth` / `mcp`: HTTP 403 Forbidden (`OAuth and MCP tokens cannot access the administrative dashboard.`).
3. **Performance & Caching**: Fast bounded SQL queries with limits and indexed columns (`ix_c_*_updated`, `ix_c_*_status_pub`). Zero N+1 queries. Always sends `Cache-Control: no-store, no-cache, must-revalidate` (no cross-user caching).
4. **OpenAPI Document**: Documented in `docs/api/openapi-v1.json` with `DashboardResponse` schema definition.

## 9. AI Kill Switch & Status Endpoints (`POST /api/v1/ai/disconnect`, `POST /api/v1/ai/reconnect`, `GET /api/v1/ai/status`) — RESOLVED

Implemented for Settings > AI management screens per Phase 8.4:
1. `GET /api/v1/ai/status`:
   - Returns live status of AI access:
     ```json
     {
       "ai_disabled": false,
       "active_grants_count": 2,
       "active_ai_tokens_count": 1,
       "pending_changes_count": 0
     }
     ```
   - Accessible by authenticated admin users (`owner`, `admin`, `developer`, `editor`, `author`). Anonymous requests return 401; OAuth/MCP tokens return 403.
2. `POST /api/v1/ai/disconnect`:
   - "Disconnect all AI access" kill switch action.
   - Atomic transaction revoking all active OAuth grants (`status = 'revoked'`), invalidating active access/refresh tokens, revoking active AI API tokens (`revoked_at = NOW()`), expiring/rejecting pending AI changes (`status = 'rejected'`), and persisting `ai_disabled = true`.
   - Records `ai.disconnected` audit entry plus `oauth.grant.revoked` per revoked grant.
   - Requires `owner` or `admin` role and step-up authentication (`mfa_passed_at` or inline password).
   - Idempotent: repeated calls succeed safely and return revoked counts (0 if already disconnected).
   - Response contract:
     ```json
     {
       "status": "disconnected",
       "ai_disabled": true,
       "revoked_grants_count": 2,
       "revoked_tokens_count": 1,
       "expired_pending_changes_count": 0
     }
     ```
3. `POST /api/v1/ai/reconnect`:
   - Re-enables AI access (`ai_disabled = false`).
   - Requires `owner` or `admin` role and step-up authentication.
   - Does NOT resurrect previously revoked grants (clients must re-authorize cleanly).
   - Response contract:
     ```json
     {
       "status": "connected",
       "ai_disabled": false
     }
     ```
4. **Central Enforcement**:
   - `AiKillSwitchGuard` and `AiKillSwitchMiddleware` intercept all `/mcp`, `/oauth/authorize`, `/oauth/token`, and `/oauth/register` requests when `ai_disabled = true`:
     - `/mcp` returns JSON-RPC error `-32000` with message "AI access is disabled by administrator." and no data.
     - `/oauth/*` returns HTTP 403 `access_denied`.
     - `/oauth/revoke` is explicitly allowed through so connected clients can clean up credentials.

## 10. Publishing Pipeline & Static Build Token Endpoints (Step 7.4) — RESOLVED

The backend static publishing pipeline endpoints (Step 7.4) are fully implemented and ready for UI consumption in the Admin settings screens:

1. **Publishing Settings** (`/api/v1/settings/publishing`):
   - `GET /api/v1/settings/publishing`: Returns `{ target_type, enabled, debounce_window, github_repository, github_workflow_id, has_github_token, webhook_url, has_webhook_secret, updated_at }`. Sensitive secrets (`github_token`, `webhook_secret`) are encrypted at rest using libsodium and NEVER exposed or returned by any endpoint.
   - `PUT /api/v1/settings/publishing`: Updates settings (`target_type`, `enabled`, `debounce_window`, `github_repository`, `github_workflow_id`, `github_token`, `webhook_url`, `webhook_secret`).
   - `POST /api/v1/settings/publishing/test`: Validates the publishing target. Enforces SSRF protection on `webhook_url` (blocking private/reserved IPv4/IPv6 networks and loopback) or validates GitHub credentials against the GitHub API.

2. **Deployments API** (`/api/v1/deployments`):
   - `GET /api/v1/deployments`: Lists deployment history (pagination: `limit`, `offset`, order: `created_at DESC`).
   - `POST /api/v1/deployments/trigger`: Manually enqueues/dispatches a deployment.
   - `POST /api/v1/deployments/{id}/status`: Single-use callback endpoint invoked by external runners (GitHub Actions or webhook receivers) using a Bearer callback token. State transitions: `building` -> `succeeded` / `failed` / `cancelled`.

3. **Build Tokens API** (`/api/v1/tokens/build`):
   - `GET /api/v1/tokens/build`: Lists build tokens (`id`, `name`, `token_prefix`, `last_used_at`, `revoked_at`, `created_at`). Raw tokens and token hashes are never exposed.
   - `POST /api/v1/tokens/build`: Generates a new build token with format `bsbuild_{prefix}_{secret}`. The plain token is returned ONCE in the response (`{ id, name, token, token_prefix, created_at }`).
   - `DELETE /api/v1/tokens/build/{id}`: Immediately revokes the token. Revoked tokens are instantly rejected (401).
   - Build tokens are strictly restricted to read-only access for published content (`/api/v1/public/*` and build endpoints); any mutating, administrative, or draft endpoint immediately returns 403 Forbidden.


