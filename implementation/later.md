# After v1 (not part of the v1 build)

Do not start any of these until v1.0 has shipped. They are listed so v1 code leaves room for them (extension points, tables, flags), not so they get built early.

| Item | Notes / prerequisites already in v1 |
|---|---|
| Plugin system | Manifest (handle, version, core compatibility, dependencies, permissions), namespaced migrations (`plugin:<handle>`), operations/tools/blocks/settings registration, schema-driven admin screens first, runtime-loaded admin bundles second, compatibility check + rollback. v1 already has the event bus, operations registry, block registry and `plugins` table. |
| NGO pack | Campaigns collection template, Donations plugin with a payment-gateway interface (hosted checkout only, signed idempotent webhooks, gateway-managed recurring donations, receipts, refunds). Never handle card data. |
| Theme editor / visual page builder | Possibly Puck or our own on the block registry; layouts stored in our block format. |
| Simplified editor app | Optional separate app for content writers on the same API (v1 already supports limited roles in the main dashboard). |
| Localization | `locale` columns already reserved; add translation workflow, hreflang, per-locale routes, admin languages. |
| Collaboration | Real-time editing needs a persistent service (not shared-hosting friendly); revisit with an optional external service. |
| Live preview side-by-side, review workflows, scheduled releases, SSO, passkeys | |
| GraphQL API | Generated from the same field schemas. |
| In-admin AI assistant | Bring-your-own key, through the job/API layer. |
| NLWeb-style `ask` endpoint, AI citation tracking, CDN analytics import | Behind feature flags. |
| S3-compatible media storage, image CDN | `StorageDriver` interface exists. |
| Multi-site | Not planned; single site per install. |
| Open-sourcing / licensing / marketplace | Decide later; keep dependency licences compatible (recorded in `DEPENDENCIES.md`). |
