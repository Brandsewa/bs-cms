# @bs/client

Typed API client for BS CMS + codegen from OpenAPI/schema.
Used by the Astro starter, preview environment, and admin UI screens.

## Features

- **Typed Operations & Schemas**: Generated directly from `docs/api/openapi-v1.json` (`npm run generate-client`).
- **Idempotent Retry Policy**:
  - Automatically retries network errors and 5xx responses with linear backoff (default 2 retries).
  - Only **idempotent HTTP methods** (`GET`, `HEAD`, `PUT`, `DELETE`) are retried by default.
  - Non-idempotent mutating requests (`POST`, `PATCH`) are **not** retried unless the caller supplies an `idempotencyKey` in `RequestOptions` / `MultipartOptions` (transmitted via the `Idempotency-Key` HTTP header).
  - 4xx client errors are never retried.
  - Aborted requests (`signal.aborted`) are immediately terminated and never retried.
- **Multipart & Chunked Uploads**:
  - `requestMultipart()` converts network errors to typed `ApiResult` with `status: 0`.
  - Supports `MultipartOptions` (`signal`, `idempotencyKey`, `ifMatch`, and `retry`).
  - Chunk uploads (`POST /media/chunks/{id}`) are safe to retry because uploading the same `chunk_index` for a session is idempotent.
- **Conditional Writes & Preconditions**:
  - When `ifMatch` is provided, HTTP 428 (Precondition Required) and 409 (Conflict) responses surface as a typed `{ ok: false, status: 428 | 409, kind: 'precondition', problem }`.
  - A 428 response returned without providing `ifMatch` comes back as a normal `ApiError` (`kind` is undefined).
- **CSRF Protection**:
  - Mutating requests automatically carry the `X-CSRF-Token` header set on the client via `setCsrfToken()`.
