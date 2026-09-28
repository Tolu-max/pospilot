# Sabilytics integration

POSPilot is registered in Sabilytics as `Pospilot` for `pospilot.tconnect.com.ng`.
The account-provided site ID is `arsmn1oxr2nl` (public tracking identifier, not a secret).

The official React install instructions provide this script configuration:

```html
<script
  async
  src="https://www.sabilytics.com/script.js"
  data-site="arsmn1oxr2nl"
  data-domain="pospilot.tconnect.com.ng"
></script>
```

POSPilot loads the same source, site ID, and domain once from its Inertia React
root. It loads only when the browser hostname is the registered production
domain. Sabilytics observes the History API and `popstate`, so its pageview
tracking covers Inertia route changes and browser back/forward navigation
without a second pageview hook.

## Custom events

The frontend uses `sabilytics.track` through a strict event allowlist. Metadata
is fixed in code; handlers cannot pass arbitrary values.

| Event | Safe metadata |
| --- | --- |
| `signup_completed` | none |
| `login_completed` | none |
| `onboarding_completed` | `feature: onboarding` |
| `gmail_connected` | `source: gmail` |
| `statement_sync_started` | `source: gmail` |
| `statement_imported` | `source: gmail` |
| `provider_connection_started` | `provider: moniepoint` |
| `daily_closing_completed` | `feature: daily_closing` |

Completion events from password/Google signup, password/Google login, and Gmail
OAuth use one-time server session flash envelopes. Other events fire inside the
successful user action handler. Analytics exceptions are swallowed so they do
not interrupt POSPilot actions.

No amounts, balances, earnings, account numbers, terminal IDs, provider
references/RRNs, email addresses, names, credentials, OAuth tokens, or statement
contents are supplied as custom event metadata. The official pageview tracker
records the current pathname and query string; POSPilot pages must not put
financial values, credentials, email addresses, or provider references in URLs.

The dashboard showed the site as awaiting installation before this change.
Traffic and custom-event receipt must be confirmed in that dashboard after the
production release is active; local development traffic is intentionally not
sent to this site.
