# Interview notification investigation

Investigated on 26 October 2026 using repository code and synthetic SQLite `:memory:` test records only. Production credentials, production records, existing databases, migrations, and deployment settings were not accessed or changed. No commit, push, or deployment was performed.

The confirmed repository defect was a missing notification write in `JobListingController::updateApplication()`. The employer's interview request saved application status, date, time, and notes inside a transaction, but did not create a `UserNotification`, dispatch a notification job, or trigger an observer that created one. Therefore the application page could show the correct interview while the Notifications page remained empty. This reproduces the reported behavior locally; production database contents and the deployed revision were not inspected.

Application notifications are advertised by the empty state in `src/pages/NotificationsPage.jsx`. The existing scheduling integration was absent rather than implemented and delayed. Existing notification producers cover moderation decisions and accommodation request updates.

The inspected path is:

1. `src/services/jobs.js::scheduleInterview()` sends `PATCH /api/employer/applications/{application}` with `status=interview` and the schedule.
2. `backend/routes/api.php` requires Sanctum authentication, an active account, and the employer role. The controller checks listing ownership before validation and checks it again against the locked application inside the transaction.
3. `UserNotification` uses `user_notifications`, with a recipient foreign key, title, body, read timestamp, and navigation URL. The existing schema supports this fix without a migration.
4. `NotificationController::index()` retrieves only `$request->user()->userNotifications()`, newest first, with pagination. Its mark-read action rejects any recipient mismatch. There is no admin override for another user's notices.
5. The React page fetches `/api/notifications`, maps `notifications`, renders an empty state when `meta.total` is zero, and reloads on Refresh. Its response field names match the controller. API responses retain private/no-store headers. No frontend defect was identified in this path.

`QUEUE_CONNECTION=database` does not explain these missing in-app notices. Existing moderation and accommodation services create `UserNotification` records synchronously. `App\Notifications\ModerationDecision` implements `ShouldQueue` for optional **mail only**, and password recovery has a separate queued job. Jobs dispatched onto a database queue need a worker; merely storing them does not execute them. The checked-in Docker service script starts PHP-FPM and Nginx without a worker. A missing worker can prevent queued emails, but the interview handler previously queued nothing and wrote no in-app record. See [Laravel queue workers](https://laravel.com/docs/12.x/queues#running-the-queue-worker).

The fix writes the applicant's in-app notice in the same transaction as the schedule. Initial scheduling creates **Interview scheduled**; changed dates, times, or notes create **Interview updated**. The recipient comes from the locked application's `user_id`, never from a submitted recipient field. Identical retries create no duplicate, including MySQL `TIME` values returned with seconds. A failed notification write rolls back the schedule update. Other application statuses do not create interview notices.

Notification bodies contain a generic update and link to `/applicant/applications`. Interview notes, accommodation details, profile information, PWD identifiers, and cover letters are not copied into notices. Detailed interview information remains on the existing authorized application page. Authentication, active-account checks, role restrictions, recipient scoping, mark-read ownership, pagination, and response cache controls are unchanged.

This approach supports Render Free because the small database insert completes during the existing HTTP request, using the configured database connection. It needs no queue worker, cron service, new service, local persistent disk, or email provider. Existing optional email behavior is unchanged. Render Free web services can spin down after inactivity; the notice remains in the external database and is retrieved when the app next serves a request. See [Render Free service limits](https://render.com/docs/free).

Changed files:

- `backend/app/Http/Controllers/JobListingController.php`: transactional interview notice creation and duplicate suppression.
- `backend/tests/Feature/InterviewNotificationTest.php`: regression coverage with the real database queue selected, no worker, and guarded in-memory SQLite.
- `.github/INTERVIEW-NOTIFICATIONS.md`: investigation and verification record.

Regression tests verify immediate creation, the application owner's recipient identity despite a submitted `user_id`, retrieval and read state, repeated reads, generic private content, isolation from employers/other applicants/admins, guest and inactive-account denial, scheduling role/ownership enforcement, invalid schedules, rescheduling, retry suppression including MySQL time formatting, pagination order, non-interview updates, and transaction rollback. Queue tables remain empty during successful scheduling; no queue fake hides a worker dependency.

Before the controller fix, the initial regression run reproduced missing notices: 9 tests, 41 assertions, 5 failures and 1 error caused by the absent record/notification attempt. After the fix, that suite passed with 9 tests and 98 assertions; inactive-recipient coverage was then added.

Final verification:

- `cd backend; php vendor/phpunit/phpunit/phpunit`: **161 tests, 1,400 assertions, all passed**, including all 10 interview notification tests. The shared test base refuses to use a database other than SQLite `:memory:`.
- `cd backend; php vendor/laravel/pint/builds/pint --test app/Http/Controllers/JobListingController.php tests/Feature/InterviewNotificationTest.php`: passed.
- PHP syntax checks for the changed controller and added test: passed.
- `npm.cmd run lint`: passed.
- `npm.cmd run lint:eslint`: passed.
- `npm.cmd run build`: passed.
- `git diff --check`: passed. A pre-existing change in `deploy/docker/nginx.conf.template` was preserved and is not part of this fix.

The database-specific time representation is covered synthetically; no MySQL/Aiven integration test, live production test, or browser interaction test was performed.

This change creates notifications for future scheduling actions and genuine interview changes. It does not backfill historical interviews or create a notice for an unchanged retry of a previously saved interview. Production verification and historical backfill were outside this investigation's authorized data access.
