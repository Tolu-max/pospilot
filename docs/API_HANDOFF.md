# POSPilot API Handoff

Browser and API authentication use Laravel's `web` session guard; there is no Bearer-token authentication contract. Retain the session cookie configured by `SESSION_COOKIE` (do not hardcode a cookie name) and include the current page's `csrf-token` meta value as `X-CSRF-TOKEN`, or send the `XSRF-TOKEN` cookie back as `X-XSRF-TOKEN`, on state-changing calls. Login and registration regenerate the session; logout invalidates it and regenerates the CSRF token. JSON clients should send `Accept: application/json`; validation failures return `422` with Laravel's `message` and `errors` structure. Ownership failures return `404` for scoped financial resources. Pagination is capped at 100 where `per_page` is accepted. Financial/provider functionality requires an authenticated, verified-email account; agent-profile onboarding and account-security routes are available to authenticated unverified users where noted.

## Contract freeze and staging clients

Frontend contract version: `1` (Milestone 8). Existing keys and meanings are frozen for the staging handoff. New response fields may be added compatibly; do not rename/remove keys or reinterpret amounts without an explicit contract revision. Money is returned as decimal strings in NGN; clients must not recalculate authoritative earnings with JavaScript floating-point arithmetic. `estimated_earnings` and summary net earnings must be displayed with their accompanying confidence status.

For a local Postman or Bruno environment, configure only `base_url` (for example, the staging origin), and use an authenticated Laravel session plus CSRF cookie/token for state-changing requests. Keep `Accept: application/json` and `Content-Type: application/json` on JSON calls; multipart import calls use the client's generated multipart content type. Treat the session cookie and CSRF token as credentials: use a private/local environment, never commit populated environment files, and never export live cookies in a collection. Do not add provider API keys, webhook secrets, RSA material, passwords, PINs, or OTPs to Postman/Bruno environments. Provider integration credentials are accepted only through the dedicated backend connection flow and are never returned.

## Authentication

| Method | Path | Request | Response/status |
|---|---|---|---|
| POST | `/register` | `name`, `email`, `password`, `password_confirmation` | Creates the account, sends verification email, authenticates, regenerates session, then redirects. `422` validation failure. |
| POST | `/login` | `email`, `password`, `remember` optional | Redirects to `/dashboard`; session is regenerated. Invalid credentials use one generic error. Throttled. |
| POST | `/logout` | none | Redirects to `/`; session is invalidated and token regenerated. |
| POST | `/forgot-password` | `email` | Throttled. Same success response for existing and unknown valid emails; only known eligible users get mail. |
| POST | `/reset-password` | `token`, `email`, `password`, `password_confirmation` | Generic invalid-token error; on success changes password, revokes database sessions, and redirects to login. |
| GET | `/verify-email` | none | Verification notice for authenticated unverified users. |
| GET | `/verify-email/{id}/{hash}` | signed URL | Verifies the signed-in account; invalid/expired signatures are rejected. |
| POST | `/email/verification-notification` | none | Sends another signed link; throttled. |

Google OAuth routes, verified-email enforcement, CSRF/session handling, provider linking policy, and error statuses are specified in [AUTHENTICATION.md](AUTHENTICATION.md). Google OAuth uses Socialite state checking and does not persist tokens.

Account session management:

| Method | Path | Authentication | Response/status |
|---|---|---|---|
| GET | `/api/security/sessions` | Authenticated session | Current user's active devices only; opaque SHA-256 session IDs, current-device flag, IP, user agent, and activity time. Returns `503` if the configured session driver is not `database`. |
| DELETE | `/api/security/sessions/others` | Authenticated, recently reauthenticated; throttled | Revokes only the current user's other database sessions and remember-me credentials; returns `{ "revoked_sessions": 2 }`. JSON without recent confirmation receives `423`. |

Provider finance routes are protected by `auth`, `auth.session`, and `verified`. Account business-profile endpoints remain available after login for onboarding. Sensitive provider credential routes also require recent authentication. The signed provider webhook is not a user-session endpoint.

## Agent profile

