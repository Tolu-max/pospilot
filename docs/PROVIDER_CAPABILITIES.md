# Data source hierarchy and capability matrix

The product source order is OPay Direct, Moniepoint Direct, then Email Statements as the practical fallback. Eligible businesses may use direct and email sources together. Every source must normalize through `NormalizedTransactionData` and `TransactionIngestionService`; the existing financial engine remains authoritative.

`config/pospilot.php` is the source of truth for direct-provider capability statuses. `GET /api/providers` returns those statuses. A documented API is not an implemented connector, provider approval, sandbox test, or live test.

| Source | Product priority | Current application state | Provider / Google access | External test |
|---|---|---|---|---|
| OPay Direct | Preferred, first | TN history API is documented; POSPilot API client and historical sync are planned, not implemented. | Eligible OPay Business credentials, identifiers, key material, and allowlisted stable outbound IP are required. | Sandbox: NO. Live: NO. |
| Moniepoint Direct | Preferred, second | Encrypted credential storage and API introspection test exist. Existing-terminal history is not publicly documented in the reviewed reference; webhook ingestion stays disabled pending verified delivery contract. | Eligible business API key and scopes; production secret manager required. | Sandbox: NO. Live: NO. |
| Email Statements | Fallback; can also enrich direct records | Gmail discovery and CSV/XLSX statement processing use the shared ingestion pipeline. | Separate Google OAuth for Gmail read access; deployment must enable/configure the feature. | No provider statement schema is certified by listing a provider. |
| PalmPay Direct | Not available | No direct connector. Use Email Statements if a compatible statement is available. | Official ordinary-agent terminal-history access not established in reviewed docs. | Sandbox: NO. Live: NO. |

Feature flags control whether existing UI or code paths are exposed; they do not prove connector completeness or vendor access. OPay and Moniepoint direct feature flags remain disabled until their incomplete or unverified operations are ready. PalmPay direct remains disabled. See [official-source research](providers/DIRECT_PROVIDER_RESEARCH.md) and [provider connection statuses](providers/README.md).

The documented OPay TN history API is not implemented. It requires business/branch IDs, POS serial and bound TN; the inclusive history window is at most seven calendar days and provider IP authorization applies. The OPay POS payment-notification webhook concerns payment orders created through that integration and is not a general existing-terminal activity feed.

Moniepoint's current beta POS reference documents API-key introspection and webhook subscription management but does not establish a general existing-terminal history listing API or a verified callback contract for this application. Its existing webhook ingestion remains disabled.

PalmPay's public POS notification product is a bank/provider-partner flow. No ordinary POS agent existing-terminal history API was verified in the reviewed public documentation.
