# 01. Data model (BS CMS)

Status: draft for implementation. Read `PLAN.md` first. Every table below must exist in the first migration set unless marked *(later)*. Do not skip a table or column because it looks optional: other specs (UI, auth, discoverability) depend on it.

## 0. Conventions

- Engine InnoDB, charset `utf8mb4`, collation `utf8mb4_unicode_ci` (works on both MySQL 8 and MariaDB; do not use MySQL-only `utf8mb4_0900_*`).
- Primary keys: `CHAR(26)` ULID (sortable, safe to expose). Join tables use composite keys. Auto-increment ints only for log-style tables (`audit_log`, `jobs`, `email_log`, `bot_log`).
- Timestamps: `DATETIME(3)` stored in UTC. Names: `created_at`, `updated_at`, `deleted_at` (soft delete where noted).
- Actor columns: `created_by`, `updated_by` hold a user id; when an AI client acts, the user who authorised it is stored here and the client is recorded in `audit_log.actor_*`.
- JSON columns: `JSON` type. On MariaDB `JSON` is an alias of `LONGTEXT`; use `JSON_UNQUOTE(JSON_EXTRACT(col,'$.path'))` (works on both) in generated columns, not `->>`. **(verify on target MariaDB version in the Phase 0 spike.)**
- Booleans: `TINYINT(1)`. Enums: use `VARCHAR` + check in application code for portability where the set can grow (plugins), `ENUM` only for closed sets.
- Every table with user-visible rows has `created_at`, `updated_at`.
- No foreign keys on plugin-owned tables pointing into core tables except by id; core tables use real foreign keys with `ON DELETE` behaviour stated below.
- Migrations are namespaced: `core`, `plugin:<handle>`. Each migration is idempotent-safe and has `up()` and `down()`.
- **Table prefix:** every table name gets a configurable prefix (default `bs_`, e.g. `bs_users`, `bs_c_posts`), because shared hosting often gives one database to several apps. DDL in this document omits the prefix for readability; the migration runner adds it.
- **ID columns** (`id` and every `*_id` holding a ULID) are declared `CHAR(26) CHARACTER SET ascii COLLATE ascii_bin` to halve index size and make comparisons byte-exact. Hash columns (`*_hash CHAR(64)`) use the same ascii charset.
- **Reserved words:** no column may be named with a MySQL/MariaDB reserved word. Identifier columns are called `handle` (not `key`); rule sets are `conditions` (not `condition`).
- **Identifiers from users** (collection, field, taxonomy handles) must match `^[a-z][a-z0-9_]{1,40}$`, must not be a reserved word or a system column name, and are always quoted with backticks in generated SQL. Values are always bound parameters; identifiers are never built from raw request input, only from the validated field registry.
- **Minimum database versions:** MySQL 8.0+ or MariaDB 10.6+ (installer refuses older versions). No feature here needs recursive CTEs or window functions.
- **No cross-table foreign keys into dynamic collection tables** (`c_*`): `entry_relations`, `term_relationships`, `media_usage`, `entry_versions` are cleaned up by the content engine when an entry is purged (one transaction), and a nightly integrity job reports orphans.
- **Retention** (settings, defaults shown; pruning runs as a daily job in small batches): `audit_log` 180 days, `entry_versions` keep the latest 50 per entry plus every published version (autosaves pruned after 7 days), `jobs` done/failed 14 days, `email_log` 90 days, `webhook_deliveries` 30 days, `index_notifications` 30 days, `sessions` expired 7 days, `rate_limits` older than 1 day.

## 1. Identity and access

