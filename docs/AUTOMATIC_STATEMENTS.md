# Automatic provider statements

## Hackathon beta

The intended journey is provider selection → separate Gmail connection → provider sender rules → account/terminal setup → scheduled statement discovery → one-time column mapping → existing transaction ingestion. The provider workflow supports multiple providers and multiple terminals/accounts per provider.

The flow is controlled by `FEATURE_GMAIL_STATEMENTS` and disabled by default. Google configuration and a verified email statement sample are still needed before a real end-to-end demonstration. Gmail OAuth testing-mode access is a limited beta, not general production approval.

## Current processing

- Separate OAuth callback: `/integrations/gmail/callback`.
- Minimum scope currently requested: `https://www.googleapis.com/auth/gmail.readonly` (Google Restricted).
- Configured exact sender addresses, attachment-only Gmail search and bounded date windows; unrelated message bodies are not stored.
- `.csv` and `.xlsx` only; maximum 10 MB, maximum 5,000 rows. `.xls` and PDF are reported as unsupported.
- First unseen schema waits for mapping. The mapping stores normalized headers and schema fingerprint, not row/customer data.
- Account/terminal match uses a keyed HMAC and masked suffix. Raw statement account numbers are not retained. Multiple active terminals require a terminal identifier column; ambiguous files wait for setup.
- Import uses `TransactionIngestionService` and its existing idempotency. Verified statement fee data can enrich a matching record; a missing fee remains provisional.
- Temporary encrypted files are removed after import/disconnect and expire after seven days while awaiting mapping.
- A queue worker and Laravel scheduler are required. Discovery runs every 15 minutes with overlap protection.

Provider formats and delivery paths remain **not verified** until checked against provider-issued sanitized data. See [Gmail setup, privacy, and release gates](GMAIL_STATEMENT_INTEGRATION.md) and [provider evidence](providers/README.md).

## Manual import

Manual CSV upload remains an internal QA/recovery capability. Its parser and tests remain, but it is not presented in the ordinary production user journey.
