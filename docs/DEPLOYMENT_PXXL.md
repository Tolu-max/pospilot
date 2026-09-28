# Pxxl deployment readiness (historical assessment only)

> Pxxl is not POSPilot's current deployment target. The active host is Go54 shared hosting with DirectAdmin at `https://pospilot.tconnect.com.ng/`. AI coding tools must follow [`AI_DEPLOYMENT_GO54.md`](AI_DEPLOYMENT_GO54.md) before preparing or uploading a release.

## Status

This repository has **not** been deployed to Pxxl. No Pxxl account, production domain, production database, external secret-store adapter, or production credentials were supplied. Vendor guides describe Git-based deployment, environment variables, managed HTTPS, and separate background worker processes, but the project has not been exercised on the platform. Confirm current capabilities and limits in the Pxxl account before committing to it.

## Laravel deployment requirements

- PHP 8.3 with the extensions required by `composer.lock`; Composer install with optimized autoloading.
- Node build: `npm ci` then `npm run build`; serve Laravel's `public/` directory.
- Persistent MySQL database and a private, durable storage location for Laravel runtime files where needed.
- `APP_ENV=production`, `APP_DEBUG=false`, a unique generated `APP_KEY`, correct HTTPS `APP_URL`, and explicit trusted proxy settings for the platform's proxy chain.
- `SESSION_DRIVER=database` (or another supported shared store), secure cookies, correct session domain, and cache/queue drivers shared across web and worker processes.
- Run reviewed migrations as a release step; never use `migrate:fresh` in production.
- Run a supervised queue worker for queued mail/import/sync work and a scheduler process/cron for scheduled commands. Confirm Pxxl supports persistent workers and a scheduler before launch; do not rely on a web request to run either.
- Configure outbound mail through SendByte only after sandbox testing and domain verification.
- Set `PROVIDER_SECRET_STORE=external` and a reviewed `PROVIDER_SECRET_STORE_CLASS`; the repository's database secret store is local/test only and refuses production. Do not connect Moniepoint until a real KMS/secret manager adapter passes security tests.
- Configure Google OAuth only with real registered production redirect URLs. Keep analytics disabled until the exact Sabilytics snippet and data handling are verified.
- Configure HTTPS, backups, access control, retention, error monitoring, and log/header redaction. Logs must not contain cookies, OAuth/provider secrets, webhook signatures, or financial payloads.

## Environment inventory

Required core settings include `APP_KEY`, `APP_ENV`, `APP_DEBUG`, `APP_URL`, `DB_*`, `SESSION_*`, `CACHE_STORE`, `QUEUE_CONNECTION`, `MAIL_MAILER`, and `MAIL_FROM_*`. Optional integrations use `GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`, `GOOGLE_REDIRECT_URI`, `SENDBYTE_API_KEY`, `SENDBYTE_SMTP_*`, and the provider secret-store adapter settings. Never put production values in `.env.example`, Git, Postman, screenshots, or support logs.

## Release gate

Require a clean production frontend build, full test suite, Pint, Composer audit, configuration-cache smoke check, TLS/session-cookie check, backup/restore check, queue/mail test, and a staging security regression run. Production Moniepoint sync remains disabled until official production endpoint/payload requirements are independently verified and the external secret-store gate passes.

References: [Pxxl background workers and cron guide](https://pxxl.app/guide/migrate-render-background-workers-crons-to-pxxl) and [Laravel task scheduling](https://laravel.com/docs/13.x/scheduling). The repository has not been deployed on Pxxl; confirm service availability, persistence, regions, backups, process supervision, and current plan limits in the Pxxl account before launch.