```sql
CREATE TABLE users (
  id CHAR(26) PRIMARY KEY,
  email VARCHAR(190) NOT NULL,
  email_verified_at DATETIME(3) NULL,
  password_hash VARCHAR(255) NULL,            -- NULL until invite accepted
  name VARCHAR(190) NOT NULL,
  avatar_media_id CHAR(26) NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active', -- active | invited | suspended
  locale VARCHAR(10) NOT NULL DEFAULT 'en',
  timezone VARCHAR(64) NOT NULL DEFAULT 'UTC',
  totp_secret_enc VARBINARY(512) NULL,         -- encrypted with app key
  totp_enabled_at DATETIME(3) NULL,
  totp_last_step BIGINT NULL,                  -- last accepted RFC 6238 time step for replay protection
  recovery_codes_hash JSON NULL,               -- array of hashed one-time codes
  failed_logins SMALLINT NOT NULL DEFAULT 0,
  locked_until DATETIME(3) NULL,
  last_login_at DATETIME(3) NULL,
  last_login_ip VARCHAR(45) NULL,
  created_at DATETIME(3) NOT NULL,
  updated_at DATETIME(3) NOT NULL,
  deleted_at DATETIME(3) NULL,
  UNIQUE KEY uq_users_email (email)
);

CREATE TABLE roles (
  id CHAR(26) PRIMARY KEY,
  handle VARCHAR(64) NOT NULL,                  -- owner, admin, developer, editor, author, contributor, viewer, ai_draft, ai_publisher, custom keys
  name VARCHAR(120) NOT NULL,
  description VARCHAR(500) NULL,
  is_system TINYINT(1) NOT NULL DEFAULT 0,     -- system roles cannot be deleted; owner cannot be edited
  is_ai TINYINT(1) NOT NULL DEFAULT 0,         -- usable for AI clients / API tokens
  created_at DATETIME(3) NOT NULL,
  updated_at DATETIME(3) NOT NULL,
  UNIQUE KEY uq_roles_key (handle)
);

CREATE TABLE user_roles (
  user_id CHAR(26) NOT NULL,
  role_id CHAR(26) NOT NULL,
  PRIMARY KEY (user_id, role_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
);

-- One row = one rule. Effective permission = union of allows minus explicit denies.
CREATE TABLE role_permissions (
  id CHAR(26) PRIMARY KEY,
  role_id CHAR(26) NOT NULL,
  subject_type VARCHAR(32) NOT NULL,   -- collection | taxonomy | media | users | roles | settings | schema | ai | webhooks | redirects | plugins | audit | system
  subject_key VARCHAR(120) NOT NULL,   -- collection key, taxonomy key, or '*'
  action VARCHAR(32) NOT NULL,         -- read | create | update | delete | publish | unpublish | schedule | export | import | manage
  effect VARCHAR(5) NOT NULL DEFAULT 'allow', -- allow | deny
  fields JSON NULL,                    -- {"allow":[...]} or {"deny":[...]} field-level control; NULL = all fields
  conditions JSON NULL,               -- e.g. {"owner":"self"} = only entries created by the user; {"status":["draft"]}
  created_at DATETIME(3) NOT NULL,
  FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  KEY ix_perm_role (role_id, subject_type, subject_key)
);

CREATE TABLE sessions (
  id CHAR(26) PRIMARY KEY,
  user_id CHAR(26) NOT NULL,
  token_hash CHAR(64) NOT NULL,        -- SHA-256 of the cookie token
  csrf_secret CHAR(32) NOT NULL,
  ip VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  mfa_passed_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL,
  last_seen_at DATETIME(3) NOT NULL,
  expires_at DATETIME(3) NOT NULL,
  revoked_at DATETIME(3) NULL,
  UNIQUE KEY uq_sessions_token (token_hash),
  KEY ix_sessions_user (user_id),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE password_resets (
  id CHAR(26) PRIMARY KEY,
  user_id CHAR(26) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  requested_ip VARCHAR(45) NULL,
  expires_at DATETIME(3) NOT NULL,
  used_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL,
  UNIQUE KEY uq_pwreset_token (token_hash),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE email_verifications (
  id CHAR(26) PRIMARY KEY,
  user_id CHAR(26) NOT NULL,
  new_email VARCHAR(190) NULL,         -- set when changing email
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME(3) NOT NULL,
  used_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL,
  UNIQUE KEY uq_emailver_token (token_hash),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE invitations (
  id CHAR(26) PRIMARY KEY,
  email VARCHAR(190) NOT NULL,
  role_id CHAR(26) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  invited_by CHAR(26) NOT NULL,
  expires_at DATETIME(3) NOT NULL,
  accepted_at DATETIME(3) NULL,
  revoked_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL,
  UNIQUE KEY uq_invite_token (token_hash),
  KEY ix_invite_email (email)
);

CREATE TABLE api_tokens (
  id CHAR(26) PRIMARY KEY,
  user_id CHAR(26) NOT NULL,           -- the human who owns it
  role_id CHAR(26) NULL,               -- optional: restrict to a role narrower than the user's
  name VARCHAR(120) NOT NULL,
  token_prefix CHAR(8) NOT NULL,       -- shown in UI to identify
  token_hash CHAR(64) NOT NULL,
  scopes JSON NULL,
  ai_mode VARCHAR(20) NOT NULL DEFAULT 'draft_only', -- draft_only | approval_required | full
  last_used_at DATETIME(3) NULL,
  last_used_ip VARCHAR(45) NULL,
  expires_at DATETIME(3) NULL,
  revoked_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL,
  UNIQUE KEY uq_apitoken_hash (token_hash),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

### OAuth 2.1 authorization server (for MCP clients such as Claude and ChatGPT)

```sql
CREATE TABLE oauth_clients (
  id CHAR(26) PRIMARY KEY,
  client_id VARCHAR(255) NOT NULL,     -- may be a client-metadata-document URL
  name VARCHAR(190) NOT NULL,
  redirect_uris JSON NOT NULL,
  is_public TINYINT(1) NOT NULL DEFAULT 1,      -- public clients use PKCE, no secret
  client_secret_hash VARCHAR(255) NULL,
  registration_method VARCHAR(20) NOT NULL,     -- dynamic | metadata_document | manual
  metadata JSON NULL,
  created_at DATETIME(3) NOT NULL,
  revoked_at DATETIME(3) NULL,
  UNIQUE KEY uq_oauth_client (client_id)
);

CREATE TABLE oauth_grants (            -- the owner's consent record: "Claude may do X on this site"
  id CHAR(26) PRIMARY KEY,
  user_id CHAR(26) NOT NULL,
  client_id CHAR(26) NOT NULL,
  role_id CHAR(26) NOT NULL,            -- role the AI acts with
  scopes JSON NOT NULL,
  ai_mode VARCHAR(20) NOT NULL DEFAULT 'draft_only',
  created_at DATETIME(3) NOT NULL,
  last_used_at DATETIME(3) NULL,
  revoked_at DATETIME(3) NULL,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (client_id) REFERENCES oauth_clients(id) ON DELETE CASCADE
);

CREATE TABLE oauth_authorization_codes (
  code_hash CHAR(64) PRIMARY KEY,
  grant_id CHAR(26) NOT NULL,
  code_challenge VARCHAR(128) NOT NULL,
  code_challenge_method VARCHAR(10) NOT NULL DEFAULT 'S256',
  redirect_uri VARCHAR(2048) NOT NULL,
  resource VARCHAR(2048) NULL,          -- RFC 8707 resource indicator
  expires_at DATETIME(3) NOT NULL,
  used_at DATETIME(3) NULL
);

CREATE TABLE oauth_access_tokens (
  token_hash CHAR(64) PRIMARY KEY,
  grant_id CHAR(26) NOT NULL,
  expires_at DATETIME(3) NOT NULL,
  revoked_at DATETIME(3) NULL,
  KEY ix_oat_grant (grant_id)
);

