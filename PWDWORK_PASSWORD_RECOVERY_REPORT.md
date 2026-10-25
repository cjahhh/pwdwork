# PWDWork password recovery — Gmail local testing and Brevo HTTPS

Implemented in the existing repository on 25 October 2026. Actual `.env` values and real Gmail app passwords were not inspected or edited. No real email was sent, no existing MySQL records were changed, and no migration, push or deployment was performed. External delivery requires your private configuration and a manual inbox test.

## 1. Existing functionality reused

- Installed Laravel **12.69.3**, Sanctum first-party cookies, React Forgot Password and Reset Password pages, and the existing `/api/forgot-password` and `/api/reset-password` endpoints.
- Laravel's built-in password broker and the existing `password_reset_tokens` table in the initial users migration. Tokens are stored hashed, expire after **60 minutes**, and are deleted after successful use. No new schema is required.
- Existing password validation: minimum eight characters, confirmation required, maximum **72 UTF-8 bytes** to avoid bcrypt truncation. Registration/login rules are unchanged.
- Generic reset-request confirmation, broker token-generation throttle of **60 seconds**, request limits of **five requests/minute** and **ten reset attempts/minute**, shared API throttling and CSRF protection.
- Password hashing, remember-token rotation, personal-access-token revocation and the target user's database-session deletion. Resetting a password does not change role or suspended/inactive status and does not log the user in.
- Existing encrypted recovery job, dedicated queue configuration and database/Redis worker option.

## 2. Changes implemented

- PWDWork-branded HTML and plain-text email, with expiry, a single-use reset action, an unrequested-email explanation and a private-link reminder.
- Explicit `PASSWORD_RESET_MAILER` selection. Gmail uses Laravel's existing SMTP transport; Brevo uses a new HTTPS mail transport. Both send the same broker notification/mailable and use the same reset endpoint. No provider is switched automatically.
- Laravel 12 SMTP keys: `MAIL_SCHEME`, SMTP host/port/credentials, bounded `MAIL_TIMEOUT` and `MAIL_REQUIRE_TLS`. This installed version's Symfony SMTP factory supports required STARTTLS. Legacy `MAIL_ENCRYPTION=tls` is not the configuration used by this project.
- Existing `deferred` queue support is documented for local testing: delivery runs after the response in the same PHP process, without a separate worker. `sync`, `null`, unknown and unreviewed failover queue configurations are rejected before creating a pending dispatch or looking up an account. The default code fallback remains `database`; changing your existing `.env` is your manual step.
- Recovery refuses log, log-failover and round-robin mailers. Delivery exceptions are replaced with generic exceptions without the original exception chain; dispatch logging contains a static message only. Provider responses, SMTP transcripts, keys, email bodies and reset URLs are not logged by this flow.
- New links use `FRONTEND_URL/reset-password#token=...&email=...`. The fragment is not sent in HTTP requests. React reads the fragment and continues to accept older query-string links. Successful resets clear URL secrets and hide the completed form; reopening a link shows the reset form and replay is rejected by the broker.
- An origin-only `strict-origin` referrer policy protects URL details while preserving Sanctum's stateful API detection. A blanket no-referrer policy was rejected after browser testing showed it broke authenticated GET/session restoration.
- An outer database transaction and token-row lock keep broker validation and token consumption together on MySQL. Real MySQL concurrency testing was not performed against your data.
- Password email/reset limits retain their existing values but now have separate named IP buckets, preventing unrelated numeric throttle middleware from consuming the reset budget.
- Browser regression email capture uses only a guarded temporary SQLite test inbox. It no longer prints reset tokens to mail logs or extracts them from queue-worker output. This test router is not used by `public/index.php` and has no production inbox endpoint.

## 3. Exact local Gmail configuration — make these edits yourself

