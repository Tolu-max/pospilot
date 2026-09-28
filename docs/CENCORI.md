# Cencori Business Insight

POSPilot adds an opt-in **Explain my day** feature to the dashboard. Laravel computes the financial summary; Cencori only explains the summary in plain language. The AI response is not used to calculate or update transactions, earnings, expenses, balances, reconciliation, or daily closing.

For the current release, the integration is retained in the codebase but disabled with `FEATURE_CENCORI_BUSINESS_INSIGHT=false`. The dashboard displays **Coming soon**, and the server rejects requests without contacting Cencori. No dashboard data is sent while this flag is off.

## Cencori setup

The Cencori dashboard was signed in to the POSPilot organization and a `POSPilot Business Insight` project was created. One server secret key was created for the Laravel application and saved as `CENCORI_API_KEY` in the local, git-ignored `.env`. The key itself is not documented or exposed. A read-only request to the official models endpoint returned HTTP 200, confirming that the configured secret key authenticates to Cencori.

The project dashboard showed `$0.00` prepaid balance. A real chat-completion request from the Laravel service returned HTTP 403 and no explanation. Cencori documents 403 responses for conditions including secret-key requirements and exhausted credits; because this key authenticates successfully on `/v1/models` and the dashboard balance is zero, credit availability/model routing remains the current blocker. No billing was added. The completion and its Cencori log entry have not been verified.

Create or select a Cencori project, enable a model that the project can route, then create a server-side secret key from that project's API Keys page. Set these values in the application environment (never in a `VITE_` variable):

```dotenv
CENCORI_API_KEY=
CENCORI_BASE_URL=https://api.cencori.com/v1
CENCORI_MODEL=gpt-4o
CENCORI_TIMEOUT=20
```

`gpt-4o` is the default documented example. Confirm the selected model is enabled for the Cencori project. The secret key must stay server-side and must not be committed or included in browser props, API responses, logs, analytics, or screenshots.

## Request and data boundary

The authenticated `POST /api/business-insight` endpoint obtains today's totals from `AgentFinancialSummaryService` and posts to Cencori's OpenAI-compatible `POST /v1/chat/completions` endpoint. It is limited to one request per user per minute. No request is sent when `CENCORI_API_KEY` is empty. Cencori errors return a generic temporary-unavailable message; dashboard figures remain available.

The user-facing request contains only these summary fields:

- Successful transaction count and aggregate transaction volume
- Aggregate customer charges
- Aggregate provider fees only when financial completeness confirms them; otherwise the fee value is `null`
- POSPilot-calculated estimated earnings and provisional status
- Aggregate expenses and reconciliation issue count
- Closing variance only when a finalized daily closing has one

It excludes customer names, emails, account numbers, terminal IDs, references/RRNs, transaction rows, Gmail content, statements, and provider credentials. The dashboard tells the user that daily totals are sent to Cencori for the explanation. Cencori's documentation states request logs include prompts and responses, so these sanitized totals and the generated explanation may be visible in the Cencori project logs.

The system prompt tells the model not to calculate, alter, estimate, or invent numbers; to treat `null` as not recorded; and to explicitly say earnings are provisional when provider fees are incomplete. The response is displayed as plain text and is never used by the financial engine.

## Verification status

- Automated feature tests use Laravel's HTTP fake and fictional fixtures; they do not depend on Cencori availability.
- Local key configuration: **verified**. The key authenticates to `GET https://api.cencori.com/v1/models` (HTTP 200); only the status and secret-key type were checked, never the key value.
- Real chat request: **attempted through `BusinessInsightService`**, using only its aggregate summary fields. Cencori returned HTTP 403; no model completion was received. The application returned its generic unavailable response and kept dashboard financial figures available.
- Cencori dashboard log: **not verified**. The project Logs link did not navigate from the current dashboard UI. The project balance remains `$0.00`; no top-up or provider credentials were added.
- The integration is currently disabled. To enable a future test, set `FEATURE_CENCORI_BUSINESS_INSIGHT=true`, make model usage available in Cencori, then click **Explain my day** on the POSPilot dashboard and verify a successful response and matching request in the project's Logs page.
- Local `.env` is git-ignored. The API key is absent from tracked diffs and this document. No deployment or production key configuration was performed.

## Official references

- [Cencori Quick Start](https://cencori.com/docs/quick-start)
- [Cencori Chat API](https://cencori.com/docs/api/chat)
- [Cencori API Keys](https://cencori.com/docs/api/api-keys)
