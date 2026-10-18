# Phase 3: Schema engine, operations layer, REST API and entries

**Goal:** the heart of the CMS. Collections and fields are defined as data (and as files), stored in real tables, and every action goes through one **operations layer** that REST (now), the admin (Phases 5-6) and MCP (Phase 8) share. At the end of this phase the API contract is **frozen** (versioned `v1`).
**Depends on:** Phases 1-2.

## 3.1 Migrations (DDL exactly as `docs/01-data-model.md`)
`collections`, `fields`, `schema_changes`, `entry_versions`, `saved_views`, `webhooks`, `webhook_deliveries`, plus the generated collection tables for the built-ins (3.9).

## 3.2 Operations layer (`core/src/Operations/`)
Every capability is a class implementing:
```php
interface Operation {
    public static function name(): string;              // 'entries.create'
    public static function describe(): OperationMeta;  // summary, input JSON Schema, output schema, flags
    public function authorize(Context $ctx, array $input): void;   // throws Forbidden
    public function handle(Context $ctx, array $input): Result;
}
```
`OperationMeta` flags: `mutates`, `destructive`, `bulk`, `idempotent`, `requiresStepUp`, `aiPolicy` (`allowed` | `draft_only_blocked` | `needs_approval`), `rateLimitBucket`.
`Dispatcher::run(name, input, Context)` does, in order: input validation against the JSON Schema -> `authorize` -> AI-mode enforcement (Phase 8 hooks in here; in this phase implement the `draft_only` rejection for API tokens with that mode) -> transaction -> `handle` -> audit log entry -> domain events -> response. Nothing may bypass the dispatcher.
`Context` carries: `Actor`, request id, ip, user agent, clock, `dryRun` flag, `confirmToken` (for two-step destructive ops).
**Two-step confirmation:** destructive or bulk operations above a threshold (deleting published entries, bulk > 10 items, schema purge) first return `409` with a `confirm_token` (valid 5 min, bound to input hash); the client repeats the call with the token.

## 3.3 Field type system (`core/src/Schema/FieldTypes/`)
One class per type listed in `docs/01-data-model.md` "Field types" table, implementing:
`validateConfig(array $config)`, `jsonSchema(array $config)` (used for API input validation, OpenAPI and MCP), `normalize($value)`, `validate($value, ValidationContext)`, `storage()` (`column` type + DDL or `json`), `toApi($value, PopulateContext)`, `filterOperators()`, `sortable()`.
In this phase implement all types except `richtext`, `media`, `taxonomy`, `blocks` storage details that depend on Phase 4 (define the classes; full behaviour lands in Phase 4).

## 3.4 Schema engine (`core/src/Schema/`)
- `CollectionRegistry`: loads collections + fields once into a compiled cache (`storage/cache/schema.php`), invalidated by `schema_version` change. All other code reads schema from here (never from DB per request).
- `SchemaPlanner`: given a desired collection definition (from admin, file or AI), produces a **plan**: list of changes, SQL statements, risk flags (`data_loss`, `long_running`, `locks_table`), estimated rows affected. `propose` returns the plan and stores a `schema_changes` row with `status=proposed`.
- `SchemaApplier`: executes a proposed plan (step-up required for humans; approval for AI in Phase 8), with `GET_LOCK('bs_schema', 30)`, creates/alters the `c_<handle>` table, generated columns + indexes for `is_filterable`/`is_sortable`, updates registry rows, bumps `schema_version`, records SQL and result. Large tables (> 20k rows, or slow `ALTER` measured in Phase 0) run as a background job with progress shown.
- Rules (from `docs/01-data-model.md` section 2): handles validated by regex and reserved list; system columns (`id, slug, title, status, locale, parent_id, position, author_id, published_at, scheduled_at, seo, data, search_text, created_at, created_by, updated_at, updated_by, deleted_at`) cannot be used as field handles; removing a field hides it (data kept); `purge` is a separate destructive operation; changing a field type is allowed only between compatible types (text <-> textarea, enum -> text, number int -> decimal), otherwise create a new field and migrate.
- **Schema as code:** `bs schema:export [--dir]` writes one JSON file per collection/taxonomy (stable key order, no ids); `bs schema:diff --dir`, `bs schema:apply --dir [--dry-run]`. Same planner/applier as the UI. The admin shows "managed in code" badge for collections imported from files (still editable; drift is reported).

