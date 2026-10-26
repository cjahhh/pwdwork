# Production password-reset delivery investigation

Investigated on 26 October 2026 using repository code, installed framework source, public provider documentation, and synthetic test accounts in guarded SQLite `:memory:`. No production credentials, user records, provider account, deployment, or private logs were accessed. No environment files, credentials, APP_KEY, database settings, migrations, startup restrictions, or deployment settings were changed. No public email-test endpoint was added. Nothing was committed, pushed, or deployed.

The production delivery root cause is **not yet proven**. The supplied symptoms do not establish whether the production job executes, the broker skips sending, Brevo rejects the request, or an accepted email fails downstream. A verified sender and an existing API key do not prove transactional-account activation, API-key validity, credits, or inbox delivery.

The confirmed code problem was insufficient diagnostics. The Brevo transport discarded both the HTTP status and original failure reason. The job replaced exceptions with a safe generic exception, and the production exception handler recorded only its class. Laravel rescues/reports deferred callback exceptions after the response, so these failures cannot change an already-sent HTTP 202. That response correctly means request accepted; it never proved email delivery. This patch fixes the observability gap while preserving the public behavior. It also rejects a malformed 201 response with an empty/whitespace message ID, which the previous code incorrectly accepted. There is no evidence that such a malformed response caused the reported incident.

The complete path is:

