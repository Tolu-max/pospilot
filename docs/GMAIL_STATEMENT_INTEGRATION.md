# Gmail statement integration

## Release status

Gmail statement discovery is controlled by `FEATURE_GMAIL_STATEMENTS`. Runtime defaults to disabled; enable it only after configuring the dedicated OAuth web client and protected environment values. The Google OAuth project is External / In production but unverified for its restricted Gmail scope. Any Google account can reach the consent flow; Google currently imposes a 100-user lifetime cap on unverified restricted-scope apps. Google Cloud does not limit this to two users. The production POSPilot application has not been deployed with this flow or configured with a production credential store.

The provider statement schemas have not been verified until checked against genuine provider-issued statements. The generic mapping flow imports compatible CSV/XLSX through the existing `TransactionIngestionService`; there is no separate earnings or reconciliation engine.

## Google OAuth

Use the separate Gmail OAuth web client and keep normal Google Sign-In on its existing client and identity-only scopes. Request only `https://www.googleapis.com/auth/gmail.readonly`. Google classifies this scope as restricted. It technically permits broad viewing of Gmail messages and settings, while POSPilot's query and processing rules limit what POSPilot searches and processes.

Set these server-side values without committing their contents:

```dotenv
FEATURE_GMAIL_STATEMENTS=true
GOOGLE_GMAIL_CLIENT_ID=
GOOGLE_GMAIL_CLIENT_SECRET=
GOOGLE_GMAIL_REDIRECT_URI=https://pospilot.tconnect.com.ng/integrations/gmail/callback
GMAIL_TOKEN_STORE=external
GMAIL_TOKEN_STORE_CLASS=<reviewed external GmailCredentialStore adapter>
```

The exact production OAuth redirect URI is `https://pospilot.tconnect.com.ng/integrations/gmail/callback`. The Laravel callback route is `GET /integrations/gmail/callback`. Start OAuth with `GET /integrations/gmail/connect`. See [Google Cloud setup](GMAIL_GOOGLE_CLOUD_SETUP.md) for the full console walkthrough.

OAuth uses state bound to the authenticated session, authorization-code exchange on the server, offline access, incremental consent, and refresh-token preservation when Google omits a new refresh token. Tokens are encrypted at rest in a separate user-scoped credential record and hidden from serialization. The encrypted database adapter is local/testing only and explicitly refuses production. Production must configure a reviewed external `GmailCredentialStore` adapter with `GMAIL_TOKEN_STORE=external`; the stable Laravel `APP_KEY` must remain protected in Go54's application environment.

In Testing mode, only listed Google test users can authorize, and Gmail refresh tokens expire after about seven days. In the current In production mode, users can reach the app but see Google's unverified-app warning, with the 100-user lifetime cap. Neither mode completes restricted-scope verification; public Gmail data access remains subject to Google's verification and any applicable security assessment.

## Search, minimization, and flow

Truthful product flow:

1. The agent requests/sends a statement from the provider app.
2. The email reaches the Gmail inbox.
3. The agent selects **Check for statements** in POSPilot.
4. POSPilot searches only recent messages for selected providers, with an attachment filter and statement-related subject terms. An exact sender address can narrow the search when the user verifies it from a genuine provider email; POSPilot does not assume sender addresses.
5. POSPilot requests message IDs first, then sender/subject/date metadata, and requests full message details only for a candidate that matches provider evidence.
6. POSPilot retrieves CSV/XLSX attachments server-side, detects schema headers, identifies a masked account hint only when a recognized header has one distinct value, asks the agent to map unknown schemas/accounts, and passes normalized transactions to the existing ingestion service.

POSPilot does not retain raw email bodies or unrelated email content. It does not send Gmail data to Sabilytics or SendByte. Gmail message and attachment IDs are encrypted at rest; candidate fingerprints are connection-scoped. Temporary attachment content is Laravel-encrypted on private storage while awaiting setup and expires after seven days. Processed temporary files are deleted. Repeating a check does not duplicate message attachments or normalized transactions.