Onboarding note (frontend QA): a newly registered and email-verified user may not yet have an `AgentProfile`. Previously, `GET /dashboard` returned `404` in this state, leaving the user without an onboarding destination even though `PATCH /api/agent/profile` can create the profile. Also, a profile saved with `onboarding_state: in_progress` must resume onboarding if the user leaves before finishing. The dashboard renders the onboarding screen while the profile is absent or not completed; the profile API contract remains unchanged.

| Method | Path | Request | Representative response |
|---|---|---|---|
| GET | `/api/agent/profile` | none | `{ "id": 1, "business_name": "Lagos Corner POS", "country": "Nigeria", "currency": "NGN", "onboarding_state": "completed" }` |
| PATCH | `/api/agent/profile` | `business_name`, `phone`, `country`, `currency`, `location`, `onboarding_state` | Returns the profile. On first update, the profile is created with Nigeria/NGN defaults. State: `not_started`, `in_progress`, `completed`. |

## Providers and connections

| Method | Path | Request | Representative response |
|---|---|---|---|
| GET | `/api/providers` | none | `{ "data": [{ "id": 1, "name": "OPay", "slug": "opay", "capabilities": { "direct_connection": "requires_provider_access", "connector_implementation": "planned", "api_transaction_sync": "documented" } }] }` |
| GET | `/api/provider-connections` | none | Returns the user's connections with provider, `connection_state`, `last_synced_at`, `last_imported_at`, sync status, and configured terminal count. Credentials are never returned. |

Connection states are `csv_only`, `manual`, `demo`, `connected`, and `sync_error`. Moniepoint has a separate connection status API below. Its API-key introspection test exists; webhook ingestion is disabled because the current official reference does not verify the callback authentication and payload contract, and existing-terminal history sync is not documented.

### Moniepoint connection

All four routes below require the authenticated, email-verified Laravel web session. State-changing routes use CSRF protection. Credential update, connection test, and disconnect additionally require recent authentication (`password.confirm`; JSON returns `423` when stale). The API key is accepted only on update and is never returned or flashed back as old input. Connection test currently targets Moniepoint's documented development introspection host.

| Method | Path | Request | Response/status |
|---|---|---|---|
| GET | `/api/providers/moniepoint/connection` | none | `{ "connected": false, "connection_type": null, "status": "not_connected", "error": null, "last_synced_at": null, "last_webhook_at": null, "terminal_count": 0 }` |
| PUT | `/api/providers/moniepoint/connection` | `api_key`, `webhook_secret`, numeric `business_id` | Saves encrypted values, leaves status unverified, returns safe status. `201` created, `200` updated, `422` validation. |
| POST | `/api/providers/moniepoint/connection/test` | none | Tests documented read-only key introspection and verifies the selected business ID. `{ "connected": true, "status": "verified", "error": null, "connection": { ... } }`. `422` failed test, `404` missing connection. |
| DELETE | `/api/providers/moniepoint/connection` | none | Invalidates POSPilot credentials while preserving existing records. `{ "connected": false, "status": "disconnected" }`. |

Connection update and test are throttled to 10 requests per minute. Never send the Moniepoint dashboard password, PIN, or OTP.

### Moniepoint webhook

| Method | Path | Authentication | Request | Response/status |
|---|---|---|---|---|
| POST | `/webhooks/providers/moniepoint` | Provider HMAC signature; no user session | Documented headers `moniepoint-webhook-id`, `moniepoint-webhook-timestamp`, `moniepoint-webhook-signature`, plus documented event JSON | `{ "accepted": true, "duplicate": false }`; repeat ID returns `duplicate: true`; invalid signatures return generic `401`, malformed/unsupported events return `401`, processing failure returns generic `500`. Throttled to 30/min. |

Webhook event IDs are stored with a unique connection/event constraint. The webhook normalizer converts documented kobo amounts to NGN, resolves `terminalSerial`, and only retains event ID/type/business ID metadata. Fee is not present in the documented transaction event, so it is set to `0.00` with `provider_fee_supplied=false`; earnings from those records are provisional until fee data arrives through a verified source.

## Terminals

