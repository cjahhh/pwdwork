# Email verification implementation and rollout report

Date: 26 October 2026

Implemented locally. New-account enrollment defaults to **off**. No MySQL migration, production access, environment change, commit, push or deployment was performed. The pre-existing modification to `scripts/nginx-smoke.mjs` was preserved and was not part of this implementation.

## Pre-deployment safety audit: 26 October 2026

**Local compatibility verdict: PASS in both directions. Production activation is still gated on the checkpoints below.** No production connection or backup was inspected. These results prove application compatibility in isolated SQLite, not Aiven MySQL execution, production configuration or the identity of the currently deployed image.

| Compatibility check | Evidence | Verdict |
| --- | --- | --- |
| Updated application, enrollment disabled, original schema without the three columns | All **230 unchanged pre-feature tests, 2,851 assertions** passed. Additional regression cases exercise both signup roles, all three existing roles, real database-backed session cookies, private API reads, profile writes, bearer authentication, serialization, password reset and disabled verification endpoints. Their recorded application SQL never names a missing column. | **PASS** |
| Actual pre-feature application after the additive migration | All **230 unchanged pre-feature tests, 2,851 assertions** passed with the new migration included in isolated schema creation. This uses actual source from `d495aa8eed7a683b3213f3f59440b9700adfedcb`, not a substitute legacy model. | **PASS before enrollment** |
| Updated application with migrated schema and enforcement | Full suite: **273 tests, 3,556 assertions passed**. This includes **43 verification tests, 705 assertions**, six more compatibility cases than the original implementation report. | **PASS** |

The repeatable offline audit is `php scripts/email-verification-legacy-compatibility.php --old-schema` for updated code/original schema, and the same command without the option for pre-feature code/additive schema. It archives only selected source paths into a random temporary directory, never copies `.env` or database files, forces the original suite's SQLite `:memory:` guard, checks core class locations, and removes its snapshot. It shares the unchanged installed Composer dependencies. Keep the baseline Git object available; a shallow checkout may need that reviewed history fetched before this audit can run. Neither audit checks the live image; an operator must independently identify its commit before rollout.

**No application or migration correction was necessary.** This audit changed this runbook, expanded `backend/tests/Feature/EmailVerificationCompatibilityTest.php`, and added the offline compatibility script. The existing dirty application implementation and Nginx smoke file were preserved. PHP syntax, Pint, whitespace checks and the initial Aiven helper's 6 accepted/11 rejected offline guard cases passed. The full updated suite completed in 22.56 seconds. React source was not changed during this audit; its previous lint/build/browser results are recorded below.

Audit findings:

- `EMAIL_VERIFICATION_ENABLED=false` is the exact disabled setting; absence also defaults to false. The Laravel key is `email-verification.enabled`. Runtime configuration is cached at container startup: changing the variable requires an approved deployment incorporating the updated environment and verification of the effective setting on every instance. Render's **Restart service** reuses the running configuration and does not incorporate newly saved variables; do not use it to activate the feature. Never print the entire configuration or environment to check it. See [Render restart behavior](https://render.com/docs/deploys#restarting-a-service).
- Casts and status getters tolerate absent attributes. Disabled registration does not write new fields, and ordinary login, session restoration, profile operations and password recovery do not write them. Verification send/confirmation is unavailable for legacy accounts while off. Premature activation on the original schema refuses new registrations with a safe 503 before account creation, while existing access remains available.
- The persisted `email_verification_required_at` guard runs on all sensitive authenticated API routes, including bearer requests. It does not depend on the enrollment switch. Public jobs, login, recovery, current-user status, verification and logout remain reachable. Tests cover the route middleware inventory, credential recovery and disabling enrollment after a requirement exists. No current API can edit the ownership/enrollment fields or email address; any future email-edit feature or alternate account creation path needs a new ownership review.
- Verification only updates ownership timestamps/version. Public signup still allows only applicant/employer, existing role/status checks remain, and an employer still needs independent administrator approval. Neither migration nor confirmation promotes an administrator or approves an employer.
- Verification selects the existing Brevo HTTPS mailer and inherits recovery's delivery connection. Password controller, recovery jobs/services, queue configuration and Brevo transport are unchanged. Mocked-provider and deferred-response tests passed. Deferred delivery can still be lost during a restart; it is not durable queue delivery.
- Docker build, `deploy/docker/entrypoint.sh`, `deploy/docker/run-services.sh` and console scheduling do **not** run migrations. Startup only validates/caches configuration/views and starts PHP-FPM/Nginx, plus the existing explicit opt-in storage probe. Composer's scaffold-only `post-create-project-cmd` contains a migration command, but the Docker install/dump uses `--no-scripts` and startup never invokes project creation. Do not introduce that command into deployment.
- `.github/workflows/aiven-migrations.yml` and its helper intentionally reject any populated application database. **Do not run or weaken this initial-only workflow for this upgrade.** There is currently no approved incremental workflow in this repository. Preparing/reviewing an incremental runner is a release prerequisite, not work performed by this audit.
- The older generic `pwdwork:production-check` command expects durable recovery queues and can flag the already-supported Render deferred configuration. It is not an email-verification readiness check; do not change working recovery settings just to satisfy it. The current Docker environment check explicitly supports deferred recovery.

