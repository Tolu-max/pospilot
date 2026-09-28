# Product analytics

## Current status

Sabilytics is installed from the account-specific snippet and event contract in [SABILYTICS.md](SABILYTICS.md). It loads only on the production hostname and sends only allowlisted coarse events with fixed metadata.

## Privacy requirements for a future integration

Only these allowlisted events may be sent: `signup_completed`, `login_completed`, `onboarding_completed`, `gmail_connected`, `statement_sync_started`, `statement_imported`, `provider_connection_started`, and `daily_closing_completed`. Send no financial values, customer/provider identifiers, credentials, references, balances, raw payloads, or free-form properties. Analytics errors must be isolated from and never block financial operations.

The official tracker sends pathname and query string as the page field. Keep financial values and personal/provider identifiers out of URLs. The dashboard-specific tracking ID is public and is not a secret.

Reference: [Sabilytics public product and custom-event description](https://www.sabilytics.com/).