| Method | Path | Request | Response/status |
|---|---|---|---|
| GET | `/api/terminals` | none | `{ "data": [{ "id": 1, "name": "OPay Main", "provider": { ... }, "active": true }] }` |
| POST | `/api/terminals` | `provider_id`, `name`, optional `terminal_identifier`, optional `active` | Created terminal, `201`. |
| PATCH | `/api/terminals/{terminal}` | Any terminal fields above | Updated terminal, `200`; another agent's terminal is `404`. |
| PATCH | `/api/terminals/{terminal}/status` | `active` boolean | Updated terminal. |
| DELETE | `/api/terminals/{terminal}` | none | `204` only when no financial records exist; `422` otherwise. |

## Charge rules

| Method | Path | Request | Response/status |
|---|---|---|---|
| GET | `/api/charge-rules` | none | `{ "data": [{ "minimum_amount": "1.00", "maximum_amount": "5000.00", "charge_type": "fixed", "charge_value": "100.0000", "priority": 1, "active": true }] }` |
| POST | `/api/charge-rules` | `minimum_amount`, optional `maximum_amount`, `charge_type` (`fixed`/`percentage`), `charge_value`, optional `provider_id`, `priority`, `active` | Created rule, `201`. |
| PATCH | `/api/charge-rules/{chargeRule}` | Any rule fields | Updated rule. Invalid ranges or same-scope equal-priority overlaps return `422`. |
| DELETE | `/api/charge-rules/{chargeRule}` | none | `204`. |
| GET | `/api/charge-rules/preview?amount=10000&provider_id=1` | query fields | `{ "amount": "10000.00", "charge": "200.00", "rule": { ... } }` |

## Transactions

| Method | Path | Request/query | Response/status |
|---|---|---|---|
| GET | `/api/transactions` | `provider_id`, `terminal_id`, `transaction_status`, `settlement_status`, `from`, `to`, `reference`, `per_page` | Laravel pagination. Each transaction includes provider, terminal, customer charge, charge source, legacy provider fee and `provider_fee_supplied`, adjustment components, `estimated_earnings`, `financial_status`, `reconciliation_status`, source, and timestamps. |
| GET | `/api/transactions/{transaction}` | none | One ownership-scoped transaction with `estimated_earnings`, `financial_status`, provider, terminal, import batch, adjustment components, and charge override actor where present. |
| PATCH | `/api/transactions/{transaction}/customer-charge` | `customer_charge_override` nullable decimal | Updated transaction. The original imported and calculated values remain intact; manual source, actor, and timestamp are recorded. |

Successful transactions alone contribute to earnings. Failed, pending, and reversed transactions are excluded from finalized earnings.

`financial_status` on transaction responses has this shape: `{ "financial_data_status": "provisional", "earnings_status": "provisional", "is_final": false, "reasons": ["provider_fee_missing"], "customer_charge_source": "calculated", "provider_fee_supplied": false, "provider_fee_components_complete": false, "is_calculated": true, "is_manually_overridden": false }`. `financial_data_status` distinguishes `complete_verified`, `provisional`, `calculated`, and `manually_overridden`; `earnings_status` independently says `final` or `provisional`. Calculated charges from configured POSPilot charge rules and deliberate manual overrides are surfaced as provenance, not confused with missing provider deductions. A numeric `provider_fee` of `0.00` is not evidence of a zero fee when `provider_fee_supplied` is false. When a scalar fee exists without a verified complete fee-component breakdown, the reason is `provider_fee_components_incomplete`; both provisional conditions aggregate to `earnings_status: provisional`.

## Imports (internal QA/recovery only)

The QA page and preview/confirm upload routes are retained for internal tooling but return `404` outside `local` and `testing`; they are not part of the production agent workflow. Gmail statement discovery and import routes are implemented behind the Gmail feature flag; production OAuth, secure token-store configuration, and real-mailbox verification remain deployment prerequisites. See [GMAIL_STATEMENT_INTEGRATION.md](GMAIL_STATEMENT_INTEGRATION.md).

| Method | Path | Request | Response/status |
|---|---|---|---|
| POST | `/transactions/import/preview` | multipart `provider_id`, `file`, optional `mapping` | Preview with `rows_detected`, `valid`, `duplicates`, `invalid`, row errors, and expiring `preview_token`. CSV max size is 5 MB and max data rows is 5,000. |
| POST | `/transactions/import/confirm` | `preview_token` | `{ "batch_id": 1, "rows_detected": 10, "rows_imported": 8, "rows_duplicate": 1, "rows_failed": 1 }` |
| POST | `/settlements/import/preview` | multipart `provider_id`, `file`, optional `mapping` | Settlement preview with the same counts and an expiring `preview_token`. |
| POST | `/settlements/import/confirm` | `preview_token` | Settlement import summary with batch counts. |
| GET | `/api/imports` | optional `per_page` | Paginated import history. |
| GET | `/api/imports/{importBatch}` | none | Ownership-scoped batch with provider, transactions, and settlements. |