CREATE TABLE oauth_refresh_tokens (
  token_hash CHAR(64) PRIMARY KEY,
  access_token_hash CHAR(64) NULL,
  grant_id CHAR(26) NOT NULL,
  expires_at DATETIME(3) NOT NULL,
  rotated_at DATETIME(3) NULL,          -- refresh tokens rotate on every use
  revoked_at DATETIME(3) NULL
);
```

## 2. Schema registry (collections and fields)

```sql
CREATE TABLE collections (
  id CHAR(26) PRIMARY KEY,
  handle VARCHAR(64) NOT NULL,           -- 'posts', 'pages', 'campaigns'
  kind VARCHAR(12) NOT NULL DEFAULT 'collection', -- collection | single (singleton, e.g. site settings, header, footer)
  label_singular VARCHAR(120) NOT NULL,
  label_plural VARCHAR(120) NOT NULL,
  description VARCHAR(500) NULL,
  icon VARCHAR(64) NULL,
  table_name VARCHAR(64) NOT NULL,      -- 'c_posts'
  is_system TINYINT(1) NOT NULL DEFAULT 0,       -- pages, posts are system but editable
  has_public_pages TINYINT(1) NOT NULL DEFAULT 1,
  route_pattern VARCHAR(255) NULL,      -- '/blog/{slug}', '/campaigns/{slug}'
  list_route_pattern VARCHAR(255) NULL, -- '/blog'
  features JSON NOT NULL,               -- {"drafts":true,"versions":true,"scheduling":true,"seo":true,"hierarchy":false,"soft_delete":true,"manual_order":false}
  title_field VARCHAR(64) NOT NULL DEFAULT 'title',
  slug_source VARCHAR(64) NULL DEFAULT 'title',
  default_sort VARCHAR(64) NOT NULL DEFAULT '-updated_at',
  schema_org JSON NULL,                 -- {"type":"BlogPosting","map":{"headline":"title","datePublished":"published_at",...}}
  list_columns JSON NULL,               -- default visible columns in admin list
  plugin_key VARCHAR(64) NULL,          -- owning plugin, NULL for core/user
  schema_version INT NOT NULL DEFAULT 1,
  created_at DATETIME(3) NOT NULL,
  updated_at DATETIME(3) NOT NULL,
  UNIQUE KEY uq_collections_key (handle),
  UNIQUE KEY uq_collections_table (table_name)
);

