# Moniepoint integration status

POSPilot implements the transaction webhook workflow documented by Moniepoint POS Apps Developer Support. API key testing uses the documented read-only key introspection endpoint in the development environment. Historical transaction sync and production API connectivity are not enabled.

## Verified owner setup

The published setup guides describe this flow:

1. Log in to the Moniepoint Business Dashboard and select the business.
2. Open Settings → POS Terminal Configuration / POS Terminal Features and enable ERP Integration.
3. Open POS Apps Developer and create an API key scoped to the required business and permissions. Save it securely when shown.
4. In Webhooks, create a subscription using the POSPilot webhook URL and choose event types.
5. Copy the subscription's generated secret while it is available.
6. Enter the API key, webhook secret, and business ID in the authenticated POSPilot connection endpoint. POSPilot tests the API key against the documented development introspection endpoint.

The provider's API key documentation says API credentials can be invalidated by deleting the client credential from the Moniepoint account. POSPilot disconnect only clears POSPilot's encrypted copy; it does not revoke the provider credential.

## Stored data

- API key in the encrypted `ProviderConnection.credentials` attribute.
- Webhook secret in the encrypted `ProviderConnection.webhook_secret` attribute.
- Selected business ID in `provider_merchant_identifier`.
- Connection state, safe status text, and last webhook/sync timestamps.
- Webhook receipt ID and resulting internal transaction ID for idempotency.

Credentials are hidden from model/API serialization. Logs contain only event labels and internal IDs. No raw webhook body is persisted.

## Data POSPilot never asks for

POSPilot does not request or store the Moniepoint login password, PIN, OTP, customer name, phone number, full account number, or unrelated webhook fields. The normalizer keeps only the documented transaction reference, amount, terminal serial, transaction type/status/time, event ID/type, and business ID needed by ingestion and reconciliation.

## Webhook processing

`POST /webhooks/providers/moniepoint` verifies the provider's documented Base64 HMAC-SHA256 using the webhook ID, timestamp, raw request body, and subscription secret. It requires an active matching business connection, then records the unique webhook ID and sends normalized data through `TransactionIngestionService`. Repeated event IDs are acknowledged without creating another transaction. Requests fail closed when signature, connection, event structure, or required transaction fields are invalid.

POSPilot does not impose an undocumented timestamp expiration window. It relies on the signed timestamp plus persistent event-ID uniqueness to prevent replayed deliveries. A provider event with an unsupported or undocumented status/type is rejected and should be handled through CSV until mapped from verified documentation.

The webhook example/schema documents amount in the smallest currency unit (kobo); the normalizer converts it to a two-decimal NGN amount. The documented event does not include provider fees. POSPilot currently records `0.00` and flags `metadata.provider_fee_supplied=false` for such webhook transactions. Their earnings contribution is therefore provisional and can overstate net earnings until a fee-bearing source is reconciled.

## Initial sync / backfill

`SyncMoniepointTransactions` and `MoniepointTransactionHistoryConnector` define the future connector/job boundary. The job uses `last_synced_at`, normalizes connector records, and calls the shared transaction ingestion service. No connector implementation is bound, so it marks sync as `blocked_documentation` and makes no network request.

The public POS Apps API pages reviewed document key introspection, webhook management, payment requests, and status lookup for a merchant-created payment reference. They do not document a general list/history endpoint for the POS owner's existing transactions. POSPilot will not use payment-submission or per-reference status endpoints to fabricate a backfill.

## What is implemented vs pending verification

| Area | State | Notes |
|---|---|---|
| CSV transactions and settlements | Supported | Existing permanent import path. |
| Key structure validation and connection storage | Implemented | Credentials are encrypted and excluded from serialization. |
| Key test | Implemented against documented development host | No real Moniepoint credential was supplied or tested. Production introspection base URL is not documented in the reviewed page. |
| Webhook HMAC and replay protection | Implemented from published guide | Signature formula and event ID header are documented; deploy endpoint behind HTTPS. |
| Transaction payload normalization | Implemented for documented event types and fields | Unknown events/statuses fail closed. Personal fields are discarded. |
| Provider fee in webhook | Not available in documented payload | Stored as zero with an explicit unknown-fee marker; earnings remain provisional. |
| Historical transaction backfill | Blocked | Need a documented merchant transaction-history endpoint, request permissions, pagination, date/filter rules, and response schema. |
| Settlement and balance sync | Planned | No verified endpoint/schema implemented. |
| Provider API revocation | Manual at Moniepoint | POSPilot disconnect removes its copy and leaves transaction history intact. |

## Security assumptions and follow-up

- Deploy the webhook endpoint over HTTPS, as required by Moniepoint's guide.
- Keep Laravel `APP_KEY` protected and backed up because encrypted credentials depend on it; rotate provider keys through Moniepoint when compromised.
- The connection test uses only the documented read-only development introspection endpoint; no production API host has been guessed.
- Ask Moniepoint for the production introspection host, transaction-history access, status vocabulary for failures/reversals, and a webhook fee field or fee lookup source before claiming complete net earnings from webhook-only records.

## Documentation references

- [Moniepoint POS as a Platform documentation](https://teamapt.atlassian.net/wiki/spaces/EI/pages/1892974728/POS%2Bas%2Ba%2BPlatform%2BDocumentation)
- [Moniepoint webhook guide](https://teamapt.atlassian.net/wiki/spaces/EI/pages/1492648078/Webhooks)
- [Moniepoint API key setup](https://teamapt.atlassian.net/wiki/spaces/EI/pages/2090893327)
- [Moniepoint webhook subscription setup](https://teamapt.atlassian.net/wiki/spaces/EI/pages/1651605514/Suscription%2BManagement)