## Migration review

For the owner-reported Aiven **8.4.11** service and confirmed full backups, use the detailed [disposable MySQL rehearsal procedure](EMAIL-VERIFICATION-MYSQL-REHEARSAL.md). It includes synthetic fixtures, restore/data comparisons, read-only SQL files and partial-failure cases. It is preparation only; no MySQL rehearsal or production access has been performed.

An isolated [GitHub Actions testing-branch workflow](EMAIL-VERIFICATION-GITHUB-ACTIONS.md) is also prepared for machines without Docker. It uses no production secrets and does not replace the separately approved incremental production migration runner. It remains uncommitted and unexecuted at preparation time.

The migration adds only nullable `TIMESTAMP` ownership/requirement fields and a `BIGINT UNSIGNED NOT NULL DEFAULT 0` version. Existing `email_verified_at`, role/status, password, relationship and session values are untouched. Existing records receive no ownership timestamp or new requirement. Older writers may omit the three new fields. The version default supplies zero to existing and later old-code inserts; that numeric initialization is not an ownership backfill. There is no timestamp `useCurrent`, UPDATE, DELETE, DROP, index change, seeding or table reset.

The no-connection MySQL 8.4 grammar regression compiles these **three independent statements**:

```sql
alter table `users` add `email_ownership_verified_at` timestamp null;
alter table `users` add `email_verification_required_at` timestamp null;
alter table `users` add `email_verification_version` bigint unsigned not null default '0';
```

This is supported MySQL syntax, but it was not executed on MySQL here. Three statements can leave a partial schema if a later statement fails; MySQL DDL does not provide an all-three rollback. The migration deliberately refuses `down()` to preserve ownership evidence. Do not issue a general Laravel rollback, fresh or reset command, delete migration-history rows, or rerun a partial migration blindly.