1. `src/pages/PasswordRecoveryPage.jsx` sends `POST /api/forgot-password`. Validation and existing IP/API rate limits run first.
2. `PasswordController::forgot()` validates the response-first/asynchronous connection before account lookup and dispatches `SendPasswordResetLink` to the separately configured password-reset connection/queue. Dispatch failures return the same generic 503 for any address. Accepted dispatches return the existing generic 202.
3. `SendPasswordResetLink::handle()` validates the selected mailer, then calls Laravel's password broker with the email. Unknown users and broker-throttled requests return without sending. Those broker outcomes remain private and are not logged.
4. For an eligible request, the broker stores a hashed token and sends Laravel's `ResetPassword` notification. `AppServiceProvider` registers its `toMailUsing()` callback, which builds `PasswordResetEmail` through `PasswordRecoveryMail`.
5. `PasswordRecoveryMail::message()` explicitly sets the mailable's mailer to `mail.password_reset_mailer`, falling back to `mail.default` only when no override is configured. Laravel's mail notification channel sends that mailable using its selected mailer. `PASSWORD_RESET_MAILER=brevo` therefore overrides a general log/SMTP mailer at runtime; it does not select the queue connection.
6. `AppServiceProvider` registers `BrevoTransport` using `config('services.brevo.key')`, sourced from `BREVO_API_KEY`. The sender comes from `MAIL_FROM_ADDRESS` and `MAIL_FROM_NAME`. `PASSWORD_RESET_QUEUE_CONNECTION` and `QUEUE_CONNECTION` are independently read by `config/queue.php`.
7. The transport posts JSON to the fixed `https://api.brevo.com/v3/smtp/email` endpoint, with an `api-key` header, JSON content/accept headers, `sender`, `to`, `subject`, and `htmlContent` or `textContent`. It requires HTTP 201 plus a nonempty string `messageId`. TLS verification stays enabled, redirects stay disabled, and connection/total timeouts remain 5/10 seconds. There is no SMTP or log fallback. This agrees with [Brevo's transactional API documentation](https://developers.brevo.com/docs/send-a-transactional-email).

Deferred execution is supported by the checked-in PHP-FPM request lifecycle. `backend/public/index.php` invokes Laravel's `Application::handleRequest()`. The installed implementation calls `$kernel->handle($request)->send()` before `$kernel->terminate(...)`. Symfony's response sender invokes `fastcgi_finish_request()` under PHP-FPM. Laravel's default `InvokeDeferredCallbacks` middleware then runs the deferred callback during termination for successful responses, including 202. The installed `DeferredQueue` schedules a callback that invokes the synchronous queue; it does not insert a database job or require a queue worker. The application does not remove this middleware.

The new lifecycle test calls the real kernel's handle and terminate phases separately: before termination there is no provider request or reset token; termination performs the HTTPS send once. The checked-in FPM configuration does not disable `fastcgi_finish_request`; its 10-second process-idle setting is not evidence that a busy deferred send is killed. Docker/PHP-FPM are unavailable in the current Windows test environment, so an actual container/FPM execution and the deployed runtime still need verification. See [Laravel deferred dispatching](https://laravel.com/docs/12.x/queues#deferred-dispatching) and [PHP response completion](https://www.php.net/manual/en/function.fastcgi-finish-request.php).

Deferred delivery remains best effort: `$tries` and `$backoff` do not give the synchronous deferred queue worker-style retries. An interrupted process can lose the send; a failed attempt does not create a durable failed-job entry through this path. HTTPS plus deferred execution works on one Render Free service without a worker, but cannot guarantee retry across restarts. A durable database queue requires a separately provisioned consumer; merely switching to database would leave mail waiting without one. No such architecture or configuration switch was made. Render documents Free service sleep/restart limits and no Free background-worker instance type: [Free service limits](https://render.com/docs/free).

`sync` remains rejected by both the password-recovery connection validator and the Docker startup check. Inline delivery would move account-dependent lookup/network work into the public response path and weaken the response-first design. The general production-readiness checker continues to require durable asynchronous delivery; this patch does not waive that check or claim deferred delivery is durable.

The dedicated `password_recovery` logging channel writes to stderr at info level. General application logging remains unchanged at warning or higher as required by startup validation. This narrow channel exists so stage events remain visible without enabling general debug/info logging. `PasswordRecoveryDiagnostics` accepts only allowlisted stage/category strings and an optional integer HTTP status in 100–599. It never receives an email, token, message body, exception, API key, message ID, queue payload, credential, or broker result. Logging failures cannot prevent the send. Exceptions remain sanitized and unchained.

An example failure contains only:

```json
{"stage":"provider_failed","failure_category":"provider_permission","http_status":403}
```

Successful flow stages are `dispatch_accepted`, `job_started`, `mailer_validation`, `broker_started`, `notification_started`, `notification_ready`, `provider_started`, `provider_accepted`, and `broker_completed`. No request/account identifier is recorded, so overlapping requests cannot be individually correlated; use a quiet controlled test window. A provider acceptance event proves API acceptance, not inbox delivery.

| Observed stage/category | What it establishes and next action |
| --- | --- |
| No `dispatch_accepted` | Confirm the diagnostic release is deployed, the request reached this service, and runtime logs are being viewed. |
| `dispatch_failed` / `dispatch` | Connection validation or dispatch failed before lookup. Retain approved queue settings; do not switch to sync. |
| `dispatch_accepted` without `job_started` | Investigate the deployed termination path or a process interruption. A 202 alone does not prove job execution. |
| `job_failed` / `configuration` | The selected mailer failed validation before lookup. Verify the documented configuration privately. |
| `broker_completed` without `provider_started` | No provider send was attempted. Unknown-account/broker throttle or notification suppression can explain this; wait over 60 seconds before another controlled request. |
| `notification_started` without `notification_ready`, then `job_failed` | Failure during message/link construction. Verify the existing trusted HTTPS `FRONTEND_URL` privately. |
| `provider_failed` / `configuration` | Missing provider configuration; no HTTPS call was made. |
| `provider_failed` / `connection`, no HTTP status | Connection/TLS/DNS/timeout failure; no usable HTTP response. |
| HTTP 401 / `provider_authentication` | Authentication rejection. Owner checks key validity/account association privately. |
| HTTP 403 / `provider_permission` | Permission rejection. Owner checks transactional activation, account restrictions and authorized-IP policy. Status alone does not prove which restriction applies. |
| HTTP 400/other rejection / `provider_rejected` | Provider rejected the request. Owner checks sender, account activation, credits and provider dashboard; no response body is logged. |
| HTTP 429 / `provider_rate_limit` | Provider rate limiting; owner checks quota/usage. |
| HTTP 5xx / `provider_server` | Provider server failure. |
| HTTP 201 / `provider_response` | Malformed acceptance response; no valid message ID was returned. |
| HTTP 201 / `provider_accepted` | Brevo accepted the email. Use Brevo transactional events, recipient suppression/bounce status and inbox/spam to investigate later delivery. |
| `job_failed` / `processing` | Job/broker/message/send processing threw. Read preceding stage events to locate the boundary; exception text stays private. |

Brevo's own troubleshooting documentation confirms that transactional sending may require separate activation or may be suspended even when the sender is configured. The owner should privately check Transactional platform activation/suspension, current sending credits/paused queues, sender/domain status, recipient suppression, and any API/IP authorization restrictions in the correct Brevo account. If the platform has never been activated, Brevo directs the owner to request activation through account support. No private account API or credentials were accessed to check this user's state. [Brevo activation and sending restrictions](https://help.brevo.com/hc/en-us/articles/115000188150-Troubleshooting-Issues-with-Brevo-SMTP).

Changed files:

- `backend/app/Http/Controllers/PasswordController.php`: fixed dispatch-stage diagnostics; public responses unchanged.
- `backend/app/Jobs/SendPasswordResetLink.php`: job, mailer validation and broker-stage diagnostics; sanitized exceptions unchanged.
- `backend/app/Services/PasswordRecoveryMail.php`: message-construction stages; mailer selection unchanged.
- `backend/app/Mail/Transport/BrevoTransport.php`: provider stages, fixed failure categories and status; stricter acceptance validation.
- `backend/app/Support/PasswordRecoveryDiagnostics.php`: centralized fixed-vocabulary diagnostics with no exception/request context.
- `backend/config/logging.php`: dedicated safe stderr channel.
- `backend/tests/Feature/PasswordRecoveryDiagnosticsTest.php`: 17 regression cases, including data-provider cases.
- `.github/PASSWORD-RESET-DELIVERY.md`: investigation, interpretation and deployment instructions.

Verification uses fake HTTP responses, never a real provider request. The 32 focused password-recovery tests passed with 948 assertions, covering success/request format, true handle-versus-terminate ordering, mailer override, environment mapping, HTTP rejection categories, invalid acceptance responses, network failure, generic known/unknown/throttled responses, logging safety and failure isolation. Existing password-security coverage retains hashed/expiring/single-use tokens, wrong-email/replaced-token rejection, trusted fragment URLs, password validation, session/token revocation, unchanged roles/suspension, throttles, and refusal of sync/log/fallback transports. PHP syntax, Pint, frontend lint/build, and the full backend suite are also checked; final counts are recorded below.

Final validation results:

- `cd backend; php vendor/phpunit/phpunit/phpunit`: **178 tests, 2,190 assertions, all passed; PHP process exit code 0**. Existing-database access is refused by the shared SQLite `:memory:` test guard.
- Focused `PasswordRecoveryDiagnosticsTest.php` and `PasswordRecoveryMailTest.php`: **32 tests, 948 assertions, all passed**.
- PHP syntax checks for all seven changed/added PHP files: passed.
- Pint `--test` for those seven files: passed.
- `npm.cmd run lint`, `npm.cmd run lint:eslint`, and `npm.cmd run build`: passed.
- `git diff --check`: passed. The pre-existing Nginx template edit was preserved; it is not part of this change.
- Validation ran on local PHP 8.2.12, not the deployment's Docker PHP runtime. No live provider/FPM/container test or real email send was performed.

Owner deployment and controlled verification steps, for a later approved release:

1. Review these eight files and include them in your normal source release. Preserve unrelated working-tree edits. This investigation does not publish any source.
2. In Render, keep the existing Docker service and existing secrets/settings. Retain `PASSWORD_RESET_MAILER=brevo`, `PASSWORD_RESET_QUEUE_CONNECTION=deferred`, `PASSWORD_RESET_QUEUE=password-resets`, and `QUEUE_CONNECTION=database`. The existing Docker startup check also requires `MAIL_MAILER=brevo`, `LOG_CHANNEL=stderr`, and the general `LOG_LEVEL` at warning or higher. Do not rotate APP_KEY or credentials, switch to sync, change database settings, or add a worker for this diagnostic release.
3. Once the reviewed source is available on the service's configured branch, open Render Dashboard → the web service → Manual Deploy → Deploy latest commit. The Docker build includes the new PHP/config files, and the existing entrypoint rebuilds Laravel's configuration cache after runtime secrets are injected. No migration, SQL command, public test endpoint, Render shell, or manual `config:cache` command is needed.
4. After the service is healthy, open its runtime logs and filter for `Password recovery processing.`. Keep general debug disabled. The narrow diagnostic channel requires no additional environment flag.
5. In a quiet window, using only an account and mailbox you own for this purpose, wait at least 61 seconds after the previous reset request, then submit the existing `/forgot-password` form once. Do not send private addresses, keys, reset links or raw request/response bodies to chat. Normal handling creates a reset token for that test account; this investigation has not performed that production operation.
6. Expect the unchanged generic HTTP 202. Wait about 15 seconds for termination/provider processing, then inspect the stage sequence and the fixed failure category/status. If no final event appears, examine restart/termination timing rather than assuming delivery. Use the table above; a 403 does not by itself establish that activation is the cause.
7. If `provider_accepted` appears, inspect the matching test-window events privately in the correct Brevo account and check inbox/spam. If restricted/disabled, resolve that condition through the provider account owner/support, then repeat only after the broker throttle expires. Do not change credentials or disable TLS based on an unproven guess.
8. Once the test email arrives, use its link privately to confirm successful reset, old-password rejection and single-use replay rejection for the owned test account. Do not modify other users' data. If durable retries are required, plan a separate supervised-consumer deployment; this Free diagnostic release does not provide them.

No actual Brevo acceptance, inbox receipt, live FPM execution, production reset, or account-activation check has been performed. The diagnostics make a controlled production test actionable; they do not prove that production email delivery has been restored.
