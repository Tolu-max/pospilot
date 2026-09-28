# Moniepoint direct connector

## Documented capabilities

The current official [Moniepoint POS API reference](https://docs.pos.beta.moniepoint.com/) documents API-key bearer authentication and `GET /v1/introspect` on `https://api.pos.beta.moniepoint.com`. The response includes granted scopes, businesses, `authMethod`, and environment (including `SANDBOX`). The connection test must match the user-selected business against this response; a valid token alone does not establish business ownership.

The reference documents webhook subscription lifecycle and auth/secret configuration, including `BASIC` or `NONE` subscription authentication and events such as `V1_POS_TRANSACTION`. It also documents transaction push and merchant-reference transaction outcome lookup. Those transaction operations are for initiating/tracking provider payments and are outside POSPilot's product.

No general API to list historical transactions already processed by an agent's POS terminal was found in the current reference. No applicable settlement/balance API or verified fee-bearing historical/event schema was established during this research.

## POSPilot status

Product priority: second preferred direct source for eligible Moniepoint Business/API accounts. Email Statements remain available alongside it. This priority does not establish that current POSPilot transaction sync is implemented or externally tested.

| Stage | Status |
|---|---|
| Documented | Introspection, sandbox environment representation, webhook subscription controls, and transaction push/status are documented. |
| Implemented | Credential storage and an introspection test exist. Introspection now targets the current official beta reference host. Existing webhook code is retained but its callback authentication and exact payload contract are unverified against the current docs. |
| Automated-tested | Local tests cover credential secrecy, ownership, fake introspection responses, ingestion/idempotency, and rejection paths. Those tests do not validate vendor behavior. |
| Sandbox-tested | No. No sandbox credential or physical terminal is available. |
| Live-tested | No. No production API access or terminal is available. |

Direct API configuration stays off by default. Production use also requires a production-safe `ProviderSecretStore` adapter. Do not enable webhook ingestion as provider-verified until the exact delivery authentication, header, event payload, amount units, event ID, terminal/business identifiers, fee semantics, retry behavior, and replay handling have been checked against official docs/provider-issued examples. The current HMAC verifier is legacy application code, not evidence that the current Moniepoint API uses that scheme.

## Required before an external test

- Provider-issued sandbox key, test business, and eligible POS terminal.
- Confirmed scope grant and current sandbox/prod base URLs with Moniepoint.
- Written confirmation of ordinary POS agent access and webhook delivery authentication.
- Official transaction event examples/schema, including transaction references, status/reversal meanings, amount units, terminal mapping, and any fee field.
- A production secret-manager adapter before production credential storage.

Historical existing-POS sync is not implemented and remains undocumented in the reviewed current API. POSPilot will not call transaction-push endpoints. Missing fees remain unknown/provisional; they are not turned into confirmed zero fees.