## 3.5 Entries (`core/src/Content/`)
- `EntryRepository` per collection table using the query builder; `title` mirrored to its column; non-column fields in `data`; `search_text` maintained on save.
- **Statuses:** `draft`, `in_review`, `scheduled`, `published`, `archived`, plus soft delete (`deleted_at`) as "Trash". Transitions validated (e.g. `scheduled` requires future `scheduled_at`; publishing sets `published_at` if empty). `publish_scheduled` scheduler task publishes due entries every minute (jobs).
- **Slugs:** generated from `slug_source` (transliterate to ASCII, lowercase, hyphenate, max 120), unique per collection + locale; editing slug of a published entry creates an automatic redirect (Phase 4 table; in Phase 3 emit the event).
- **Versions:** every save writes `entry_versions` (autosave flag for editor autosaves, throttled to one per 30 s per user/entry); restore creates a new version; compare endpoint returns field-level diff.
- **Optimistic concurrency:** every entry response includes `version` (the latest `version_no`). Content updates (`PUT`/`PATCH`) strictly require `If-Match: <version>` (`428 Precondition Required` if missing). State-change endpoints (`publish`, `unpublish`, `archive`, `restore`, `schedule`, `submit-review`) evaluate `If-Match` when provided (`409 Conflict` if stale) and otherwise act on the current version. Stale version mismatch returns `409 Conflict` with ETag and current version details.
- **Entry Presentation:** authenticated entry responses return custom fields at the top level with no redundant `data` blob, stripped of internal audit columns (`search_text`, `created_by`, `updated_by`).
- **Hierarchy** (`pages`): `parent_id` validation (no cycles, max depth 6), ordered children via `position`.
- **Singletons:** `kind=single` collections have exactly one row (created on collection creation); API exposes `GET/PUT /api/v1/singletons/{handle}`.
- **Relations:** `relation` fields stored in `entry_relations` (cardinality enforced, inverse fields resolved at read), cleaned on purge.
- **Duplicate:** copies data, appends "(copy)" to title, new slug, status draft.

## 3.6 REST API v1 (contract freeze at end of phase)
Conventions: JSON only; `snake_case`; ULIDs; timestamps ISO-8601 UTC; errors RFC 9457; collection handle in path.

| Method & path | Operation |
|---|---|
| `GET /api/v1/collections` / `GET /collections/{c}` | list / describe (fields, features, permissions for the current actor) |
| `POST /api/v1/schema/plan` | propose collection/field changes (returns plan) |
| `POST /api/v1/schema/apply/{change_id}` | apply a proposed plan |
| `GET /api/v1/schema/changes` | history |
| `GET /api/v1/collections/{c}/entries` | list with filters, sort, search, fields, populate, cursor/offset pagination, `status` tabs counts (`include_counts=true`) |
| `POST /collections/{c}/entries` | create |
| `GET /collections/{c}/entries/{id}` | get (with `populate`, `fields`) |
| `PATCH /collections/{c}/entries/{id}` | partial update (requires version) |
| `DELETE /collections/{c}/entries/{id}` | move to trash; `?permanent=true` purges (destructive, confirm token) |
| `POST /entries/{id}/publish` `/unpublish` `/schedule` `/submit-review` `/archive` `/restore` `/duplicate` | status operations |
| `GET /entries/{id}/versions`, `GET /versions/{vid}`, `POST /versions/{vid}/restore`, `GET /entries/{id}/compare?from=&to=` | history |
| `POST /collections/{c}/entries/bulk` | `{action, ids?|filter?, params}` actions: publish, unpublish, archive, trash, restore, delete_permanent, set_status, set_author, add_terms, remove_terms, duplicate, export; returns per-item results; large sets become a job with progress (`GET /api/v1/jobs/{id}`) |
| `GET /collections/{c}/entries/export?format=csv|json` | export (job for > 1,000 rows, notification when ready) |
| `GET/PUT /api/v1/singletons/{handle}` | singleton read/update |
| `GET/POST /api/v1/saved-views`, `PATCH/DELETE /saved-views/{id}` | per-user views |
| `GET/POST /api/v1/webhooks`, `PATCH/DELETE /webhooks/{id}`, `POST /webhooks/{id}/test`, `GET /webhooks/{id}/deliveries`, `POST /deliveries/{id}/retry` | webhooks |
| `GET /api/v1/public/collections/{c}/entries` and `/public/collections/{c}/entries/by-slug/{slug}` | published-only read API for build tokens and the public; cacheable (`ETag`, `Cache-Control: public, max-age=60, stale-while-revalidate=600`) |
| `GET /api/v1/openapi.json` | generated OpenAPI 3.1 from operations + field schemas (per actor permission it can hide collections) |
| `GET /api/v1/jobs/{id}` | job progress |

