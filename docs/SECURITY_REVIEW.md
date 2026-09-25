# POSPilot Security Review

## Scope

Reviewed the authenticated web/API routes, import controllers and services, ownership policies, financial models, provider connection model, webhook route, and the new daily-closing workflow.

## Fixes applied

- Added ownership checks for daily closings and provider balance snapshots through the owning closing.
- Kept financial totals server-calculated; daily closing finalization recalculates all totals before writing the final state.
- Provider credentials are accessed through `ProviderSecretStore`; the local encrypted database adapter is development/test only and refuses production use. API responses do not return credentials or secret references.
- Kept ownership IDs, fingerprints, calculated totals, reconciliation fields, and override audit fields out of request-controlled operations.
- Added safe terminal deletion rules and bounded pagination.
- Added CSV row limits, UTF-8 header validation, empty/malformed-file handling, and spreadsheet formula rejection.
- Kept import preview confirmation server-side with expiring session tokens.
- Added throttling to authentication, import, charge-preview, webhook, and finalization operations.
- Changed generic webhook responses to fail closed without exposing provider identifiers or implementation details.
- Added audit metadata for manual customer-charge overrides.

## Provider integration considerations

OPay, PalmPay, and future providers must implement signature/authentication verification before any webhook or API connector is enabled. Moniepoint's published POS Apps webhook HMAC scheme is now implemented. Provider credentials are stored using encrypted attributes and hidden from JSON responses; credential values must never enter logs or CSV metadata.

The intended future webhook flow is:

`provider payload → provider-specific verification → replay/idempotency check → provider parser → normalized data → ingestion service`

Unsigned generic financial webhooks remain disabled. The generic handler still fails closed. Moniepoint has a dedicated signed webhook endpoint; CSV remains the supported fallback for all providers.

## Known remaining risks

- Production deployments still need HTTPS, secure cookie settings, secret rotation, database backup controls, and infrastructure-level rate limiting.
- OPay and PalmPay signature schemes remain unverified. Moniepoint's event-ID replay check follows its guide; no timestamp expiration window is imposed because the provider does not document a safe delivery-age window.
- Daily closing currently uses explicit configured transaction-type effects; new transaction types must be added to `config/pospilot.php` before being treated as financially meaningful.
- Provider balance snapshots are treated as positive provider balances, while the aggregate electronic position retains the configured signed transaction effects.
- Reconciliation issues are currently calculated rather than persisted as an audit ledger.

## Milestone 6 integration review

- Added a centralized capability matrix so planned API/webhook features are not presented as live support.
- Added a disabled OPay Business connector boundary for head merchant, branch merchant, POS serial, client authentication key, RSA material, signing, and response verification. It performs no network requests.
- Confirmed Moniepoint and PalmPay remain CSV-only in the current implementation; no undocumented API behavior was added.
- Added a complete authenticated three-provider workflow regression from registration through finalized daily closing.

## Milestone 7 Moniepoint security review

- Added encrypted `webhook_secret` storage and kept both API credentials and webhook secret hidden from serialization.
- Added authenticated, agent-scoped Moniepoint connection status, credential update, read-only key test, and disconnect endpoints. Connection tests target only the provider's documented development introspection host.
- Added a dedicated webhook route that verifies the documented HMAC-SHA256 over header event ID, timestamp, and exact raw body before normalization or persistence.
- Added a unique provider-connection/event-ID receipt constraint to prevent replayed deliveries and duplicate financial records.
- Rejected unknown event types/statuses and logged only safe static reasons/internal IDs. Personal payload fields and full raw request bodies are not persisted.
- Moniepoint transaction history sync has a job/connector contract but remains blocked without a documented list/history endpoint. No outbound sync request is made.
- Remaining concerns: deployment must enforce HTTPS; development introspection endpoint is not production host confirmation; documented webhook payload has no provider fee field, so webhook-only net earnings are provisional.

## Provider secret security gate — 2026-09-24

- Removed raw credential and webhook-secret columns from `provider_connections`; legacy encrypted values migrate into one connection/agent/provider-bound encrypted secret record, while `ProviderConnection` retains only a hidden opaque reference.
- Added the `ProviderSecretStore` contract and create/retrieve/rotate/revoke operations. Local encrypted storage is refused in production; an external adapter must be configured and implement the contract. Missing or invalid external configuration fails closed.
- Removed direct secret-column reads from provider domain services. Moniepoint API-key verification and webhook signature verification now retrieve their respective secrets through the contract.
- Hardened operational errors/logging to use safe static messages and exception classes rather than provider exception text. Connection responses expose only safe state; credentials are accepted only on the authenticated update request.
- Set Moniepoint webhook capability to `planned` pending production secret-store/deployment readiness. OPay/PalmPay live integration remains disabled.
- See [PROVIDER_SECRET_SECURITY.md](PROVIDER_SECRET_SECURITY.md) for threat model, deployment gates, and residual risks.
