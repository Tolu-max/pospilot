# SendByte transactional email

POSPilot keeps Laravel's native authentication notifications and uses the `sendbyte` SMTP mailer for delivery. Email verification and password reset keep their existing signed/token flows. The app also sends account security notices after password changes/resets and alerts the previous email address when the account email changes.

## Runtime configuration

Configure these values in the application environment or deployment secret store. Never put a live key in source control, browser code, logs, or support tickets.

```dotenv
MAIL_MAILER=sendbyte
MAIL_FROM_ADDRESS=security@pospilot.tconnect.com.ng
MAIL_FROM_NAME=POSPilot
SENDBYTE_API_KEY=<SendByte live API key>
SENDBYTE_SMTP_HOST=smtp.sendbyte.africa
SENDBYTE_SMTP_PORT=2525
SENDBYTE_SMTP_SCHEME=smtp
SENDBYTE_SMTP_USERNAME=apikey
SENDBYTE_SMTP_TIMEOUT=15
```

The mailer reads the API key from `SENDBYTE_API_KEY`; SMTP user is the literal `apikey`. POSPilot uses port 2525 with SMTP opportunistic STARTTLS and a 15-second transport timeout. SendByte also documents STARTTLS on port 587 and implicit TLS on port 465; configure the scheme to match if the hosting network blocks port 2525. After changing production variables, rebuild Laravel's configuration cache and restart the app and queue workers. Local development can keep `MAIL_MAILER=log` or use a SendByte test key. Test keys simulate delivery and never reach a real inbox.

## Sender domain and DNS

The SendByte dashboard domain is `pospilot.tconnect.com.ng`. The existing `pospilot` A record and root mail records were preserved. SendByte's DKIM TXT record and dedicated subdomain SPF and DMARC TXT records were published in the `tconnect.com.ng` DNS zone. The existing root SPF record remains unchanged. SendByte needs the domain verified before live sends; check the Domains page and Email log for the current verification and delivery state.

Use a SendByte **live** API key with the `send_only` scope for POSPilot. The dashboard reveals each key once, so place it directly into the secret store. Do not use the dashboard's test key for real delivery.

## Failure handling and privacy

Transport failures are caught at the auth/account notification boundary. The app logs only a notification type and exception class, without recipient addresses, message content, signed URLs, reset tokens, or provider credentials. Verification can be retried from the verification screen; password reset keeps the same generic response whether or not an account matches.

Authentication and security emails contain only an account action and brief security instructions. Do not include balances, transaction histories, account numbers, POS provider credentials, PINs, OTPs, or customer data. Security notices say only that a password or email address changed and direct the recipient to sign in.

## Checks

Run `php artisan test --compact tests/Feature/Auth/EmailVerificationTest.php tests/Feature/Auth/PasswordResetTest.php tests/Feature/Auth/PasswordUpdateTest.php tests/Feature/ProfileTest.php`, `npm run build`, and `vendor/bin/pint --dirty --format agent` after changes. Confirm a real verification email and password reset in SendByte's Email log before considering live delivery verified.

References: [SendByte SMTP guide](https://docs.sendbyte.africa/guides/smtp), [SendByte domain setup](https://docs.sendbyte.africa/guides/domains), [SendByte API keys](https://docs.sendbyte.africa/api-reference/authentication), [Laravel verification notifications](https://laravel.com/docs/13.x/verification), [Laravel password resets](https://laravel.com/docs/13.x/passwords).
