# One-time first administrator setup

The existing `pwdwork:provision-admin` console command securely creates a **new**
administrator with an interactively entered password. It cannot promote the
already registered account while preserving its password, so this workflow uses
a separate, workflow-only helper. The command and application are unchanged.
The normal authentication system uses `users.role = admin` and `status = active`;
the frontend `/admin` dashboard and backend admin routes already enforce this.
Public registration continues to accept only `applicant` and `employer` roles.

## Prepare privately

Use a **dedicated administrator account**, already registered through the normal
Jobseeker registration flow. It must have role `applicant`, status `active`, a
nonempty password hash, and no employer record. Do not select an existing
Jobseeker or Employer test account. The application does not label test accounts,
so that distinction must be made by the authorized operator selecting the secret.
Only the selected account's role changes. Its password, name, email, timestamps,
remember token, profile and other data are preserved; other users are untouched.

Configure repository secrets (never workflow input fields):

| Secret | Required value |
| --- | --- |
| `PWDWORK_BOOTSTRAP_ADMIN_EMAIL` | Exact stored email of the dedicated registered account, including case; no surrounding whitespace |
| `AIVEN_DB_HOST` | Approved Aiven DNS hostname |
| `AIVEN_DB_PORT` | Approved MySQL port |
| `AIVEN_DB_DATABASE` | `pwdwork_prod` |
| `AIVEN_DB_USERNAME` | Authorized account scoped to this database |
| `AIVEN_DB_PASSWORD` | Its existing password |
| `AIVEN_CA_PEM` | Aiven CA PEM with real newlines |
| `AIVEN_APP_KEY` | Existing production Laravel key; do not generate a new key |

Repository variables `AIVEN_EXPECTED_HOST` and `AIVEN_EXPECTED_PORT` must contain
the independently approved endpoint values, matching the corresponding secrets.
No default host/port is guessed. The helper verifies the effective Laravel target,
selected database, MySQL 8.4, verified certificate/hostname options and negotiated
TLS. Leave Aiven's network restrictions intact; failed reachability must be
resolved through an approved network design, not a public allowlist.

The database account needs SELECT on the relevant tables, UPDATE on `users`, and
INSERT on `admin_audit_logs`. No migration, seeding, schema-change or reset
permission is required by this helper. InnoDB is required for all three tables
involved (`users`, `employers`, `admin_audit_logs`). It does not run migrations or
seeders, discover pending migrations, or create tables.

## Exact manual steps (after separate approval of source changes)

1. Review these files and separately approve making them available on `main`.
   Confirm the account selected by the private email secret is the dedicated
   administrator account, not a test user.
2. In GitHub, open **Actions → PWDWork First Admin Setup → Run workflow**.
3. Select branch **main**. Enter exactly `CREATE FIRST PWDWORK ADMIN` in
   `confirmation`. Do not enter an email or password in that field.
4. Run once. Review only the safe success/refusal status. No email, account ID,
   password, key, token, certificate, SQL, bindings or connection string is logged.
5. On success, log out and sign back in through the normal PWDWork login using
   the existing account credentials. Open `/admin` and verify dashboard access.
   Inspect the existing audit interface for `admin.first_bootstrapped`, recorded
   with actor role `cli` and the affected user ID, without an email in the reason.

Promotion and audit insertion share a transaction. The helper uses serializable
MySQL isolation and locking to guard the empty administrator set, then checks
that exactly one administrator exists and that it is the selected active user
before committing. This can briefly block concurrent user writes; use a quiet
period and do not run other administrator provisioning tools at the same time.
Workflow concurrency also serializes this job with initial Aiven migration runs.
There are no automatic transaction retries. Audit/verification failure rolls back.

If **any** admin already exists, including a suspended admin, the safe result is
`administrator_already_exists` and no promotion. Do not remove or demote admins
to bypass this protection. Other refusals identify fixed categories (wrong
target, missing exact account, ineligible account, missing settings). Framework
failures are withheld with the fixed stage label. A successful commit followed
by a runner interruption can leave success uncertain: verify through normal
authorized access; a repeat run refuses if an admin exists. No direct recovery
SQL is part of this workflow.

The Docker build receives no deployment secrets. Runtime uses `www-data`, a
0600 CA owned by that image user in a private temporary runner directory, a
read-only mount, debug disabled, and the null logger. Cleanup removes the
container and temporary CA on ordinary completion/failure; hosted runners are
ephemeral if abruptly terminated. Checkout does not persist credentials and
GitHub permissions are only `contents: read`. Manual inputs enter the shell only
as quoted environment values; the email is never a shell argument.

## Disable immediately after success

In Actions, open the workflow menu and select **Disable workflow**. Delete the
`PWDWORK_BOOTSTRAP_ADMIN_EMAIL` repository secret. Then separately review and
remove `.github/workflows/first-admin-setup.yml`,
`.github/scripts/first-admin-setup.php`, and the associated
`backend/tests/Feature/FirstAdminSetupTest.php`. Keep this document/audit record as
appropriate. The helper is excluded from the application image and is never
installed as an Artisan command or HTTP endpoint, so removal needs no production
application change. Leave shared Aiven secrets used by other workflows intact.

## Validation

Run `php -l .github/scripts/first-admin-setup.php`, actionlint on the workflow,
and, from `backend`, `php vendor/bin/phpunit tests/Feature/FirstAdminSetupTest.php`.
The test builds only synthetic SQLite `:memory:` fixtures without migrations or
seeders. It checks exact email, eligibility, confirmation, existing admins,
atomic audit rollback, unchanged unrelated users/passwords, target guards,
public role validation and existing backend admin access restrictions. MySQL
lock behavior and container integration require separately approved testing;
these tests do not connect to Aiven or activate a real administrator.
