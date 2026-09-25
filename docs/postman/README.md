# POSPilot Postman QA

Import `PosPilot.postman_collection.json` and `PosPilot.local.postman_environment.json` into Postman and select the local environment. Set `base_url` to the local or approved staging origin. Before starting, clear any saved cookies for that host so the first unauthenticated request can assert `401`. Do not export a populated environment or cookie jar.

## Authentication is a Laravel browser session

The `/api/*` routes are registered in `routes/web.php` inside Laravel's `web` and `auth` middleware. The application uses the `web` session guard and its session cookie; it does not issue or expect bearer tokens. POST `/login` authenticates, regenerates the session, and returns a redirect. POST `/logout` invalidates the session and regenerates the CSRF token. Login and registration are throttled. State-changing API requests also pass through web CSRF protection.

The collection starts with an unauthenticated API request (expected `401`), then GETs `/login`, extracts the `csrf-token` meta value, and registers/logs in a unique fictional QA account. Postman must keep its normal cookie jar enabled for the selected host. The collection sends `X-CSRF-TOKEN: {{csrf_token}}` for writes and `Accept: application/json` for API calls. No `Authorization: Bearer` header is defined.

To rerun with a fresh account, keep the dynamic timestamp in the email request or clear the collection/environment variables before the run. Requests that create rules, terminals, expenses, and imports are not idempotent at the request level. Start against an isolated local/staging QA database and account; do not run destructive database reset commands against shared or production data.

## Run order and CSV fixtures

Run the Authentication folder first, then execute the remaining folders in order. The CSV file request bodies use Postman's file picker: select these local fixture files when prompted.

- OPay transaction: `docs/qa/scenario/opay-transactions.csv`
- Moniepoint transaction: `docs/qa/scenario/moniepoint-transactions.csv`
- PalmPay transaction: `docs/qa/scenario/palmpay-transactions.csv`
- OPay settlement: `docs/qa/scenario/opay-settlements.csv`
- Moniepoint settlement: `docs/qa/scenario/moniepoint-settlements.csv`
- PalmPay settlement: `docs/qa/scenario/palmpay-settlements.csv`

The duplicate OPay preview must select the same OPay transaction CSV a second time. The malformed-import request is deliberately a negative test; attach a malformed CSV (e.g. a header-only file) to that request. Automated upload tests cover empty, oversized, and invalid-extension files; for manual Postman QA, attach a file above the 5 MiB limit or a non-CSV file and expect `422`.

The OPay transaction import request has its own preview token, captured from the session-backed preview response; confirm it before previewing another file. Confirm the Moniepoint and PalmPay transaction previews immediately after each corresponding preview. Settlement previews work the same way and must be confirmed before moving to the next provider. The collection environment stores only preview tokens and IDs, never uploaded files.

Configure the three non-overlapping charge ranges before importing the complete scenario. The collection includes one create request per range; run each once for a fresh QA account. Import all three transaction statements and all three settlement statements, then enter actual balances for all three providers before finalization. The scripted expected values are in [`../QA_SCENARIO.md`](../QA_SCENARIO.md).

The connection credential request contains clearly fictional local-only placeholders solely to assert redaction. Never replace them with a real API key, webhook secret, password, PIN, OTP or private key. The collection deliberately has no live connection-test request because testing a provider credential can make a real outbound provider request.

## QA interface

The isolated browser-only integration page is at `/qa/integration` after login. It is labeled “QA / Integration Frontend” and is not the production frontend. It uses same-origin session cookies and the rendered page's CSRF meta token.
