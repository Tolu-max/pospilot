# PalmPay access request preparation

No application or contact has been submitted. The public PalmPay developer portal reviewed on 2026-09-28 did not document an API that lets an ordinary POS agent/business authorize POSPilot to read historical transactions from an existing PalmPay POS terminal. Its documented POS notification product describes a bank/PalmPay partner flow, not merchant terminal-history access.

If PalmPay confirms this product exists, POSPilot should request written technical documentation for a least-privilege, read-only integration covering:

- agent/business onboarding and eligibility for an existing POS terminal;
- terminal/account binding and ownership verification;
- historical transaction listing, date limits, pagination, status/reversal handling, and stable transaction references;
- webhook delivery for existing terminal activity, authentication/signature, retries, and replay identifiers;
- fees, settlements, and balance fields with units and provenance;
- sandbox availability and test credentials;
- credential issuance, rotation, revocation, IP allowlisting, rate limits, and support escalation.

POSPilot does not need payment initiation, collections, transfers, payouts, or debit access. Request only documented read/notification access. Until PalmPay confirms the existing-terminal use case and issues test access, `FEATURE_PALMPAY_DIRECT=false`; use Gmail statements or CSV where available.