CREATE TABLE fields (
  id CHAR(26) PRIMARY KEY,
  collection_id CHAR(26) NOT NULL,
  handle VARCHAR(64) NOT NULL,
  label VARCHAR(120) NOT NULL,
  type VARCHAR(32) NOT NULL,            -- see "Field types" below
  config JSON NOT NULL,                 -- type-specific options (min, max, options, relation target, allowed mime, ...)
  required TINYINT(1) NOT NULL DEFAULT 0,
  is_unique TINYINT(1) NOT NULL DEFAULT 0,
  is_filterable TINYINT(1) NOT NULL DEFAULT 0,   -- creates real/generated column + index
  is_sortable TINYINT(1) NOT NULL DEFAULT 0,
  is_searchable TINYINT(1) NOT NULL DEFAULT 0,   -- included in full-text index
  show_in_list TINYINT(1) NOT NULL DEFAULT 0,
  default_value JSON NULL,
  help_text VARCHAR(500) NULL,
  placeholder VARCHAR(190) NULL,
  group_key VARCHAR(64) NULL,           -- editor tab / section
  position INT NOT NULL DEFAULT 0,
  width VARCHAR(8) NOT NULL DEFAULT 'full', -- full | half | third
  validation JSON NULL,
  conditional JSON NULL,                -- show field when another field has a value
  storage VARCHAR(16) NOT NULL DEFAULT 'json',   -- json | column
  column_name VARCHAR(64) NULL,
  created_at DATETIME(3) NOT NULL,
  updated_at DATETIME(3) NOT NULL,
  UNIQUE KEY uq_fields_key (collection_id, handle),
  FOREIGN KEY (collection_id) REFERENCES collections(id) ON DELETE CASCADE
);
```

### Field types (all must be implemented in API validation, admin form, admin list cell, MCP schema, and TypeScript client generator)

| Type | Stored as | Admin control | Notes |
|---|---|---|---|
| `text` | VARCHAR / JSON string | Input | min/max length, regex, prefix/suffix |
| `textarea` | TEXT | Textarea with counter | max length |
| `richtext` | JSON (editor document tree, validated against a node/mark allow-list) | Rich text editor (Tiptap, profile `article` or `minimal`); see `docs/05-editor.md` | rendered to HTML and markdown by the same renderer; API also accepts Markdown input and converts it; derived fields (excerpt, reading time, outline, image ids, internal links, FAQ items) computed on save |
| `markdown` | TEXT | Markdown editor with preview | optional alternative to richtext |
| `number` | INT / DECIMAL | Number input with step, unit | min, max, precision |
| `boolean` | TINYINT | Switch | |
| `date` | DATE | Date picker (calendar popover) | |
| `datetime` | DATETIME(3) UTC | Date-time picker with timezone display | |
| `time` | TIME | Time input | |
| `enum` | VARCHAR | Select / Radio group / Segmented | options list with label + value + color |
| `multi_enum` | JSON array | Multi-select | |
| `slug` | VARCHAR | Input linked to source field | unique, auto-regenerate until edited |
| `email`, `url`, `phone` | VARCHAR | Typed input | validated |
| `color` | VARCHAR | Color picker | |
| `json` | JSON | Code editor | optional JSON Schema validation |
| `media` | media id(s) | Media picker (single or multiple) | restrict mime types, min/max count |
| `relation` | `entry_relations` rows | Searchable async combobox / multi-select | one-to-one, one-to-many, many-to-many; target collection; optional inverse field |
| `taxonomy` | `term_relationships` rows | Term picker (tree for hierarchical) | bound to a taxonomy; see section 4 |
| `user` | user id | User picker | author / owner |
| `repeater` | JSON array | Field array (add, remove, reorder) | nested field definitions |
| `group` | JSON object | Section of nested fields | |
| `blocks` | JSON array of typed blocks | Block editor (add, reorder, collapse) | block types registered by theme / plugins |
| `faq` | JSON array {q,a} | Field array | feeds `FAQPage` structured data |
| `seo` | JSON | SEO panel | see section 6; present automatically when feature `seo` is on |
| `computed` | not stored | read-only | derived, e.g. reading time |

### Per-collection data table (template, generated by the schema engine)

```sql
CREATE TABLE c_posts (
  id CHAR(26) PRIMARY KEY,
  slug VARCHAR(191) NOT NULL,
  title VARCHAR(255) NOT NULL,                 -- title_field mirrored here for fast lists and search
  status VARCHAR(12) NOT NULL DEFAULT 'draft', -- draft | in_review | scheduled | published | archived
  locale CHAR(5) NOT NULL DEFAULT 'en',        -- reserved; English only in v1
  parent_id CHAR(26) NULL,                     -- only when features.hierarchy (pages)
  position INT NOT NULL DEFAULT 0,             -- only when features.manual_order
  author_id CHAR(26) NULL,                     -- users.id or authors.id depending on field config
  published_at DATETIME(3) NULL,
  scheduled_at DATETIME(3) NULL,
  seo JSON NULL,
  data JSON NOT NULL,                          -- all non-column fields
  -- generated + indexed columns for fields flagged is_filterable / is_sortable, example:
  -- reading_time INT GENERATED ALWAYS AS (JSON_EXTRACT(data,'$.reading_time')) VIRTUAL, KEY ix_posts_reading_time (reading_time),
  created_at DATETIME(3) NOT NULL,
  created_by CHAR(26) NULL,
  updated_at DATETIME(3) NOT NULL,
  updated_by CHAR(26) NULL,
  deleted_at DATETIME(3) NULL,
  UNIQUE KEY uq_posts_slug (slug, locale),     -- soft-deleted rows get a suffix on slug (a unique key including a NULL deleted_at would NOT stop duplicates)
  KEY ix_posts_status_pub (status, published_at),
  KEY ix_posts_updated (updated_at),
  KEY ix_posts_author (author_id),
  FULLTEXT KEY ft_posts (title)                -- extended with searchable fields' text via a maintained `search_text` column
);
```

Rules for the schema engine:
- Slug uniqueness is enforced by the plain unique key `(slug, locale)`; on soft delete append a short suffix (e.g. `-deleted-<id>`) to `slug` so the original slug can be reused, and strip it on restore if free.
- Changing a field to `is_filterable` adds a generated column and index in a versioned migration with a dry-run diff first.
- Removing a field never drops data in the same step: it is marked hidden, data is kept in `data` JSON, and a separate explicit "purge" operation removes it.
- Every schema change writes a row to `schema_changes` (below) and bumps `collections.schema_version`.

```sql
CREATE TABLE schema_changes (
  id CHAR(26) PRIMARY KEY,
  collection_id CHAR(26) NULL,
  change_type VARCHAR(32) NOT NULL,     -- create_collection | add_field | alter_field | hide_field | purge_field | add_index | delete_collection ...
  before_state JSON NULL,
  after_state JSON NULL,
  sql_statements JSON NULL,
  applied_by CHAR(26) NULL,
  applied_via VARCHAR(20) NOT NULL,     -- admin | cli | ai | plugin
  dry_run TINYINT(1) NOT NULL DEFAULT 0,
  status VARCHAR(16) NOT NULL,          -- proposed | applied | failed | rolled_back
  created_at DATETIME(3) NOT NULL
);
```

## 3. Built-in collections (shipped on day one)

### `pages` (hierarchical, no taxonomies)
Fields: `title` (text, required), `slug` (slug), `parent_id` (hierarchy via page tree), `template` (enum: default, landing, full-width; extended by theme), `summary` (textarea, "answer summary" for AEO), `blocks` (blocks), `featured_image` (media), `seo` (seo), `show_in_nav` (boolean), `nav_label` (text), `status`, `published_at`.
Features: drafts, versions, scheduling, seo, hierarchy, soft_delete. **No taxonomy fields.**

### `posts` (blog)
Fields: `title`, `slug`, `excerpt` (textarea), `summary` (textarea, answer summary), `body` (richtext), `featured_image` (media), `featured_image_alt` (derived from media alt, overridable), `authors` (relation to `authors`, many), `categories` (taxonomy, hierarchical, multiple), `tags` (taxonomy, flat, multiple), `related_posts` (relation to posts, many), `faq` (faq), `key_facts` (repeater: statement, value, source_url), `reviewed_by` (relation to `authors`), `last_reviewed_at` (date), `reading_time` (computed), `is_featured` (boolean, filterable), `seo`, `published_at`, `status`.
Features: drafts, versions, scheduling, seo, soft_delete.
Public routes: `/blog`, `/blog/{slug}`, `/blog/category/{category-path}`, `/blog/tag/{tag}`, paginated `/blog/page/{n}`, feeds.

### `authors`
Fields: `name`, `slug`, `bio` (textarea), `avatar` (media), `job_title`, `same_as` (repeater of URLs), `user` (optional link to a user). Feeds `Person` structured data and author pages. No taxonomies by default.

### Singletons (`kind = single`)
- `site_settings`: site name, tagline, default language (en), logo, favicon, social links, contact details, organization details (feeds `Organization` JSON-LD), default social image, analytics ids, environment (`production` | `staging`), canonical base URL.
- `navigation`: menus (header, footer, others) as repeater of items {label, link type (page / url / entry), target, children[]}.
- `footer` and `header` content blocks (optional, theme-defined).

## 4. Taxonomies (categories, tags and custom)

Design: a generic taxonomy system, not hard-coded categories. Posts get `categories` (hierarchical) and `tags` (flat). Pages get none. Any custom collection can attach existing taxonomies or define new ones, and relations let collections link to each other.

```sql
CREATE TABLE taxonomies (
  id CHAR(26) PRIMARY KEY,
  handle VARCHAR(64) NOT NULL,             -- 'categories', 'tags', 'campaign_types'
  label_singular VARCHAR(120) NOT NULL,
  label_plural VARCHAR(120) NOT NULL,
  description VARCHAR(500) NULL,
  hierarchical TINYINT(1) NOT NULL DEFAULT 0,
  max_depth TINYINT NULL,                 -- NULL = unlimited (recommend UI cap of 4)
  has_public_archive TINYINT(1) NOT NULL DEFAULT 1,
  archive_route_pattern VARCHAR(255) NULL, -- '/blog/category/{path}'
  is_system TINYINT(1) NOT NULL DEFAULT 0,
  plugin_key VARCHAR(64) NULL,
  created_at DATETIME(3) NOT NULL,
  updated_at DATETIME(3) NOT NULL,
  UNIQUE KEY uq_taxonomies_key (handle)
);

