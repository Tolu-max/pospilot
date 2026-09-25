# POSPilot Authentication & Account Security Contract

## Authentication model

POSPilot uses Laravel's `web` session guard and CSRF protection. It does not issue Bearer tokens, JWTs, or personal access tokens for browser/API authentication. API routes are same-origin Laravel web routes. Keep the configured session cookie and send the current `XSRF-TOKEN` cookie as `X-XSRF-TOKEN`, or read the page's `csrf-token` meta tag and send `X-CSRF-TOKEN`, on state-changing requests. Send `Accept: application/json` when a JSON response is wanted.

The cookie name is configured by `SESSION_COOKIE`; do not hardcode `laravel_session`. Production must use HTTPS, `SESSION_SECURE_COOKIE=true`, `SESSION_HTTP_ONLY=true`, and an appropriate `SESSION_SAME_SITE` value for the deployed origin. The default session lifetime is 120 minutes. Keep CSRF enabled.

## Email/password routes

| Method | Path | Authentication | Request | Result |
|---|---|---|---|---|
| GET | `/register` | Guest | — | Inertia registration page. |
| POST | `/register` | Guest, throttled | `name`, `email`, `password`, `password_confirmation` | Creates an account, sends a signed verification email, authenticates, regenerates the session ID, and redirects to `/dashboard`. Validation failure: `422`. |
| GET | `/login` | Guest | — | Inertia login page. |
| POST | `/login` | Guest, throttled | `email`, `password`, optional `remember` | Authenticates and regenerates the session ID. Invalid credentials return the same generic error regardless of whether the email exists. |
| POST | `/logout` | Authenticated | — | Logs out, invalidates the session, regenerates the CSRF token, then redirects to `/`. |

Login and registration are limited to six HTTP requests per minute per IP, with the login FormRequest also limiting failed attempts per normalized email/IP pair. The exact response text for a lockout is intentionally shared between existing and non-existing accounts.

## Email verification

`App\Models\User` implements Laravel's `MustVerifyEmail`. Registration sends the verification notification. Google accounts are considered verified only when the verified-email claim returned by Google's user-info response is true. Users can request another link with `POST /email/verification-notification` (authenticated, six/minute) and follow the signed link at `GET /verify-email/{id}/{hash}`. The verification notice is `GET /verify-email`.

Unverified users may authenticate and read/update their agent business profile so onboarding can continue. Financial/provider operations—including transactions, CSV imports, terminals, charge rules, expenses, settlements, reconciliation, financial summaries, daily closings, provider connections, and the QA integration page—require both authentication and verified email. The signed Moniepoint webhook remains provider-authenticated and is not a user-session route.

## Google OAuth

Google sign-in uses Laravel Socialite with its normal session-backed OAuth `state` verification; the application never calls Socialite's `stateless()` mode.

| Method | Path | Authentication | Result |
|---|---|---|---|
| GET | `/auth/google/redirect` | Guest or authenticated, throttled | Starts Google authorization with the `openid`, `profile`, and `email` scopes. |
| GET | `/auth/google/callback` | OAuth callback, throttled | Validates state, verified email, and Google subject; signs in or creates a user; then regenerates the session. |
| GET | `/auth/google/link` | Authenticated, verified, recent authentication, throttled | Starts explicit linking of a verified Google identity to the signed-in account. Google email must match the local account email; an identity already owned by another user is rejected. |
| GET | `/auth/google/reauthenticate` | Authenticated, throttled | Reauthenticates an already-linked Google identity, enabling passwordless Google accounts to satisfy Laravel's recent-authentication gate. |