Uploaded files are not stored permanently. Formula-like spreadsheet content, invalid encoding, malformed rows, and oversized statements are rejected or reported as invalid.

## Settlements and reconciliation

| Method | Path | Request/query | Response |
|---|---|---|---|
| GET | `/api/settlements` | none | Paginated ownership-scoped settlements with provider and terminal. |
| GET | `/api/settlements/{settlement}` | none | Settlement plus reconciliation comparison: expected, actual, discrepancy, outcome, matched transaction count, and structured issues. |
| GET | `/api/reconciliation/overview` | optional `from`, `to` | Outcome counts, settlement count, unreconciled amount, and issue count. |
| GET | `/api/reconciliation/issues` | optional `from`, `to` | Structured issue list including issue type, message, provider, date, and settlement ID where applicable. |

Outcomes include `reconciled`, `pending`, `partially_reconciled`, `unreconciled`, and `disputed`.

## Earnings and financial summary

| Method | Path | Request/query | Response |
|---|---|---|---|
| GET | `/api/financial-summary` | optional `from`, `to` | Includes `transaction_volume`, successful count, customer charges, provider fees, other transaction credits, expenses, estimated net earnings, `earnings_status`, `financial_data_status`, `is_final`, provisional transaction/reason counts, calculated/manual override counts, pending/reversed values, attention count, unreconciled amount, and issue count. |
| GET | `/api/provider-breakdown` | optional `from`, `to` | Provider rows with transaction volume/count, charges, fees, other transaction credits, estimated earnings, confidence statuses and counts, settlement discrepancy, and unreconciled count. |

Representative completeness fields in a financial summary: `{ "estimated_net_earnings": "200.00", "earnings_status": "provisional", "financial_data_status": "provisional", "is_final": false, "provisional_transaction_count": 1, "provisional_reasons": [{ "code": "provider_fee_missing", "transaction_count": 1 }], "calculated_transaction_count": 0, "manually_overridden_transaction_count": 0, "complete_verified_transaction_count": 0 }`. Any included successful transaction with unavailable fees makes the aggregate provisional. Amounts remain estimates for continuity, but must not be presented as final.

The authoritative earnings formula is customer charges plus other transaction credits minus provider deductions and recorded expenses. Legacy transactions continue using `provider_fee`; a complete authoritative component breakdown replaces that legacy deduction in calculations without changing the legacy column. Every component has explicit provenance; the backend does not guess or synthesize VAT/tax/levy/commission/provider deductions. See [TRANSACTION_ADJUSTMENTS.md](TRANSACTION_ADJUSTMENTS.md).

## Expenses

| Method | Path | Request | Response/status |
|---|---|---|---|
| GET | `/api/expenses` | optional `per_page` | Paginated owned expenses. |
| POST | `/api/expenses` | `amount`, `category`, `description` optional, `expense_date` | Created expense, `201`. Categories: `transport`, `power`, `staff`, `cash handling`, `miscellaneous`. |
| PATCH | `/api/expenses/{expense}` | Any expense fields | Updated expense. |
| DELETE | `/api/expenses/{expense}` | none | `204`. |

## Daily closings

| Method | Path | Request | Response/status |
|---|---|---|---|
| GET | `/api/daily-closings/preview` | optional `closing_date` | Server-calculated expected cash/electronic position, volume, charges, fees, expenses, variance, pending/reversed counts, and structured reasons. |
| GET | `/api/daily-closings` | optional `from`, `to`, `per_page` | Paginated owned closing history. |
| POST | `/api/daily-closings` | optional `closing_date`, `opening_cash`, `entered_closing_cash`, `notes` | Creates or updates the one draft for the agent/date. |
| GET | `/api/daily-closings/{dailyClosing}` | none | Current server-calculated closing and snapshots. |
| POST | `/api/daily-closings/{dailyClosing}/balances` | `provider_id`, optional `terminal_id`, optional non-negative `actual_balance` | Saves a provider/terminal balance snapshot and recalculates variance. |
| GET | `/api/daily-closings/{dailyClosing}/breakdown` | none | Variance, status, pending/reversed counts, and structured reasons. |
| POST | `/api/daily-closings/{dailyClosing}/finalize` | none | Finalizes only after required actual values are supplied. Re-finalization returns `422`. |