-- Which collections may use which taxonomy
CREATE TABLE collection_taxonomies (
  collection_id CHAR(26) NOT NULL,
  taxonomy_id CHAR(26) NOT NULL,
  required TINYINT(1) NOT NULL DEFAULT 0,
  allow_multiple TINYINT(1) NOT NULL DEFAULT 1,
  field_key VARCHAR(64) NOT NULL,         -- the taxonomy field's key on the collection
  position INT NOT NULL DEFAULT 0,
  PRIMARY KEY (collection_id, taxonomy_id),
  FOREIGN KEY (collection_id) REFERENCES collections(id) ON DELETE CASCADE,
  FOREIGN KEY (taxonomy_id) REFERENCES taxonomies(id) ON DELETE CASCADE
);

CREATE TABLE terms (
  id CHAR(26) PRIMARY KEY,
  taxonomy_id CHAR(26) NOT NULL,
  parent_id CHAR(26) NULL,
  name VARCHAR(190) NOT NULL,
  slug VARCHAR(190) NOT NULL,
  description TEXT NULL,
  path VARCHAR(512) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, -- materialized path of ids: '/01A.../01B.../' for subtree queries (ascii keeps the index small)
  slug_path VARCHAR(1024) CHARACTER SET ascii COLLATE ascii_bin NOT NULL, -- 'news/community/events' for URLs (ascii keeps the index small)
  depth TINYINT NOT NULL DEFAULT 0,
  position INT NOT NULL DEFAULT 0,        -- manual order among siblings
  image_media_id CHAR(26) NULL,
  color VARCHAR(16) NULL,
  icon VARCHAR(64) NULL,
  data JSON NULL,                         -- custom term fields
  seo JSON NULL,
  entry_count INT NOT NULL DEFAULT 0,     -- cached count of published entries, maintained on change
  created_at DATETIME(3) NOT NULL,
  updated_at DATETIME(3) NOT NULL,
  parent_key CHAR(26) GENERATED ALWAYS AS (IFNULL(parent_id, '')) STORED, -- needed because NULLs never collide in a unique key
  UNIQUE KEY uq_terms_slug (taxonomy_id, parent_key, slug),
  KEY ix_terms_path (taxonomy_id, path(191)),
  KEY ix_terms_parent (taxonomy_id, parent_id, position),
  FOREIGN KEY (taxonomy_id) REFERENCES taxonomies(id) ON DELETE CASCADE,
  FOREIGN KEY (parent_id) REFERENCES terms(id) ON DELETE RESTRICT
);