Set `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, and `GOOGLE_REDIRECT_URI` in the server environment. The registered Google redirect URI must exactly match `/auth/google/callback` on the deployed HTTPS host. When these values are absent, Google sign-in fails closed with a safe status message.

Google's immutable subject ID is stored uniquely in `users.google_id` and hidden from model serialization. OAuth access tokens, refresh tokens, ID tokens, and client secrets are never persisted, returned, or logged. A Google credential is used only for POSPilot account authentication; it is never used for Moniepoint, OPay, PalmPay, or any provider integration.

An existing email/password account is not silently linked during Google login. The user must sign into that account, complete recent authentication, and explicitly use the Google-link flow. A verified Google email that matches another local email without a prior link receives the same generic sign-in failure as other OAuth failures. Account uniqueness constraints prevent races from creating duplicate identities.

## Password recovery and changes

| Method | Path | Authentication | Contract |
|---|---|---|---|
| GET | `/forgot-password` | Guest | Password-reset request page. |
| POST | `/forgot-password` | Guest, six/minute | Accepts `email`. For every syntactically valid address, returns the same status: “If an account with that email exists, a password reset link has been sent.” Only an eligible account receives a notification. |
| GET | `/reset-password/{token}` | Guest | Password-reset form. |
| POST | `/reset-password` | Guest, six/minute | Accepts `token`, `email`, `password`, and `password_confirmation`. Invalid reset details return a generic error that does not identify an account. Successful reset rotates the remember token, revokes database-backed sessions, records a security event, and redirects to `/login`. |
| GET/POST | `/confirm-password` | Authenticated | Confirms the local password and records Laravel's recent-authentication timestamp. POST is throttled to six/minute. |
| PUT | `/password` | Authenticated, six/minute | Requires `current_password`, a valid new password, and confirmation. The current password is the reauthentication proof; other database sessions and remember-me credentials are revoked. |

An email/password login, registration, or successful Google authentication also sets the recent-authentication timestamp. Laravel's default confirmation window is three hours (`AUTH_PASSWORD_TIMEOUT`, default `10800` seconds). Sensitive operations use the built-in `password.confirm` middleware; JSON requests receive `423` with `Password confirmation required.` Otherwise the browser is sent to `/confirm-password` and returned to its intended destination after confirmation.

## Session/device management

| Method | Path | Authentication | Result |
|---|---|---|---|
| GET | `/api/security/sessions` | Authenticated | `{ "available": true, "sessions": [{ "id": "<sha256 session id>", "current": true, "ip_address": "…", "user_agent": "…", "last_active_at": "…" }] }`. Only the current user's active sessions are listed. Raw session IDs and session payloads are never returned. |
| DELETE | `/api/security/sessions/others` | Authenticated, recent authentication, ten/minute | Deletes the current user's other database-backed sessions, rotates the remember token, and returns `{ "revoked_sessions": 2 }`. The current session remains active. |

Device listing/revocation requires `SESSION_DRIVER=database`, which is the application's default. Listing returns `503` with `available: false` under another driver; revocation returns `503` rather than claiming other sessions were revoked. Use the database driver in production when session management is required.

## Recent-authentication protections

- `PUT`/`POST test`/`DELETE /api/providers/moniepoint/connection` require a recent confirmation. Provider credentials are never flashed back as old form input.
- `GET /auth/google/link`, `PATCH /profile` (which can change the login email), and `DELETE /profile` require recent authentication. Account deletion also requires the current password for password-enabled accounts; passwordless Google accounts must have just reauthenticated with their linked Google identity.
- `PUT /password` requires the current password in the request.
- `DELETE /api/security/sessions/others` requires recent authentication.

## Security events and two-factor authentication

Lightweight `security_events` records include login, failed login, logout, registration, password changes, email changes, Google linking/rejection/reauthentication, session revocation, and Moniepoint connection lifecycle events. They store event type, user when known, IP address, user agent, timestamp, and a strict allowlist of non-secret metadata. Email addresses on failed attempts, passwords, OAuth tokens, provider credentials, and OTPs are excluded.

TOTP is not exposed in this milestone: no established TOTP package was installed, and POSPilot does not implement custom OTP cryptography. Add it only with an approved, maintained library and a separate recovery-code/account-recovery contract.

## Frontend handling requirements

- Keep the browser session cookie; do not put a token in local storage or add an `Authorization: Bearer` header.
- Keep CSRF protection on every mutating same-origin request. Native top-level navigation is required for Google authorization and callback redirects.
- Handle `422` validation, `423` reauthentication, `429` throttling, and `503` session-store unavailability distinctly.
- Show the verification notice/resend action when authenticated but unverified; do not call financial/provider endpoints until verification succeeds.
- Treat all OAuth/provider credentials as write-only. Do not save them in browser storage, analytics, logs, or client error reports.
