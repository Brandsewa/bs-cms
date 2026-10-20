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

## 6. Build integration: bundling admin shell into release zip — OPEN

Tools script tools/build-release currently creates the release archive without compiling and bundling the admin SPA application.
Per owner directives, UI agents do not touch tools/ or core/. Requesting backend/release engineering to update tools/build-release as follows:
1. **Build step**: Execute npm run build -w admin during the release pipeline.
2. **Distribution directory**: Copy the built assets from admin/dist/* into core/public/admin/.
3. **SPA fallback routing**: Ensure core/public/.htaccess routes any non-file request under /admin/* to /admin/index.html (e.g. RewriteCond %{REQUEST_FILENAME} !-f followed by RewriteRule ^admin/.* /admin/index.html [L]).
4. **Base path**: The admin build uses base /admin/ and serves index.html at /admin/.

## 7. OAuth return_to handling and sanitizeReturnTo alignment — OPEN

When an unauthenticated user arrives at GET `/oauth/authorize` while logged out, the backend redirects them to `/admin/login?return_to=https%3A%2F%2F<origin>%2Foauth%2Fauthorize%3Fclient_id%3D...`.
Notice that the backend sends an **absolute URL** (e.g. `https://bskickstarter.com/oauth/authorize?client_id=...`) rather than a relative path.
The UI `sanitizeReturnTo` implementation (`admin/src/lib/return-to.ts`) supports both:
1. Same-origin absolute URLs: parses with `new URL(val, window.location.origin)`, verifies `origin === window.location.origin`, checks that userinfo (`user:pass@`) is absent, and reduces to `pathname + search` before checking against the allow-list (`/admin/*` and `/oauth/authorize*`).
2. Same-origin relative paths (`/admin/*` and `/oauth/authorize*`).
3. Navigates to `/oauth/authorize` via full-page navigation (`window.location.assign`), since it is a server-rendered route.

Backend note: GA is separately updating the backend to emit relative paths for consistency; the frontend supports both formats cleanly.
