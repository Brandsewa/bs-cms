# Sprint D: Polish, Shell & Account Completeness Report

**Branch:** `sprint/d-polish`  
**Author:** Session D (Antigravity Design / Shell Agent)  
**Date:** 2026-10-21  

---

## 1. What Was Built

### 1.1 User Account Area (`/admin/account` & `admin/src/account`)
- **My Account View (`admin/src/account/my-account-view.tsx`)**:
  - Implemented 5 dedicated tabs: **Profile**, **Password**, **Email**, **2FA Security**, and **Sessions**.
  - **Profile Details**: Name, locale, and timezone updating via `PATCH /api/v1/me`. Displays user status and assigned system/custom roles.
  - **Password Change**: Validates minimum length and confirmation; invokes `POST /api/v1/me/password` which safely revokes all other sessions.
  - **Email Change Flow**: Enforces step-up authentication and invokes `POST /api/v1/me/email` for verification email transmission.
  - **Two-Factor Authentication**:
    - Zero-dependency inline SVG QR code generator (`admin/src/account/qr-code.tsx`) using GF(256) Reed-Solomon encoding for TOTP standard URIs (`otpauth://...`).
    - Full setup verification cycle (`POST /api/v1/me/2fa/setup` & `POST /api/v1/me/2fa/confirm`).
    - Displays 10 backup recovery codes with 1-click clipboard copy.
    - Disabling 2FA and regenerating recovery codes with step-up security.
  - **Active Sessions Management**: Lists active user sessions with IP, User-Agent, last seen, and current session badge (`GET /api/v1/me/sessions`). Allows instant revocation of non-current sessions (`DELETE /api/v1/me/sessions/{id}`).
  - **Notification Preferences**: Configures email on sign-in, role change notifications, and in-app system alerts via `GET` / `PATCH /api/v1/me/notification-preferences`.
- Linked directly from the user dropdown menu in the top bar header and Command Menu (`Ctrl+K`).

### 1.2 Auth Screens Polish
- **Auth Card (`admin/src/auth/auth-card.tsx`)**: Refined centered card layout with official BS CMS Studio icon mark, clean typography, and responsive max-w-sm footprint (tested at 390px and 1440px).
- **Generic Error Messages**: Strict adherence to anti-enumeration principles on login, 2FA challenge, forgot password, and reset password.
- **Lockout (HTTP 423)**: Informative lockout banner when rate limits/failed attempts are exceeded.
- **Session Expired Dialog (`admin/src/auth/session-expired-dialog.tsx`)**: Clean modal offering immediate 1-click re-authentication with return-to preservation.

### 1.3 Utility & Error Pages
- **500 Error Boundary (`admin/src/shell/error-boundary.tsx`)**: Enhanced with traceable request/error reference ID and copy-to-clipboard button.
- **Offline Banner (`admin/src/shell/offline-banner.tsx`)**: Auto-detects browser network connectivity (`online`/`offline` events) and displays polite warning banner.
- **Document Title Hook (`admin/src/shell/use-document-title.ts`)**: Standardizes per-route document title updates (`<Title> | BS CMS Studio`).
- **Forbidden (403) & Not Found (404)**: Unified with standard `@bs/ui` states.

### 1.4 Onboarding & First-Run Experience
- **Getting Started Checklist (`admin/src/dashboard/onboarding-card.tsx`)**: Driven by `data.onboarding.steps` from `GET /api/v1/dashboard`. Auto-completes steps, routes directly to screens (`/admin/entries/pages/new`, `/admin/settings`, etc.), and persists user dismissal in `localStorage`.
- **First-Run Welcome Dialog (`admin/src/dashboard/welcome-dialog.tsx`)**: Welcomes new administrative users on initial login and provides immediate entry point into account security configuration.

### 1.5 Shell & UI-Kit Completeness
- **PageStates Primitive (`packages/ui/src/layout/page-states.tsx`)**: Unified wrapper handling `isLoading`, `error`, `isForbidden`, and `isEmpty` without layout shift.
- **Notification Drawer (`admin/src/shell/notification-drawer.tsx`)**: Header bell button with live unread badge and slide-over sheet connected to `/api/v1/notifications`.
- **Keyboard Shortcuts Dialog (`admin/src/shell/shortcuts-dialog.tsx`)**: Press `?` anywhere to inspect global navigation and command palette shortcuts.
- **Role Preview Banner (`admin/src/shell/role-preview-banner.tsx`)**: Top broadcast banner for simulating role views.

---

## 2. API Endpoints Utilized

| Endpoint | Method | Purpose |
|---|---|---|
| `/api/v1/me` | `GET`, `PATCH` | Profile retrieval and updates |
| `/api/v1/me/password` | `POST` | Password update with session revocation |
| `/api/v1/me/email` | `POST` | Sensitive email change request |
| `/api/v1/me/2fa/setup` | `POST` | Initiate TOTP setup |
| `/api/v1/me/2fa/confirm` | `POST` | Verify TOTP code and issue recovery codes |
| `/api/v1/me/2fa/disable` | `POST` | Disable TOTP protection |
| `/api/v1/me/2fa/recovery-codes` | `POST` | Re-issue backup codes |
| `/api/v1/me/sessions` | `GET` | Retrieve active user sessions |
| `/api/v1/me/sessions/{id}` | `DELETE` | Revoke specific session |
| `/api/v1/me/notification-preferences` | `GET`, `PATCH` | Notification toggle settings |
| `/api/v1/notifications` | `GET` | List notifications and unread count |
| `/api/v1/notifications/read-all` | `POST` | Mark notifications as read |
| `/api/v1/dashboard` | `GET` | Onboarding progress and system telemetry |

---

## 3. Known Gaps & Notes for Testing
- **Settings Table Key Column in PHP**: Notice recorded in `docs/ui/BACKEND-REQUESTS.md` regarding `bs_settings` table using `handle` rather than `key` for `user_notif_prefs`.
- **QR Code Rendering**: Pure TypeScript SVG QR matrix generation avoids external npm bloat and keeps the admin bundle chunk strictly under the 200 KB gzipped limit.