CREATE TABLE term_relationships (
  collection_id CHAR(26) NOT NULL,
  entry_id CHAR(26) NOT NULL,
  term_id CHAR(26) NOT NULL,
  is_primary TINYINT(1) NOT NULL DEFAULT 0, -- primary category used for canonical URL and breadcrumbs
  position INT NOT NULL DEFAULT 0,
  is_published TINYINT(1) NOT NULL DEFAULT 0, -- denormalised from the entry (benchmark: makes term lists index-ordered)
  sort_at DATETIME(3) NULL,                -- denormalised entry published_at; maintained on publish/unpublish/date change/delete
  PRIMARY KEY (collection_id, entry_id, term_id),
  KEY ix_termrel_term (term_id, collection_id),
  KEY ix_termrel_sorted (term_id, collection_id, is_published, sort_at, entry_id),
  FOREIGN KEY (term_id) REFERENCES terms(id) ON DELETE CASCADE
);
```

Hierarchy storage decision: parent id plus materialized path and depth. Rationale: categories are read constantly and changed rarely; subtree queries become one indexed prefix lookup (`path LIKE '/A/B/%'`) and work on MySQL and MariaDB without recursive queries. Moving a term updates the paths of its subtree in one transaction. (Alternatives considered: nested sets, which make writes expensive, and a closure table, which adds a second table; sources agree the choice depends on how often trees change, and none benchmarked this on MySQL.)

Taxonomy behaviour requirements:
- Create, rename, reorder, re-parent (drag and drop), merge terms (reassign entries), delete with a "reassign entries to…" step, and show entry counts.
- Deleting a term with children is blocked until children are moved or deleted.
- A "default term" setting per taxonomy (e.g. Uncategorized) used when none is chosen.
- Filtering: list and API filters accept term ids or slugs, with `include_descendants=true` so choosing "News" also returns entries in its subcategories.
- Public archives: category and tag pages with pagination, canonical URLs, breadcrumbs (`BreadcrumbList` JSON-LD) and SEO fields on each term.
- Custom collections: when a user creates a collection the builder offers "Add taxonomy": pick an existing taxonomy or create a new one (flat or hierarchical). The same taxonomy can be shared by several collections, which is how different content types are linked and filtered together (e.g. a "Programs" taxonomy shared by Campaigns, Posts and Events).
- Entries can also link directly through `relation` fields:

```sql
CREATE TABLE entry_relations (
  from_collection_id CHAR(26) NOT NULL,
  from_entry_id CHAR(26) NOT NULL,
  field_key VARCHAR(64) NOT NULL,
  to_collection_id CHAR(26) NOT NULL,
  to_entry_id CHAR(26) NOT NULL,
  position INT NOT NULL DEFAULT 0,
  PRIMARY KEY (from_collection_id, from_entry_id, field_key, to_entry_id),
  KEY ix_rel_reverse (to_collection_id, to_entry_id, field_key)
);
```

## 5. Content history, media, workflow

```sql
CREATE TABLE entry_versions (
  id CHAR(26) PRIMARY KEY,
  collection_id CHAR(26) NOT NULL,
  entry_id CHAR(26) NOT NULL,
  version_no INT NOT NULL,
  status VARCHAR(12) NOT NULL,
  snapshot JSON NOT NULL,               -- full entry incl. seo and relations
  change_summary VARCHAR(255) NULL,
  actor_type VARCHAR(16) NOT NULL,      -- user | ai_client | system | plugin
  actor_label VARCHAR(190) NULL,
  created_by CHAR(26) NULL,
  is_autosave TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME(3) NOT NULL,
  UNIQUE KEY uq_version (collection_id, entry_id, version_no),
  KEY ix_version_entry (collection_id, entry_id, created_at)
);

CREATE TABLE media_folders (
  id CHAR(26) PRIMARY KEY,
  parent_id CHAR(26) NULL,
  name VARCHAR(190) NOT NULL,
  path VARCHAR(512) NOT NULL,
  created_at DATETIME(3) NOT NULL,
  UNIQUE KEY uq_mediafolder (parent_id, name)
);

CREATE TABLE media (
  id CHAR(26) PRIMARY KEY,
  folder_id CHAR(26) NULL,
  filename VARCHAR(255) NOT NULL,
  original_name VARCHAR(255) NOT NULL,
  mime VARCHAR(120) NOT NULL,
  extension VARCHAR(16) NOT NULL,
  size_bytes BIGINT NOT NULL,
  width INT NULL, height INT NULL, duration_seconds INT NULL,
  content_hash CHAR(64) NOT NULL,       -- dedupe
  storage_driver VARCHAR(16) NOT NULL DEFAULT 'local', -- local | s3
  storage_path VARCHAR(512) NOT NULL,
  alt_text VARCHAR(500) NULL,
  caption VARCHAR(500) NULL,
  title VARCHAR(255) NULL,
  focal_x DECIMAL(5,4) NULL, focal_y DECIMAL(5,4) NULL,
  blurhash VARCHAR(64) NULL,            -- low-res placeholder for skeleton / blur-up
  dominant_color VARCHAR(9) NULL,
  usage_count INT NOT NULL DEFAULT 0,
  uploaded_by CHAR(26) NULL,
  created_at DATETIME(3) NOT NULL,
  updated_at DATETIME(3) NOT NULL,
  deleted_at DATETIME(3) NULL,
  KEY ix_media_folder (folder_id, created_at),
  KEY ix_media_hash (content_hash)
);

CREATE TABLE media_variants (
  id CHAR(26) PRIMARY KEY,
  media_id CHAR(26) NOT NULL,
  preset VARCHAR(32) NOT NULL,          -- thumb, sm, md, lg, og
  format VARCHAR(8) NOT NULL,           -- webp | avif | jpg | png
  width INT NOT NULL, height INT NOT NULL, size_bytes INT NOT NULL,
  storage_path VARCHAR(512) NOT NULL,
  UNIQUE KEY uq_variant (media_id, preset, format),
  FOREIGN KEY (media_id) REFERENCES media(id) ON DELETE CASCADE
);

CREATE TABLE media_usage (              -- where a media item is referenced (to warn before delete)
  media_id CHAR(26) NOT NULL,
  collection_id CHAR(26) NOT NULL,
  entry_id CHAR(26) NOT NULL,
  field_key VARCHAR(64) NOT NULL,
  PRIMARY KEY (media_id, collection_id, entry_id, field_key)
);

-- Changes proposed by AI clients (or any actor) that need human approval
CREATE TABLE pending_changes (
  id CHAR(26) PRIMARY KEY,
  actor_type VARCHAR(16) NOT NULL,
  actor_label VARCHAR(190) NOT NULL,    -- e.g. "Claude (via Anna)"
  oauth_grant_id CHAR(26) NULL,
  api_token_id CHAR(26) NULL,
  on_behalf_of CHAR(26) NOT NULL,
  operation VARCHAR(64) NOT NULL,       -- publish_entry, delete_entry, apply_schema_change...
  subject_type VARCHAR(32) NOT NULL,
  subject_id VARCHAR(64) NULL,
  payload JSON NOT NULL,
  diff JSON NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'pending', -- pending | approved | rejected | expired | applied | failed
  decided_by CHAR(26) NULL,
  decided_at DATETIME(3) NULL,
  decision_note VARCHAR(500) NULL,
  expires_at DATETIME(3) NOT NULL,
  created_at DATETIME(3) NOT NULL,
  KEY ix_pending_status (status, created_at)
);

