# POSPilot Integration QA Scenario

This is the shared, fictional test case for the automated integration test, the Postman collection and the internal QA page. All dates, provider references, terminal identifiers and amounts are synthetic. No real customer data or provider credentials are used.

Scenario date: `2026-06-01` (NGN). Import the three transaction CSVs and three settlement CSVs from `docs/qa/scenario/`. The automated test and Postman requests use the same records. The QA page displays the values returned by the same backend endpoints; it does not calculate totals in JavaScript.

## Setup

Create three terminals: OPay `OPA-QA-01`, Moniepoint `MP-QA-01`, PalmPay `PPA-QA-01`.

Configure active fixed-charge rules with no gaps or overlap:

| Principal range | Customer charge |
|---|---:|
| ₦1.00–₦40,000.00 | ₦500.00 |
| ₦40,000.01–₦49,999.99 | ₦600.00 |
| ₦50,000.00 and above | ₦700.00 |

The customer-charge column is blank in each transaction statement, so the charge rule supplies the charge and the transaction records its calculated provenance. A manual override later changes the pending OPay row from ₦500.00 to ₦550.00; pending transactions do not contribute to finalized earnings.

## Transactions

| Provider | Reference | Status | Principal | Rule charge | Imported provider fee |
|---|---|---|---:|---:|---:|
| OPay | `QA-OP-001` | successful | ₦50,000.00 | ₦700.00 | ₦150.00 |
| OPay | `QA-OP-002` | pending | ₦20,000.00 | ₦500.00 | not supplied |
| Moniepoint | `QA-MP-001` | successful | ₦45,000.00 | ₦600.00 | ₦160.00 |
| PalmPay | `QA-PP-001` | successful | ₦40,000.00 | ₦500.00 | ₦140.00 |
| PalmPay | `QA-PP-002` | successful | ₦50,000.00 | ₦700.00 | ₦170.00 |
| PalmPay | `QA-PP-003` | reversed | ₦15,000.00 | ₦500.00 | not supplied |

Successful transaction volume (principal only):

`₦50,000.00 + ₦45,000.00 + ₦40,000.00 + ₦50,000.00 = ₦185,000.00`

Successful customer charges:

`₦700.00 + ₦600.00 + ₦500.00 + ₦700.00 = ₦2,500.00`

Provider fees supplied on those successful rows:

`₦150.00 + ₦160.00 + ₦140.00 + ₦170.00 = ₦620.00`

The pending ₦20,000.00 and reversed ₦15,000.00 principal are visible in their separate summary fields but excluded from successful volume and earnings. The manual pending-charge override is likewise excluded from earnings.

## Earnings and completeness

One operating expense is recorded: transport, `₦800.00`.

`Customer charges ₦2,500.00 − provider fees ₦620.00 − expenses ₦800.00 = estimated net earnings ₦1,080.00`

The expected numeric result is `₦1,080.00`, but the API must label it **provisional**, not final. CSVs provide only a legacy scalar provider-fee value and no complete verified fee-component breakdown. The response should report the reason `provider_fee_components_incomplete` for the four successful transactions. POSPilot must not invent VAT or other deductions.

## Settlement reconciliation

| Provider | Expected | Actual | Difference (actual − expected) | Outcome |
|---|---:|---:|---:|---|
| OPay | ₦49,850.00 | ₦49,800.00 | **−₦50.00** | partially reconciled; short by ₦50.00 |
| Moniepoint | ₦44,840.00 | ₦44,840.00 | ₦0.00 | reconciled |
| PalmPay | ₦89,690.00 | ₦89,690.00 | ₦0.00 | reconciled |
| Total | ₦184,380.00 | ₦184,330.00 | **−₦50.00** | one provider discrepancy |

OPay expected settlement: `₦50,000.00 − ₦150.00 = ₦49,850.00`.

Moniepoint expected settlement: `₦45,000.00 − ₦160.00 = ₦44,840.00`.

PalmPay expected settlement: `₦40,000.00 + ₦50,000.00 − ₦140.00 − ₦170.00 = ₦89,690.00`.

The pending and reversed transactions remain visible as reconciliation context; they do not inflate successful transaction earnings.

## Daily closing

Opening cash is `₦200,000.00`. These records are withdrawals, whose configured cash effect is `customer charge − principal`; expense reduces cash.

`Expected cash = ₦200,000.00 + (₦2,500.00 − ₦185,000.00) − ₦800.00 = ₦16,700.00`

Entered closing cash is `₦16,690.00`, therefore:

`Cash variance = ₦16,690.00 − ₦16,700.00 = −₦10.00`

Actual provider balances are OPay `₦49,800.00`, Moniepoint `₦44,840.00`, PalmPay `₦89,690.00`.

`Expected electronic position = ₦49,850.00 + ₦44,840.00 + ₦89,690.00 = ₦184,380.00`

`Actual electronic position = ₦49,800.00 + ₦44,840.00 + ₦89,690.00 = ₦184,330.00`

`Electronic variance = ₦184,330.00 − ₦184,380.00 = −₦50.00`

`Total daily-closing variance = −₦10.00 + (−₦50.00) = −₦60.00`

With the current configured ₦100.00 small-variance threshold, expected closing status is `small_variance`. Finalization must be rejected until the cash entry and the balances for each provider with successful activity are present. The explanation should identify provider balance below expected and cash mismatch; it must not suggest theft or fraud.

## Expected API checks

For `from=2026-06-01&to=2026-06-01`, `/api/financial-summary` should return transaction volume `185000.00`, successful count `4`, customer charges `2500.00`, provider fees `620.00`, expenses `800.00`, estimated net earnings `1080.00`, provisional earnings status, pending principal `20000.00`, and reversed principal `15000.00`.

`/api/reconciliation/overview` should return three settlements, one partially reconciled outcome, and net unreconciled amount `-50.00`.

The daily-closing detail after entering the actuals should return expected cash `16700.00`, expected electronic position `184380.00`, actual electronic position `184330.00`, total variance `-60.00`, and variance status `small_variance`.

The permanent provider CSV examples are in `docs/sample-imports/`. The scenario-specific files in `docs/qa/scenario/` are deliberately small and map directly to this exact arithmetic.
