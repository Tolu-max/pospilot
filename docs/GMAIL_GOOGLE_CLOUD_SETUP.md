# Gmail OAuth setup for POSPilot

## Current Google Cloud status

The POSPilot Google OAuth app is currently configured as **External / In production**, but Gmail restricted-scope verification is incomplete. Google will show an unverified-app warning for the restricted `gmail.readonly` request. Its current OAuth user cap is 100 lifetime users, not two. Google Cloud does not offer a two-user allowlist while the app is In production. Do not describe this app as Google verified or restrict claims to two users unless POSPilot enforces a separate access gate.

Switching the app to **Testing** would restrict authorization to up to 100 explicitly listed Google accounts and make test authorizations expire after seven days. Since the production app is currently public, use a separate Cloud project for future Testing environments when practical.

## Google Cloud project

1. Select or create the Google Cloud project for POSPilot.
2. Open **APIs & Services → Library**, enable **Gmail API**, and confirm it is enabled for this project.
3. Open **Google Auth Platform** (or **APIs & Services → OAuth consent screen**, depending on the current console layout).
4. Configure the app name, support email, developer contact, and public privacy-policy/homepage links.
5. Set the audience to **External**. The current POSPilot project is **In production**; Google Cloud does not provide a two-account limit in this mode. If setting up a separate test project, leave it in **Testing** and add only intended testers there.
6. Testing-mode projects require project owner/tester Google accounts to be added as **Test users**. Do not hardcode tester email addresses in POSPilot.
7. Add only `https://www.googleapis.com/auth/gmail.readonly` under data access. It is a **restricted** scope. Do not add `https://mail.google.com/`, `gmail.modify`, `gmail.send`, or `gmail.compose`.
8. Create an OAuth client with application type **Web application**.
9. Add this exact authorized redirect URI:

   `https://pospilot.tconnect.com.ng/integrations/gmail/callback`

The deployed Laravel callback route is `GET /integrations/gmail/callback`. Google compares redirect URIs exactly; scheme, host, path, and trailing slash must match.

## Production environment values

Set these values in Go54's protected application environment/configuration. Never put a real secret in this document, Git, a screenshot, a query string, or a log.

```dotenv
FEATURE_GMAIL_STATEMENTS=true
GOOGLE_GMAIL_CLIENT_ID=<OAuth web client ID>
GOOGLE_GMAIL_CLIENT_SECRET=<OAuth web client secret>
GOOGLE_GMAIL_REDIRECT_URI=https://pospilot.tconnect.com.ng/integrations/gmail/callback
GMAIL_TOKEN_STORE=external
GMAIL_TOKEN_STORE_CLASS=<reviewed external GmailCredentialStore adapter>
```

Keep the existing `APP_KEY` stable and protected. Production uses a reviewed external `GmailCredentialStore` implementation configured in `GMAIL_TOKEN_STORE_CLASS`; the encrypted database adapter is local/testing only and refuses production. Do not place Gmail credentials in the normal Google Sign-In client configuration. After changing environment values, refresh Laravel's cached configuration using the deployment's supported CLI process.

The normal **Continue with Google** flow remains separate and requests identity/login scopes only. Gmail access is requested only after the signed-in user selects **Connect Gmail**.

## Production and testing limits

- In **Production**, any Google account can reach this app's consent flow, but users will see an unverified-app warning for unapproved restricted scopes. Google's current lifetime cap is 100 new users for unverified apps; it cannot be configured as a two-user cap.
- In **Testing**, only listed Google accounts can authorize. For external apps requesting Gmail scopes, test authorizations/refresh tokens expire after about seven days.
- `gmail.readonly` remains a restricted scope. Publishing to Production does not mean Google verification is complete. Gmail data access remains subject to restricted-scope verification and any applicable security assessment.
- If only two known testers should connect, keep the app in Testing and add their Google accounts, or implement and deploy a separate POSPilot-side access gate. Do not claim a two-user limit until that gate exists.

## Verify the flow

1. Confirm the OAuth client is a Web application and the redirect URI exactly matches the value above.
2. Confirm Gmail API is enabled, the only Gmail scope is `gmail.readonly`, and intended testers appear in the test-user list.
3. Confirm all four environment values are present without printing their contents.
4. Sign in to POSPilot, select OPay, PalmPay, and/or Moniepoint, then choose **Connect Gmail**.
5. Review the Google consent screen and authorize the requested read-only Gmail scope.
6. Confirm POSPilot shows only a masked Gmail address. Request or send a real provider statement to that Gmail inbox, then choose **Check for statements**.
7. Map an unrecognized CSV/XLSX schema to a configured terminal. POSPilot retains only encrypted attachment data temporarily until mapping or expiry; it does not retain message bodies.
8. Confirm the resulting transactions in POSPilot and repeat the check to verify idempotency. Disconnect Gmail to revoke access where possible and delete local tokens.

## Official references

- [Gmail API OAuth scopes](https://developers.google.com/workspace/gmail/api/auth/scopes)
- [OAuth app publishing status and Testing behavior](https://developers.google.com/identity/protocols/oauth2/production-readiness/overview)
- [OAuth 2.0 web-server applications](https://developers.google.com/identity/protocols/oauth2/web-server)
- [Gmail API search and filtering](https://developers.google.com/workspace/gmail/api/guides/filtering)
- [Gmail message attachment retrieval](https://developers.google.com/workspace/gmail/api/reference/rest/v1/users.messages.attachments/get)
