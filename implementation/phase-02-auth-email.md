# Phase 2: Authentication, authorization, audit and email

**Goal:** secure human authentication, the full permission model used by every later phase, audit logging, API tokens and SMTP email.
**Depends on:** Phase 1. **Authoritative spec:** `docs/03-auth-and-email.md` (read fully before starting).

## 2.1 Migrations (DDL exactly as `docs/01-data-model.md` sections 1, 5 and 7)
`users`, `roles`, `user_roles`, `role_permissions`, `sessions`, `password_resets`, `email_verifications`, `invitations`, `api_tokens`, `audit_log`, `email_log`, `notifications`.
(OAuth tables come in Phase 8.)

Seed migration: the nine roles from `docs/03-auth-and-email.md` section 2.3 with their permission rows. Roles reference collection handles with `*` where they apply to all collections.

## 2.2 Modules (`core/src/`)
```
Auth/      PasswordHasher.php, SessionManager.php, LoginService.php, TwoFactorService.php, RecoveryCodes.php,
           PasswordResetService.php, InvitationService.php, EmailVerificationService.php, ApiTokenService.php,
           Actor.php (value object: type, id, label, onBehalfOf, roleIds, aiMode)
Authz/     Authorizer.php, Permission.php, PermissionCompiler.php, PolicyContext.php, FieldFilter.php
Audit/     AuditLogger.php, AuditEntry.php
Mail/      Mailer.php (interface), SmtpTransport.php (PHPMailer), LogTransport.php, NullTransport.php,
           MailMessage.php, TemplateRenderer.php, Templates/*.php (plain PHP templates returning html + text)
Notifications/ NotificationService.php
Users/     UserRepository.php, RoleRepository.php
```

## 2.3 Authentication rules (implement exactly)
- Session cookie `__Host-bs_session` (Secure, HttpOnly, SameSite=Lax, Path=/); on plain-HTTP `localhost` use `bs_session` without `Secure`. Token: 32 random bytes; store SHA-256 only.
- Idle timeout 12 h, absolute 14 d, "remember device" 30 d (settings). Session id rotated on login, 2FA pass, password change and role change.
- CSRF: `X-CSRF-Token` header on every non-GET request authenticated by session; compare with `hash_equals`; also check `Origin`/`Referer` against the admin origin.
- Passwords: Argon2id via `password_hash` (`memory_cost` >= 19456 KiB, `time_cost` 2, `threads` 1; tune and record on test hosts), bcrypt cost 12 fallback; `password_needs_rehash` on login; min length 12, max 256, check against a bundled list of 10k common passwords; no composition rules.
- Login throttling: per IP (20/15 min) and per account (5 failures then exponential delay up to 15 min, `locked_until`); identical response and similar timing for unknown emails (perform a dummy hash verify).
- 2FA: TOTP (30 s, 6 digits, SHA-1 per RFC 6238 for authenticator compatibility, +-1 step window, replay protection by storing last used step); 10 recovery codes (hashed, single use); optional enforcement per role; step-up re-auth (password or TOTP within last 10 min) required for: change email/password, create API token, disable 2FA, manage roles, change security settings, delete users, change environment/crawler policy (Phase 7), connect AI clients (Phase 8), apply schema changes (Phase 3).
- Tokens for reset (60 min), invitation (7 days), email verification (24 h): 32 bytes, base64url, SHA-256 hashed at rest, single use, invalidated when a new one is issued.

## 2.4 Authorizer (single source of truth)
- `Authorizer::can(Actor $actor, string $action, Subject $subject, ?array $entity = null): Decision` returns allow/deny + reason; `::filterFields(...)` returns readable/writable field sets.
- Compile permissions per role into a lookup map cached in `PhpArrayCache` keyed by role ids + permissions version; invalidate on change.
- Semantics: deny beats allow; no matching rule = deny; `subject_key='*'` matches all keys of that type; `conditions.owner='self'` checks `entity.created_by === actor.onBehalfOf`; `conditions.status` restricts by entry status; `fields` allow/deny lists apply to read and write separately (`{"read":{"deny":[...]}, "write":{"allow":[...]}}`).
- API tokens and (later) OAuth grants: effective permissions = intersection of the owner's permissions, the token's role (if set) and scopes; `ai_mode` adds the extra restrictions in `docs/03-auth-and-email.md` section 3.5 (enforced in Phase 3 operations and Phase 8).
- Owner protection: the last owner cannot be removed, suspended or downgraded; owner role permissions are immutable.
- Endpoint `GET /api/v1/me/permissions` returns the compiled map for the UI (no secrets).

