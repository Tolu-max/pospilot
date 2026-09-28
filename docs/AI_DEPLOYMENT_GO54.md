# POSPilot deployment guide for AI coding tools

This is an operational guide for coding agents working in this repository. Read it before preparing or uploading a deployment.

## Active hosting target

- Host: Go54 shared hosting, managed through the Go54 customer panel and DirectAdmin File Manager.
- Public application: `https://pospilot.tconnect.com.ng/`.
- Do not deploy this project to Pxxl or another host unless the owner explicitly changes the target.
- The current Go54 setup uses a Laravel application directory outside the public document root and a small front-controller shim in the domain document root. The shim points at the active application path. Inspect the live deployment layout before each release; never infer that `public_html` is the Laravel project root.
- The active app has used a `current` path/release layout. Treat the live `current` target and all release directories as production data. Verify the target before writing anything.

## Before changing production

1. Inspect `git status` and preserve all existing changes. Never reset, clean, or overwrite user work.
2. Read the applicable project instructions and the relevant Laravel/Inertia/test skills.
3. Make and review the smallest necessary code change locally.
4. Run the affected tests. For a release, run the full Laravel suite, `vendor/bin/pint --dirty --format agent`, `npm run build`, and `composer audit` when available.
5. Inspect the diff for credentials, account details, real statement data, customer data, local absolute paths, or generated development artifacts. None may be uploaded or committed.
6. Do not use production demo seeders or QA fixtures. Production gets migrations only; never run `migrate:fresh`, seeders, or destructive database operations against the live database.

## Go54 upload workflow

The current deployment is managed through the Go54 panel and DirectAdmin, not Pxxl's Git deployment flow. The exact release workflow and file-manager features must be confirmed in the live account each time; do not claim a deploy succeeded merely because files were uploaded.

1. Open the existing Go54 hosting service and use its DirectAdmin/File Manager entry. Do not put the source application in the public domain document root.
2. Identify the active Laravel application path and the document-root front controller. Confirm that the front controller resolves to the intended application release. Do not open, copy, screenshot, or print `.env` or any secret-bearing file.
3. Before changing active files, make a recoverable backup of the current application release and confirm how to restore it. If the UI cannot create/verify a separate release or recoverable backup, stop and ask the owner to perform the release activation step; do not edit the live release in place.
4. Upload only the reviewed release files into a new release directory that mirrors the Laravel project structure. Preserve the existing `.env`, `vendor/`, `storage/`, user uploads, logs, and runtime state. Never upload a local `.env`, `node_modules/`, test database, QA fixture, real provider statement, or the whole working tree over production.
5. If frontend/shared assets changed, upload the locally built `public/build/` output together with its Vite manifest to that release. Do not upload raw source alone and assume Vite will build it on Go54.
6. Confirm required PHP extensions/runtime and dependency compatibility. If `composer.lock` changed, use a supported Go54 SSH/Composer deployment path in the new release. Do not replace production `vendor/` with an unverified local directory. If no supported path exists, stop and report the dependency blocker.
7. Run reviewed additive migrations with `php artisan migrate --force` only after confirming the correct release and production database. Never run `migrate:fresh`, `db:wipe`, test commands that touch production, or seeders.
8. Refresh Laravel's cached configuration/routes/views using the supported CLI path after deployment or environment changes. Verify health, login, CSRF/session cookies, mail, queues, and primary application routes on HTTPS.
9. Verify the live release and key user journeys in Chrome. Check server/browser errors and inspect application logs only with secret and financial-data redaction.
10. Keep the previous release available until smoke tests pass. If the deployment is unhealthy, restore the previous release using the verified Go54 process and report what was rolled back.

## Secrets, financial data, and provider safety

- Never read secrets aloud, put them in tool output, chat, Git, screenshots, URLs, or logs.
- Do not upload or commit `.env`, OAuth client secrets/tokens, provider credentials, Gmail tokens, database passwords, or backup files.
- Change production environment values only in Go54's protected environment/configuration mechanism, with explicit owner authorization. Verify presence by boolean/status only; never echo values.
- Keep normal Google Sign-In OAuth separate from Gmail statement OAuth. The Gmail callback currently implemented in Laravel is `https://pospilot.tconnect.com.ng/integrations/gmail/callback`; register that exact HTTPS URI only on the dedicated Gmail OAuth client.
- Gmail statement access requests `https://www.googleapis.com/auth/gmail.readonly`. This is a restricted scope and technically allows broad Gmail message/settings reading. Provider sender/query rules limit what POSPilot processes, not the permission Google grants. Never accept the consent step without the owner's explicit action-time authorization.
- Production Gmail connection must fail closed unless the dedicated OAuth client and reviewed production `GmailCredentialStore` are configured. Do not switch the production token store to the local encrypted-database implementation to get around this gate.
- Never enable Moniepoint direct sync unless `FEATURE_MONIEPOINT_DIRECT` and the existing external provider secret-store/security gates have been deliberately reviewed. OPay and PalmPay direct APIs are not part of this release.
- Real transaction statements and customer/merchant identifiers remain private: inspect only what is necessary, do not add them to docs/tests/fixtures/logs, and delete temporary local copies after verification.

## Queue and scheduler

Gmail sync and statement processing use Laravel queue jobs. The scheduler entry is configured for a 15-minute interval, but it will not run reliably unless Go54 provides a working cron/scheduler and a durable queue worker. Verify the available Go54 cron/SSH features and configured queue backend before enabling Gmail. Do not claim scheduled sync or queued sync works until a job is observed completing and the app status reflects the result. Never process all Gmail synchronously in a browser request.

## Deployment record

After an actual deployment, append a dated entry to `docs/BUILD_LOG.md` recording only verified facts: release identifier (not secrets), migration result, build/test status, live URL smoke test, and any remaining blockers. Do not record credentials, real statement rows, customer information, or unverified claims.