Closing status is recalculated server-side. Finalized closings cannot be edited.

Finalization requires an entered closing-cash value whenever expected cash can be calculated, and an actual provider balance for every provider with successful activity that day. A provider-level snapshot satisfies the provider requirement; otherwise, all active transaction terminals for that provider need terminal-level snapshots. Expected electronic position continues to include all successful transactions while actual balances are incomplete, and the close remains unresolved until required actuals are supplied.

## Gmail statement beta

Gmail routes are behind authenticated, verified sessions and `FEATURE_GMAIL_STATEMENTS`. Dedicated Gmail OAuth is separate from normal Google sign-in. The OAuth start/callback use `GET /integrations/gmail/connect` and `GET /integrations/gmail/callback`; the exact production callback is `https://pospilot.tconnect.com.ng/integrations/gmail/callback`. Scope is the restricted `https://www.googleapis.com/auth/gmail.readonly`.

| Method | Path | Purpose |
|---|---|---|
| GET | `/api/gmail/connection` | Safe connection status and statement counts; no tokens or message identifiers. |
| PUT | `/api/gmail/connection/rules` | Save selected provider slugs and exact sender email rules. |
| POST | `/integrations/gmail/sync` | Bounded synchronous manual statement discovery; response reports candidate and statement counts. |
| GET | `/api/gmail/statements` | List safe statement status and headers for first-time mapping. |
| POST | `/api/gmail/statements/{message}/mapping` | Save a schema mapping for an owned statement and selected owned terminal. |
| DELETE | `/integrations/gmail` | Revoke best-effort, delete local tokens/temp files, and mark disconnected. |

See [Gmail statement integration](GMAIL_STATEMENT_INTEGRATION.md) and [Google Cloud setup](GMAIL_GOOGLE_CLOUD_SETUP.md) for consent, privacy, testing mode, supported formats, and production gates. Real provider statement schemas remain unverified until checked against real provider-issued spreadsheets. Google restricted-scope production verification is not complete.

For internal integration QA only, authenticated users can visit `/qa/integration`. It is an isolated throwaway page and is not part of the production frontend contract. The fictional cross-system arithmetic is frozen in `docs/QA_SCENARIO.md`; the Postman collection and local environment are under `docs/postman/`.

## Direct POS provider integrations

`GET /api/providers` returns the central provider capability states from `config/pospilot.php`. Treat `documented`, `planned`, `requires_provider_access`, `unverified`, `unsupported`, and `coming_later` as distinct states. A documented API is not an implemented connector or a live-tested connection. OPay TN historical query and Moniepoint webhook events are not enabled as production integrations by this application. See [provider research](providers/DIRECT_PROVIDER_RESEARCH.md).

All imports/direct sources must use `NormalizedTransactionData` and `TransactionIngestionService`. The service prioritizes documented provider transaction IDs, RRN, provider order references, then normalized references. Fallback identity includes provider, business/account fingerprint, terminal, type, amount, and exact timestamp (not fee). If no stable terminal identifier exists, the source and source reference also scope the fallback, preventing unsafe cross-source merges based only on amount/time. Connectors should normalize stable references and terminal identifiers whenever available.

Every ingestion observation creates or reuses an audit-safe `transaction_source_records` row containing only source type, a hash of its reference, a hash of selected safe metadata, and observation time. It does not retain raw email IDs, customer details, or provider payloads. A later more-authoritative source may replace a supplied provider fee only after the shared transaction identity matched; the prior fee/source/time are retained in `metadata.provider_fee_history`. Missing fees remain unknown and earnings remain provisional. Source priority values live in `config/pospilot.php` in this order: `provider_api`, `provider_webhook`, `provider_statement`, `calculated`, `manual`. Priority never replaces fields automatically; enrichment is field-specific and must preserve audit history.
