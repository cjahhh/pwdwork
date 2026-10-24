# Phase 4: Accommodation Request System

Phase 4 adds private recruitment requests to existing applications. It does not alter recommendation scoring, application status, interview schedules, or existing profile-sharing consent. No paid services, uploads, SMTP, or background workers are required.

## Upgrade status and database safety

The migration has **not** been applied to the existing MySQL database. PHPUnit and browser fixtures use isolated SQLite databases. Applying the upgrade to your existing database is a separate, explicitly authorized operation.

Exact migration:

`backend/database/migrations/2026_10_25_000001_create_accommodation_request_workflows.php`

Its `up()` creates only:

| Table | Contents |
| --- | --- |
| `accommodation_requests` | Application foreign key, selected category labels, stage, optional practical details, sharing/withdrawal timestamps, status, arrangement, interview snapshots, version and submission deduplication key/hash |
| `accommodation_request_messages` | Request foreign key, sender ID/role, message body and timestamps |
| `accommodation_request_events` | Request foreign key, actor ID/role, action, old/new status, private request snapshot, explanation, version, deduplication key/hash and timestamps |

No existing table or record is rewritten. Foreign keys use restrict-on-delete to preserve request history. Indexes cover application/status lookup, ordered request histories/messages, status lists, unique submission keys and unique action keys. Normal application APIs continue working before the migration: confirmed arrangements default to an empty array; request endpoints return HTTP 503 until all three tables exist.

## Safe upgrade procedure (manual; not executed against your database)

1. Back up the database and check that the backup can be restored. Review the migration and any other pending migrations.
2. Deploy backend code and inspect migration status from `backend`:

   ```powershell
   php artisan migrate:status
   php artisan migrate --pretend --path=database/migrations/2026_10_25_000001_create_accommodation_request_workflows.php
   ```

3. After approval, apply only this migration to the intended database, in a maintenance window if required:

   ```powershell
   php artisan migrate --path=database/migrations/2026_10_25_000001_create_accommodation_request_workflows.php --force
   ```

4. Run the frontend production build (`npm.cmd run build` on Windows, `npm run build` elsewhere) and publish its output through the existing deployment process. Clear/rebuild existing Laravel deployment caches as appropriate. No new environment variables are needed by the application.
5. Verify request creation with an applicant test account and an application belonging to it, then employer acknowledgment, proposal, explicit applicant acceptance, and withdrawal. Confirm that unrelated accounts and admins cannot read the request, messages or history.

Do not use `migrate:fresh`, reset/import commands, or rollback after real requests have been created. The migration's `down()` drops only the new tables, but would destroy Phase 4 history and is not an upgrade recovery strategy. Back out application code while retaining the additive tables if necessary. MySQL DDL may not be transactional; inspect a failed/partial migration before retrying and restore from the approved backup if needed.

## API

All routes below require Sanctum authentication, an active applicant/employer account, and schema readiness. Admins are excluded. Record reads are participant-scoped and use 404 for unauthorized record IDs. Responses containing request data use `Cache-Control: private, no-store`.

| Method and path | Purpose |
| --- | --- |
| `GET /api/accommodation-requests/catalog` | Standard accommodation labels plus recruitment categories, stages and statuses |
| `GET /api/accommodation-requests` | Paginated authorized list; filters: `status`, `application_id`, `query` (job title/applicant name), `page`, `per_page` |
| `POST /api/accommodation-requests` | Applicant creates and explicitly shares a request for their own application |
| `GET /api/accommodation-requests/{id}` | Authorized request detail, current interview and recorded interview context |
| `POST /api/accommodation-requests/{id}/actions` | Validated role-specific action with optimistic concurrency and retry deduplication |
| `GET /api/accommodation-requests/{id}/messages` | Paginated private messages, newest first |
| `GET /api/accommodation-requests/{id}/events` | Paginated private history/snapshots, newest first |

Pagination follows the existing `{requests|messages|events, meta}` convention, default 12 and maximum 50 records. Lists sort by descending ID for stable ordering when inputs are unchanged. There is no message update/delete endpoint.

Create payload:

```json
{
  "application_id": 123,
  "action_key": "f824ae37-f1d0-48a4-b1c8-510aaf91f0e5",
  "share_request": true,
  "categories": ["Accessible restroom"],
  "stage": "interview",
  "details": "Please confirm an accessible venue."
}
```

`details` is optional (maximum 2,000 characters). Categories are selected from the authenticated catalog, never inferred from private profiles. Stages are `interview`, `assessment`, and `recruitment`.

Actions require `action`, `expected_version`, and a fresh UUID `action_key`. `text` is required (5–2,000 characters) for `propose`, `agree`, `unable`, and `message`; optional explanations use the same limits. `edit` additionally requires categories, stage and renewed `share_request: true` consent, with optional details. Reuse the **same key and same payload** after uncertain network failure. Reusing a key for different content returns 409. Exact retries do not add events, messages or notifications. A new conflicting version returns 409: refresh and review before sending a new action.

