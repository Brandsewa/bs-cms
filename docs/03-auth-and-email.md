# 03. Authentication, authorization and email (BS CMS)

Status: draft for implementation. Read `docs/01-data-model.md` for tables.

## 1. Decision: compose small libraries, do not adopt an auth framework

The request was to use an open-source auth solution if it is light and saves effort. Findings:

| Option | Assessment |
|---|---|
| `delight-im/auth` (PHP, MIT) | Self-contained login, registration, reset, roles. [Packagist](https://packagist.org/packages/delight-im/auth) lists v9.0.0 (2025-05-28) with about 159k installs, but a third-party health score is [middling and the repo shows slow activity](https://mcp.depscope.dev/pkg/composer/delight-im/auth). It brings its own tables and a bitmask role model that cannot express our role x collection x action x field permission matrix, and it knows nothing about OAuth for AI clients. **Not adopted.** |
| Full identity servers (Keycloak, Ory, Authelia) | Need Java/Go services and their own database; incompatible with shared hosting. **Rejected.** |
| `league/oauth2-server` | The most established PHP OAuth server library. [Packagist](https://packagist.org/packages/league/oauth2-server) shows it was updated 2026-06-25, requires PHP 8.2 to 8.5, uses PSR-7 (`psr/http-message ^2.0`) and lists PKCE (RFC 7636) among implemented RFCs. It is described as OAuth 2.0, so we configure it to the OAuth 2.1 profile (authorization code + PKCE only; refresh tokens rotate; no implicit or password grants). **Adopted** for the AI-client authorization server. |
| PHP native `password_hash` / `password_verify` | Argon2id where available, bcrypt fallback. **Adopted**, no library. |
| TOTP library (e.g. `spomky-labs/otphp`) | Small, standard. **Adopt one maintained TOTP package** after checking its current release **(verify)**. |
| Mailer | `phpmailer/phpmailer` recommended (single package, SMTP with TLS, header-injection protection, [active per a 2026 comparison](https://www.pistack.xyz/posts/2026-08-24-php-email-libraries-phpmailer-symfony-mailer-swiftmailer-comparison/)). Licence is LGPL-2.1: fine as an unmodified Composer dependency, but record it because the project may later be open-sourced or kept proprietary. Alternative: `symfony/mailer` (MIT, more dependencies). SwiftMailer is archived; do not use. Put it behind a `MailTransport` interface so it can be swapped. |

So: our own small `Auth` module (users, sessions, passwords, 2FA, invitations, resets) + `league/oauth2-server` for AI clients + one TOTP package + PHPMailer. Everything else is native PHP. This keeps the dependency count and memory footprint low and keeps the permission model under our control.

## 2. Authentication for humans (dashboard)

### 2.1 Mechanism
- Cookie session. Cookie: `__Host-bs_session` (Secure, HttpOnly, SameSite=Lax, Path=/). Session token is 32 random bytes; only a SHA-256 hash is stored (`sessions.token_hash`).
- CSRF: per-session secret; all mutating requests need the `X-CSRF-Token` header (admin app reads it from a bootstrap call). SameSite plus origin check as defence in depth.
- Session lifetime: idle timeout 12 hours, absolute 14 days (configurable); "remember this device" extends to 30 days. Rotate session id on login and privilege change.
- Password hashing: Argon2id (`memory_cost` 19 MiB or higher, `time_cost` 2, `threads` 1 as starting values, tuned on the target host) with `password_needs_rehash` on login; bcrypt cost 12 if Argon2id is unavailable.
- Password policy: minimum 12 characters, no composition rules, block the top common passwords list, allow passphrases and paste, strength meter. No forced periodic rotation.
- Login protection: rate limit by IP and by account (e.g. 5 failures per 15 minutes then exponential delay), temporary lock (`locked_until`), uniform error messages ("email or password is incorrect"), constant-time comparison, notify user by email on new-device login (optional setting).
- 2FA: TOTP with QR code and manual key, 10 single-use recovery codes (hashed), optional enforcement per role (e.g. required for owner/admin/developer). Step-up re-authentication (password or TOTP) before sensitive actions: change email/password, create API token, connect AI client, apply schema change, change crawler policy, delete user.
- Passkeys/WebAuthn: *(later)*.

### 2.2 Flows (all need UI, emails, rate limits, audit entries, tests)
1. **First run / installer:** create the owner account (email, name, password), email verification skipped if mail is not yet configured (warning banner shown).
2. **Login:** email + password, then TOTP if enabled; redirect to the originally requested page.
3. **Logout / logout everywhere:** revokes sessions.
4. **Forgot password:** always show "If that email exists, we sent a link"; token 32 bytes, hashed in DB, expires in 60 minutes, single use; link opens reset form; all other sessions revoked after reset; confirmation email sent.
5. **Invitation:** admin invites by email + role; token expires in 7 days; accept page sets name and password; revocable; resend.
6. **Email verification / change of email:** verification link to the new address; old address notified.
7. **Change password** (requires current password), **enable/disable 2FA**, **regenerate recovery codes**.
8. **Account lockout recovery:** admin can unlock; owner recovery through CLI (`php bs user:reset-password`) for locked-out owners.
9. **Suspend user:** immediate session revocation.

### 2.3 Roles and permissions model
- Permission = (subject_type, subject_key, action, effect, optional field list, optional condition). Evaluated by one `Authorizer` class used by REST, admin and MCP.
- Deny overrides allow. Unknown subject = deny.
- Conditions: `{"owner":"self"}` (own entries), `{"status":["draft"]}` (only drafts). Field-level: allow-list or deny-list of fields for read and write.
- The UI computes visibility from the same rules (`GET /me/permissions`), and the server re-checks every request.
- Cache the user's compiled permissions for the request only (and in APCu if present), invalidated on role change.

Default role presets (seeded, editable except `owner`):

| Role | Content | Taxonomies | Media | Site (nav, redirects, SEO) | Users/roles | Settings | Schema | AI/API/Webhooks/Plugins | Audit |
|---|---|---|---|---|---|---|---|---|---|
| owner | all | all | all | all | all | all | all | all | all |
| admin | all | all | all | all | all except owner | all except security keys | read | all | read |
| developer | all | all | all | all | read | read | all | all | read |
| editor | all collections: read, create, update, publish, unpublish, schedule, delete (soft) | manage terms | all | navigation only | none | none | none | none | none |
| author | own entries: create, update, submit for review (no publish) | assign existing terms, create terms optional | upload, edit own | none | none | none | none | none | none |
| contributor | create drafts only | assign existing | upload | none | none | none | none | none | none |
| viewer | read | read | read | none | none | none | none | none | none |
| ai_draft (AI role) | create, update as draft | assign existing | upload | none | none | none | none | none | none |
| ai_publisher (AI role) | create, update, publish with approval | manage terms with approval | upload | navigation with approval | none | none | propose only | none | none |

## 3. Authentication for AI clients (MCP and API)

### 3.1 Requirements gathered
- ChatGPT's custom connectors reportedly need HTTPS and OAuth for non-public servers and may not accept plain API keys **(verify against current OpenAI docs)**; sources: [Auth0 guide](https://auth0.com/blog/add-remote-mcp-server-chatgpt/), [community write-up](https://tspi.at/2026/01/10/chatgptremotemcp.html).
- The MCP authorization spec (latest) requires the server to publish OAuth 2.0 Protected Resource Metadata (RFC 9728) and tell clients where the authorization server is; clients and servers should support Client ID Metadata Documents and may support dynamic client registration (RFC 7591). Earlier spec versions (2025-03-26, 2025-06-18) differ, so implement the latest and keep dynamic registration enabled for compatibility **(verify against https://modelcontextprotocol.io/specification/latest/basic/authorization)**; see also [this implementation guide](https://www.permit.io/blog/oauth-on-mcp).
- Claude's custom connector auth requirements were not confirmed by research **(verify against Anthropic docs)**.

### 3.2 Endpoints (all under the CMS host)
| Endpoint | Purpose |
|---|---|
| `POST /mcp` | Stateless MCP over Streamable HTTP. Returns `401` with `WWW-Authenticate: Bearer resource_metadata="<url>"` when unauthenticated |
| `GET /.well-known/oauth-protected-resource` | RFC 9728 metadata: resource URL and `authorization_servers` |
| `GET /.well-known/oauth-authorization-server` | RFC 8414 metadata: endpoints, supported grants, PKCE methods, scopes |
| `GET/POST /oauth/authorize` | Login (reuse dashboard session), then consent screen |
| `POST /oauth/token` | Code + PKCE exchange, refresh with rotation |
| `POST /oauth/register` | Dynamic client registration (rate-limited, can be disabled) |
| `POST /oauth/revoke` | Token revocation |

### 3.3 Consent screen (security critical)
Shows client name and redirect host, the site, what the agent may do, and lets the owner choose: **role** (ai_draft, ai_publisher, or a custom AI role), **mode** (`draft_only`, `approval_required`, `full`), expiry. Requires step-up re-authentication. Warns on unknown clients registered dynamically. Creates an `oauth_grants` row; revocable anytime from AI connections.

### 3.4 Token rules
- Access tokens short-lived (1 hour), refresh tokens rotate on use (a reused old refresh token revokes the grant), PKCE S256 required, exact redirect URI matching (no wildcards), `resource` parameter honoured (RFC 8707), tokens bound to a single site, hashes only in DB.
- Personal access tokens (for Claude Code, scripts, CI): shown once, prefix visible, scopes + role + mode, optional expiry, last-used tracking, revoke.
- Every call carries actor context: `actor_type = ai_client | api_token`, label, on-behalf-of user; written to audit log and version history.

### 3.5 AI safety enforcement (server side, not UI)
- `draft_only`: any operation that publishes, deletes, changes users, roles, settings, crawler policy or schema is rejected.
- `approval_required`: those operations create a `pending_changes` row, notify the owner (in-app + email), and return "pending approval" to the agent.
- `full`: allowed within the role; destructive/bulk operations still need a two-step confirm token and are rate limited.
- Content returned to agents is labelled as untrusted user content; no tool interprets instructions found in content.

## 4. Rate limiting and abuse protection
- Buckets: login (IP+email), password reset (IP+email), invite, OAuth endpoints, MCP/API (per token), registration endpoint.
- Storage: APCu if available, else `rate_limits` table (fallback).
- Return `429` with `Retry-After`. Log repeated violations.

## 5. Email (SMTP) integration

### 5.1 Scope for v1
Basic SMTP integration, configured in **Settings > Email**, used for: password reset, invitation, email verification and change, new-device login notice, 2FA recovery notice, AI pending-approval notification, publish/review notifications to editors, system alerts (cron failing, disk, backup), webhook failure digest (optional), test email.

### 5.2 Settings screen requirements
- [ ] Provider preset selector with prefilled host/port/security: **Resend**, **ZeptoMail (Zoho)**, **Zoho Mail**, **Generic SMTP**.
- [ ] Fields: host, port, encryption (implicit TLS / STARTTLS / none with warning), username, password/API key (stored encrypted with the app key, never shown again, "replace" button), From name, From address, Reply-To, optional BCC for audit.
- [ ] **Send test email** button with success/failure details (SMTP response), and a connection test that does not send.
- [ ] Status indicator: configured / verified / last sent / last error.
- [ ] Domain deliverability checklist card: SPF, DKIM, DMARC reminders with provider links; From address must be on a verified domain.
- [ ] Email log table (DataTable) with status, template, recipient, error, resend action.
- [ ] Mail driver `log` for local development (writes to the log instead of sending), and `disabled`.

### 5.3 Provider presets (from provider docs; confirm before release)
| Provider | Host | Ports | Username | Password |
|---|---|---|---|---|
| [Resend](https://resend.com/docs/send-with-smtp) | `smtp.resend.com` | 465 / 2465 implicit TLS, 587 / 2587 / 25 STARTTLS (465 recommended by Resend) | `resend` | Resend API key |
| [ZeptoMail](https://www.zoho.com/zeptomail/help/smtp-api.html) | `smtp.zeptomail.com` | 465 (SSL), 587 (TLS) | `emailapikey` | Mail Agent send-mail token |
| Zoho Mail | `smtp.zoho.com` (region-specific hosts exist, e.g. .eu, .in) **(verify)** | 465 SSL, 587 TLS **(verify)** | the mailbox address | mailbox or app password |
| Generic | user supplied | user supplied | user supplied | user supplied |

### 5.4 Templates
- Plain-text + HTML pair per template, responsive, with product name, site name and logo from settings; English only in v1 but text from the message catalog.
- Templates: `password_reset`, `invitation`, `verify_email`, `email_changed_notice`, `new_login_notice`, `two_factor_enabled`, `ai_change_pending`, `entry_in_review`, `entry_published`, `system_alert`, `test_email`, `webhook_failures`.
- Never include secrets other than the single-use link; links use the configured admin base URL; set `List-Unsubscribe` only on non-transactional types (none in v1).

### 5.5 Delivery mechanics
- Emails are queued as jobs and sent by the job runner (cron or lazy trigger) so requests never wait on SMTP; password reset and invitation are marked high priority and attempted inline once with a short timeout, then queued.
- Retries with backoff (1, 5, 15, 60 minutes), then marked failed and surfaced in the dashboard banner.
- Limit sends per recipient per hour to prevent abuse.
- Header injection protection (library + strict validation of address fields).

## 6. Security checklist for this module
- [ ] All tokens random (`random_bytes`), hashed at rest, compared in constant time.
- [ ] No user enumeration on login, reset, invite and registration endpoints (timing and message).
- [ ] Secrets (SMTP password, TOTP secrets, webhook secrets) encrypted with an app key stored outside the web root or in an environment file; key rotation command.
- [ ] Security headers on the admin: CSP, HSTS, X-Frame-Options/`frame-ancestors`, X-Content-Type-Options, Referrer-Policy, Permissions-Policy.
- [ ] Admin and API hosts send `X-Robots-Tag: noindex` and `Disallow: /` robots (see `docs/04-crawlers-and-discoverability.md`).
- [ ] Session fixation, CSRF and clickjacking tests; brute-force tests; OAuth redirect-URI and PKCE downgrade tests.
- [ ] Dependency audit (`composer audit`) in CI.
