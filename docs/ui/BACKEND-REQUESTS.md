# UI / Backend coordination requests

Written by the Phase 5 (design system & admin shell) agent. Each item names
the endpoint/behaviour wanted and why; nothing here is worked around
silently in `packages/` or `admin/`.

## 1. General user-preferences endpoint (theme persistence)

**Wanted:** `GET /api/v1/me/preferences` and `PUT /api/v1/me/preferences`
accepting a free-form JSON object (at least `{ "theme": "light" | "dark" | "system" }`),
or a dedicated `PUT /api/v1/me/theme`.

**Why:** docs/02-ui-spec.md section 2 and implementation/phase-05 section 5.2
require the theme to be stored **per user (API)** with a localStorage cache.
Today only `GET/PATCH /api/v1/me/notification-preferences` exists, which is a
different concern. `ThemeProvider` (`packages/ui/src/theming/theme-provider.tsx`)
currently persists to localStorage only, with a code comment marking the
integration point. When the endpoint exists: read on session bootstrap,
write on change, keep localStorage as the cache and no-flash fallback.

## 2. OpenAPI response schemas for the Phase 2 endpoints

**Wanted:** `docs/api/openapi-v1.json` response schemas for `POST /auth/login`
(success vs `requires_2fa`), `POST /auth/2fa/challenge`, `GET /auth/session`,
`GET /me`, `GET /users`, `GET /roles`, token and audit endpoints.

**Why:** the typed client is generated from the OpenAPI document; the Phase 2
shapes are currently hand-written in `packages/client` from the committed
controllers (e.g. login → `{status:'success', user, csrf_token}` or
`{status:'requires_2fa', user_id, challenge_token}`). Once the document is
backfilled, the hand-written types are swapped for generated ones
(tracked in NOTES.md, Phase 5).

## 3. eslint-plugin-react-hooks approval (process, not backend)

The dependency allow-list process requires approval for new packages. This
dev-only linter (exhaustive-deps, rules-of-hooks) is recommended for a
React codebase of this size; it is not installed pending approval. All other
Phase 5 dependencies are recorded in DEPENDENCIES.md sections 4.1–4.3.
