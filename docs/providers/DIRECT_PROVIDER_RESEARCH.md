# Direct POS provider research

Research date: 2026-09-28. Sources below are official provider documentation only. No production credentials or physical terminals were available; no external provider API was called, and no provider was sandbox- or live-tested by POSPilot in this review.

Product priority is OPay Direct first, Moniepoint Direct second for eligible accounts, then Email Statements as the practical fallback for ordinary agents. Direct and email sources may coexist. The rank is a product preference and does not imply the documented direct operation is implemented or tested.

The implementation and verification labels are distinct:

- **DOCUMENTED** — current official provider documentation describes it.
- **IMPLEMENTED** — POSPilot has production-intent code for that exact capability.
- **AUTOMATED-TESTED** — local tests cover the code with sanitized fixtures/fakes.
- **SANDBOX-TESTED** — exercised against the provider sandbox.
- **LIVE-TESTED** — exercised successfully against a real provider account/device.
- **UNVERIFIED** — official evidence or access needed to substantiate the behavior is missing.

## Moniepoint

| Item | Evidence/status |
|---|---|
| Official docs | [Moniepoint POS API reference](https://docs.pos.beta.moniepoint.com/) |
| Authentication | **DOCUMENTED:** API key in `Authorization: Bearer ...`; `GET /v1/introspect` returns scopes, authorized businesses, auth method and environment. The reference server is `https://api.pos.beta.moniepoint.com`. |
| Sandbox | **DOCUMENTED:** introspection schema includes `SANDBOX`; **UNVERIFIED:** no sandbox credential/terminal available here. |
| Credential requirements | **DOCUMENTED:** business-scoped API key; applicable endpoint scopes are required. **IMPLEMENTED:** encrypted ProviderSecretStore boundary for the current Moniepoint connection. Production store adapter is not configured/verified. |
| Webhooks | **DOCUMENTED:** subscription create/read/update/delete and secret/auth management; supported auth choices include `BASIC` and `NONE`; `V1_POS_TRANSACTION` is a documented event name. **UNVERIFIED:** current application callback signature/header and complete event schema are not confirmed by the current reference. Existing POSPilot webhook code is not represented as provider-verified. |
| Transaction history | **UNVERIFIED / NOT PUBLICLY DOCUMENTED:** current reference describes pushing transactions and querying an outcome by merchant reference, not listing existing terminal transactions. These endpoints must not be used to initiate or simulate customer payments. |
| Terminal identifier | **UNVERIFIED** for the current transaction-event schema in the reviewed reference; provider-issued mapping information needed. |
| Fees | **UNVERIFIED** for the applicable existing-POS event/history data. Unknown stays unknown. |
| Settlement / balance | **UNVERIFIED:** no applicable existing-POS settlement or balance read contract verified. |
| Onboarding | Provider business access, API key, granted scopes, and webhook permissions/subscription where applicable. Ordinary-agent eligibility needs confirmation. |
| Implemented | Introspection connection test exists, currently calls the corrected official beta reference host. Webhook normalization and verification code exists but is **UNVERIFIED** against the current official contract; it must not be enabled as verified functionality. No historical transaction-list connector is implemented. |
| Automated-tested | Existing local feature tests use sanitized/fake HTTP responses and local webhook samples. They do not establish official-contract validity. |
| Sandbox-tested | **NO** |
| Live-tested | **NO** — no credentials or terminal. |

## OPay

| Item | Evidence/status |
|---|---|
| Official docs | [OPay API Basics](https://documentation.opayweb.com/doc/offline/api-basics.html), [Authentication](https://documentation.opayweb.com/doc/offline/authentication.html), [POS Integration](https://documentation.opayweb.com/doc/offline/pos-api.html), [TN Integration API](https://documentation.opayweb.com/doc/offline/tn-api.html), [Query Balance](https://documentation.opayweb.com/doc/offline/query-account-balance.html) |
| Authentication | **DOCUMENTED:** encrypted JSON request envelope, RSA signing/verification, merchant `clientAuthKey`, version/body-format/timestamp fields; timestamps valid for five minutes. Exact algorithm/key format/message construction is specified in OPay's Authentication page and must be implemented from that contract, not approximated. |
| Sandbox | **UNVERIFIED:** no sandbox for these TN history endpoints or usable credentials verified in the reviewed pages. |
| Credential requirements | OPay Business ID, branch ID, business-issued `clientAuthKey`, POSPilot/merchant RSA key material, POS serial and OPay TN. Business Dashboard Developer Tools setup is documented for POS webhooks. |
| Webhooks | **DOCUMENTED:** POS webhook sends payment status notification for POS/API payment orders; configuration requires Business Dashboard Super Admin. Payload includes fee and sender/customer fields. **UNVERIFIED / NOT APPLICABLE to existing-terminal activity:** this is not a general agent-activity feed, and POSPilot must not create payment orders. TN-history endpoint is the documented read path. |
| Transaction history | **DOCUMENTED:** TN API queries successful inbound transactions by business, branch, TN, serial and date range; 1–100 page size; inclusive range no more than seven calendar days. **IMPLEMENTED:** no POSPilot client/sync/normalizer yet. |
| Terminal identifier | **DOCUMENTED:** POS serial number (`sn`) and bound TN; `queryBySn` returns the TN association. |
| Fees | **UNVERIFIED / absent from documented TN history record fields reviewed.** POS API order notification fee applies to that payment-order product and is not substituted for terminal-history fee evidence. |
| Settlement | **UNVERIFIED** for TN history endpoints. |
| Balance | **DOCUMENTED:** merchant/branch account balance query returns amount in Kobo and account identifier. **IMPLEMENTED:** no POSPilot integration; it is an account balance, not proof of cash settlement or a terminal-only balance. |
| Onboarding / network | Business credentials and merchant identifiers required. TN docs include an IP-not-whitelisted error. **UNVERIFIED:** the Go54 service panel and DirectAdmin were inspected read-only; neither exposed a stable outbound-IP value. GO54 publicly describes shared hosting, but the reviewed material does not promise a fixed outbound IP. The application egress IP and OPay allowlisting remain unconfirmed; live access cannot be claimed. |
| Implemented | OPay credential DTO, signer/verifier contracts, and disabled connector boundary exist; they do not perform documented crypto or provider requests. Historical sync and TN lookup are not implemented. |
| Automated-tested | **UNVERIFIED** for OPay protocol components; no production-intent crypto/client implementation to contract-test. |
| Sandbox-tested | **NO** |
| Live-tested | **NO** — no credentials or terminal. |

## PalmPay

| Item | Evidence/status |
|---|---|
| Official docs | [PalmPay developer documentation](https://docs.palmpay.com/), especially [POS notification product introduction](https://docs.palmpay.com/#/en-us/value-added-services/pos/notification/instruction). |
| Authentication | General partner API access/onboarding and public/private key setup are documented in the portal; no ordinary-agent POS history authorization contract was found. |
| Sandbox | **UNVERIFIED** for existing PalmPay POS history. The portal describes test tools for other open-platform products, which does not establish POS history sandbox access. |
| Credential requirements | Product application/merchant onboarding and provider-issued keys are described generally; eligibility for an ordinary POS agent is **UNVERIFIED**. |
| Webhooks | A POS notification product is documented for bank/PalmPay partner transaction notification (the docs describe partner integration and PalmPay receiving NIP notification). It is not evidence of a webhook stream for a POS agent's existing terminal activity. |
| Transaction history | **NO public documented ordinary-agent history API found** in the reviewed official docs. |
| Terminal identifiers | **UNVERIFIED** for a merchant-authorized history interface. |
| Fees / settlement / balance | **UNVERIFIED** for existing POS history; no inference from other PalmPay payment or payout products. |
| Implemented | No direct connector. Existing CSV/Gmail statement paths remain separate. |
| Automated-tested | CSV imports are covered separately; no direct PalmPay API contract. |
| Sandbox-tested | **NO** |
| Live-tested | **NO** — no credentials or terminal. |

## Release boundary

- The existing financial engine remains authoritative. Any future direct adapter must normalize through `NormalizedTransactionData` and `TransactionIngestionService`.
- No POSPilot payment initiation, transfer, payout, withdrawal, or collection capability is part of this readiness work.
- OPay and Moniepoint direct capability flags are not proof of API completeness or live verification. PalmPay direct stays disabled.
- Gmail statement ingestion and CSV imports remain separate ingestion channels and are not altered by this research.