Manual `POST /integrations/gmail/sync` runs a bounded recent check synchronously so today's proof does not depend on a queue worker. An optional scheduled `SyncConnectedGmailStatements` job exists and requires a functioning Laravel scheduler and durable queue worker.

## User routes

| Method | Path | Purpose |
|---|---|---|
| GET | `/integrations/gmail/connect` | Start dedicated Google authorization |
| GET | `/integrations/gmail/callback` | Verify OAuth state, exchange code, store credentials |
| POST | `/integrations/gmail/sync` | Check recent selected-provider statement candidates |
| DELETE | `/integrations/gmail` | Revoke best-effort, delete local tokens, mark disconnected |
| GET | `/api/gmail/connection` | Safe status, masked address, provider choices, statement counts |
| PUT | `/api/gmail/connection/rules` | Save selected providers and optional verified sender addresses |
| GET | `/api/gmail/statements` | List safe status and headers needed for setup |
| POST | `/api/gmail/statements/{message}/mapping` | Save mapping for an owned statement and terminal |

Disconnect prevents future sync, deletes local OAuth credentials and pending temporary files, and preserves normalized POSPilot financial history. Raw Google API error bodies and token values are never returned or logged.

## Supported formats and money handling

CSV and XLSX are supported up to 10 MB and 5,000 rows. XLSX reads the first worksheet and rejects formulas. PDF support is limited to the observed machine-readable OPay and PalmPay layouts; OCR and legacy `.xls` are not supported. PDF table cells are reconstructed from text coordinates, not guessed from column order. The OPay layout uses transaction time, value date, description, debit, credit, balance after, channel, and transaction reference. The PalmPay layout uses transaction date, transaction detail, money in, money out, and transaction ID. Only rows explicitly describing POS, terminal, or card activity are eligible; a row must also have a provider reference, a parseable date, and one unambiguous positive debit/credit direction. OPay rows also require a parseable balance-after value. Non-POS wallet activity and ambiguous rows are skipped.

These provider statements contain posted ledger rows rather than a separate status column. Eligible rows are marked successful unless their description explicitly identifies a reversal, pending, processing, failure, or decline. This is a statement-specific interpretation of posted ledger activity. The parser does not infer a provider fee from debit/credit values; these observed layouts expose no verified fee field, so missing fees remain unknown and earnings remain provisional. When a PDF labels one provider account number, the parser carries it only in the encrypted temporary mapping file; the saved account match stores a masked hint and deterministic fingerprint. New/unrecognized PDF layouts stay in setup rather than being imported speculatively. The generic mapping flow still requires a configured terminal before import.

The generic CSV parser converts decimal text using decimal-safe PHP arithmetic. Provider fee is marked supplied only when the attachment actually includes a mapped value; missing fees remain provisional. Import idempotency and all financial calculations stay within POSPilot's existing ingestion and financial services.

## Position in the data architecture

Direct APIs are the preferred source when supported and when an eligible business has provider-issued access: OPay first, then Moniepoint. Email Statements are the practical fallback for ordinary agents and may remain connected alongside a direct source to add statement-supplied reconciliation detail. All normalized statements pass through `TransactionIngestionService`; Gmail is not a separate financial engine.

The provider may require the agent to request or send a statement from its app. POSPilot automates discovery, attachment retrieval, provider/schema recognition, account matching, parsing, deduplication, and ingestion after the email arrives. It does not promise that every provider transaction is automatically emailed.

## Official references

- [Gmail API scopes](https://developers.google.com/workspace/gmail/api/auth/scopes)
- [OAuth Testing status](https://developers.google.com/identity/protocols/oauth2/production-readiness/overview)
- [Gmail search and filtering](https://developers.google.com/workspace/gmail/api/guides/filtering)
- [Gmail attachment retrieval](https://developers.google.com/workspace/gmail/api/reference/rest/v1/users.messages.attachments/get)
- [OAuth web-server flow](https://developers.google.com/identity/protocols/oauth2/web-server)
