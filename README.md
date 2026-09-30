# POSPilot

**POSPilot helps Nigerian POS agents see their recorded transactions, customer charges, provider fees, expenses, and settlement differences in one place.** It separates transaction principal from earnings and marks estimates provisional when fee information is missing.

A two-person hackathon project built by **Tolu Oyelola and Maxwell**.

## The problem

Agents who operate across POS terminals and providers often have to piece together activity from separate provider apps, statements, notebooks, and end-of-day cash counts. That makes it hard to answer practical questions: What did I earn? Which transactions are still pending? Did the settlement match? What needs attention before I close for the day?

POSPilot brings those checks into one workspace. It is designed for independent agents and small POS businesses, including people using a personal account dedicated to POS work or a mixed personal-and-POS wallet.

## What POSPilot does

- Records transactions by provider and terminal, with status and transaction details.
- Calculates customer charges from the agent’s configured charge rules. The transaction principal is never counted as earnings.
- Tracks provider fees, expenses, estimated earnings, and financial completeness. Missing fee data stays unknown, so earnings are shown as provisional when appropriate.
- Compares recorded activity with settlement information and surfaces discrepancies for review without attributing blame.
- Supports daily closing, float records, issues, terminal reports, and team roles.
- Supports Gmail statement discovery and processing flows, with account type and activity classification so personal wallet rows are not automatically treated as POS sales. Ambiguous rows require review.

## Data sources and current limits

All imported transaction sources use the same normalized ingestion and financial-calculation pipeline.

| Source | Current status |
|---|---|
| Gmail statements | OAuth, recent statement discovery, attachment processing, mapping, and duplicate protection are implemented. CSV/XLSX and selected machine-readable OPay/PalmPay PDF layouts are supported in code. A successful real-mailbox import is not guaranteed; mailbox configuration, supported statement format, and confident POS classification are required. |
| OPay Direct | Preferred direct source when an eligible business has provider access. Historical sync and the production connector are not implemented or externally tested. |
| Moniepoint Direct | Introspection/configuration code exists, but existing-terminal transaction history sync is not implemented or externally tested. |
| PalmPay Direct | Unavailable pending verified provider access and official support for reading existing POS activity. Gmail statements may be used where supported. |

Direct provider API access is optional. Ordinary agents can use POSPilot’s operational features without provider developer credentials. POSPilot does not initiate payments or move funds. No direct provider API has been live-tested with provider-issued credentials and a physical terminal. Gmail restricted-scope production verification and a confirmed real statement import should not be inferred from local automated tests or demo records.

## Demo and data honesty

The application can be seeded with fictional transactions and settlements to demonstrate the dashboard, reconciliation, and closing flows. Seeded records are explicitly marked as demo data; they are not real provider activity and are not imported from Gmail. Keep demo data separate from a user’s actual business records. Do not use production seeding to overwrite or mix with real user data. The dedicated live showcase workspace is labeled **SHOWCASE DEMO** in the authenticated app; its dashboard uses fictional sample records, while Gmail connection status is shown separately and is not presented as the source of those samples. The demo label does not change normal account registration, sign-in, or access controls.

The live hackathon demo is at [pospilot.tconnect.com.ng](https://pospilot.tconnect.com.ng). The project team provides access to the live showcase account separately; local seeded credentials are not production credentials and must never be used on the live site.

## Local setup

Requirements: PHP 8.3, Composer, Node.js/npm, and a database supported by Laravel.

1. Copy `.env.example` to `.env` and configure the local database.
2. Install dependencies with `composer install` and `npm install`.
3. Generate an application key with `php artisan key:generate`.
4. Run migrations with `php artisan migrate`.
5. For a **local demo only**, run `php artisan db:seed` to create fictional sample activity. The seeded local account is `test@example.com` with password `password`; never reuse these credentials on a deployed environment.
6. Build frontend assets with `npm run build`.
7. Start Laravel with `php artisan serve`.

## Technology

- Laravel 13 / PHP 8.3
- React 18 with Inertia.js
- MySQL in deployment; SQLite is used by the local test environment
- Vite and Tailwind CSS
- Decimal-safe money calculations

## Product walkthrough

1. Create an account, verify its email, and configure a business, terminals, and charge rules.
2. Review Home for today’s recorded volume, estimated earnings, open issues, float status, and recent activity.
3. Use Transactions to inspect recorded POS activity; use Reconciliation, Float & Closing, Issues, and Reports for operational follow-up.
4. To use email statements, connect Gmail separately from Google Sign-In, choose providers, and configure account/activity mappings when requested. Request or send the statement from the provider app first; POSPilot processes it after it arrives. POSPilot does not promise that providers email every transaction automatically.

## Architecture

Provider/API and email sources are normalized into `NormalizedTransactionData` and pass through `TransactionIngestionService`. The existing financial engine remains authoritative for deduplication, charge rules, earnings, expenses, reconciliation, and closing. Source provenance is retained where supported so later statement data can enrich a transaction without creating a duplicate.

Useful project references:

- [Product guide](docs/PRODUCT_GUIDE.md)
- [Provider capability research](docs/providers/DIRECT_PROVIDER_RESEARCH.md)
- [Gmail statement integration and limitations](docs/GMAIL_STATEMENT_INTEGRATION.md)
- [Security notes](docs/security/README.md)

## Team

- **Tolu Oyelola** — co-builder
- **Maxwell** — co-builder
