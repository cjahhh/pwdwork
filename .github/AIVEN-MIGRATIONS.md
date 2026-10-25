# Initial Aiven migrations

This workflow is prepared for manual use in `cjahhh/pwdwork` on `main` only.
Adding these files does not connect to Aiven. It does not deploy the application,
push an image, change production configuration, or access local XAMPP data.
Existing migrations and the Dockerfile are unchanged.

## Setup before an approved manual run

Configure these repository secrets in GitHub Actions:

| Secret | Value |
| --- | --- |
| `AIVEN_DB_HOST` | Exact Aiven service DNS hostname, not an IP address |
| `AIVEN_DB_PORT` | Aiven service MySQL port |
| `AIVEN_DB_DATABASE` | `pwdwork_prod` |
| `AIVEN_DB_USERNAME` | Approved migration account |
| `AIVEN_DB_PASSWORD` | Migration account password |
| `AIVEN_CA_PEM` | Full PEM CA certificate, with real newlines |
| `AIVEN_APP_KEY` | Existing production Laravel `APP_KEY`; required for PREVIEW/APPLY |

Never generate or rotate `APP_KEY` as part of a migration run. INSPECT does not
bootstrap Laravel and does not need the key.

Also set **repository variables** `AIVEN_EXPECTED_HOST` and `AIVEN_EXPECTED_PORT`
from the independently approved deployment record. These must exactly match the
host and port secrets. They are required: no guessed hostname or default port is
accepted. The database name is independently fixed to `pwdwork_prod` in code.
The service hostname must end in `.aivencloud.com`; a custom DNS alias requires
reviewing this guard first. MySQL must report version 8.4.

Use an account scoped to the target database with only the permissions needed
by the existing migrations. INSPECT only issues SELECT/SHOW queries and can use
a read-only account. PREVIEW may require CREATE for migration bookkeeping.
Do not broaden Aiven's IP allowlist, disable TLS checks, or make the service
public to accommodate GitHub-hosted runners. If the Ubuntu runner cannot reach
the service through an already approved network path, this workflow must fail;
arrange an approved runner/network design separately.

## Manual sequence

1. After these files have been reviewed and separately merged/pushed with your
   approval, open **Actions → Aiven initial migrations → Run workflow**. Select
   `main` and leave the operation at **INSPECT**. It verifies the exact endpoint,
   database, CA chain, server hostname and negotiated TLS cipher. It prints table
   names/count and migration-history count. It accepts zero tables, or only an
   empty `migrations` table left by PREVIEW. Any other tables/views or recorded
   migrations fail the suitability check. INSPECT never runs Artisan.
2. Make a **separate manual invocation**, explicitly selecting **PREVIEW**.
   Read the full commit SHA printed by the workflow, the pending migration names,
   and the SQL from `php artisan migrate --pretend --force`.
   **Laravel may create its `migrations` bookkeeping table even with `--pretend`.**
   See Laravel's [migration command implementation](https://github.com/laravel/framework/blob/12.x/src/Illuminate/Database/Console/Migrations/MigrateCommand.php).
   Application migration SQL is printed, not applied, and the helper checks that
   no application tables or populated history appeared afterward. As with any
   Laravel pretend run, migration PHP is executed: custom non-database side
   effects or explicit alternate connections would require separate review.
   The current migrations use the default schema connection; keep that review
   requirement when adding migrations. Pretend SQL is advisory, not a guarantee
   that MySQL will accept every statement.
3. Review a **successful** PREVIEW run. Make another **separate manual invocation**
   for **APPLY**, enter exactly `APPLY pwdwork_prod`, and paste the full 40-character
   commit SHA from that PREVIEW into `reviewed_sha`. The dispatched `main` commit
   must still equal that SHA; if main changed, run and review PREVIEW again.
   This is the operator's attestation of review; the workflow does not query
   GitHub run history to prove that a PREVIEW was completed.
4. APPLY rechecks TLS, the effective Laravel connection and initial schema, then
   runs only `php artisan migrate --force` as the schema-changing command. It
   runs `php artisan migrate:status` afterward and checks every migration in the
   image is recorded with no unexpected history entries. It never seeds, resets,
   refreshes, rolls back, or drops the database.

Only one operation runs per invocation. Workflow runs are serialized without
cancelling an in-progress migration. GitHub concurrency does not exclude other
database clients: keep application writers and other migration operators stopped
during initialization. MySQL DDL is not generally transactional. If APPLY fails
partway, investigate the partial state manually; do not retry this initial-only
workflow or delete tables/history to make its guards pass. Once initialized,
future schema upgrades need a separately reviewed deployment workflow.

## Secret handling and diagnostics

The image is built before deployment secrets enter the execution step. No secret
is passed as a Docker build argument. The existing Docker context excludes local
environment files, certificates, database files and data dumps. Only the CA and
workflow helper are mounted, read-only, into a disposable container; no local
application directories or databases are mounted. The web-server entrypoint is
bypassed. Configuration is runtime-only and no production settings are edited.

The CA lives in a random runner temporary directory (0700), in a file with mode
0600. It is never printed or passed into the container environment. A cleanup
trap removes the container and certificate on normal exit/failure; GitHub-hosted
runners are ephemeral if abrupt termination prevents cleanup. Credentials/key are
forwarded by environment-variable name, never interpolated into command strings.
Do not enable shell tracing, dump Docker metadata, or add environment logging.
Actions has only `contents: read`, and checkout does not persist a GitHub token.

PDO/framework exception details and failed Artisan output are withheld to avoid
leaking credentials. Successful Artisan output redacts credential values and is
prefixed to prevent runner-command injection. A failed connection should prompt
checking secret presence, independent target variables, the CA and certificate
hostname, approved network reachability and database privileges. Do not disable
certificate verification to troubleshoot. SQL review output can reveal schema
details; restrict access to repository Actions logs appropriately.

## Offline validation

From the repository root, these commands only parse PHP and test pure guards;
they do not bootstrap Laravel or connect to any database:

```sh
php -l .github/scripts/aiven-migrations.php
php .github/scripts/aiven-migrations.php --self-test
actionlint .github/workflows/aiven-migrations.yml
```

Docker build/container integration and database behavior must be checked later
in the explicitly approved manual GitHub runs. No local Docker installation is
needed to use the workflow.

Validation performed during preparation: actionlint 1.7.12 accepted the workflow
(including its YAML and Actions expressions); both multiline Bash scripts passed
`bash -n`; PHP syntax validation passed; six accepted and eleven rejected pure
guard cases passed; seven offline executions of the authorization step passed,
including rejected shell-injection input. ShellCheck was not installed, so
actionlint's optional ShellCheck integration was disabled. No Docker build,
container integration, cloud operation, or database connection was performed.