Creation is limited to 30 requests/minute; actions to 60/minute under existing Laravel throttling. Database transactions, locking reads, unique keys and active-account rechecks protect writes and duplicate notifications. All requests also use existing first-party Sanctum/CSRF protection.

## Lifecycle

| Actor/action | Allowed source | Result |
| --- | --- | --- |
| Applicant create | Owned application + explicit sharing | `submitted` |
| Employer acknowledge | `submitted` | `under_discussion` |
| Employer confirm original request (`agree`) | `submitted`, `under_discussion` | `agreed` |
| Employer propose arrangement/alternative | Any non-withdrawn status | `alternative_proposed`; applicant acceptance required |
| Applicant accept | `alternative_proposed` | `agreed` |
| Applicant decline | `alternative_proposed` | `under_discussion`, proposed arrangement cleared |
| Employer unable to provide | `submitted`, `under_discussion`, `alternative_proposed` | `unable_to_provide`, explanation required |
| Applicant edit and explicitly share update | Any non-withdrawn status | `submitted`, previous agreement cleared, private history preserved |
| Either participant message | Any non-withdrawn status | Status unchanged; version/history/notification advance |
| Applicant withdraw | Any non-withdrawn status | `withdrawn`; employer loses future access to details/messages/history |

Employer confirmation is for original requested categories/details. Any changed arrangement should use a proposal. The backend rejects employer confirmation while an alternative is pending; only the applicant can accept it. Withdrawn requests are immutable and cannot be reopened. An applicant may intentionally create a new request if they want to share again. No request action changes application status or interview date/time.

## Privacy, interviews, and notifications

- Submitting a request shares only its selected labels, optional details, discussion and history with the employer associated with that application. It does not enable existing `share_accommodations` profile consent.
- Existing profile-sharing consent remains managed on Applications. Turning that choice off revokes profile preference sharing, independently of explicitly submitted requests. Withdraw an individual request to revoke that request's sharing.
- Withdrawal removes the request from employer lists and denies future detail/history/message reads and writes. Applicants retain their history. Previously viewed information cannot be made unseen. Older notification links are still subject to current authorization.
- There is no admin access to request bodies, messages or request history. No private request data is added to admin statistics or ordinary admin audit logs. Reports are a separate, intentional workflow; the request page links to it without copying private descriptions.
- A request records the interview context at submission and agreement, and displays current interview details from the original application. Rescheduling through the existing authorized workflow preserves requests, messages and history. Changed schedules are flagged for discussion/reconfirmation. Confirmed interview arrangements appear on applicant and employer application cards; withdrawn agreements do not appear.
- In-app notices use the existing `user_notifications` table and existing read/unread APIs and UI. One notice goes to the other participant per successful action. Titles are generic, previews contain no category/details/message/medical information, and links are relative and authorization checked. Withdrawal links lead to the list. Notices are transactional and do not depend on SMTP or workers. Phase 4 does not send email.
- React renders user content as text. Forms use labels, native controls, status/error announcements and existing dialogs with focus management; important acceptance/withdrawal actions require confirmation. Existing accessibility settings and styles remain in effect.

## Verification commands

```powershell
cd backend
php artisan test
vendor\bin\pint.bat --test --dirty
cd ..
npm.cmd run build
npm.cmd run lint
node scripts/browser-smoke.mjs
```

The browser harness creates and migrates a newly initialized temporary SQLite database with a guarded fixture loader, never the configured MySQL database. It also creates an isolated browser profile and temporary Vite proxy config. It requires Chrome/Edge, PHP and Node. If development ports are in use, run:

```powershell
$env:PWDWORK_SMOKE_API_PORT='18000'
$env:PWDWORK_SMOKE_FRONTEND_PORT='15173'
$env:PWDWORK_SMOKE_BROWSER_PORT='19222'
node scripts/browser-smoke.mjs
```

`PWDWORK_SMOKE_PHASE4_ONLY=1` runs the focused Phase 4 workflow for iteration; omit it for the full regression run. The existing browser suite also verifies Phase 1–3 functionality, saved-button synchronization, accessibility settings and responsive layouts. Results are recorded in `PWDWORK_IMPLEMENTATION_REPORT.md`.

## Production and later phases

Use the existing environment-based HTTPS/API, secure cookies, Sanctum, trusted-proxy and CORS settings. Use persistent MySQL with least-privilege runtime credentials, protected backups, and appropriate database transport/storage protections. Deploy the additive migration with a separate migration account if needed. Disable production debug output and do not enable request-body logging on private endpoints. No local filesystem persistence or cloud-specific code is added.

Communication is refresh-based. There are no attachments, realtime services, reminders, employer team membership, request deletion/export/retention automation, independent verification of arrangements, or automatic detection of medical information in free text. Users are instructed not to submit medical information. Native CDP keyboard injection did not reach focused documents in this Windows headless environment; browser checks use native form submission and dialog-cancel event fallbacks while verifying focus, native controls and reflow. Full keyboard-only manual testing remains necessary. Formal accessibility auditing, high-volume MySQL profiling and organizational retention rules remain deployment work. The typed lifecycle, private events, concurrency protections and regression coverage provide a base for Phase 5 without coupling requests to hiring or matching.
