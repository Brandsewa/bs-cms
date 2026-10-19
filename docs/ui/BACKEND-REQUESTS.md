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