Use your Google Account settings privately. App passwords require two-step verification; managed accounts, Advanced Protection and account policy can make the option unavailable. Do not send an app password in chat, screenshots, commits or support messages. [Google app-password instructions](https://support.google.com/accounts/answer/185833).

In **your existing `backend/.env`**, manually review/update only the following applicable values. The credential strings below are placeholders, not usable secrets. Keep your database, application key, sessions and other unrelated settings.

```dotenv
APP_ENV=local
FRONTEND_URL=http://localhost:5173

PASSWORD_RESET_MAILER=smtp
PASSWORD_RESET_QUEUE_CONNECTION=deferred
PASSWORD_RESET_QUEUE=password-resets

MAIL_SCHEME=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME="your-test-sender@gmail.com"
MAIL_PASSWORD="ENTER_YOUR_APP_PASSWORD_PRIVATELY"
MAIL_REQUIRE_TLS=true
MAIL_TIMEOUT=10
MAIL_FROM_ADDRESS="your-test-sender@gmail.com"
MAIL_FROM_NAME="PWDWork"
```

Use your full Gmail address and the account's app password, not its normal sign-in password. Paste the app password privately without presentation/grouping spaces. Keep the From address equal to the authenticated address or a Gmail-authorized sender alias. If `MAIL_URL` is already present, review and remove/clear it yourself for this configuration: a connection URL can override separate SMTP settings. Do not paste its value here. Gmail's alternative implicit-TLS configuration is `MAIL_PORT=465` with `MAIL_SCHEME=smtps`; use one configuration consistently. Do not disable certificate verification. [Google SMTP setup](https://support.google.com/a/answer/176600).

`MAIL_MAILER` may remain your existing value: the explicit password-reset mailer applies only to this workflow. If you want all application mail sent through Gmail, you can separately choose `MAIL_MAILER=smtp` yourself. No such change was made automatically. `QUEUE_CONNECTION` is also independent of `PASSWORD_RESET_QUEUE_CONNECTION`.

After your edits, from the backend folder:

```powershell
php artisan config:clear
```

Restart the local PHP server/Apache process if needed. For the standalone development server, use `php artisan serve --host=localhost --port=8000` in `backend`; use `npm.cmd run dev` in the repository root for Vite. If your current XAMPP API routing already works, retain it rather than starting a conflicting second server. `FRONTEND_URL` must be the actual browser-facing React origin; keep existing CORS/Sanctum origin settings consistent with your chosen ports. Never put SMTP credentials or provider API keys into `VITE_*` variables.

For XAMPP TLS errors, enable/use the existing PHP OpenSSL/cURL support and configure a trusted CA bundle in the PHP configuration actually used by Apache or CLI. Do not set `verify_peer=false`, disable HTTPS verification or revert to unencrypted SMTP. These machine settings and network/firewall permissions were not changed by this integration.

## 4. Manual end-to-end inbox test

1. Use a disposable test account/mailbox you control, ideally in a separate test database. Do not reset an existing person's account as a delivery test. This implementation itself did not create or reset any account in your MySQL database.
2. Start the existing React/API application after entering the local settings above. With `deferred`, no `queue:work` command is required for password email.
3. Open `/forgot-password`, enter that test account's email and submit. The generic “Request accepted” message is the same for registered and unknown addresses and does not guarantee inbox delivery.
4. Check inbox/spam for **Reset your PWDWork password**, with PWDWork branding and the configured sender. Click the button or copy the complete link, including the `#` fragment, into the browser.
5. Confirm that the React reset page opens, email is prefilled and both password fields are labeled. Choose a matching password of at least eight characters and no more than 72 UTF-8 bytes.
6. Submit. The page should confirm success, clear the token from the URL and offer sign-in. Sign in with the new password; the old password should fail.
7. Reopen the same email link and submit again: it must fail with the generic invalid/expired-link message. Requesting a newer link invalidates the older token. Wait at least 60 seconds between requests for a fresh broker token.
8. Check that an unknown email receives the same request confirmation. Suspended accounts remain unable to sign in even after a valid password reset.

Do not inspect logs for a reset link. A missing email may reflect configuration, SMTP connectivity, provider rejection, spam filtering or the broker throttle. After fixing delivery configuration, wait for the 60-second throttle and request a new link. Deferred delivery is best effort, has no persisted retry job and can be interrupted if PHP exits. For durable delivery, explicitly select the existing `database` recovery connection and run a supervised worker:

```powershell
php artisan queue:work database --queue=password-resets --tries=3 --timeout=60
```

Do not switch recovery to `sync` to troubleshoot; it can reveal account existence through request timing or provider errors. Keep API errors generic, and use the provider's protected delivery dashboard to diagnose rejection. The provider necessarily handles the email/link; review its retention and disable click/open tracking for sensitive authentication messages where available.

## 5. Render-compatible delivery — explicit HTTPS configuration

Render Free blocks outbound SMTP ports **25, 465 and 587**, so Gmail SMTP is a local option. The new Brevo transport sends only to the fixed `https://api.brevo.com/v3/smtp/email` HTTPS endpoint, with TLS verification, a five-second connection timeout, ten-second total timeout, redirects disabled and no SMTP/log fallback. API acceptance is not proof of inbox delivery. [Render Free limitations](https://render.com/docs/free), [Brevo transactional API](https://developers.brevo.com/docs/send-a-transactional-email).

Create/configure the provider account yourself, obtain transactional-email access and verify the sender/domain as required. Enter a Brevo **API key**, not its SMTP key, privately in your hosting secrets. Verify applicable DNS records, quotas, account approval and provider tracking/retention. Brevo currently documents a free-plan allowance of 300 sends/day; recheck before deployment. [Sender/API setup](https://developers.brevo.com/docs/send-a-transactional-email), [free-plan limits](https://help.brevo.com/hc/en-us/articles/208580669-FAQs-What-are-the-limits-of-the-Free-plan).

The future server-side configuration is explicit, for example:

```dotenv
PASSWORD_RESET_MAILER=brevo
BREVO_API_KEY="ENTER_YOUR_VERIFIED_PROVIDER_API_KEY_PRIVATELY"
MAIL_FROM_ADDRESS="verified-sender@your-domain.example"
MAIL_FROM_NAME="PWDWork"
FRONTEND_URL=https://your-real-react-host.example
```

Optionally choose `MAIL_MAILER=brevo` yourself if it should also send the application's other mail. SMTP settings are unused by this selected transport and are not copied into the HTTP request. The integration requires no new SDK/package and makes no external request until explicitly selected and used.

Delivery scheduling is a separate deployment decision. Laravel's `deferred` connection can perform the HTTPS send without another worker, but it is not durable and remains best effort on a sleeping/restarting Free service. A reliable database/Redis queue requires an explicitly provisioned, supervised worker; do not leave queued mail waiting for a worker that does not exist. The existing production-readiness checker intentionally retains its durable-queue requirements and additionally recognizes/checks Brevo and the selected reset mailer. This change does not certify a Render Free deployment as production-ready. [Laravel deferred dispatching](https://laravel.com/docs/12.x/queues#synchronous-dispatching).

The existing patched-PHP, persistent/private storage, Aiven TLS, support/policy and operational readiness requirements remain. Aiven is not an email delivery provider. No Render or Aiven account/resource was accessed or changed.

## 6. Automated verification

- Password-recovery feature class: **15 tests / 158 assertions passed** using Laravel Notification/Mail fakes, a real in-memory array mail transport and mocked HTTPS delivery.
- Full Laravel suite: **114 tests / 1,181 assertions passed**. Tests refuse non-isolated databases; MySQL is not used.
- Covered: valid/incorrect/expired/replaced/replayed tokens, wrong email, confirmation, short passwords and bcrypt byte limits; broker/request throttles; unknown-email privacy; sender/template/fragment URL generation; worker-free delivery; log/failover refusal; missing credentials; HTTP rejection/redirect/timeout behavior; safe exceptions/logs; session/token revocation; suspended applicant/employer/admin sign-in restrictions; existing login/registration and business regressions.
- React production build, Oxlint and ESLint passed. Laravel Pint passed. PHP syntax passed for **105** application/bootstrap/config/route/test/database sources.
- **All 14 browser workflow groups passed**, including recovery without a worker, fragment links, reset completion, URL cleanup, reopening/replay rejection, absence of reset tokens in captured server logs, login/registration, admin/applicant/employer workflows, sharing and revocation. Browser mail uses a guarded synthetic inbox in the test run's temporary directory and an array transport, never Gmail or a real provider key. The fresh production-build run is recorded in ignored `backend/storage/framework/cache/password-recovery-browser.log`.

Early tests caught and corrected: a referrer policy that prevented Sanctum cookie restoration, same-document hash-link reopening after successful reset, the shared numeric rate-limit bucket, and pending-dispatch destructor side effects if connection validation happened after creating the dispatch object. Final automated results above are fresh, not inherited from earlier UI work.

## 7. Remaining manual work and limits

- Enter your private local Gmail settings yourself, clear cached configuration and perform the disposable-account inbox test. No actual Gmail handshake, sender login or real inbox delivery was attempted here.
- Configure/verify the HTTPS provider and sender, then test acceptance, inbox delivery and provider quotas on separate staging before deployment. No real API key was configured and no provider account was created.
- Confirm PHP/CA/network settings, production HTTPS frontend URLs, worker/delivery scheduling, log redaction/retention and provider tracking settings. Do not enable HTTP/SMTP debug-body logging for recovery mail.
- Validate real MySQL concurrent resets on separate approved staging data; SQLite tests do not prove MySQL lock behavior. Existing database tables/migrations are reused; no migration is required by this integration.
- The secret-handling checks apply to this application flow. Mail providers, mailbox clients, browser history/extensions and third-party monitoring need their own access and retention controls. No secrets were read or printed by the implementation tools.

Relevant implementation: `backend/app/Services/PasswordRecoveryMail.php`, `app/Mail/PasswordResetEmail.php`, `app/Mail/Transport/BrevoTransport.php`, reset email views, `PasswordController`, `SendPasswordResetLink`, `AppServiceProvider`, mail/services configuration, `.env.example`, production checks, React recovery page, the HTML referrer policy, feature tests and the isolated browser mail-capture router. Existing local `.env` values were left intact.
