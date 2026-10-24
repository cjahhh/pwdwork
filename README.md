# PWDWork

An inclusive job platform for persons with disabilities in the Philippines. React/Vite provides the browser application; Laravel 12, Sanctum session authentication, and MySQL/MariaDB provide the API and persistent data.

Phase 1.5 fixes the Phase 1 audit findings. Phase 2 has not been implemented. See [PHASE1-SETUP.md](PHASE1-SETUP.md) for API, privacy, and deployment details and [PWDWORK_IMPLEMENTATION_REPORT.md](PWDWORK_IMPLEMENTATION_REPORT.md) for verification results and limitations.

## Requirements

- PHP 8.2+ and Composer 2, with Laravel's required extensions plus PDO MySQL; tests also need PDO SQLite.
- Node.js supported by the locked Vite version (Node 24 was used for verification), and npm.
- MySQL or MariaDB for application data. XAMPP is one local option; deployment is not tied to XAMPP or Windows.
- A background worker and SMTP provider for delivered password-reset emails.

## Install

Run commands from the repository root unless indicated. Use existing env files when present; copy the examples only for a new installation. Keep credentials out of Git.

```powershell
npm ci
Copy-Item .env.example .env
Set-Location backend
composer install --prefer-dist --no-interaction
Copy-Item .env.example .env
php artisan key:generate
```

Generate APP_KEY only for a new installation. Existing installations must retain their key. Configure backend/.env with the database host, database name, and credentials; use your database administrator/provider to create an **empty database** first. Local MySQL must be running. Then, from backend:

```powershell
php artisan migrate:status
php artisan migrate
```

Laravel migrations are the authoritative schema. Existing installations should back up first, inspect pending migrations, and apply them using the same additive command. The Phase 1.5 migration adds nullable experience and an accommodation-sharing flag defaulting to false; it never rewrites application statuses. The historical database/sql/pwdwork.sql import is retired and contains comments only.

Optional demo data: run `php artisan db:seed` only in an explicitly disposable local demo database. The seeder preserves passwords but can update matching demo profiles/jobs/statuses, so it is unsuitable for real data. Demo emails and the password `password` are development fixtures. No production administrator is automatically provisioned.

## Run locally

Use localhost consistently in the browser, APP_URL, FRONTEND_URL, CORS_ALLOWED_ORIGINS, and SANCTUM_STATEFUL_DOMAINS.

```powershell
# Terminal 1, from backend
php artisan serve --host=127.0.0.1 --port=8000
```

```powershell
# Terminal 2, from the repository root
npm run dev
```

```powershell
# Terminal 3, from backend
php artisan queue:work database --queue=password-resets,default --tries=3 --timeout=60
```

Open http://localhost:5173. Vite forwards /api to Laravel. http://localhost:8000/api/health reports API liveness. The worker command matches the example queue settings; adjust connection and queue together if configured differently.

`MAIL_MAILER=log` is local-only: reset links go to the configured logs, not an inbox. Configure SMTP and verify real inbox delivery before release. The reset endpoint returns 202 when queued, and 503 when enqueueing fails.

## Verification

```powershell
# Repository root
npm run build
npm run lint

# backend
php artisan test
composer validate --no-check-publish
```

Optional integrated browser checks from the root: `node scripts/browser-smoke.mjs`. This requires Chrome/Edge (or BROWSER_PATH) and free ports 5173, 8000, and 9222. It starts hidden headless browser/local servers, initializes a new temporary SQLite database with fake records, tests React against Laravel, and removes only that run's temporary files. It never uses the configured MySQL database. Real SMTP delivery is not part of this check.

Committed backend/.env.testing and forced phpunit.xml settings use SQLite :memory:, array sessions/cache/mail, and a public test-only key. Tests refuse to start against any other default database. No existing MySQL records are used. A cached production configuration causes the safety guard to fail; run checks in a separate development/test checkout rather than clearing a live application's cache. The test suite internally builds only disposable in-memory schemas.

## Cloud deployment

The app supports a portable PHP runtime plus managed MySQL/MariaDB and static frontend hosting. Hosting must support an asynchronous queue worker. See [PHASE1-SETUP.md](PHASE1-SETUP.md#cloud-deployment-requirements) before choosing configuration. Nothing is deployed by this repository's setup commands.

Production needs HTTPS, APP_ENV=production, APP_DEBUG=false, secure session cookies, a consistent secret APP_KEY, exact trusted frontend domains, shared cache/database sessions, private durable resume storage when used, logs, backups, and supervised workers. Serve Laravel from backend/public and the frontend from dist; configure SPA fallback and API routing. Vite's development proxy does not become a production proxy.

## Scope

Implemented: registration/login/logout, password recovery, persisted professional profiles, job search and combined filters, employer listings, saved jobs, applications, distinct shortlisting/hiring, interviews, applicant-controlled accommodation sharing, and pagination.

Deferred to later phases: admin verification/user/report workflows, resume uploads, notification management/delivery, and any new matching features. Existing resume references can be downloaded through the authorized employer endpoint when their private files exist. Manual accessibility and production service checks remain release requirements.