CREATE TABLE audit_log (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  request_id CHAR(26) NOT NULL,
  actor_type VARCHAR(16) NOT NULL,      -- user | ai_client | api_token | system | plugin
  actor_id VARCHAR(64) NULL,
  actor_label VARCHAR(190) NULL,
  on_behalf_of CHAR(26) NULL,
  action VARCHAR(64) NOT NULL,          -- entry.create, entry.publish, schema.apply, auth.login, settings.update ...
  subject_type VARCHAR(32) NOT NULL,
  subject_id VARCHAR(64) NULL,
  collection_key VARCHAR(64) NULL,
  summary VARCHAR(500) NULL,
  diff JSON NULL,
  ip VARCHAR(45) NULL,
  user_agent VARCHAR(255) NULL,
  result VARCHAR(12) NOT NULL DEFAULT 'success',
  created_at DATETIME(3) NOT NULL,
  KEY ix_audit_time (created_at),
  KEY ix_audit_subject (subject_type, subject_id),
  KEY ix_audit_actor (actor_type, actor_id)
);
```

## 6. Discoverability tables

```sql
CREATE TABLE redirects (
  id CHAR(26) PRIMARY KEY,
  from_path VARCHAR(512) NOT NULL,
  match_type VARCHAR(10) NOT NULL DEFAULT 'exact', -- exact | prefix | regex
  to_path VARCHAR(2048) NOT NULL,
  status_code SMALLINT NOT NULL DEFAULT 301,
  is_auto TINYINT(1) NOT NULL DEFAULT 0,           -- created by a slug change
  hits INT NOT NULL DEFAULT 0,
  last_hit_at DATETIME(3) NULL,
  created_by CHAR(26) NULL,
  created_at DATETIME(3) NOT NULL,
  from_hash CHAR(64) NOT NULL,                     -- SHA-256 of from_path, so long paths stay unique
  UNIQUE KEY uq_redirect_from (from_hash, match_type)
);

CREATE TABLE crawler_policy_versions (    -- every change to robots/AI policy is kept and can be rolled back
  id CHAR(26) PRIMARY KEY,
  preset VARCHAR(24) NOT NULL,            -- balanced | open | private | custom
  policy JSON NOT NULL,                   -- per-bot decisions, sitemap list, crawl-delay, content-signal
  robots_txt MEDIUMTEXT NOT NULL,         -- generated output at that time
  changed_by CHAR(26) NULL,
  change_note VARCHAR(500) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME(3) NOT NULL
);

CREATE TABLE index_notifications (        -- IndexNow and sitemap pings
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  engine VARCHAR(24) NOT NULL,            -- indexnow | sitemap_ping | webhook
  urls JSON NOT NULL,
  status VARCHAR(12) NOT NULL,
  response_code SMALLINT NULL,
  error VARCHAR(500) NULL,
  created_at DATETIME(3) NOT NULL
);

CREATE TABLE bot_log (                    -- aggregated; only for dynamic endpoints (markdown API, public MCP, imported access logs)
  day DATE NOT NULL,
  bot VARCHAR(64) NOT NULL,
  verified TINYINT(1) NOT NULL DEFAULT 0,
  path_group VARCHAR(64) NOT NULL,
  hits INT NOT NULL DEFAULT 0,
  PRIMARY KEY (day, bot, verified, path_group)
);
```

SEO data per entry lives in the `seo` JSON column of each public collection table with this shape:

```json
{
  "title": null,            "description": null,
  "canonical": null,        "noindex": false,   "nofollow": false,
  "og_title": null,         "og_description": null, "og_image_media_id": null,
  "twitter_card": "summary_large_image",
  "max_snippet": null,      "nosnippet": false, "max_image_preview": "large",
  "schema_overrides": {},   "focus_topic": null,
  "answer_summary": null,   "breadcrumb_title": null
}
```
Null means "derive automatically" (title, excerpt, featured image).

## 7. Operations tables

```sql
CREATE TABLE settings (
  handle VARCHAR(120) PRIMARY KEY,
  value JSON NOT NULL,
  is_secret TINYINT(1) NOT NULL DEFAULT 0,   -- secret values encrypted with the app key; never returned by API
  updated_by CHAR(26) NULL,
  updated_at DATETIME(3) NOT NULL
);

CREATE TABLE saved_views (
  id CHAR(26) PRIMARY KEY,
  user_id CHAR(26) NOT NULL,
  collection_key VARCHAR(64) NOT NULL,
  name VARCHAR(120) NOT NULL,
  filters JSON NOT NULL, sort VARCHAR(120) NULL, columns JSON NULL, density VARCHAR(10) NULL,
  is_shared TINYINT(1) NOT NULL DEFAULT 0,
  position INT NOT NULL DEFAULT 0,
  created_at DATETIME(3) NOT NULL
);

CREATE TABLE webhooks (
  id CHAR(26) PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  url VARCHAR(2048) NOT NULL,
  secret_enc VARBINARY(255) NOT NULL,       -- HMAC signing secret
  events JSON NOT NULL,                     -- ["entry.published","entry.updated","media.created",...]
  collections JSON NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME(3) NOT NULL
);

CREATE TABLE webhook_deliveries (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  webhook_id CHAR(26) NOT NULL,
  event VARCHAR(64) NOT NULL,
  payload JSON NOT NULL,
  status VARCHAR(12) NOT NULL,              -- pending | success | failed
  attempts SMALLINT NOT NULL DEFAULT 0,
  response_code SMALLINT NULL,
  response_excerpt VARCHAR(500) NULL,
  next_attempt_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL,
  KEY ix_wh_status (status, next_attempt_at)
);

