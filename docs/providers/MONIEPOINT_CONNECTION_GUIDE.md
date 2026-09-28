# Connect Moniepoint to POSPilot

> **Research update (2026-09-28):** This historical setup guide predates the current official reference at [docs.pos.beta.moniepoint.com](https://docs.pos.beta.moniepoint.com/). It is not an authoritative webhook/authentication guide. POSPilot's direct connection flag defaults off, the current callback authentication/schema has not been verified, and webhook ingestion is disabled. Follow [current Moniepoint status](MONIEPOINT.md) and [provider research](DIRECT_PROVIDER_RESEARCH.md) for release decisions. Do not configure a webhook using the legacy HMAC claims later in this archived guide.

This guide separates what was observed in a signed-in Moniepoint Business account from what Moniepoint's published developer instructions describe. The dashboard path below could not be reached in the account reviewed, so do not treat this guide as confirmation that every business account has the same menu or permissions.

## Before you begin

You need access to the Moniepoint Business account that owns the POS terminals and a POSPilot account. POSPilot does not need your Moniepoint login password, transaction PIN, card PIN, or OTP. Never enter those into POSPilot.

The Moniepoint developer setup can create API credentials and a webhook subscription. Moniepoint's published introspection example shows `webhook:read`, `webhook:write`, and `webhook:delete` scopes. Review the permissions shown to you before creating a key. The available scopes were not verified in the live account reviewed for this guide.

## What was observed in the live account

The signed-in Business dashboard's visible navigation showed **Overview**, **Accounts**, **Cards**, **All Transactions**, **Transfers**, **Airtime**, **Data**, **Bill Payment**, **POS Transfers**, **Disputes**, **POS**, **Network**, **Cashback**, and **Referrals**. No **Settings**, **Integrations**, **ERP Integration**, or developer/API area was visible in that navigation.

Opening **POS** led to **POS Terminals**, an informational page. It did not expose developer settings or assigned POS terminals in the account reviewed. Consequently, the current account-specific route into API setup could not be verified.

A cropped screenshot of that POS Terminals page was inspected for this verification. It showed only the general POS information area and no account or transaction details. A screenshot file is not included: the browser capture could not be safely transferred into the repository as a sanitized image artifact. Do not use full dashboard screenshots as setup instructions; they can show private business/account information.

## Published Moniepoint developer setup

Moniepoint's [API key setup guide](https://teamapt.atlassian.net/wiki/spaces/EI/pages/2090893327) and [webhook subscription guide](https://teamapt.atlassian.net/wiki/spaces/EI/pages/1651605514/Suscription%2BManagement) describe this route:

1. Sign in to the Moniepoint Business account and select the business that owns the terminals.
2. Open **Settings**.
3. Open **POS Terminal Configuration**, then **POS Terminal Features**.
4. Enable **ERP Integration** if it is not already enabled.
5. Open **POS Apps Developer**.
6. Open **API Keys** and choose **Create New API Key**.
7. Enter a recognizable key name and duration, select only the required business, and review every permission offered. Moniepoint says the API secret is shown only once; store it only in an approved secret manager and never in a document, screenshot, chat, or source control.
8. In the developer area, create a webhook subscription for the POSPilot webhook endpoint and the supported POS transaction event. The subscription flow may issue a webhook secret once; treat it as a credential.
9. In POSPilot, open **Providers → Moniepoint** and enter the requested integration values. Do not enter Moniepoint login credentials or any PIN/OTP.
10. Use **Test connection** only after your administrator has confirmed the exact key scopes and the deployment's secret store is approved for real provider credentials.

The route above is **verified from published Moniepoint documentation**, not from the live dashboard. Menu names or availability may differ by account role or account configuration. If the route is absent, stop and ask Moniepoint support rather than enabling unrelated settings.

## Permission and connection safety

The published POS-as-a-Platform documentation's introspection example lists `webhook:read`, `webhook:write`, and `webhook:delete`. It does not establish that a narrower key is available for POSPilot, and webhook deletion is a management permission. The live permission selector was not reached, so the scope boundary remains unverified. Do not create a real key until the listed scopes and their effects have been reviewed with Moniepoint.

POSPilot's local environment currently uses its encrypted database secret store. The code disallows that store in production and requires a configured external secret-store adapter there. No production connection was tested. A real credential should only be entered in the intended non-production environment after the provider permission boundary and local data handling have been approved.

POSPilot's existing connection test calls Moniepoint's documented **development** key-introspection endpoint. It does not prove production API connectivity, webhook delivery, historical transaction sync, settlement sync, or balance sync. No API key or webhook subscription was created in the real account during this review.

## Statement export

The inspected web dashboard's Accounts and All Transactions pages did not present a usable statement export action. Moniepoint's [support instructions](https://support.moniepoint.com/topics/account-information-and-updates-234) describe exporting a business statement from the mobile app using the account's History and Export controls. That app flow was not inspected in this account. See [MONIEPOINT_STATEMENT_FORMAT.md](MONIEPOINT_STATEMENT_FORMAT.md) for what is and is not verified about statement formats.

## Verification status

| Claim | Status |
|---|---|
| Live dashboard navigation and POS page | Verified in the signed-in account; no developer setup route found |
| API key and webhook setup path | Verified from Moniepoint's published documentation only |
| Current account's permission choices | Not verified |
| Real API key or webhook subscription | Not created |
| POSPilot connection using a real credential | Not tested |
| Live webhook event delivery | Not tested |
| Webhook historical backfill, balances, or settlements | Not verified/documented for POSPilot |
| Real statement file schema and parser compatibility | Not verified |

## Official references

- [Moniepoint POS as a Platform documentation](https://teamapt.atlassian.net/wiki/spaces/EI/pages/1892974728/POS%2Bas%2Ba%2BPlatform%2BDocumentation)
- [Moniepoint API key setup](https://teamapt.atlassian.net/wiki/spaces/EI/pages/2090893327)
- [Moniepoint webhook subscription setup](https://teamapt.atlassian.net/wiki/spaces/EI/pages/1651605514/Suscription%2BManagement)
- [Moniepoint account statement support](https://support.moniepoint.com/topics/account-information-and-updates-234)
