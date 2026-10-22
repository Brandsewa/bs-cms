# Sprint Report: Session C (Backend & Hardening)

Branch: `sprint/c-backend`

## 1. What was Built

### Phase 7 Discoverability & Starter Polish
- **Block Registry & Sync API**:
  - `BsCms\Blocks\BlockRegistry`: File-persisted registry for blocks with dynamic loader and directory syncer.
  - CLI `bs blocks:sync [--dir=path]`: Syncs starter block definitions from `starter/src/blocks/` into CMS storage.
  - `GET /api/v1/blocks` and `GET /api/v1/blocks/{name}`: Registered block definitions for the page block editor.
  - Starter block definitions: `hero.json`, `text.json`, `faq.json`, `callout.json`.
- **Crawler Policy Engine**:
  - `core/resources/bots.json`: Sourced and verified bot catalogue (Googlebot, Bingbot, DuckDuckBot, OAI-SearchBot, Claude-SearchBot, GPTBot, ClaudeBot, PerplexityBot, Google-Extended, Applebot-Extended, CCBot, Meta-ExternalAgent, Bytespider, cohere-ai).
  - `BsCms\CrawlerPolicy\RobotsGenerator`: Generates compliant `robots.txt` based on Balanced, Open, Private, or Custom presets, with safety rails blocking disallows on search engines in production.
  - `BsCms\CrawlerPolicy\CrawlerPolicyService`: Database-persisted version history (`crawler_policy_versions`) with rollback and active state tracking.
  - `BsCms\CrawlerPolicy\CanaryChecker`: Verifies live `robots.txt` against active CMS policy versions and checks crawlability.
  - `BsCms\CrawlerPolicy\IndexNowService`: Sends batch ping notifications to IndexNow API with logging into `index_notifications`.
  - `BsCms\Http\Controllers\CrawlerPolicyController`:
    - `GET /api/v1/crawler-policy`: Returns active version and bot catalogue.
    - `PUT /api/v1/crawler-policy`: Updates policy with step-up authentication and audit logging.
    - `GET /api/v1/crawler-policy/versions`: Lists past versions.
    - `POST /api/v1/crawler-policy/rollback/{id}`: One-click rollback.
    - `POST /api/v1/crawler-policy/canary`: Runs post-deploy live canary checks.
    - `POST /api/v1/crawler-policy/indexnow`: Manually or automatically dispatches IndexNow URLs.
- **Starter Enhancements**:
  - Pagefind build-time search index generation (`starter/scripts/generate-artifacts.mjs`).
  - Search page (`starter/src/pages/search.astro`) with client-side querying.
  - RSS 2.0 Feed (`/rss.xml`) and IndexNow key file (`/<key>.txt`) artifact generation.

### Phase 8 AI Interface Hardening
- **Rate Limiting & Caps**:
  - MCP grant daily limit: 1,000 calls/day per grant (`mcp:grant:daily:{grantId}`) with burst cap and clear `Retry-After`.
  - Write rate limits: 20 writes/minute per grant for modifying tools.
  - Tool response size cap: responses truncated at 64 KB with continuation cursor.
  - Dynamic Client Registration toggle: `security.oauth.dynamic_registration_enabled` setting can disable dynamic client registration.
  - Content Entries Export: `GET /api/v1/collections/{handle}/entries/export?format=csv|json` for fast data extraction.

### Phase 9 Operations & Hardening
- **Database Backup & Restore**:
  - `BsCms\Operations\DatabaseBackupService`: Full PDO SQL dump table-by-table with batched inserts (zero `exec()` dependency, 100% Hostinger compatible).
  - CLI `bs backup:create` and `bs backup:restore --file=path`.
  - `POST /api/v1/system/backup`.
- **Self Updater & Maintenance Mode**:
  - `BsCms\Operations\SelfUpdaterService`: Release verification (Ed25519 sodium detached signatures + SHA256SUMS) and maintenance lock management.
  - `BsCms\Http\Controllers\MaintenanceController`:
    - `GET /api/v1/system/maintenance`: Status.
    - `POST /api/v1/system/maintenance/enable`: Turn on maintenance mode.
    - `POST /api/v1/system/maintenance/disable`: Turn off maintenance mode.
  - CLI `bs doctor`: Comprehensive environmental diagnostic tool verifying PHP, OPcache, Sodium, PDO MySQL, disk space, and lock status.

## 2. Endpoints Added
- `GET /api/v1/blocks`
- `GET /api/v1/blocks/{name}`
- `GET /api/v1/crawler-policy`
- `PUT /api/v1/crawler-policy`
- `GET /api/v1/crawler-policy/versions`
- `POST /api/v1/crawler-policy/rollback/{id}`
- `POST /api/v1/crawler-policy/canary`
- `POST /api/v1/crawler-policy/indexnow`
- `GET /api/v1/collections/{handle}/entries/export`
- `GET /api/v1/system/maintenance`
- `POST /api/v1/system/maintenance/enable`
- `POST /api/v1/system/maintenance/disable`
- `POST /api/v1/system/backup`

## 3. Known Gaps & Future Work
- Dynamic client registration UI toggle in Settings (frontend session wiring).
- Off-site S3 backup target drivers (v2 feature).