Plan for metadata locks, resource use and potentially repeated table rebuilds. Aiven documents that its MySQL backup implementation falls back from INSTANT changes to INPLACE/COPY and that backups can block DDL. Do not promise a zero-downtime/instant change merely because columns are additive. Rehearse on the same MySQL version/service configuration and use a low-traffic approved maintenance window with one migration operator. See [Aiven MySQL backup and DDL behavior](https://aiven.io/docs/products/mysql/concepts/mysql-backups).

## Verified backup and restoration: future operator procedure

**No backup was created, downloaded, inspected or restored in this audit.** The owner must establish available backups and permissions; do not assume the current Aiven plan includes the required retention or restore capabilities.

1. Record the independently approved Aiven project/service, host, port, database `pwdwork_prod`, MySQL version, snapshot time in UTC and recovery objective. In Aiven's Backups view, verify a completed backup and the available recovery range/retention. Aiven's retention depends on plan. Record a non-secret backup identifier and chosen recovery time; if no suitable backup exists, stop. See [Aiven backup policy](https://aiven.io/docs/products/mysql/concepts/mysql-backups).
2. Prove restoration **before** the production migration. Where supported, use **Backups > Fork & restore**, choose a backup/recovery point and create a separately named service. Check the new endpoint before connecting. A fork copies databases, users and configuration, so restrict its access and keep application traffic, workers and outbound email disconnected. It contains real sensitive data and must remain in an approved environment, never this workspace. If that operation is unavailable on the current plan, use an approved encrypted logical backup and a separate empty restoration target. See [Aiven fork/point-in-time restoration](https://aiven.io/docs/products/mysql/howto/fork-service).
3. An optional logical backup uses a matching MySQL client and a private option file **outside the repository**, with source host/port/user/password, `ssl-mode=VERIFY_IDENTITY` and the trusted `ssl-ca`. Protect it and the output with restrictive permissions and encryption. Never put passwords in command arguments, terminal transcripts or CI logs. After separate backup approval, an operator may use this template in a secure runner:

   ```sh
   mysqldump --defaults-extra-file=/secure/aiven-source.cnf \
     --single-transaction --quick --set-gtid-purged=OFF --no-tablespaces \
     --hex-blob --routines --events --triggers \
     --result-file=/secure/pwdwork-before-email-verification.sql pwdwork_prod
   ```

   Confirm all application tables are transactional InnoDB and avoid simultaneous schema changes during the dump. Check the exit status, non-empty output, expected tables and file checksum; a file existing is not proof of a valid backup. Test routine/event/trigger privileges and DEFINER compatibility on the restoration target without broadening production permissions blindly. `--result-file` avoids shell output-encoding corruption, including older PowerShell redirection. See [Aiven logical backup and restore guidance](https://aiven.io/docs/products/mysql/howto/migrate-database-mysqldump).
4. For a logical restore, independently verify the **separate empty target** and its private TLS option file. The dump above omits `--databases`, so it should not select/create the live database; inspect that before import. Use the MySQL client's `SOURCE` command against the explicitly selected empty target, or native byte-preserving input redirection in a suitable shell. Never import into the live source for a rehearsal. Verify exit status, schema/migration history, table counts and protected integrity comparisons at the same snapshot; check accounts, role/status values, related records and session rows. An offline application check must use this separate endpoint, blocked outbound mail and no real-user actions. Record restoration duration and recovery point. Counts from an actively changing source at a later time are not expected to match a prior snapshot exactly.
5. Immediately before migration, confirm the restore-tested backup/recovery point is sufficiently recent and still retained; take a fresh approved logical snapshot if necessary. Keep the encrypted artifact/backup ID, checksum, restricted location and restoration record under the owner's retention policy. Preserve the stable APP_KEY securely outside the database; it is needed for existing encrypted cookies and jobs.
6. If a disaster requires restoration, stop application writes and workers under incident approval, preserve the damaged database for investigation, and restore to a new independently checked target first. Validate it, then approve a controlled cutover. Reconcile writes since the recovery point: registrations, applications, messages, sessions and ownership evidence could otherwise be lost. A restore to before enrollment can remove requirements and reopen access, so freeze registration and reconcile the affected accounts before reopening. Ordinary application rollback should retain the additive schema instead of restoring old data.

## Implemented behavior

- When `EMAIL_VERIFICATION_ENABLED=true`, new public jobseeker and employer registrations are created with an email verification requirement. They retain a secure login session and access to public jobs, account status, verification and logout. Other protected API routes require ownership verification, including bearer-token requests.
- Registration never assigns a verification timestamp. All new verification fields remain protected from client mass assignment.
- The initial email is dispatched through Laravel's registration event after the account/profile transaction. One encrypted job sends through the existing Brevo HTTPS transport. A dispatch failure preserves the pending account and session so resend can recover it.
- Signed proofs expire 60 minutes after creation for delivery. They bind the account ID, current email digest and verification version. The email opens `/verify-email#proof=...`; React captures the proof in memory and removes the fragment from browser history. Confirmation posts it in JSON to a fixed endpoint. Query-only proofs, arbitrary URLs, modified/expired proofs, another account's proof and superseded versions are rejected.
- Verification requires authentication as the matching account and an explicit confirmation POST. Simply visiting the page or an email scanner following its link cannot verify an account. Correct confirmation records proven ownership, rotates the verification version, renews the current session ID, and emits one `Verified` event. Repeated valid confirmation does not repeat changes/events.
- Resend uses the authenticated account's stored address, never a request-supplied recipient. The initial email and resends share one send per account per 60 seconds and five per hour. A shared cache lock prevents concurrent duplicate dispatch. Additional IP limits allow six resend requests per minute and thirty per hour. Confirmation is limited to ten requests per account per minute.
- Resend increments the version, so earlier links/jobs become obsolete. Stale, suspended, deleted or already-verified account jobs do not send. A model-driven email change invalidates previous ownership and links.
- Employer approval, account status and administrator permissions are unchanged. Email ownership grants no role or employer approval. Password recovery never grants ownership verification. Existing login IP/account limits, CSRF checks and suspension rules remain intact.

## Existing accounts and sessions

Three additive fields separate proof from access policy:

| Field | Definition | Existing-row result |
| --- | --- | --- |
| `email_ownership_verified_at` | Nullable timestamp | `NULL`: no new ownership claim. |
| `email_verification_required_at` | Nullable timestamp | `NULL`: existing access continues. |
| `email_verification_version` | Unsigned big integer, default zero | `0`: no initial link generation. |

Historical `email_verified_at` values are preserved but are not trusted as ownership evidence. Only successful proof confirmation sets the new ownership timestamp and synchronizes the old timestamp. The migration does not backfill ownership, reset passwords, alter roles/statuses or touch sessions or related records.

For legacy accounts the API reports `email_verified=false` and `email_verification_required=false`. This honestly preserves access without calling them verified. When enrollment is enabled, existing users may voluntarily use the verification page. There is no bulk email campaign, forced legacy deadline or automatic enrollment of existing administrators. Authorized console administrator provisioning remains separate from public signup and does not create an ownership claim.

The switch controls **new enrollment**, not enforcement of already-persisted requirements. Enrolled users remain protected and can confirm/resend after the switch is disabled. This is essential for a safe application rollback. Existing accounts that never enrolled keep their access in either switch state.

## Brevo, deferred delivery and log privacy

`backend/config/email-verification.php` defaults to disabled. Its queue connection inherits the existing `PASSWORD_RESET_QUEUE_CONNECTION` unless an operator explicitly supplies `EMAIL_VERIFICATION_QUEUE_CONNECTION`. Thus Render's existing deferred recovery setup also provides deferred verification delivery. No password recovery code, credentials, mail transport or production variable was changed. Inline `sync`, discarded and fallback delivery connections are rejected for verification.

The verification service explicitly chooses the existing `brevo` mailer, even when the general default mailer is `log`. It refuses an altered/log Brevo transport or missing credentials. The encrypted job carries only account ID/version; rendered mail, recipient addresses and proofs are not serialized into its payload. Links are created inside the delivery job. Expiry begins then, not while waiting in a durable queue.

Application diagnostics use fixed stages only. Provider failures are rethrown without the provider message or previous exception. Proofs are excluded from flashed exception input and never returned in API responses. Frontend code never logs Axios errors containing request bodies. HTTP URLs and Referer headers do not carry the fragment proof. Existing host access-log restrictions and recovery transport sanitization remain in place.

Deferred delivery is response-first but **not durable**. A restart or interrupted PHP process can lose a send; `$tries` and `$backoff` do not create durable deferred retries. A successful request means accepted for an attempt, not delivered to an inbox. Pending users can resend after the cooldown. For durable retries, separately provision a worker consuming `email-verifications` alongside the existing recovery/notification queues as appropriate. Do not switch to a database queue without a consumer and do not switch password recovery to sync. See [Laravel deferred dispatching](https://laravel.com/docs/12.x/queues#deferred-dispatching) and [Render Free limitations](https://render.com/docs/free).

Brevo necessarily receives the email body and may retain email previews or rewrite tracked links. Those provider-held copies are outside application-log guarantees. Before enabling, an authorized owner must review preview retention, access and tracking settings and confirm that the delivered link preserves its complete fragment. No provider settings were accessed or changed here. See [Brevo transactional logs and previews](https://help.brevo.com/hc/en-us/articles/360021533839-Manage-your-transactional-logs-and-email-previews).

## All files changed for this implementation

| File | Purpose |
| --- | --- |
| `backend/.env.example` | Document the disabled default and optional delivery override. |
| `backend/.env.production.example` | Same guidance for a production template; not a production environment file. |
| `backend/app/Http/Controllers/AuthController.php` | Enrollment preflight, protected enrollment and post-commit initial notification; verification status payload. |
| `backend/app/Http/Controllers/EmailVerificationController.php` | Authenticated status, resend and body-only confirmation. |
| `backend/app/Http/Middleware/EnsureEmailOwnership.php` | Enforce persisted requirements on sensitive API routes. |
| `backend/app/Jobs/SendEmailVerification.php` | Encrypted deferred/asynchronous delivery with stale-job checks and safe errors. |
| `backend/app/Mail/EmailVerificationEmail.php` | Verification message rendered inside the job. |
| `backend/app/Models/User.php` | Verification contract, ownership/access distinction and invalidation methods. |
| `backend/app/Providers/AppServiceProvider.php` | Verification request rate limits. |
| `backend/app/Services/EmailVerification.php` | Readiness checks, shared sending limits, signed proofs and atomic confirmation. |
| `backend/app/Support/EmailVerificationDiagnostics.php` | Fixed-vocabulary diagnostics without sensitive context. |
| `backend/bootstrap/app.php` | Register ownership middleware and exclude proofs from flashed input. |
| `backend/config/email-verification.php` | Disabled enrollment and inherited asynchronous delivery settings. |
| `backend/database/migrations/2026_10_26_000001_add_email_ownership_verification_to_users.php` | Add the three ownership/enrollment fields without rewriting existing values. |
| `backend/phpunit.xml` | Isolate verification configuration from developer environment settings. |
| `backend/resources/views/emails/verify-email.blade.php` | HTML email. |
| `backend/resources/views/emails/verify-email-text.blade.php` | Plain-text email. |
| `backend/routes/api.php` | Verification endpoints and sensitive-route guard; keep session/logout reachable. |
| `backend/tests/Feature/EmailVerificationCompatibilityTest.php` | Old schema, additive preservation, older-writer defaults and premature activation tests. |
| `backend/tests/Feature/EmailVerificationMailTest.php` | Mocked Brevo, real deferred lifecycle, encrypted jobs and secret-free failure tests. |
| `backend/tests/Feature/EmailVerificationTest.php` | Registration, proof validation, access, session, resend, CSRF, recovery and role tests. |
| `src/App.jsx` | Public verification page route. |
| `src/components/Layout.jsx` | Keep pending users in the public browsing layout. |
| `src/components/Navbar.jsx` | Direct pending workspace links to verification. |
| `src/components/ProtectedRoute.jsx` | Direct enrolled users to the verification page. |
| `src/context/AuthContext.jsx` | Refresh verification state and synchronize account changes across tabs. |
| `src/pages/LoginPage.jsx` | Direct pending users to verification after login. |
| `src/pages/HomePage.jsx` | Skip protected recommendation prefetches for pending users. |
| `src/pages/JobDetailsPage.jsx` | Preserve public details while avoiding private saved-state calls and explaining verification before applying/saving. |
| `src/pages/RegisterPage.jsx` | Direct enrolled registrations to verification. |
| `src/pages/VerifyEmailPage.jsx` | Confirmation, inline matching-account login, resend cooldown, status check, logout and public browsing. |
| `src/services/api.js` | Handle the ownership-required response without treating it as logout. |
| `src/services/auth.js` | Verification requests and account destination selection. |
| `scripts/email-verification-ui-smoke.mjs` | Real-browser regression checks with built assets and a loopback mocked API; no database or mail provider. |
| `scripts/email-verification-legacy-compatibility.php` | Added in the safety audit: repeat the actual pre-feature suite against both compatibility combinations, using isolated snapshots and in-memory SQLite. |
| `.github/EMAIL-VERIFICATION-ROLLOUT.md` | This implementation report and deployment runbook. |

No dependencies or lockfiles changed. Generated frontend output and test caches remain ignored artifacts. The existing modified Nginx smoke script is outside this change's file list.

## Automated test results

| Check | Result |
| --- | --- |
| Full Laravel suite: `php artisan test --compact` | **273 passed, 3,556 assertions**, 22.56 seconds; exit 0, rerun during the safety audit. |
| Verification coverage within the full suite | **43 tests, 705 assertions** across the three verification test files, including six audit additions. |
| Updated code/original schema: offline baseline suite | **230 passed, 2,851 assertions**. |
| Actual pre-feature code/additive schema: offline baseline suite | **230 passed, 2,851 assertions**. |
| React-aware lint: `npm.cmd run lint` | Passed without warnings. |
| ESLint: `npm.cmd run lint:eslint` | Passed. |
| Frontend production build: `npm.cmd run build` | Passed. |
| Browser UI: `node scripts/email-verification-ui-smoke.mjs` | Passed all four scenario groups against built assets and a mocked API, with no JavaScript exceptions. |
| PHP syntax on every changed/new PHP file, including email templates | Passed. |
| Pint on every changed/new PHP source file | Passed. |
| `git diff --check` | Passed. |

All database tests use SQLite `:memory:`. Compatibility tests create a second, explicitly in-memory connection to simulate the old schema and apply the new migration there. The existing test guard refuses any existing database. No local/production MySQL connection or migration was used. Brevo responses are mocked and stray HTTP requests are blocked in mail tests. Existing password-recovery tests intentionally emit safe warnings for simulated failures; the suite has no failing tests.

React lint/build/browser checks above were performed for the original implementation and were not rerun for this audit's PHP-test/documentation-only changes. The updated Laravel suite, both compatibility combinations, offline MySQL SQL compilation, PHP syntax, Pint, whitespace and initial migration-helper guard checks were run for this audit.

Coverage includes initial signup for both public roles, exact deferred handle-versus-terminate ordering, post-commit account/profile availability, explicit confirmation, valid/expired/tampered/wrong-account/external/malformed proofs, query-only proof rejection, old-version invalidation, repeat confirmation, session restoration, shared database-cache sending limits, concurrent-send lock contention, account/IP/hourly budgets, existing users/admins, employer approval separation, suspension, real bearer tokens, CSRF, dispatch/provider failures, encrypted payloads and logs, password recovery, disabled-before-schema behavior, premature activation refusal and older SQL writer compatibility.

The browser check covers public home/search/details without private prefetches, protected-route redirect, same-page fragment capture/removal, explicit confirmation, memory-only proof retention through logout/login, refreshed ownership status, accepted resend and Retry-After countdowns, and the legacy access label. It uses a fresh temporary Chrome profile and deletes it after the run. Chromium child processes were restricted inside the execution sandbox; the isolated test passed with approved normal process access. The fixture never starts Laravel or connects to a database/provider.

No real Brevo inbox delivery, Linux Docker execution, live Render ingress or Aiven MySQL DDL/concurrency test is claimed. The browser API is mocked; the actual Laravel API, signing, database and Brevo request logic are covered separately by isolated PHP tests.

## Required migration and safe production sequence

The required migration is `2026_10_26_000001_add_email_ownership_verification_to_users.php`. It has **not** run outside isolated memory. These are future operator steps requiring the separate approvals specified by the owner:

**Stop at every checkpoint until its evidence is accepted. None of these production steps has been executed.**

1. **Checkpoint A — reviewed release and rollback.** Record the exact approved commit/image digest, migration-file checksum, actual currently deployed revision, compatible rollback image and operator. The local baseline above is not proof of the live revision. Keep enrollment absent/`false`, stable APP_KEY, database session settings, Brevo credentials and deferred password recovery unchanged. Confirm Render auto-deploy behavior before any future merge/push so it cannot skip these gates. No automatic startup migration may be added.
2. **Checkpoint B — disposable staging and recovery rehearsal.** On synthetic data in matching disposable MySQL, test disabled code both without/with the columns and the actual older release after migration. Apply only the reviewed migration, inspect all three definitions, preserve row contents/relationships/sessions and measure DDL duration/locks. Test a staged failure/recovery plan without real records. Complete the separately authorized backup/restore rehearsal above. Stop if either compatibility direction or restoration fails.
3. **Checkpoint C — delivery/enforcement staging.** Use the intended Linux Docker/PHP-FPM image, isolated MySQL/cache and owned inboxes. Test enrollment, sensitive-action denial with session and bearer authentication, confirmation, cross-device matching-account login, invalid/expired proof, resend after a lost deferred send and recovery without ownership bypass. Confirm admin/employer approvals stay separate, privacy in app/ingress/provider logs, and complete link fragments after Brevo rewriting. Check real Render proxy behavior using harmless health/status requests, not repeated failed logins; retain the earlier trusted-proxy release gate. No real users or production records are part of these tests.
4. **Checkpoint D — deploy compatible code with enrollment OFF.** After deployment approval, deploy the reviewed disabled release to the web service and any workers. Confirm its effective `email-verification.enabled` is false without dumping secrets, and that new assets match the release. Check health/public jobs and authorized test accounts for all three roles, including a pre-existing test-account session cookie. Expect legacy `email_verified=false` and `email_verification_required=false`. Do not change legacy rows to make checks pass. Observe sanitized error rates before proceeding.
5. **Checkpoint E — approve the incremental migration runner and fresh recovery point.** The initial Aiven workflow is unsuitable and must remain unchanged. Use a separately reviewed manual incremental workflow or an approved secure operator runner, outside the Render Free web process. Pin the release image/SHA and only this migration path; require independent expected-host/port/database checks, Aiven CA/hostname verification, non-root scoped credentials, protected temporary files, safe output, one operator and separate preview/apply approval. Reuse the existing workflow's secret-handling and TLS design, not its empty-database operation. Verify original columns and populated history first, identify unrelated pending migrations without running them, and record an adequately recent restore-tested backup/recovery point. If any of the three new columns already exists without a matching migration record, **stop for partial-DDL review**.
6. **Checkpoint F — preview, then separately apply only this migration.** Schedule a low-traffic maintenance window, coordinate writers/other migration operators and avoid Aiven backup locks. In the independently verified runner/image with enrollment off, the future commands are:

   ```sh
   # Future operator instructions only: NOT executed during this audit.
   php artisan migrate --path=database/migrations/2026_10_26_000001_add_email_ownership_verification_to_users.php --pretend --force
   # Separate approval after reviewing that preview and the exact unchanged release:
   php artisan migrate --path=database/migrations/2026_10_26_000001_add_email_ownership_verification_to_users.php --force
   ```

   Preview still executes migration PHP; this reviewed migration only describes default-connection schema changes. An existing valid Laravel `migrations` table must already exist. Never run all pending migrations, the initial empty-database workflow, fresh/reset, seeders or rollback. If apply fails, leave enrollment off, inspect schema/history and follow a separately reviewed forward repair; a completed first ALTER is not undone by a later failure.
7. **Checkpoint G — verify the additive production state while still OFF.** Confirm all three exact column types/nullability/defaults and one recorded migration. Check preservation using approved aggregate counts/integrity evidence, accounting for legitimate concurrent writes. Confirm no ownership/requirement backfill, existing role/status and employer approval unchanged, old test-account sessions and role features functional, public jobs available, and no sanitized SQL/configuration errors. Do not print account data, passwords, proofs or environment values. Stop on any mismatch.
8. **Checkpoint H — separately approve activation.** Confirm every serving release/worker has the ownership guard, stable key and shared cache/locks; retain a compatible rollback image. Establish a controlled signup boundary (pause new registrations through an approved operational mechanism if necessary while configuration changes propagate). After explicit activation approval only, set `EMAIL_VERIFICATION_ENABLED=true` through the approved Render configuration/redeploy process. Startup rebuilds config cache; check the effective boolean on the new instance before reopening signup. Do not run `config:cache` at image build time. Using owned production test inboxes and authorized test accounts, perform one jobseeker and one employer signup/confirmation, confirm private access is denied before proof and allowed afterward, employer approval remains pending, and legacy/admin access remains unchanged. Do not generate repeated failed login attempts or modify real users. Requests accepted before all instances activate remain legacy; do not silently enroll/backfill them.
9. **Checkpoint I — observe and retain recovery options.** Monitor fixed send stages, aggregate pending/success/error rates and approved inbox delivery. Keep deferred delivery and password recovery unchanged. Do not set verification to a database queue without a separately provisioned consumer for `email-verifications`; the sample existing worker's queue list does not consume it. If an incident occurs after activation, pause signup and use the compatible rollback rules below. Existing users may verify voluntarily; a mandatory legacy transition would require separate notice, a grace period, administrator/support recovery arrangements and approval.

Render Free has no SSH/dashboard shell or one-off jobs; pre-deploy commands are available only to eligible paid services. The migration therefore needs the separately approved external runner above. Free services can restart and deferred delivery is not durable. These limitations do not justify migrating on startup, exposing a web migration endpoint or switching recovery to sync. See [Render Free limitations](https://render.com/docs/free) and [Render deployment/pre-deploy behavior](https://render.com/docs/deploys).

When enabled before the schema is ready or with invalid delivery settings, new registration returns a generic 503 before creating a required account. Existing account login/access remains available. This is a safety net, not a substitute for the approved sequence.

## Rollback and remaining risks

- Before activation, older application code can coexist with the additive schema while enrollment remains off. After accounts have enrolled, rollback must use a compatible release that retains the ownership guard. Pre-feature code would ignore their requirements.
- Disabling the enrollment switch preserves protection, confirmation and resend for already-enrolled users. It also stops enrolling subsequent registrations; those would receive compatibility access. After public activation, pause new registrations during an incident instead of treating the off switch as continuing new-account protection.
- Keep all three columns, ownership evidence, account records, sessions and APP_KEY during rollback. The migration's `down()` deliberately refuses to delete verification evidence. Never bulk-mark accounts verified to resolve delivery problems.
- Render Free delivery can be lost during interruption. Safe resend mitigates recovery but cannot promise delivery availability. A durable worker remains the recommended reliability improvement.
- Resend invalidates older links as soon as its request is accepted; if delivery fails, the user must request another email after the limit. The page explains using the latest message. Refreshing after the proof was removed from history requires reopening the email; proofs are intentionally not persisted in browser storage.
- Brevo retention/tracking, real inbox delivery and link rewriting need owner verification before activation. Provider-held email copies cannot be eliminated by application logging controls.
- MySQL DDL locking, true concurrent production traffic, Linux/FPM behavior and live trusted-proxy assumptions remain staging checks. The previous proxy assessment's ingress gate remains applicable.
- Existing accounts retain a deliberate legacy-access exception until a separately approved transition. This implementation never presents that exception as proven ownership.
- The local PHP runtime remains 8.2.12, below the repository's existing security baseline; runtime/deployment settings were not changed. The repository's Dockerfile remains pinned to its existing reviewed PHP version. Local passing tests do not certify the deployed runtime.

**Completion:** Both local compatibility directions PASS and all updated automated tests pass. The implementation is ready to enter the controlled rollout process, starting with its review/staging/recovery gates. This is not unconditional approval to deploy or enable verification: actual MySQL rehearsal, a verified backup/restore and an approved incremental runner are mandatory prerequisites. Enrollment remains disabled by default; production migration, activation and deployment remain unperformed.
