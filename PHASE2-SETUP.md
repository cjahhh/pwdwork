# Phase 2 administration and safe upgrade

Phase 2 extends the existing React/Laravel/Sanctum/MySQL application. It does not replace authentication, reset records, deploy a host, or require XAMPP in production. Install and run the application using the root README.

## Apply the migration safely

New migration: `backend/database/migrations/2026_10_24_000002_add_administration_workflows.php`.

It adds employer review reason/timestamps/version, a user administrative version, separate job moderation status/reason/timestamp/version, notification navigation links, indexed report records, and indexed audit records. Existing users, employers, jobs, saved jobs, applications, passwords, verification values and availability values remain intact. Existing application statuses, including legitimate hired records, are not changed.

**Publication changes intentionally:** every existing job defaults to pending moderation. It remains stored, but public search, public detail, save, and apply require an approved and open listing from a verified, active employer. Arrange an authorized admin review before expecting previous listings to reappear. No migration or seeder automatically approves them. Existing saved records stay saved; unavailable listings display a neutral placeholder without unpublished content. Existing applications stay accessible to the owning applicant/employer.

Before an existing-database upgrade, take and verify a backup, review the migration on staging and check table sizes/DDL lock implications with your MySQL/MariaDB provider. From backend, inspect `php artisan migrate:status` and `php artisan migrate --pretend`; then an authorized operator can run `php artisan migrate`. Production operators use the provider's release process and, when required, Laravel's `--force` flag after review. This implementation did not run the Phase 2 migration against your existing database. Tests only migrate isolated SQLite databases. Avoid reset/import/rollback commands as an upgrade strategy.

## Provision the first administrator

Use an authorized interactive server/developer console after migrations:

```powershell
cd backend
php artisan pwdwork:provision-admin
```

Email and name can optionally be supplied through `--email` and `--name`. The password and confirmation are always prompted privately; there is no password argument/environment option. Passwords require at least 12 characters, upper/lower case and numbers. Production asks for confirmation. Noninteractive use is rejected. The command creates a new active admin and a CLI audit event in one transaction. It refuses to overwrite or promote an existing account. Console access is privileged and should be restricted to authorized operators. No account is created merely by installing dependencies or running migrations.

Admins sign in at the normal login page and are directed to `/admin`. Public registration accepts only applicant and employer roles, regardless of submitted role/status/verification fields. Existing historical demo admin records are preserved; an operator must secure/restrict any publicly known development credentials before exposure. The development seeder no longer creates administrators.

## Administrator workflow

The admin navigation contains Overview, Employer verification, Job moderation, User management, Reports and complaints, and Audit logs. All data comes from Laravel queries. Statistics count registered users by role, verification states, all job listings, publicly eligible jobs, pending jobs, all submitted application records, pending reports, and suspended accounts. Queues and counts refresh on page navigation; explicit Refresh controls reload current data after actions and when other administrators may have acted.

List endpoints use the existing named-array plus `meta` pagination contract: 12 records by default, `per_page` 1-50. All filters apply on the server before pagination. Searches are bounded strings and use parameterized Eloquent queries. Composite indexes support roles/statuses, moderation/availability, report ownership/status, and audit targets/actions. Substring search is not a full-text index; measure and tune against production volume.

Company review shows company name, description, location/address, website, account contact name/email, registration date, verification and account status. No identity/medical documents are collected. Administrators approve or reject; rejection requires a reason. Decisions and their reasons appear to the employer in Company profile, its paginated history, and Notifications. Verified company detail changes set verification back to pending; rejected companies save corrections then explicitly request review. Existing verification decisions are retained in history rather than overwritten. Reviewing a previously decided employer with stale data returns 409.

Unverified employers may save a closed, pending listing. Verified employers can submit it for publication; a verified employer's new listing starts open/pending. Admin approval does not change availability, so an approved closed listing remains closed until its owner reopens it. Content edits to an approved listing reset moderation to pending; availability-only changes retain approval. Rejected listings retain their reason during correction and can be resubmitted. Hidden listings remain hidden after employer edits and cannot be resubmitted by their owner; an administrator must explicitly restore approval after review. Approval requires a currently verified, active employer. Verification loss or suspension immediately removes otherwise approved jobs from public eligibility; successful re-verification/reactivation can restore their eligibility if their content is still approved and availability remains open.

Every admin decision includes `expected_version`; the server locks and rechecks the current record and active admin before writing. Stale versions return 409 and require refresh/review. Company/job edits increment the same review version. Admin status changes use `users.admin_version`. A common admin lock order protects restrictions and prevents simultaneous operations from removing the last active administrator. These locks are designed for MySQL transactions; the automated SQLite suite does not prove production concurrent load behavior.

Account restriction/reactivation dialogs require a reason and explicit confirmation. Admins cannot suspend themselves or leave the platform without an active administrator. Suspension revokes Sanctum tokens and database sessions, while the role/status middleware independently blocks protected actions for every non-active account on each request. React clears stale auth state on the account_inactive response code. Reactivation does not restore revoked credentials; users sign in again. Existing inactive/pending account values remain readable/filterable and can be explicitly reactivated. No user role-changing or business-record deletion API was added.

## Reports and privacy

Authenticated jobseekers and employers can use `/reports` for platform/account support and relevant job/employer/account complaints. Job details and hiring interaction screens contain Report links with IDs filled in. Supported categories cover suspicious jobs, misleading descriptions, inaccurate accessibility, discrimination, suspicious employers, and account/platform issues. Categories are checked against the target type. Job/employer targets must be public or related to the reporter's hiring interaction; private unrelated accounts cannot be enumerated. Platform reports need no target ID.

