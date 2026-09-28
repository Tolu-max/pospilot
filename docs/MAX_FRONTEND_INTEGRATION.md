# Max Frontend Integration Map

This document maps Max's delivered React demo to the existing POSPilot Laravel + Inertia application. Max's layouts, page composition, typography, spacing, tables, transaction details and reconciliation visual language are the UX reference. The Laravel session-authenticated application and its financial services remain authoritative.

The archive is `C:\Users\USA\Downloads\POS PILOT - zip.zip`. Its bundled `node_modules/` and `dist/` are not part of the integration. The archive's browser-only sample data and business rules are not production data.

## Page and backend mapping

| Max page/concept | POSPilot source of truth | Integration notes |
|---|---|---|
| Home / dashboard | `GET /dashboard` and `GET /api/financial-summary`, `GET /api/provider-breakdown`, `GET /api/provider-connections` | Keep Max's earnings-first hierarchy. Show API completeness/provisional reasons as returned; use backend-provided recent transactions and summary. |
| Transactions | `GET /transactions` for Inertia list; `GET /api/transactions` for supported filters and pagination | Keep Max's scan-friendly table/list and filters only for actual API fields: provider, terminal, transaction status, settlement status, date range and reference. Remove unsupported local filters and exports unless they are backed by real data. |
| Transaction detail | `GET /transactions/{transaction}` or `GET /api/transactions/{transaction}` | Use backend financial components, charge provenance, earnings state, source, terminal, and settlement status. Never infer settlement amount or display missing fees as zero. |
| Provider activity / reconciliation | `GET /api/reconciliation/overview`, `GET /api/reconciliation/issues`, `GET /api/settlements`, `GET /api/settlements/{settlement}` | Preserve clear variance presentation and evidence. Do not use Max's provider-vs-cash matching algorithm; the backend's settlement reconciliation service is authoritative. |
| Daily closing / cash count | `GET /api/daily-closings/preview`, `POST /api/daily-closings`, `POST /api/daily-closings/{id}/balances`, `GET /api/daily-closings/{id}/breakdown`, `POST /api/daily-closings/{id}/finalize`, `GET /api/daily-closings` | Adapt the count/closing presentation to existing `DailyClosingService`. Cash and provider balances are persisted and recalculated by the backend. Max's opening-float/movement calculation is unsupported and must not be used. |
| Expenses | `GET/POST/PATCH/DELETE /api/expenses` | Keep simple expense entry/history, using backend categories, decimal strings and dates. Expense effects on earnings come from the backend. |
| Terminals | `GET/POST/PATCH/DELETE /api/terminals` and `PATCH /api/terminals/{id}/status` | Use actual terminal/provider records. Multiple terminals per provider are supported. Location assignment and operator assignment are not in the current model. |
| Provider connections | `GET /api/provider-connections`, `GET/PUT/DELETE /api/providers/moniepoint/connection`, `POST /api/providers/moniepoint/connection/test` | Show real connection state only. Credential fields are write-only, protected by recent authentication, and must never be included in page props, browser storage or logs. OPay/PalmPay are not live direct integrations. |
| Reports | `GET /api/financial-summary`, `GET /api/provider-breakdown`, reconciliation and daily-closing APIs | Reuse supported summary values. Remove Max's browser-calculated daily report and shift report. |
| Settings | `GET/PATCH /api/agent/profile`, terminal and charge-rule endpoints, `/profile`, `/api/security/sessions`, `DELETE /api/security/sessions/others` | Map business settings to the agent profile, charge pricing, terminals and account security. Remove demo business-mode switches, arbitrary alert thresholds and reset-demo action. |
| Authentication | Laravel web routes: `/register`, `/login`, `/forgot-password`, `/reset-password/{token}`, `/verify-email`, `/auth/google/redirect`, `/logout` | Retain session cookies, CSRF, verification, OAuth state, recent-authentication checks and existing Laravel authentication pages. No React Router role switch or client-side authentication. |

## Demo-only concepts to remove or defer

Max's `src/data/transactions.js`, `src/data/reconciliation.jsx`, `src/data/operations.js`, and `src/ReconciliationProvider.jsx` generate fictional transactions, terminals, movements, provider balances, shifts, attendants, exceptions, notifications, report totals, and business settings in browser memory. None of these are a substitute for POSPilot API data.

The following concepts have no matching backend domain and will not be presented as real functionality: shifts, opening float, cash movement timelines, attendant mode, owner/attendant switching, owner approval, correction requests, shift exceptions, demo notifications, arbitrary locations/operators, fake provider polling, demo reset, and generated “statement” exports. Cash-count UX is retained only where it maps to the existing daily-closing cash fields.

## Integration constraints

- Use the existing Laravel + React/Inertia page resolver and named application routes; do not create a second production SPA/router.
- Continue using same-origin Laravel session authentication and CSRF behavior.
- Use backend decimal strings, status/completeness metadata, reconciliation results and daily-closing variances without frontend financial recomputation.
- Keep `QAIntegration` as a separate internal QA page.
- Keep CSV import functionality available through the existing transaction/settlement import workflow, but do not imply that OPay or PalmPay has a direct API connection.
- Any API contract issue discovered during integration must be documented and corrected only when it is an actual backend defect.
