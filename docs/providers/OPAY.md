# OPay direct connector

## Documented capabilities

The official [Authentication](https://documentation.opayweb.com/doc/offline/authentication.html) and [API Basics](https://documentation.opayweb.com/doc/offline/api-basics.html) pages describe RSA-based request/response encryption and signing, a merchant-issued `clientAuthKey`, version/body-format/timestamp parameters, and a five-minute request timestamp validity period.

The official [TN Integration API](https://documentation.opayweb.com/doc/offline/tn-api.html) provides read endpoints to query a TN bound to a POS serial and query successful inbound transactions by business, branch, TN, serial, and time window. The inclusive window may not exceed seven calendar days; pages accept 1–100 records. The TN transaction response documents Naira amount, merchant/branch, POS serial, TN, references, status, timestamps, and RRN. The reviewed response fields do not document provider fee or settlement data. It reports an IP-not-whitelisted error; Go54 egress stability/allowlisting is not confirmed.

The [Query Balance API](https://documentation.opayweb.com/doc/offline/query-account-balance.html) describes merchant/branch account balance, with `usableAmount` in Kobo. This is not a terminal-only balance or settlement confirmation.

The [POS Integration API](https://documentation.opayweb.com/doc/offline/pos-api.html) creates payment orders and documents their payment notifications. It includes fee and sender data, but applies to an order created through that integration. POSPilot must not create those payment orders or treat this webhook as a general feed for transactions from an existing POS terminal.

## POSPilot status

Product priority: first preferred direct source when an eligible OPay Business account provides access. Email Statements remain available alongside it. This priority does not mean the connector is currently implemented or live-tested.

| Stage | Status |
|---|---|
| Documented | RSA envelope, TN lookup, bounded historical successful-transaction query, merchant/branch balance query, and API-order payment notifications are documented. |
| Implemented | Credential DTO and signer/verifier contracts plus a disabled connector boundary exist. No production cryptographic envelope, TN lookup, history sync, or balance client is implemented. `FEATURE_OPAY_DIRECT` must remain off until those operations are implemented and verified. |
| Automated-tested | No end-to-end OPay protocol contract implementation is available to test. |
| Sandbox-tested | No. No applicable sandbox or credentials verified. |
| Live-tested | No. No OPay API credentials or physical terminal. |

## Required before an external test

- Eligible OPay Business onboarding and provider-issued `clientAuthKey`.
- Merchant private/public key material and OPay public key in the documented formats.
- Business ID, branch ID, POS serial, and provider-confirmed bound TN.
- Provider confirmation of access to TN query APIs and date-range behavior.
- Confirmed stable outbound IP and OPay allowlisting for the deployed Go54 application.
- Sanitized official request/response contract coverage before enabling sync.

OPay direct remains disabled. The eventual read path must use documented TN querying, honor seven-day windows/pagination/rate limits, discard sender/customer identity fields, normalize to `NormalizedTransactionData`, and ingest through `TransactionIngestionService`. No payment initiation, payout, transfer, collection, or debit is permitted. Missing historical provider fee remains unknown.
