# Provider Capability Matrix

Capability values are centralized in `config/pospilot.php` and exposed by `GET /api/providers`.

| Capability | OPay | Moniepoint | PalmPay | Generic / Other |
|---|---|---|---|---|
| CSV transaction import | supported | supported | supported | supported |
| CSV settlement import | supported | supported | supported | supported |
| API transaction sync | planned | planned | planned | unavailable |
| Webhook transactions | planned | supported* | planned | unavailable |
| Settlement sync | planned | planned | planned | unavailable |
| Balance sync | planned | planned | planned | unavailable |

`supported` means the current application has a working implementation. `planned` means the internal boundary exists but no production provider integration is enabled. `unavailable` means the capability is not offered for that provider type. `unknown` is the safe fallback for an unconfigured provider slug.

## OPay foundation

The disabled OPay Business connector boundary accommodates:

- `headMerchantId`
- branch `merchantId`
- POS serial number
- client authentication key
- RSA private/public key material
- request signing and response verification services

The connector foundation does not contain credentials or make live API calls. Provider secrets are accessed only through `ProviderSecretStore`; production requires a separately configured external secret-manager/KMS adapter. OPay API and webhook work remains disabled until verified documentation, credentials, endpoint requirements, encryption, and signature rules are supplied.

\* Moniepoint webhooks are implemented against the public POS Apps Developer webhook guide, including the documented HMAC signature and unique event ID. The documented payload does not include provider fee; such transactions carry `provider_fee=0.00` and `metadata.provider_fee_supplied=false`. Treat earnings as provisional until a fee-bearing CSV or another verified source is available.

## Moniepoint

Moniepoint supports CSV transaction and settlement imports. A provider-specific signature-verified webhook implementation exists, but its capability remains `planned` until production secret-manager storage and deployment controls are configured. Connection testing uses the documented development key-introspection endpoint only. Transaction history/backfill, settlement sync, balance sync, and production API endpoint configuration remain planned. See [docs/providers/MONIEPOINT.md](providers/MONIEPOINT.md) for the exact scope and known gaps.

## PalmPay

PalmPay remains CSV/import based. API URLs, authentication, webhook signatures, OAuth flows, and payload fields have intentionally not been invented.