CREATE TABLE jobs (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  queue VARCHAR(32) NOT NULL DEFAULT 'default',
  type VARCHAR(96) NOT NULL,
  payload JSON NOT NULL,
  status VARCHAR(12) NOT NULL DEFAULT 'queued', -- queued | running | done | failed
  attempts SMALLINT NOT NULL DEFAULT 0,
  max_attempts SMALLINT NOT NULL DEFAULT 5,
  available_at DATETIME(3) NOT NULL,
  reserved_at DATETIME(3) NULL,
  last_error TEXT NULL,
  created_at DATETIME(3) NOT NULL,
  KEY ix_jobs_pick (status, queue, available_at)
);

CREATE TABLE email_log (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  to_email VARCHAR(190) NOT NULL,
  template VARCHAR(64) NOT NULL,
  subject VARCHAR(255) NOT NULL,
  status VARCHAR(12) NOT NULL,              -- queued | sent | failed
  provider VARCHAR(24) NULL,
  provider_message_id VARCHAR(190) NULL,
  error VARCHAR(500) NULL,
  related_user_id CHAR(26) NULL,
  created_at DATETIME(3) NOT NULL,
  sent_at DATETIME(3) NULL
);

CREATE TABLE notifications (               -- in-app notification center
  id CHAR(26) PRIMARY KEY,
  user_id CHAR(26) NOT NULL,
  type VARCHAR(48) NOT NULL,                -- ai_change_pending, entry_in_review, mention, system_warning...
  title VARCHAR(190) NOT NULL,
  body VARCHAR(500) NULL,
  link VARCHAR(512) NULL,
  read_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL,
  KEY ix_notif_user (user_id, read_at, created_at)
);

CREATE TABLE rate_limits (                 -- DB-backed fallback when APCu/Redis are absent
  bucket VARCHAR(190) NOT NULL,
  window_start DATETIME NOT NULL,
  hits INT NOT NULL DEFAULT 0,
  PRIMARY KEY (bucket, window_start)
);

CREATE TABLE plugins (
  id CHAR(26) PRIMARY KEY,
  handle VARCHAR(64) NOT NULL,
  version VARCHAR(32) NOT NULL,
  is_enabled TINYINT(1) NOT NULL DEFAULT 0,
  config JSON NULL,
  license_key_enc VARBINARY(512) NULL,
  installed_at DATETIME(3) NOT NULL,
  UNIQUE KEY uq_plugin_key (handle)
);

CREATE TABLE migrations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  namespace VARCHAR(64) NOT NULL,
  name VARCHAR(190) NOT NULL,
  batch INT NOT NULL,
  applied_at DATETIME(3) NOT NULL,
  UNIQUE KEY uq_migration (namespace, name)
);
```

### Publishing pipeline tables (added in review v4)

The public site is a static Astro build, which runs outside the PHP host (CI runner or a developer machine), so the CMS must track builds and preview tokens.

```sql
CREATE TABLE deployments (               -- one row per requested site build
  id CHAR(26) PRIMARY KEY,
  trigger_type VARCHAR(16) NOT NULL,     -- publish | schedule | manual | schema | policy
  status VARCHAR(12) NOT NULL DEFAULT 'queued', -- queued | dispatched | building | succeeded | failed | cancelled
  target VARCHAR(32) NOT NULL,           -- github_actions | webhook | manual
  changed_urls JSON NULL,                -- paths affected, used for IndexNow after success
  requested_by CHAR(26) NULL,
  dispatched_at DATETIME(3) NULL,
  finished_at DATETIME(3) NULL,
  build_log_url VARCHAR(1024) NULL,
  error VARCHAR(1000) NULL,
  callback_token_hash CHAR(64) NOT NULL, -- the CI job uses this one-time token to report status
  created_at DATETIME(3) NOT NULL,
  KEY ix_deploy_status (status, created_at)
);

CREATE TABLE preview_tokens (            -- short-lived tokens that let a preview renderer read a draft
  token_hash CHAR(64) PRIMARY KEY,
  collection_id CHAR(26) NOT NULL,
  entry_id CHAR(26) NOT NULL,
  created_by CHAR(26) NOT NULL,
  expires_at DATETIME(3) NOT NULL,       -- default 30 minutes
  created_at DATETIME(3) NOT NULL
);

CREATE TABLE build_tokens (              -- read-only tokens used by the Astro build to fetch published + build-only data
  id CHAR(26) PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  token_prefix CHAR(8) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  last_used_at DATETIME(3) NULL,
  revoked_at DATETIME(3) NULL,
  created_at DATETIME(3) NOT NULL,
  UNIQUE KEY uq_buildtoken (token_hash)
);
```

## 8. Full-text search

- Admin search: MySQL `FULLTEXT` on the mirrored `title` plus a maintained `search_text` column (concatenated searchable field text, stripped of markup).
- Public search: static index (Pagefind) built from the Astro output, not from PHP.

## 9. Seed data created by the installer

- Roles: `owner`, `admin`, `developer`, `editor`, `author`, `contributor`, `viewer`, `ai_draft` (AI, drafts only), `ai_publisher` (AI, publishes with approval). Permission matrices are defined in `docs/03-auth-and-email.md`.
- Collections: `pages`, `posts`, `authors`; singletons `site_settings`, `navigation`.
- Taxonomies: `categories` (hierarchical, attached to `posts`), `tags` (flat, attached to `posts`). Default category term "Uncategorized".
- Settings: environment (`production` by default on live installs, `staging` if the installer detects a non-public host), crawler policy preset `balanced`, mail provider unset.
- Sample content (optional installer checkbox): home page, about page, one blog post, one category.
