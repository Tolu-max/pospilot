# POSPilot security and data practices

## Data handled

POSPilot stores account identity and session/security events, business profile and terminal identifiers, transaction principal/status/time/provider/reference, customer charge and its provenance, provider-reported financial components, settlements, expenses, closing snapshots, and import/reconciliation metadata needed for the product. It does not need customer names, phone numbers, full account numbers, card data, or unrelated provider payload fields; provider normalizers should discard these.

## Provider access and credentials

Provider credentials are write-only from the browser and accessed through the provider-secret-store contract. Local encrypted database storage is for development/test only. Production requires an independently reviewed external secret manager/KMS adapter. Never give POSPilot an ordinary provider login password, PIN, transaction PIN, or OTP.

OPay is the first preferred direct read source and Moniepoint the second for eligible businesses. Email Statements are the practical fallback and can coexist with direct data for enrichment. OPay's documented TN history connector is not implemented. Moniepoint credential introspection exists, but existing-terminal history is not documented in the reviewed reference and webhook ingestion remains disabled until delivery security and payload behavior are verified. PalmPay Direct remains disabled. A documented provider capability or enabled feature flag is not evidence of live connectivity. POSPilot does not initiate payments, transfers, withdrawals, payouts, or debits.

Disconnecting disables POSPilot-side credential use and preserves imported financial history. It does not necessarily revoke a credential at the provider; revoke it in the provider dashboard too if needed.

## Email and analytics

Account verification and password reset use Laravel's mail abstraction. SendByte is configured as an optional SMTP mailer but has not been tested with a real sandbox key. Messages must not include transaction histories, balances, customer data, or provider secrets. See [SendByte setup](../SENDBYTE.md).

Sabilytics loads on the production hostname using the account-specific snippet. Custom events use a strict allowlist and fixed metadata; POSPilot does not pass Gmail contents or financial values. The tracker records page paths and query strings, so sensitive values must never be placed in URLs. See [analytics integration](../SABILYTICS.md).

Gmail statement discovery uses a separate OAuth client and the restricted `https://www.googleapis.com/auth/gmail.readonly` scope. Google grants broader technical mailbox access than POSPilot's provider/statement searches restrict. POSPilot does not store message bodies or unrelated emails, never sends Gmail data to analytics, and encrypts mailbox tokens in a separate credential record. The OAuth app is External / In production but unverified; Google displays an unverified-app warning and currently caps unverified restricted-scope access at 100 lifetime users. POSPilot does not enforce a two-person access limit. Google verification and any applicable security assessment are incomplete. See [Gmail integration](../GMAIL_STATEMENT_INTEGRATION.md) and [Google Cloud setup](../GMAIL_GOOGLE_CLOUD_SETUP.md).

## Retention, deletion, and production readiness

Uploaded CSV files are temporary; normalized records and import summaries are retained according to the application database lifecycle. Disconnecting a provider preserves history. For account deletion, contact the POSPilot operator until a production support contact and a tested deletion/export process are published. Do not load real customer/provider records into the current local/demo environment.

Production launch is blocked until a production domain/support contact, external secret store, verified Moniepoint permissions/capabilities, and tested deployment/backup/session/mail operations are in place. See [deployment checklist](../DEPLOYMENT_PXXL.md) and [secret threat model](../PROVIDER_SECRET_SECURITY.md).

## Direct provider connector boundary

Moniepoint direct endpoints are disabled unless `FEATURE_MONIEPOINT_DIRECT` is explicitly enabled. Its webhook event route returns unavailable while `moniepoint_webhooks_verified` is false; the legacy HMAC verifier is not a verified interpretation of the current Moniepoint reference's `BASIC`/`NONE` subscription authentication. OPay and PalmPay flags default off. No provider API keys, RSA private keys, merchant identifiers, or customer payloads should be sent to analytics. Read [direct-provider research](../providers/DIRECT_PROVIDER_RESEARCH.md) for evidence and outstanding checks. No live provider calls or tests were performed as part of this change.
