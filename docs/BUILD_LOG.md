# Build log

## 2026-09-22

- Initialized an empty repository as a Laravel 13 application.
- Added Breeze React/Inertia authentication and a Tailwind/Vite frontend shell.
- Added the core agent, provider, terminal, transaction, charge rule, expense and settlement schema.
- Added enums, relationships, decimal-safe financial services and a provider connector contract.
- Added fictional demo data for one agent across OPay, Moniepoint and PalmPay, including a settlement discrepancy.
- Added automated financial logic tests.

## 2026-09-22 — Milestone 2

- Added CSV import batches, temporary preview/confirmation flow and provider-specific normalization adapters.
- Added Generic/Other column mapping, validation reporting and fictional sample CSV statements.
- Added transaction fingerprints, database uniqueness protection and import history.
- Added transaction filters/details, auditable customer charge overrides and reconciliation categories.
- Added authorization coverage and CSV/import/reconciliation tests.
- Refactored CSV persistence through `NormalizedTransactionData` and `TransactionIngestionService` so future API and webhook sources share the same ingestion path.
- Added `ProviderConnection`, connector/webhook contracts and an idempotent provider sync foundation without external API calls.
- Added cross-source duplicate, metadata and sync idempotency tests.

## 2026-09-23 — Milestone 3

- Added normalized settlement DTOs and provider-specific OPay, Moniepoint, PalmPay and Generic settlement CSV adapters.
- Added idempotent settlement ingestion with import-batch/source tracking, terminal resolution and database fingerprint protection.
- Expanded reconciliation outcomes, discrepancy calculations and structured issue reporting, including missing, pending and reversed transaction signals.
- Expanded decimal-safe earnings reporting with date ranges, provider/terminal breakdowns, expense deductions and an authoritative agent financial summary service.
- Added authenticated JSON endpoints for financial summaries, provider breakdowns, reconciliation overview/issues and settlement list/details, plus settlement CSV preview/confirmation actions.
- Added focused settlement normalization, duplicate-ingestion, reconciliation and earnings tests; the full suite passes with 47 tests and 123 assertions.

## 2026-09-24 — Milestone 4

- Added authenticated Agent Operations API endpoints for profile/onboarding, providers, provider connections, terminals, charge rules, expenses, transactions, imports and transaction charge overrides.
- Added onboarding state tracking and audit fields for manual customer-charge overrides.
- Added ownership-scoped terminal/provider connection/expense/import/transaction operations and bounded API pagination/filtering.
- Added deterministic charge-rule boundary validation: same-provider active ranges may overlap only when priorities differ; higher-priority rules win, with provider-specific rules preferred.
- Secured settlement confirmation with server-side preview tokens and exposed CSV import timestamps through provider connection status.
- Added feature coverage for onboarding, ownership isolation, charge-rule boundaries/overlaps, transaction filtering, charge overrides and expense effects.

## 2026-09-24 — Milestone 5

- Completed a focused backend security hardening pass across authentication throttling, ownership checks, mass-assignment boundaries, CSV handling, error responses, encrypted provider credentials, and fail-closed webhooks.
- Added row limits, UTF-8 validation, spreadsheet formula rejection, and safe generic CSV processing errors.
- Added the separate DailyClosing and ProviderBalanceSnapshot domain with explicit transaction-type effects and centralized variance thresholds.
- Added server-authoritative daily closing preview, draft, provider balance, finalize, history, details, and discrepancy breakdown endpoints.
- Added daily closing and security tests covering balance outcomes, provider shortages/surpluses, cash variance, pending/reversed transactions, encryption, webhook rejection, ownership, mass assignment, duplicate finalization, and oversized imports.
- Full Milestone 5 verification passed with 61 tests and 172 assertions; migrations, seeders, Pint, and dependency audit completed successfully.

## 2026-09-24 — Milestone 6

- Added an end-to-end authenticated three-provider workflow test covering profile setup, terminals, charge rules, transaction imports, charge fallback, settlements, reconciliation, expenses, financial summary, provider balances, variance, and daily closing finalization.
- Added centralized provider capability metadata and exposed it through the provider API without claiming unimplemented API/webhook support.
- Added a disabled OPay Business connector foundation for merchant hierarchy, POS serial, encrypted credential material, signing, and response verification boundaries.
- Kept Moniepoint and PalmPay API/webhook behavior unimplemented pending verified documentation and credentials.
- Added `docs/API_HANDOFF.md` and `docs/PROVIDER_CAPABILITIES.md`; expanded the security review with integration readiness notes.
- Completed the financial consistency audit by applying the same successful-transaction contribution rule to transaction responses and financial summaries; pending, failed, and reversed transactions contribute zero earnings.
- Final Milestone 6 verification passed with 62 tests and 219 assertions; migrations, seeders, Pint, and Composer security audit completed successfully.

