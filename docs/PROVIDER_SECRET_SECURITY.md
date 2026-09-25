# Provider Secret Security Gate

## Gate status

Production provider synchronization is not enabled. The application now accesses integration secrets only through `ProviderSecretStore`. The included encrypted database implementation is for local development and tests and refuses to read or write secrets in the production environment. Production must configure an external implementation of the contract (`PROVIDER_SECRET_STORE=external` and `PROVIDER_SECRET_STORE_CLASS`) backed by an approved secret manager or KMS. If that adapter is missing or invalid, credential operations fail closed with a safe unavailable response.

No external KMS adapter is included or verified in this repository. Therefore this is a hardened integration boundary, not production approval to connect a live provider.

## Secret policy and access boundary

- Accept only officially issued integration credentials. Never accept provider account passwords, login/transaction/card PINs, or OTPs.
- `ProviderSecretStore` is the only application-level interface for storing, retrieving, rotating, or revoking a secret. Domain services do not read credential columns.
- Each secret record is bound to one provider connection, agent, and provider. The local implementation encrypts the secret map with Laravel's encrypted cast; `ProviderConnection` stores only a UUID reference and hides that reference from serialization.
- API responses expose safe connection state only. Submitted secret values are never echoed, and subsequent status responses do not reveal them.
- Secret values and provider response bodies are excluded from logs and exception messages. Operational logs use static messages, internal record IDs, and exception class names only.
- Disconnect revokes secret access and marks the connection inactive; imported financial history is retained.
- Provider permission requests must be least-privilege and limited to verified transaction reading, terminal identification, settlement/reconciliation, and balance access. Transfer, withdrawal, payout, and payment-initiation permissions are out of scope.

## Threat model

| Threat | POSPilot mitigation | Remaining exposure |
|---|---|---|
| Database-only breach | Local/test secrets are encrypted at rest; provider connections contain a reference, not secret material. Production is required to store only a secret-manager reference through an external adapter. | An attacker with both database access and the Laravel encryption key can decrypt local-store records. Local storage is not an approved production configuration. |
| Compromised application account | Tenant-scoped connection endpoints and connection/provider/agent binding prevent cross-record lookup; secrets are never returned to the browser. Application execution may still use secrets internally. | A fully compromised application process or privileged runtime identity can request secrets from the configured store. Use isolated workload identity and least-privilege secret-manager policies. |
| Leaked database backup | Same encryption and production external-store separation apply; backup encryption and access controls are required. Revocation/rotation should follow any suspected exposure. | A backup containing local encrypted records plus the corresponding application key is recoverable. Production backups must not contain secret payloads. |
| Leaked logs | Controllers, sync jobs, and webhook handlers log safe static reasons and identifiers, not credentials, request bodies, headers, response bodies, or exception messages. | Infrastructure/proxy logging must also redact authorization and webhook-signature headers; this repository cannot enforce hosting-level log retention/access. |
| Stolen provider key | The secret can be rotated/revoked through the store lifecycle; disconnect removes local access and disables connection use. Credentials must have read-only/least-privilege scopes. | POSPilot cannot revoke a key at the provider unless the provider offers a documented revocation API. Revoke it in the provider dashboard as well. |
| Webhook forgery | Moniepoint has provider-specific HMAC verification before normalization; generic/unsigned provider webhooks fail closed. OPay and PalmPay webhook verification remains unimplemented. | Production Moniepoint event handling remains gated until an external secret-store adapter and deployment controls are configured. Provider-specific freshness guarantees must follow verified provider documentation. |
| Webhook replay | Verified Moniepoint event IDs have a unique receipt constraint per provider connection; duplicate deliveries are acknowledged without re-ingestion. | Do not assume other providers use the same event ID or signing algorithm. Add provider-specific replay handling only from verified specifications. |

## Lifecycle and provider isolation

The contract exposes `store`, `retrieve`, `rotate`, and `revoke`. Rotation replaces the old reference/record atomically in the local adapter. Disconnect revokes and nulls the reference before returning success. Secret records are unique per provider connection and include agent/provider identifiers that are checked on retrieval. Connectors must receive credentials for their own provider connection only; provider credentials must never be reused across OPay, Moniepoint, or PalmPay.

The local adapter refuses to operate when `APP_ENV=production`. External adapter configuration must be reviewed to ensure encrypted transport, workload identity, per-agent/per-connection isolation, audit trails without secret values, rotation, revocation, and safe failure behavior. The application verifies the configured adapter implements `ProviderSecretStore`.

## Webhook gate

Expected sequence:

`provider-specific authenticity verification → supported timestamp/replay checks → event idempotency → minimal provider normalizer → TransactionIngestionService`

Moniepoint's current webhook path verifies its documented signature over the specified headers and exact body before creating an event receipt or transaction. Invalid and unsigned requests fail closed. Signature material is never logged. OPay and PalmPay remain disabled until their authentication, signature, replay, and payload contracts are verified. A generic unsigned financial webhook must not be enabled.

## Operational requirements before live sync

1. Select and implement a production KMS/secret-manager adapter; deploy with its least-privilege workload identity.
2. Verify production environment rejects the local database adapter and that secret-store outages fail closed.
3. Configure HTTPS, trusted-proxy/header redaction, encrypted backups, restricted secret-manager audit access, and incident rotation procedures.
4. Obtain provider-issued integration credentials with documented read-only scopes. Never request interactive user login credentials.
5. Complete provider-specific endpoint, payload, response-signature, webhook-signature, replay-window, and revocation verification before enabling any connector.
6. Run the security regression suite against the deployed adapter before allowing production connections.