## 2.5 API endpoints (all JSON, problem-details errors, rate limited)
Auth: `POST /api/v1/auth/login`, `POST /auth/logout`, `POST /auth/logout-all`, `GET /auth/session` (current user + CSRF token), `POST /auth/2fa/challenge`, `POST /auth/password/forgot`, `POST /auth/password/reset`, `GET /auth/invitations/{token}` (validate), `POST /auth/invitations/{token}/accept`, `POST /auth/email/verify`, `POST /auth/step-up`.
Me: `GET/PATCH /api/v1/me`, `POST /me/password`, `POST /me/email`, `POST /me/2fa/setup` (returns otpauth URI + secret), `POST /me/2fa/confirm`, `POST /me/2fa/disable`, `POST /me/2fa/recovery-codes`, `GET /me/sessions`, `DELETE /me/sessions/{id}`, `GET /me/permissions`, `GET/PATCH /me/notification-preferences`.
Users: `GET /api/v1/users` (filters, sort, cursor pagination), `POST /users/invite`, `GET/PATCH /users/{id}`, `POST /users/{id}/suspend`, `POST /users/{id}/unsuspend`, `POST /users/{id}/unlock`, `DELETE /users/{id}`, `POST /users/bulk` (actions: change_role, suspend, resend_invite, delete), `GET /users/{id}/sessions`, `DELETE /users/{id}/sessions`.
Roles: `GET/POST /api/v1/roles`, `GET/PATCH/DELETE /roles/{id}`, `PUT /roles/{id}/permissions` (full replace with validation), `POST /roles/{id}/clone`.
Tokens: `GET/POST /api/v1/tokens` (plain token returned once), `DELETE /tokens/{id}`.
Audit: `GET /api/v1/audit` (filters: actor, action, subject, date range; cursor), `GET /audit/{id}`.
Email: `GET/PUT /api/v1/settings/email`, `POST /settings/email/test` (connection test), `POST /settings/email/send-test`, `GET /api/v1/email-log`, `POST /email-log/{id}/resend`.
Notifications: `GET /api/v1/notifications`, `POST /notifications/read`, `POST /notifications/read-all`.

## 2.6 Audit logging
- `AuditLogger::record(Actor, action, subject, summary, diff?, result)`; called by every mutation in the system (in Phase 3 automatically by the operations dispatcher).
- Actions in this phase: `auth.login`, `auth.login_failed`, `auth.logout`, `auth.2fa_enabled`, `auth.2fa_disabled`, `auth.password_changed`, `auth.password_reset`, `user.invited`, `user.updated`, `user.suspended`, `user.deleted`, `role.created`, `role.updated`, `role.deleted`, `token.created`, `token.revoked`, `settings.email_updated`.
- Diffs never contain secrets (redactor applied).

## 2.7 Email
- Settings and provider presets exactly as `docs/03-auth-and-email.md` section 5 (Resend, ZeptoMail, Zoho Mail (verified values from Phase 0), Generic). Password stored encrypted; API never returns it (returns `has_password: true`).
- Messages queued as jobs (`mail.send`); password reset and invitation try once inline with a 5 s SMTP timeout, then fall back to the queue. Retries 1, 5, 15, 60 min; then `failed` + dashboard banner.
- Templates (HTML + text, English strings from `resources/lang/en.json`): `password_reset`, `invitation`, `verify_email`, `email_changed_notice`, `new_login_notice`, `two_factor_enabled`, `test_email` in this phase; `ai_change_pending`, `entry_in_review`, `entry_published`, `system_alert`, `webhook_failures`, `crawler_policy_changed` added in their phases.
- Header-injection protection: reject CR/LF in any header value; validate addresses with `filter_var` + length limits. Per-recipient limit 20 emails/hour (except system alerts to owners).
- `LogTransport` for development writes `.eml` files under `storage/logs/mail/`.

## 2.8 Installer update
Add step 6 **Owner account** (name, email, password with strength meter); creates user + owner role; email verification skipped if SMTP not configured, with a dashboard banner later.

## 2.9 Security tests (must exist and pass)
- Session fixation (id changes after login), CSRF missing/invalid token rejected, cookie flags, idle and absolute expiry.
- Brute force: lockout after 5 failures; IP limit; timing difference between known and unknown email under a threshold (statistical test with tolerance).
- No user enumeration on forgot-password, invite acceptance and login.
- TOTP replay rejected; recovery code single use; step-up required for protected endpoints.
- Reset token single use and expiry; all sessions revoked after reset.
- Authorizer matrix test: for each seeded role x each action x representative subjects, expected allow/deny table (data-driven test file). Deny-overrides-allow, owner protection, field filtering.
- API tokens: hash only at rest; revoked token rejected; scope + role intersection.
- Email: header injection attempt rejected; secrets never in logs or API responses.

## 2.10 Acceptance checklist
- [ ] All endpoints in 2.5 implemented with tests and listed in the OpenAPI document (generator added in Phase 3; until then keep a route list test).
- [ ] Seeded roles produce exactly the matrix in `docs/03-auth-and-email.md` section 2.3 (matrix test green).
- [ ] Owner created through installer on both shared hosts; login, 2FA setup, logout, reset password, invitation all work end to end with real emails through Resend and ZeptoMail test accounts.
- [ ] Audit entries written for every action in 2.6.
- [ ] Budgets: login request < 300 ms p95 including Argon2id on test hosts (record actual hash time).
