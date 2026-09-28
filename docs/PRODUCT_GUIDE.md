# POSPilot quick guide

POSPilot helps a POS agent compare transactions, charges, provider deductions, settlements, expenses, and end-of-day balances. It is an operations aid, not a bank balance, accounting ledger, or guarantee that a provider has settled funds.

## Getting started and account security

Create an account, verify the email address, then enter a business name, terminals, and customer charge rules. Use a unique password, keep your device signed out when shared, and review active sessions. POSPilot never needs your provider dashboard password, PIN, transaction PIN, or OTP.

## Understanding earnings

Estimated earnings are based on successful eligible customer charges, less provider fees known to POSPilot and recorded operating expenses. Transaction principal is not earnings. Pending, failed, and reversed transactions are not treated as finalized earnings. If fee data is missing or incomplete, the displayed amount is provisional; it is not a confirmed net amount. POSPilot only shows tax or other deductions when a source supplies them or an explicitly documented rule produces them.

## Transactions, fees, and reconciliation

Transaction details separate principal, customer charge, provider fee, other supplied financial components, and estimated contribution. Reconciliation compares expected and actual provider settlement records. A difference means the records need review; it does not say why the difference occurred and is never an accusation.

## Daily closing

Daily closing is a separate business-day check. Enter the actual cash and provider balances requested by the screen. POSPilot compares these with expected positions and explains variances. Review pending transactions, reversals, expenses, and missing balances before finalizing.

## Providers and statements

POSPilot brings your POS transactions, charges, fees, expenses, and reconciliation into one place. OPay Direct is the first preferred source, followed by Moniepoint Direct for eligible business/API accounts. Email Statements are the practical fallback for ordinary agents without provider developer access. These sources can be used together and all feed the same transaction and financial pipeline.

Direct API access is optional and is not needed to use POSPilot. OPay's documented TN history API is not implemented in this build. Moniepoint connection introspection is implemented, but it does not sync existing POS transaction history; its webhook ingestion is disabled pending contract verification. PalmPay direct remains unavailable pending official confirmation of ordinary-agent transaction-history access.

For Email Statements, first request or send a statement from the provider app so it reaches Gmail. Then connect Gmail separately from Google sign-in, select OPay, PalmPay, and/or Moniepoint, and choose **Check for statements**. After an email arrives, POSPilot searches for a matching statement, retrieves the supported CSV/XLSX attachment, asks you to map an unknown format/account, and imports through the existing transaction pipeline. PDF and legacy XLS are not automatically imported. Gmail uses Google's restricted read-only scope. The Google OAuth app is In production but unverified; users may see Google's unverified-app warning, and Google's 100-user lifetime cap applies. Never share mailbox/provider passwords, PINs, or OTPs with POSPilot.

## Privacy and help

POSPilot needs account/profile details and the financial transaction, fee, settlement, expense, and closing fields used by its features. Provider payload fields not needed for financial operations should be discarded. Disconnecting a provider must not delete already imported history. Contact the POSPilot operator for data deletion requests until a documented self-service deletion flow and support contact are available.

POSPilot is still a staging/demo candidate, not yet approved for production financial records. See [security details](security/README.md), [API contract](API_HANDOFF.md), and [authentication contract](AUTHENTICATION.md).

## Direct POS connections

Eligible businesses may configure an official provider connection when POSPilot supports the required read operations. Provider credentials, physical-terminal access, sandbox testing, and live testing are distinct readiness steps. No provider that has not been externally verified should be presented as live-connected. POSPilot does not initiate payments or move funds. Review [provider capability status](PROVIDER_CAPABILITIES.md) before representing any direct provider as connected.
