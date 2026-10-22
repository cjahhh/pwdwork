# Phase 1 setup and verification

Persistent data lives in Laravel/MySQL. No application data is read from browser storage. Legacy browser records are deliberately left untouched; they are not automatically uploaded because their provenance and IDs cannot be trusted. Export and review them manually before any migration. Historical examples in src/data/jobs.js are inactive; the development examples remain in PwdWorkSeeder.

## Environment

Set backend/.env locally; never commit credentials:

```dotenv
APP_ENV=local
APP_URL=http://localhost:8000
FRONTEND_URL=http://localhost:5173
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=pwdwork
DB_USERNAME=your_local_user
DB_PASSWORD=your_local_password
SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
SESSION_SECURE_COOKIE=false
SANCTUM_STATEFUL_DOMAINS=localhost:5173,localhost:8000
MAIL_MAILER=smtp
MAIL_HOST=your_smtp_host
MAIL_PORT=587
MAIL_USERNAME=your_smtp_username
MAIL_PASSWORD=your_smtp_password
MAIL_SCHEME=smtp
MAIL_FROM_ADDRESS=no-reply@your-domain.example
MAIL_FROM_NAME=PWDWork
```

Use one hostname consistently. If using 127.0.0.1, change APP_URL, FRONTEND_URL and SANCTUM_STATEFUL_DOMAINS together. FRONTEND_URL is a single trusted URL used in reset emails. Use HTTPS, SESSION_SECURE_COOKIE=true and your real same-site frontend/API hosts in production. Configure SESSION_DOMAIN only if needed for sibling subdomains. The first-party browser app uses Laravel session cookies with CSRF protection; external API tokens remain supported by Sanctum. The default frontend API URL is /api; Vite proxies it to Laravel. For a separate API host, set VITE_API_URL=https://api.example.com/api and configure CORS plus Sanctum domains. Never use a third-party cross-site API host for this session setup.

MAIL_MAILER=log is available for local development; it logs links and does not deliver email. SMTP credentials come from your mail provider. Laravel SMTP supports STARTTLS with smtp and provider-specific implicit TLS using smtps. Verify a real inbox delivery before deploying. Reset email responses report accepted requests without promising delivery; delivery exceptions are logged and retried by the worker. Run php artisan queue:work database --queue=password-resets --tries=3 to deliver reset emails. Every request queues the same job before any account lookup, limiting account enumeration through response timing. A 202 response means queued, not delivered; queue failures return a generic 503. Mail exceptions retry and enter Laravel failed jobs. Monitor failed jobs and retry after correcting SMTP configuration. Password broker tokens expire after 60 minutes, are hashed in MySQL, and are single use. Requests are limited to 5/minute/IP plus the broker's 60-second per-account throttle. Password reset revokes personal tokens and database sessions. Keep SESSION_DRIVER=database for revocation across all browser sessions.

## Apply

From backend, run php artisan config:clear, then php artisan migrate. Start the password-reset worker with php artisan queue:work database --queue=password-resets --tries=3. To populate an explicitly local demo database, run php artisan db:seed. Do not run the demo seeder on production data. Existing data is preserved by the migration. The seeder no longer deletes older job examples or overwrites account passwords. Demo credentials are public development data only. Existing accommodation labels are preserved; the new filters use exact standardized labels. Employers must review old entries and select the new labels themselves; no unverified claims are inferred for existing real jobs. All displayed accommodation claims are labeled employer-reported.

Run npm run build and npm run lint in the root. Run php artisan test in backend. Tests use an isolated in-memory SQLite database, never the configured MySQL database.

## Accessibility review

Target: WCAG 2.2 AA; no independent conformance claim. Native controls, keyboard focus, skip link, route focus, native modal focus management, persistent settings, reduced-motion preference, dark/high-contrast presentation, and optional browser read-aloud are implemented. The reading-font option uses Arial/Verdana with additional spacing; it does not load a specialized clinical font or guarantee reading outcomes. Read aloud is user initiated, can be stopped, and stops on navigation; external screen readers work without enabling a toggle.

Manual release checks: keyboard-only navigation and dialogs; NVDA/VoiceOver labels, errors and status announcements; 200% browser zoom and 320 CSS-pixel reflow; dark/high-contrast controls; reduced motion; mobile filters; login then refresh; logout/login persistence; offline operations and retry; expired sessions; password email delivery and expired/reused reset links. Automated checks do not establish full WCAG conformance.

## Completed verification (2026-10-22)

18 PHPUnit tests / 71 assertions passed, including persistence and account isolation, authorization and expired tokens, CSRF enforcement, combined search filters, interview synchronization, queued reset delivery, recovery rate limits, password confirmation, expiry and reuse, and preservation of demo data. The additive migrations were applied to local MySQL. The production Vite build and Oxlint passed. Headless Edge checks passed for actual cookie login, refresh restoration, logout/protected redirects, absence of sensitive localStorage writes, 320px filter layout without horizontal overflow, combined filters, persistent contrast settings, skip navigation, offline errors without sample data, and missing reset tokens. Real SMTP inbox delivery and manual assistive-technology conformance checks remain environment/release checks. The current local MAIL_MAILER=log does not deliver email.