**Query language** (validated against registry, otherwise 400):
- `filter[<field>][<op>]=value` with ops per type: `eq, ne, in, nin, lt, lte, gt, gte, between, contains, starts_with, null` (`null=true|false`); relation/taxonomy fields accept ids or slugs; taxonomy filters support `include_descendants=true` (Phase 4).
- `search=` full-text over `title` + `search_text` (MySQL `MATCH ... AGAINST` in boolean mode, sanitised).
- `sort=-published_at,title` (only sortable fields; max 3 keys; always tie-break by `id`).
- `fields=title,slug,status` sparse fieldsets; `populate=authors,categories` max depth 2, max 5 relations, each populated list capped (25).
- Pagination: `page[size]` (1-100, default 25), cursor `page[after]` (default for API) or `page[number]` (admin tables); responses include `meta.total` (exact up to 10,000, then `meta.total_estimate`) and `links.next`.
- Limits: max 20 filter clauses, max URL 8 KB; queries timing out at 5 s return 503 with advice.
- **Filters only on indexed fields:** filters and sorts are accepted only for system columns and fields flagged `is_filterable`/`is_sortable` (which have generated columns + indexes). Filtering on an unindexed JSON path is rejected with a message suggesting to mark the field filterable (Hostinger benchmark: unindexed JSON filter on 50k posts took 313-365 ms vs 16-23 ms indexed).
- **Cursor predicate form:** write keyset conditions as `published_at < ? OR (published_at = ? AND id < ?)`, not the row-constructor form `(published_at, id) < (?, ?)`, which MariaDB did not use as an index range in the benchmark (deep cursor was no faster than offset).

**N+1 prevention:** populate loads related rows in batches (`WHERE id IN (...)`); a test asserts the number of queries for a list of 25 entries with 3 populates is <= 6.

## 3.7 Events, hooks and webhooks
- In-process event bus (`entry.created/updated/published/unpublished/deleted/restored`, `schema.applied`, `user.*`, `settings.updated` ...). Listeners registered by core modules now, plugins later.
- Webhooks: deliveries queued as jobs; payload `{event, occurred_at, data, actor}` signed with HMAC-SHA256 header `X-BS-Signature: t=<ts>,v1=<sig>`; retries 1, 5, 30, 120 min, max 6; auto-disable after 50 consecutive failures with an owner notification; SSRF-safe client.

## 3.8 Caching
- Entry read cache keyed by collection + id + schema version, invalidated on write (APCu if available, else none; DB is fast enough for single reads).
- Public API: full response cache (file cache) for list/slug endpoints with tag-based invalidation (`collection:posts`), plus HTTP `ETag`/`304`.

## 3.9 Built-in collections (seed migration, using the schema engine itself)
Create exactly as `docs/01-data-model.md` section 3, with the fields possible in this phase: `pages` (hierarchical; no taxonomies), `posts`, `authors`, singletons `site_settings`, `navigation`. Fields that need Phase 4 types (`body` richtext, `featured_image` media, `categories`/`tags` taxonomy, `faq`, `blocks`) are added by a Phase 4 migration through the same planner, so this phase must leave the definitions ready.
`site_settings` includes `environment` (`production`|`staging`), `canonical_base_url`, organization fields; changing `environment` requires step-up + confirm token.

## 3.10 Tests
- Field types: config validation, normalize/validate, JSON Schema generation snapshot, storage DDL.
- Schema planner: add/alter/hide/purge/rename; incompatible type change rejected; reserved handles rejected; SQL snapshots; apply + rollback on failure; concurrent apply blocked by lock.
- Entries: CRUD, statuses and transitions, scheduling job, slugs (unicode, collisions, soft-delete suffix), versions + restore + compare, optimistic concurrency conflict, hierarchy cycles, singletons, relations (cardinality, inverse, cleanup).
- Query language: every operator per type, invalid field/op -> 400, injection attempts in field names/values, populate limits, sort tie-break, cursor stability under inserts.
- Authorization at operation level for each role (data-driven), field-level read/write filtering, `owner=self` condition, API token `draft_only` mode blocked from publish/delete.
- Two-step confirmation for destructive/bulk ops.
- Bulk: partial failures reported per item; job path for big sets.
- Webhooks: signature verification sample, retries, SSRF block.
- Performance: list endpoint with 50k rows meets budget on CI MySQL; query count test.

## 3.11 Acceptance checklist
- [ ] Every row in the 3.6 table implemented, documented in `/api/v1/openapi.json`, and covered by HTTP tests.
- [ ] Create a custom "Campaigns" collection with 6 field types via API, then export it with `schema:export`, delete the test DB, and recreate it with `schema:apply` identically.
- [ ] Built-in collections present after install.
- [ ] API contract frozen: `openapi.json` committed as `docs/api/openapi-v1.json`; later changes must be additive or bump the version.
- [ ] Budgets met on both shared hosts (list p95 < 150 ms with 10k posts).
