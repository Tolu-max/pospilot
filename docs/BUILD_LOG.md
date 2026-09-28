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

## 2026-09-27 — Production UI and integration readiness QA

- Completed a Chrome walkthrough of the fictional POS journey across onboarding, terminals/rules, three-provider transaction CSV import, duplicate settlement handling, reconciliation, charge override, expense capture, daily close preview/finalization, Moniepoint setup, profile/security, password reset, and the unconfigured Google sign-in state.
- Fixed the transaction page's ambiguous provider sort/query, corrected the expense paginator response handling, made expense and closing forms submit the values actually present in their native inputs, added terminal editing and settlement-import controls to the production workflow, and clarified that pending/reversed transaction earnings are excluded.
- Compared the shared June 1 QA arithmetic through API and UI: successful principal volume ₦185,000.00, four successful transactions, customer charges ₦2,500.00, known provider fees ₦620.00, expense ₦800.00, provisional earnings ₦1,080.00; settlement difference −₦50.00; cash/electronic closing variance −₦60.00. No VAT or unknown fee values were invented.
- Added an optional SendByte SMTP mailer while retaining the default local/test mailer. No production credentials or live SendByte delivery were used. Added deployment, privacy, analytics, email-statement, and user-facing product guidance.
- Sabilytics remains disabled because the public installation example exposes a placeholder script URL rather than a verifiable production tracker URL. Automatic inbound statement intake remains disabled pending dedicated inbound-mail infrastructure and verified provider statement formats. Pxxl deployment has not been performed.
- Rotated the local Laravel application key after a diagnostic command exposed it in local tool output; confirmed the local provider-secret table was empty first. Updated the ignored local environment to use the POSPilot name and `APP_DEBUG=false`. Production still requires a separately managed key and external provider secret store.
- Verification: Vite production build passed; PHPUnit passed with 113 tests and 595 assertions; Laravel Pint passed; Composer audit reported no known advisories. Authentication-page responsive checks at 360, 390, 430, 768, and 1024 test widths found no horizontal overflow. These local checks do not constitute staging or production approval.

## 2026-09-28 — Gmail statement connection implementation

- Added a separate server-side Gmail OAuth flow with the restricted `gmail.readonly` scope, session-bound OAuth state, offline access, refresh-token preservation, the production callback route, and a masked mailbox address. Normal Google Sign-In remains separate.
- Moved Gmail tokens into a dedicated encrypted credential record. The database adapter is local/testing only and now fails closed in production; production requires a reviewed external credential-store adapter.
- Added bounded recent-message discovery, metadata-first candidate checks, CSV/XLSX attachment retrieval, safe schema/account hints, mapping profiles, existing transaction-ingestion reuse, duplicate protection, manual sync, and disconnect handling that preserves normalized financial history.
- Updated the automatic-statements UI and Google Cloud setup, API, product, and security documentation. OAuth client values are absent in the local environment; no production environment values were changed. The live Go54 release and owner Gmail mailbox were not accessed for authorization or statement processing.
- Verification: PHPUnit passed with 128 tests and 687 assertions; Vite production build passed; Laravel Pint passed; Composer audit found no advisories; `git diff --check` passed. The deployed app returned its existing sign-in page in Chrome. Production OAuth and real-mailbox end-to-end verification remain blocked until a reviewed external credential store, protected production environment configuration, production deployment, and the owner's action-time Gmail consent are available.
- Google Cloud follow-up: confirmed Gmail API enabled, `gmail.readonly` declared as the only restricted Gmail scope, and the dedicated web client has the exact production callback. Saved the public homepage and privacy-policy URLs in OAuth branding. At the owner's request, changed the OAuth audience to External / In production without scope verification; Google reports 0 of 100 lifetime users consumed. The unverified consent warning applies, and Google Cloud does not enforce a two-user cap. No client secret was viewed, rotated, or configured in Go54; POSPilot's production release and external credential store remain unconfigured.

## Direct provider readiness review — 2026-09-28

- Reviewed current official Moniepoint POS API, OPay API Basics/Authentication/POS/TN/Balance, and PalmPay developer references before changing provider integration code. Evidence and maturity labels are in `docs/providers/DIRECT_PROVIDER_RESEARCH.md`.
- Corrected Moniepoint introspection default to `https://api.pos.beta.moniepoint.com/v1/introspect`; the test response now surfaces only the introspected environment, matched business name, granted scopes, and verification time. `FEATURE_MONIEPOINT_DIRECT` gates its authenticated connection endpoints and remains off by default.
- Disabled Moniepoint webhook processing while the current official callback auth/header and payload contract are not confirmed. Existing verifier/normalizer code is retained but marked unverified.
- Added OPay and PalmPay direct feature-flag configuration, both off by default. No OPay API crypto/client or PalmPay direct connector was falsely marked implemented.
- No physical terminal, provider credential, sandbox, or live provider was available; this work made no external provider API call and made no production data changes. Gmail and the shared financial ingestion pipeline remain intact.
- Updated shared ingestion idempotency so fee differences do not split a fallback fingerprint, fallback keys include business/account/terminal/type context, and later provider statement/API/webhook fee data enriches an existing fee-unknown record with provenance. Added local regression coverage; no new financial engine was introduced.
- Finalized the POSPilot data-source hierarchy: OPay Direct, then Moniepoint Direct for eligible businesses, with Email Statements as the practical fallback; sources may coexist and all normalize through the same ingestion service. Added hashed, PII-minimized transaction source observations and field-specific provider-fee enrichment with source-priority audit history. OPay history sync remains unimplemented, Moniepoint introspection does not sync existing POS history, and PalmPay Direct remains disabled. No new ingestion method was started.

## 2026-09-28 — Go54 production release

- Activated release `20260928-439e154` from GitHub commit `aa6e135`; kept the previous release available at `releases/20260927`. Installed Composer dependencies in the new release so its PSR-4 autoloader resolves the release's own controllers. No local `.env` was uploaded.
- Applied all six pending production migrations. MySQL initially rejected an overlong automatically generated team-terminal index name; shortened the name and made the incomplete table-creation migration retryable. Verified the partial membership and invitation tables contained zero rows before retrying. The retry and remaining migrations completed.
- Rebuilt Laravel config, route, and view caches. Copied the built Vite assets to the domain's separate public `build/` directory after the first Chrome smoke test found static JS/CSS returning 404; retained the previous hashed assets. The current bundle assets now return 200.
- Verified HTTPS homepage and login responses, a real owner Google Sign-In session reaching onboarding, CSRF meta-token presence, and Chrome rendering at 390, 768, and 1440px with no horizontal overflow. Production onboarding remains unfinished, so authenticated Providers/dashboard workflows could not be tested. The current Chrome page showed no POSPilot JavaScript errors; warnings observed were from a browser extension.
- Gmail was not connected or tested: production `FEATURE_GMAIL_STATEMENTS` is off; dedicated Gmail OAuth client ID/secret and a production credential-store class are absent. Normal Google Sign-In was tested separately. SendByte delivery was not tested because the production mailer is not SendByte and the SendByte API key is absent. Queue worker and scheduler were not enabled or verified.
- Verification: 154 PHPUnit tests and 844 assertions passed; Vite build and Pint passed; Composer audit found no advisories; `git diff --check` passed. No provider credentials or real financial data were used.