Submission validates description length (10-5000), category, target and active account, and is limited to five attempts/minute/account using the existing shared Laravel cache. An indexed nullable fingerprint prevents concurrent duplicate open reports for the same reporter/target/category. Resolution/dismissal releases that fingerprint so a recurring problem can be reported again. Closed reports cannot be reopened; internal note corrections on a closed report are allowed and audited.

Reports progress through pending, under_review, resolved, or dismissed. Admins can search/filter, review, assign themselves by recording a decision, enter private moderation notes, and record a resolution/dismissal reason. Links take the reviewer to the reported job/employer/account controls for appropriate separate action. Reporter lists contain only that user's report text, target, status and dates, never internal notes or another reporter's details. Reporter identity and internal notes are admin-only. The admin account directory deliberately excludes applicant profiles, disability fields, PWD identifiers, resumes, passwords and tokens. Do not put sensitive medical or authentication information in free-text reports/reasons/notes; the system does not automatically redact authored text.

## Audit and notification consistency

`admin_audit_logs` is a single append-only API journal used for employer/job review histories and administrative activities. It records actor ID/role, action, target type/ID, before/after statuses, timestamp and a reason. It does not snapshot whole models, credentials or applicant profiles. Initial employer registration, company/job corrections and resubmission events are labeled with the employer actor role, distinct from admin decisions. Owner history endpoints scope to that employer's own target records. Only active admins can read the complete journal; no API permits editing or deleting it.

Verification, moderation, account restrictions and report decisions save the status, audit record and in-app notification in one database transaction. Audit-write failure rolls the action back. In-app notifications are durable immediately and can be read/marked read only by their owner at `/notifications`. They do not require an email provider or running worker.

Optional email:

```dotenv
ADMIN_NOTIFICATION_MAIL=false
ADMIN_NOTIFICATION_QUEUE_CONNECTION=database
ADMIN_NOTIFICATION_QUEUE=notifications
```

Set the flag true only after SMTP and the queue are configured. The notification implements Laravel ShouldQueue and is dispatched after commit with three attempts and 60-second backoff. It uses FRONTEND_URL for links. An enqueue failure is logged and does not undo a committed decision; the in-app notification remains. A process crash between commit and optional mail enqueue can lose that email; this is not an email outbox/delivery guarantee. Monitor failed jobs and provider delivery. Password recovery retains its separate connection/queue settings. A worker matching the examples can serve both:

```powershell
php artisan queue:work database --queue=password-resets,notifications,default --tries=3 --timeout=60
```

Use an asynchronous production connection and a timeout below its retry interval. Restart supervised workers after code/config changes. Configured database sessions/cache and env-based URLs remain portable to cloud deployments; see the existing deployment section for HTTPS, exact CORS/Sanctum domains, proxy allowlists, DB TLS/backups, private storage and log handling. No hosting change is required.

## New API routes

All routes below require Sanctum authentication and an active account. Admin routes additionally require role admin, enforced before controller/Form Request handling.

| Method | Path (after `/api`) | Behavior |
| --- | --- | --- |
| GET | `/admin/overview` | Real aggregate statistics |
| GET | `/admin/employers` | Paginated query/status/employer_id filters |
| GET | `/admin/employers/{id}` | Company review details |
| PATCH | `/admin/employers/{id}/verification` | verified/rejected; reason and expected_version |
| GET | `/admin/jobs` | Paginated title query, moderation status, employer_id, job_id |
| GET | `/admin/jobs/{id}` | Job and company review details |
| PATCH | `/admin/jobs/{id}/moderation` | approved/rejected/hidden; reason and expected_version |
| GET | `/admin/users` | Paginated name/email query, role, status, user_id |
| GET | `/admin/users/{id}` | Selected basic account fields |
| PATCH | `/admin/users/{id}/status` | active/suspended, mandatory reason and expected_version |
| GET | `/admin/reports` | Paginated query/status/category filters |
| GET | `/admin/reports/{id}` | Private report and internal review details |
| PATCH | `/admin/reports/{id}` | status, reason, internal_notes, expected_version |
| GET | `/admin/audit-logs` | Paginated query/action/target_type/target_id/actor_id filters |
| POST | `/employer/verification-request` | Rejected employer resubmission, expected_version |
| GET | `/employer/verification-history` | Owner-scoped paginated history |
| POST | `/employer/jobs/{id}/submit` | Verified owner's listing submission, expected_version |
| GET | `/employer/jobs/{id}/history` | Owner-scoped paginated history |
| GET/POST | `/reports` | Own paginated reports / private validated submission |
| GET | `/notifications` | Own paginated notifications |
| PATCH | `/notifications/{id}/read` | Mark own notification read |

Use canonical lowercase statuses. Version conflicts return 409, validation/workflow errors 422, role/ownership errors 403, missing/unavailable public resources 404, expired authentication 401/419, throttling 429, and successful creation 201. There are no public admin-registration, role-promotion or delete endpoints. Cookie-based writes retain CSRF protection.

## Verification and release checks

Run the existing `php artisan test`, frontend build/lint, PHP syntax and Pint checks. `node scripts/browser-smoke.mjs` tests baseline and admin workflows with fake records in a newly initialized temporary SQLite file, isolated browser profile, and optional email disabled. It never uses the existing MySQL database. PHPUnit uses its existing :memory: guard and forces the optional-email flag off except in explicitly faked notification tests.

The implementation report records actual executed results. Before release, validate the migration and representative concurrent actions against dedicated MySQL/MariaDB staging data, verify the real domains/HTTPS/proxy/CORS/cookies, secure all existing admin accounts, deliver real SMTP messages with supervised workers, and complete manual keyboard/assistive-technology/zoom/contrast checks. Native dialogs, page navigation, responsive layouts and the existing accessibility preferences are used; no full WCAG conformance claim is made.