## 2026-09-24 — Milestone 7

- Added an encrypted Moniepoint API credential and webhook-secret connection path with safe status, update, documented development key-introspection test, and POSPilot-side disconnect endpoints.
- Added a dedicated Moniepoint webhook endpoint using the published HMAC-SHA256 signing format, provider business matching, webhook event receipt uniqueness, terminal serial resolution, and the shared normalized transaction ingestion service.
- Added a Moniepoint payload normalizer that converts documented kobo amounts to NGN and discards personal/raw payload fields. The source schema does not include provider fees; such transactions are flagged as fee-unavailable and their earnings remain provisional.
- Added the Moniepoint transaction history connector contract and sync job boundary. It records `blocked_documentation` without making network calls until a general transaction-history endpoint is documented.
- Added `docs/providers/MONIEPOINT.md`, updated the capability matrix, API handoff, and security review with implementation scope, verified documentation, and blockers.
- Final Milestone 7 verification passed with 70 tests and 288 assertions; `migrate:fresh --seed`, Laravel Pint, and Composer audit completed successfully.

## 2026-09-24 — Provider secret security gate

- Moved provider credentials out of `provider_connections` into a secret-store boundary; the local implementation encrypts values and is blocked in production, while an external KMS/secret-manager adapter is required for production.
- Added connection-scoped secret create, retrieve, rotate, and revoke operations, removed direct domain-service column reads, and made missing external storage fail closed.
- Hardened credential update, disconnect, webhook, and sync failure paths to avoid returning or logging secret values and raw provider exception messages.
- Added secret-at-rest, serialization, lifecycle, owner/provider isolation, safe response, fail-closed webhook, and duplicate-event regression coverage.
- Added `docs/PROVIDER_SECRET_SECURITY.md` and updated provider capability/security documentation. Moniepoint webhook capability remains planned pending production secret-store deployment; no production live sync was enabled.
- Full regression suite passed with 76 tests and 307 assertions. Changed PHP files passed Laravel Pint, and `composer audit` reported no known advisories. The `--dirty` Pint mode was unavailable because this workspace has no Git metadata; Pint was run directly on the changed PHP files instead.

## 2026-09-24 — Transaction adjustment components

- Added the backward-compatible `TransactionAdjustment` model, typed component/source/direction enums, migration, factory, and normalized DTO support for provider fee, VAT/tax, levy, settlement fee, commission, customer charge, adjustment, and other components.
- Kept `transactions.provider_fee` unchanged. A complete-breakdown marker makes earnings, expected settlements, and daily closing use the component debits instead of adding the legacy value a second time; partial provider fee rows do not change existing totals.
- Centralized customer-charge, deduction, and credit resolution and wired it into earnings, provider/terminal summaries, transaction contribution, settlement expectations, and daily closing. Manual charge overrides continue to take precedence.
- Added validation/provenance rules: provider components can only enter through CSV/API/webhook normalization; calculated rows require a rule identifier; component amounts are cent-precision decimal values. Existing importers continue using only fields they already receive.
- Documented the compatibility/completeness rules in `docs/TRANSACTION_ADJUSTMENTS.md` and updated the API handoff. No frontend changes or speculative provider components were added.
- Full suite passed with 86 tests and 333 assertions. All changed PHP files passed Laravel Pint.

## 2026-09-25 — Milestone 8: Financial accuracy and staging handoff

- Added a backward-compatible `provider_fee_supplied` field. Existing rows retain the legacy supplied-value assumption, while normalized CSV/API/webhook ingestion records whether a fee was actually provided; Moniepoint's fee-unavailable webhook remains explicitly missing.
- Added computed transaction and aggregate financial confidence. Successful transactions without a fee receive `provider_fee_missing`; scalar-only fees without a verified complete adjustment breakdown receive `provider_fee_components_incomplete`. Existing decimal earnings calculations and complete-component replacement of `provider_fee` remain unchanged.
- Earnings, provider/terminal breakdowns, dashboard summary, transaction APIs, and transaction Inertia responses now expose final/provisional state, reasons, and calculated/manual charge provenance. Provider fees, taxes, levies, VAT, and other components are never inferred; component provenance remains explicit.
- Froze the additive v1 frontend contract in `docs/API_HANDOFF.md`, including confidence response shapes and safe Postman/Bruno staging environment guidance. Provider integration credentials are explicitly excluded from those environments and browser responses.
- Added regressions for CSV fee absence, incomplete/provisional earnings, confidence counts, complete-component non-duplication, Moniepoint webhook/API status, and production refusal when an external provider secret store is missing.
- Full suite passed: 89 tests, 361 assertions. Laravel Pint passed on all changed PHP files (the Git-only `--dirty` mode is unavailable because this workspace has no Git metadata). `composer audit` reported no known advisories.
