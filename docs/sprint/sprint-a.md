# Sprint A: System & Developer Implementation Report

**Branch**: `sprint/a-system`  
**Author**: Sprint A Lead  
**Status**: Completed and Pushed

---

## 1. Summary of What Was Built

### 1.1 Fold-in & Hardening
- **Site Health Scanner & Report View**:
  - Folded in PR #42 commits (`baeb757`, `278bdef`, `d772aa7`).
  - Resolved dark-theme contrast violation on the `Progress` bar track at source in `@bs/ui` (`packages/ui/src/components/progress.tsx`), adding high-contrast dark border and elevated background `bg-secondary dark:bg-muted/80 border border-transparent dark:border-border/50`.
- **Redirects Management (`/admin/redirects`)**:
  - Built `RedirectsView` ([admin/src/system/redirects-view.tsx](file:///c:/ai/bs-sprint-a/admin/src/system/redirects-view.tsx)) powered by `@bs/ui` `DataTable`.
  - Supports:
    - Search by source/target path, filtering by HTTP status code (301, 302, 307, 308) and match type (`exact`, `prefix`).
    - Create / Edit dialog with backend validation error mapping (`formErrors`).
    - Bulk delete action with confirmation modal.
    - CSV Export (`/api/v1/redirects/export`).
    - CSV Import (`/api/v1/redirects/import`) with per-row result breakdown, error counter, and report.
    - Hit counts and last hit timestamp.
    - Real-time detection badges for circular redirect loops and multi-hop redirect chains.

---

### 1.2 Settings Suite (docs/02-ui-spec.md Section 4.13)
- **Settings Layout & Sub-Navigation (`/settings/*`)**:
  - Enhanced [admin/src/settings/settings-layout.tsx](file:///c:/ai/bs-sprint-a/admin/src/settings/settings-layout.tsx) with all nine settings sections: General, Security, API Tokens, Publishing, Webhooks, Email, Email Log, AI Connections, and Notifications.
- **Security Settings (`/settings/security`)**:
  - Built `SecuritySettingsView` ([admin/src/settings/security-settings-view.tsx](file:///c:/ai/bs-sprint-a/admin/src/settings/security-settings-view.tsx)).
  - Features:
    - Password policy (minimum length 8–64, require uppercase, require lowercase, require numbers, require special symbols).
    - 2FA policy enforcement (optional, mandatory for privileged roles, mandatory for all).
    - Session management (idle timeout minutes, absolute lifetime hours).
    - Rate limits (login max attempts before lockout, lockout window decay).
    - Allowed email domains restriction for team invitations.
- **API Tokens (`/settings/tokens`)**:
  - Built `ApiTokensView` ([admin/src/settings/api-tokens-view.tsx](file:///c:/ai/bs-sprint-a/admin/src/settings/api-tokens-view.tsx)).
  - Features:
    - Token generation modal with name, comma-separated scopes, AI mode selection (`draft_only`, `direct_apply`, `read_only`), and expiration duration.
    - Copy-once token reveal modal with warning.
    - Active token list with last-used and expiry dates.
    - Immediate revocation action.
- **Webhooks (`/settings/webhooks`)**:
  - Built `WebhooksView` ([admin/src/settings/webhooks-view.tsx](file:///c:/ai/bs-sprint-a/admin/src/settings/webhooks-view.tsx)).
  - Features:
    - Webhook management table (name, endpoint URL, subscribed event topics, active toggle).
    - Test ping dispatcher (`POST /api/v1/webhooks/{id}/test`).
    - Deliveries log viewer with HTTP status code badges, response durations, and manual retry trigger (`POST /api/v1/deliveries/{id}/retry`).
- **Email Log (`/settings/email-log`)**:
  - Built `EmailLogView` ([admin/src/settings/email-log-view.tsx](file:///c:/ai/bs-sprint-a/admin/src/settings/email-log-view.tsx)).
  - Searchable audit log of transactional emails with status badges (`sent`, `failed`), recipient filtering, provider identification, error details, and one-click resend.
- **Publishing Settings (`/settings/publishing`)**:
  - Built `PublishingSettingsView` ([admin/src/settings/publishing-settings-view.tsx](file:///c:/ai/bs-sprint-a/admin/src/settings/publishing-settings-view.tsx)).
  - Target provider selection (`github_actions`, `webhook`, `manual`), repository, workflow dispatch trigger, debounce window seconds.
  - "Deploy now" trigger (`POST /api/v1/deployments/trigger`).
  - IndexNow search engine auto-ping key status and verification.
  - Frontend build token generation and revocation list.
  - Deployments audit history.
- **AI Connections & Governance (`/settings/ai`)**:
  - Built `AiConnectionsView` ([admin/src/settings/ai-connections-view.tsx](file:///c:/ai/bs-sprint-a/admin/src/settings/ai-connections-view.tsx)).
  - Emergency AI Kill Switch circuit breaker with step-up verification modal to disconnect / reconnect all agents globally.
  - Authorized AI OAuth grants list with client ID, permissions, and disconnect capability.
  - Pending AI Changes queue with visual diff modal and human approve (`POST /api/v1/pending-changes/{id}/approve`) and reject (`POST /api/v1/pending-changes/{id}/reject`) controls.
- **Notification Preferences (`/settings/notifications`)**:
  - Built `NotificationsPreferencesView` ([admin/src/settings/notifications-preferences-view.tsx](file:///c:/ai/bs-sprint-a/admin/src/settings/notifications-preferences-view.tsx)).
  - Channel preferences for publish events, pending AI proposals, background job failures, and weekly summaries.

---

### 1.3 Developer Area
- **Background Jobs Queue (`/developer/jobs`)**:
  - Built `JobsView` ([admin/src/developer/jobs-view.tsx](file:///c:/ai/bs-sprint-a/admin/src/developer/jobs-view.tsx)).
  - Queue overview metric cards (Total, Queued, Failed, Completed).
  - Job inspection with attempts counter, error diagnostics, and requeue/retry action (`POST /api/v1/jobs/{id}/retry`).
- **Schema Builder (`/developer/schema`)**:
  - Built `SchemaBuilderView` ([admin/src/developer/schema-builder-view.tsx](file:///c:/ai/bs-sprint-a/admin/src/developer/schema-builder-view.tsx)).
  - Collection list & creation wizard.
  - Field list with drag-to-reorder up/down controls, add-field dialog with field-type selector (text, textarea, richtext, number, boolean, date, media, relation).
  - Validation rules, filterable (database index impact note), sortable, and required toggles.
  - Live editor form preview tab.
  - Declarative Schema-as-Code JSON viewer with copy action.
  - Migration plan generation (`POST /api/v1/schema/plan`) with SQL diff preview, risk warnings, and Apply confirmation (`POST /api/v1/schema/apply/{id}`).
- **OpenAPI Explorer (`/developer/api`)**:
  - Built `ApiExplorerView` ([admin/src/developer/api-explorer-view.tsx](file:///c:/ai/bs-sprint-a/admin/src/developer/api-explorer-view.tsx)).
  - Displays registered REST endpoints, methods, tags, and link to `/api/v1/openapi.json`.
- **Roles Matrix & "Preview as role" (`/users/roles`)**:
  - Updated `RolesMatrixView` ([admin/src/users/roles-matrix-view.tsx](file:///c:/ai/bs-sprint-a/admin/src/users/roles-matrix-view.tsx)).
  - Added "Preview as role" capability simulating what a user in that role can see and do across all canonical subjects.
  - Added granular custom role editor with allow/deny/inherit action buttons and an "own-entries only" scoping toggle.

---

### 1.4 Backend Gaps & Additive Wiring
All backend gaps were created in new controller files under `BsCms\Http\Controllers\SprintA` and wired strictly inside `// SPRINT-A BEGIN ... SPRINT-A END` blocks:
- `SecuritySettingsController.php`:
  - `GET /api/v1/settings/security`
  - `PUT /api/v1/settings/security`
- `QueueJobsController.php`:
  - `GET /api/v1/jobs`
  - `POST /api/v1/jobs/{id}/retry`
- `AiConnectionsController.php`:
  - `GET /api/v1/ai/grants`
  - `DELETE /api/v1/ai/grants/{id}`
- Core wiring:
  - Appended service registrations in `CoreServiceProvider.php`
  - Appended route definitions in `ApiRoutes.php`
  - Appended container calls in `core/public/index.php`
- Clean syntax verified via `php -l`.

---

## 2. Key Endpoints Used & Contracts
- `GET /api/v1/redirects`, `POST /api/v1/redirects`, `PATCH /api/v1/redirects/{id}`, `DELETE /api/v1/redirects/{id}`, `POST /api/v1/redirects/bulk`, `POST /api/v1/redirects/import`, `GET /api/v1/redirects/export`
- `GET /api/v1/site-health`, `POST /api/v1/site-health/run`
- `GET /api/v1/settings/security`, `PUT /api/v1/settings/security`
- `GET /api/v1/tokens`, `POST /api/v1/tokens`, `DELETE /api/v1/tokens/{id}`
- `GET /api/v1/webhooks`, `POST /api/v1/webhooks`, `PUT /api/v1/webhooks/{id}`, `DELETE /api/v1/webhooks/{id}`, `POST /api/v1/webhooks/{id}/test`, `GET /api/v1/webhooks/{id}/deliveries`, `POST /api/v1/deliveries/{id}/retry`
- `GET /api/v1/email-log`, `POST /api/v1/email-log/{id}/resend`
- `GET /api/v1/settings/publishing`, `PUT /api/v1/settings/publishing`, `POST /api/v1/deployments/trigger`, `GET /api/v1/deployments`, `GET /api/v1/tokens/build`, `POST /api/v1/tokens/build`, `DELETE /api/v1/tokens/build/{id}`
- `GET /api/v1/ai/status`, `POST /api/v1/ai/disconnect`, `POST /api/v1/ai/reconnect`, `GET /api/v1/ai/grants`, `DELETE /api/v1/ai/grants/{id}`
- `GET /api/v1/pending-changes`, `POST /api/v1/pending-changes/{id}/approve`, `POST /api/v1/pending-changes/{id}/reject`
- `GET /api/v1/jobs`, `POST /api/v1/jobs/{id}/retry`
- `GET /api/v1/collections`, `POST /api/v1/schema/plan`, `POST /api/v1/schema/apply/{id}`
- `GET /api/v1/roles`, `PUT /api/v1/roles/{id}/permissions`

---

## 3. Known Gaps & Future Work
- Schema Builder visual drag-and-drop: currently uses accessible up/down arrow buttons for field ordering; drag handles can be layered with dnd-kit in a subsequent sprint.
- Visual relations graph in Schema Builder: relation builder configures target collection metadata; interactive SVG node graph is planned for the v2 schema visualizer.
- Swagger UI / Redoc embed: API Explorer links to `/api/v1/openapi.json` and renders an endpoint list; full Swagger UI script bundle can be embedded when CDN/offline asset policies are formalized.
