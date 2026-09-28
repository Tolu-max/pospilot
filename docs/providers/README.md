# Providers and data sources

## Product order

1. **OPay Direct POS/TN API** — preferred when the business has official API access and POSPilot's documented read connector is implemented and enabled.
2. **Moniepoint Direct POS API** — next preferred for eligible business/API accounts. Current POSPilot introspection verifies credentials and business scope; it does not sync existing terminal history.
3. **Email Statements** — practical fallback for ordinary agents without developer/API access. Providers may require the agent to request or send a statement from their app first.

Direct and email sources can coexist. Email records may fill reconciliation details that direct data did not provide. All sources use `NormalizedTransactionData` and `TransactionIngestionService`, followed by the same earnings, reconciliation, and daily-closing services.

## Release states

| Source | Code state | Provider / Google access | Sandbox tested | Live tested |
|---|---|---|---|---|
| OPay Direct | TN read endpoint is documented; POSPilot API client and historical sync are planned, not implemented | Eligible OPay Business credentials, merchant identifiers, key material and deployment IP allowlisting required | NO | NO |
| Moniepoint Direct | Credential storage and introspection test implemented; existing-terminal history unavailable in reviewed docs; webhook ingestion unverified and disabled | Eligible API key/scopes; production secret-manager adapter required | NO | NO |
| Gmail statements | OAuth and CSV/XLSX statement processing code implemented; validate each real provider schema | Dedicated Google OAuth and an emailed provider statement | No provider API sandbox applies | Gmail/account test status is reported separately |
| PalmPay Direct | Disabled; ordinary-agent existing-terminal history API not verified | Provider confirmation required | NO | NO |

Feature flags describe whether an existing POSPilot feature path is exposed, not whether access is approved or tested. Keep PalmPay Direct disabled with `FEATURE_PALMPAY_DIRECT=false`. Do not expose OPay or Moniepoint operations that are not implemented or externally verified.

The generic statement mapper does not certify provider email delivery, schema, fees, statuses, or terminal identifiers. Uncertain account/terminal matches go to setup instead of being guessed. CSV import remains an internal QA/recovery capability; ordinary users should connect direct sources or Gmail, not upload a daily file.

For current evidence see [direct-provider research](DIRECT_PROVIDER_RESEARCH.md), [OPay](OPAY.md), [Moniepoint](MONIEPOINT.md), [PalmPay](PALMPAY.md), and [Gmail statement integration](../GMAIL_STATEMENT_INTEGRATION.md).
