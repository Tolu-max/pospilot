# PosAgent Bot

PosAgent Bot is a mobile-first financial operations workspace for Nigerian POS agents who use multiple providers. The hackathon MVP focuses on two questions: what did I actually earn, and which provider settlements do not add up?

## Target user

Independent Nigerian POS agents and small agent businesses using providers such as OPay, Moniepoint and PalmPay.

## Stack

- Laravel 13 and PHP 8.3
- React 18 with Inertia.js
- MySQL for normal development; SQLite is kept in the local test environment
- Vite and Tailwind CSS

## Local installation

1. Copy `.env.example` to `.env` and set the MySQL database values.
2. Install dependencies: `composer install` and `npm install`.
3. Generate the application key: `php artisan key:generate`.
4. Run migrations and demo data: `php artisan migrate --seed`.
5. Build assets: `npm run build`.
6. Start Laravel: `php artisan serve`.

The seeded demo account is `test@example.com` with password `password`.

## Architecture overview

The internal model normalizes provider data into `transactions` and `settlements`, regardless of whether the future source is an API, CSV or manual import. `PaymentProviderConnector` is the small connector contract; `DemoConnector` is a no-request placeholder for the seeded dataset.

Financial logic lives in services rather than controllers:

- `ChargeCalculationService` selects fixed or percentage charge rules.
- `EarningsService` calculates customer charges minus provider fees minus recorded expenses. Transaction principal is never revenue.
- `ReconciliationService` compares expected and actual settlement amounts and reports discrepancies.

Money is stored as MySQL `DECIMAL` values and calculated with Brick Math decimal arithmetic. Statuses and charge types are centralized PHP enums.

## Scope notes

The current milestone intentionally does not include provider API integrations, AI, billing, a full accounting system or significant PWA work.

## Transaction imports

Authenticated users can open `/transactions/import`, choose a provider, upload a CSV, review normalized rows and validation problems, then confirm the import. OPay, Moniepoint, PalmPay and Generic/Other adapters are isolated under `app/Imports`.

Every source is designed to follow the same path: provider parser/connector → `NormalizedTransactionData` → `TransactionIngestionService` → duplicate protection, charge provenance and persistence. `ProviderConnection` records future API, webhook, CSV and manual connection state without storing ordinary-user secrets. `PaymentProviderConnector`, `ProviderWebhookConnector` and `SyncProviderTransactions` provide the future integration seams; no external network calls are implemented yet.

CSV previews are held temporarily in the session for confirmation. Uploaded files are not retained. Imported transactions keep their original customer charge, calculated fallback charge and any later manual override separately.
