# Transaction Adjustment Components

## Compatibility model

`transactions.provider_fee` remains in place and is never rewritten by the component ledger. Existing transactions and sources that only provide this field keep their current earnings behavior. A new `transaction_adjustments` row records one named component with a positive decimal amount, explicit direction (`debit`/`credit`), and provenance (`provider`, `manual`, or `calculated`). Supported types are provider fee, VAT/tax, levy, settlement fee, commission, customer charge, adjustment, and other. `provider_component_code` and `calculation_rule` retain the safe identifying rule/code when supplied.

The transaction-level `provider_fee_components_complete` flag means the adjustment rows contain the complete authoritative provider deduction breakdown for that transaction. When true, earnings and settlement calculations sum debit components and do not add the legacy `provider_fee` again. The legacy column remains unchanged for existing callers and historical comparison. When false, calculations continue using the legacy provider fee; provider-sourced partial debit rows are retained as detail but are not added because they may already be included in the legacy amount. Explicit POSPilot manual/calculated debit components other than a duplicate `provider_fee` component are additive under this rule.

Customer-charge components replace the legacy customer charge when present, but an existing manual customer-charge override takes precedence. Other credit components, such as a provider-reported commission or adjustment credit, are added to earnings and offset provider deductions for expected settlement/electronic-position calculations.

All arithmetic uses decimal strings and Brick Math-backed `Money`; amounts are non-negative and direction carries the sign. A calculated component requires a `calculation_rule` identifier. A provider-reported component is accepted through normalized ingestion only for CSV/API/webhook transaction sources. Existing CSV adapters do not map any new fee components, and the Moniepoint webhook currently supplies none. No fee, tax, levy, commission, or other component is inferred from principal, provider, or transaction type.

## Source and completeness rules

- Existing/historical rows are not backfilled from `provider_fee`. They remain `provider_fee_components_complete=false` and continue using the legacy amount.
- Only a reviewed provider normalizer/import mapping may emit `source=provider`. Generic/demo/manual code must not label guessed values as provider-reported.
- POSPilot-calculated values must carry a stable `calculation_rule` identifier. Manual component entry must use `source=manual`; current UI/API does not provide a component-entry endpoint.
- Mark a provider breakdown complete only when that verified source guarantees all relevant provider deduction components for the transaction. Otherwise leave it partial and preserve the legacy calculation.
- A complete set replaces, rather than adds to, `provider_fee` for financial calculations. This is the duplicate-counting guard.
- `provider_fee_supplied` records whether the source actually supplied the legacy fee value. Existing rows default to supplied for backward compatibility; normalized CSV/API/webhook rows set it explicitly. A complete, verified component breakdown is sufficient even if a separate aggregate fee was not supplied. Missing fees remain numerically represented by the legacy zero-compatible column but are never described as a verified zero. A scalar legacy fee preserves calculation compatibility but is not proof that all separate fee/tax/levy components are known.
- Every adjustment row retains its `source` (`provider`, `manual`, or `calculated`). POSPilot does not synthesize VAT, tax, levy, commission, or provider fees. Calculated components require an explicit documented `calculation_rule`; no such provider deduction rules are currently enabled.

## Financial confidence

Earnings responses distinguish `financial_data_status` (`complete_verified`, `provisional`, `calculated`, or `manually_overridden`) from `earnings_status` (`final` or `provisional`). A successful transaction with neither a supplied provider fee nor a complete component breakdown receives reason `provider_fee_missing`; if it has a scalar fee but the total deduction breakdown is not verified complete, it receives `provider_fee_components_incomplete`. Aggregates become provisional if any included successful transaction is provisional, and include reason counts. Calculated/manual charge provenance is also exposed independently so it is not lost when a transaction has more than one status condition.

## Financial consumers

`TransactionFinancialComponentsService` is the single resolver for a transaction's customer charges, provider deductions, other credits, and net provider deduction. Earnings, transaction contribution, provider/terminal reports, settlement expected amounts, and daily-closing balances use it. Failed, pending, and reversed transaction eligibility remains governed by their existing financial services and is not changed by this ledger.

The model is intentionally not a general ledger: it has no chart of accounts, balance-sheet postings, or arbitrary accounting journals. A later import/API adapter should add only documented component mappings and tests proving the provider total and component set agree before setting the completeness flag.
