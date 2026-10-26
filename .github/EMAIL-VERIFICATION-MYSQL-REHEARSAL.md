# Email verification: disposable MySQL rehearsal

Prepared 26 October 2026. **Preparation complete; MySQL rehearsal NOT RUN; production migration NOT APPROVED.**

For a computer without Docker/MySQL tools, the [dedicated-branch GitHub Actions preparation](EMAIL-VERIFICATION-GITHUB-ACTIONS.md) automates this synthetic rehearsal on GitHub-hosted Ubuntu. It includes explicit opt-in triggering, usage/billing guidance, isolated runtime containers and safe evidence. That workflow is prepared locally only and has not been executed.

Owner-supplied production facts: Aiven service `mysql-380cabbd`, MySQL **8.4.11**, automated full backups confirmed. These facts were not checked by connecting to Aiven. A completed backup is not yet evidence of a successful restore or an acceptable recovery point.

No MySQL connection, cloud service creation, image pull/build, migration, production data access, credential request, commit, push or deployment was performed for this preparation. Docker, `mysql` and `mysqldump` were not found on the current shell's PATH. Do not substitute the existing XAMPP database/MariaDB installation.

## 1. Prerequisites and boundaries

- Use an approved Linux/Bash Docker host (or an approved Linux environment with Docker). The commands below are **future instructions**, not PowerShell commands to paste into the current terminal. An existing Docker Engine host avoids an additional cloud database. Confirm any Docker Desktop licensing requirements with the owner.
- Load a reviewed MySQL **8.4.11** image and reviewed pre-feature/candidate application images locally. Pin immutable image digests in `MYSQL_IMAGE`, `BASELINE_IMAGE`, `CANDIDATE_IMAGE`; no `latest` or silent substitution of another patch version. The baseline must omit the email migration; the candidate must contain the reviewed file. The source audit used baseline commit `d495aa8eed7a683b3213f3f59440b9700adfedcb`; independently identify the actual production revision too. Building/loading those images is a separate preparatory operation; it must not deploy anything or copy `.env`/database files.
- Use a fresh container with `--network none`, no published ports, no existing data-volume mount, and only generated **synthetic** secrets. Application CLI containers share that isolated network namespace. They cannot route to Aiven, Render, Brevo or the internet. Do not mount the working repository, production environment, backup, CA or credentials.
- This rehearsal uses `pwdwork_ev_rehearsal` and a restored baseline `pwdwork_ev_restored` on the same isolated server. Neither is `pwdwork_prod`. No workers, scheduler or web server run. Verification enrollment and all actual email delivery remain off.
- Required spare RAM/disk includes two database copies, backup output and ALTER working space; measure actual use. Local Community MySQL does **not** reproduce Aiven backup/DDL behavior, storage size or traffic. A separate Aiven 8.4.11 service may later be needed for provider-specific timing/backup-lock rehearsal and managed restore proof. Forks/additional services can incur costs and need explicit owner approval; none was created here.

Read the existing [rollout gates](EMAIL-VERIFICATION-ROLLOUT.md#required-migration-and-safe-production-sequence) first. The initial-only Aiven workflow must not be reused or weakened.

## 2. Prepare an isolated server and secret files

Use a private terminal; no shell tracing or terminal recording. Assign the three reviewed image references first without any credentials. The following creates local disposable resources **only when an operator later chooses to execute it**:

```bash
set -euo pipefail
set +x
umask 077
: "${MYSQL_IMAGE:?Set the reviewed MySQL 8.4.11 image digest}"
: "${BASELINE_IMAGE:?Set the reviewed pre-feature application image digest}"
: "${CANDIDATE_IMAGE:?Set the reviewed candidate application image digest}"
docker image inspect "$MYSQL_IMAGE" "$BASELINE_IMAGE" "$CANDIDATE_IMAGE" >/dev/null
if docker container inspect pwdwork-ev-mysql >/dev/null 2>&1; then
  echo 'STOP: rehearsal container name already exists; do not reuse or delete it blindly.'
  exit 1
fi
EV_WORK=$(mktemp -d /tmp/pwdwork-ev-rehearsal.XXXXXXXX)
mkdir "$EV_WORK/secrets" "$EV_WORK/artifacts"
openssl rand -hex 32 > "$EV_WORK/secrets/root-password"
openssl rand -hex 32 > "$EV_WORK/secrets/user-password"
printf 'base64:' > "$EV_WORK/secrets/app-key"
openssl rand -base64 32 >> "$EV_WORK/secrets/app-key"
{
  printf '[client]\nuser=root\npassword='
  cat "$EV_WORK/secrets/root-password"
  printf 'protocol=SOCKET\ndefault-character-set=utf8mb4\n'
} > "$EV_WORK/secrets/root-client.cnf"
docker run --detach --pull=never --name pwdwork-ev-mysql --network none \
  --mount "type=bind,src=$EV_WORK/secrets,dst=/run/ev-secrets,readonly" \
  --mount "type=bind,src=$EV_WORK/artifacts,dst=/rehearsal" \
  --env MYSQL_ROOT_PASSWORD_FILE=/run/ev-secrets/root-password \
  --env MYSQL_DATABASE=pwdwork_ev_rehearsal \
  --env MYSQL_USER=pwdwork_rehearsal \
  --env MYSQL_PASSWORD_FILE=/run/ev-secrets/user-password \
  "$MYSQL_IMAGE"
sql() {
  docker exec --interactive pwdwork-ev-mysql \
    mysql --defaults-extra-file=/run/ev-secrets/root-client.cnf \
    --default-character-set=utf8mb4 --init-command="SET SESSION time_zone='+00:00'" \
    --batch --skip-column-names "$@"
}
ready=false
for attempt in $(seq 1 30); do
  if printf 'SELECT 1;\n' | sql >/dev/null 2>&1; then ready=true; break; fi
  sleep 2
done
test "$ready" = true
printf "SELECT VERSION(), DATABASE();\n" | sql pwdwork_ev_rehearsal
docker inspect --format '{{.HostConfig.NetworkMode}} {{json .HostConfig.PortBindings}}' pwdwork-ev-mysql
docker port pwdwork-ev-mysql
```

**Checkpoint:** version is exactly 8.4.11 (a vendor suffix may follow), database is `pwdwork_ev_rehearsal`, network is `none`, published ports are absent. Stop on mismatch. Keep the private working directory until evidence is reviewed; never print its secrets or use `docker inspect` without the restricted format above. Explicit password files avoid the official image's random-password mode, which prints its generated password. See [official MySQL image secret-file support](https://hub.docker.com/_/mysql).

## 3. Build the original schema and representative synthetic records

Define a CLI wrapper. Credentials/key travel through stdin and are never command arguments or Docker environment metadata. The normal Nginx entrypoint is bypassed; its production checks/startup are not run. Both application images must provide the repository's `/var/www/backend` layout and writable `www-data` runtime directories.

```bash
run_image() {
  local ev_image="$1"; shift
  local ev_database="${EV_TEST_DATABASE:-pwdwork_ev_rehearsal}"
  case "$ev_database" in
    pwdwork_ev_rehearsal|pwdwork_ev_partial_one|pwdwork_ev_partial_two|pwdwork_ev_unrecorded) ;;
    *) echo 'STOP: database is not on the disposable rehearsal allowlist.'; return 1 ;;
  esac
  cat "$EV_WORK/secrets/user-password" "$EV_WORK/secrets/app-key" |
    docker run --rm --interactive --pull=never --user www-data \
      --cap-drop ALL --security-opt no-new-privileges \
      --network container:pwdwork-ev-mysql --workdir /var/www/backend \
      --env APP_ENV=production --env APP_DEBUG=false --env LOG_CHANNEL=stderr \
      --env LOG_LEVEL=critical --env EMAIL_VERIFICATION_ENABLED=false \
      --env APP_CONFIG_CACHE=/tmp/pwdwork-ev-config.php \
      --env APP_ROUTES_CACHE=/tmp/pwdwork-ev-routes.php \
      --env DB_CONNECTION=mysql --env DB_HOST=127.0.0.1 --env DB_PORT=3306 \
      --env DB_DATABASE="$ev_database" --env DB_USERNAME=pwdwork_rehearsal \
      --env DB_URL= --env DB_SOCKET= \
      --env MAIL_MAILER=array --env PASSWORD_RESET_MAILER=array \
      --env ADMIN_NOTIFICATION_MAIL=false --env CACHE_STORE=array \
      --env SESSION_DRIVER=database --env QUEUE_CONNECTION=sync \
      --entrypoint /bin/sh "$ev_image" -c \
      'set -eu; IFS= read -r DB_PASSWORD; IFS= read -r APP_KEY; export DB_PASSWORD APP_KEY; exec php "$@"' sh "$@"
}
# ONLY on this fresh, isolated disposable database: build the baseline schema.
run_image "$BASELINE_IMAGE" artisan migrate --force --no-interaction
```

SQL/dump sessions below explicitly select UTC; image/session timezone settings must remain consistent. Do not use the all-migrations initialization command against production or an existing database.

Load only this synthetic fixture into the explicitly selected isolated database:

```bash
sql pwdwork_ev_rehearsal <<'SQL'
INSERT INTO users (id,name,email,password,role,status,email_verified_at,created_at,updated_at) VALUES
 (1,'Synthetic applicant','applicant@example.test',SHA2(UUID(),256),'applicant','active',NULL,'2026-01-01','2026-01-01'),
 (2,'Synthetic pending employer','pending@example.test',SHA2(UUID(),256),'employer','active','2026-01-01','2026-01-01','2026-01-01'),
 (3,'Synthetic administrator','admin@example.test',SHA2(UUID(),256),'admin','active','2026-01-01','2026-01-01','2026-01-01'),
 (4,'Synthetic approved employer','approved@example.test',SHA2(UUID(),256),'employer','active',NULL,'2026-01-01','2026-01-01'),
 (5,'Synthetic suspended applicant','suspended@example.test',SHA2(UUID(),256),'applicant','suspended',NULL,'2026-01-01','2026-01-01');
INSERT INTO applicant_profiles (id,user_id,summary,created_at,updated_at)
 VALUES (1,1,'Synthetic profile only','2026-01-01','2026-01-01');
INSERT INTO employers (id,user_id,company_name,verification_status,created_at,updated_at) VALUES
 (1,2,'Synthetic pending company','pending','2026-01-01','2026-01-01'),
 (2,4,'Synthetic approved company','verified','2026-01-01','2026-01-01');
INSERT INTO job_listings (id,employer_id,title,location,employment_type,work_mode,description,status,moderation_status,created_at,updated_at)
 VALUES (1,2,'Synthetic job','Synthetic location','Full Time','Remote','Synthetic description','open','approved','2026-01-01','2026-01-01');
INSERT INTO applications (id,user_id,job_listing_id,status,cover_letter,created_at,updated_at)
 VALUES (1,1,1,'hired','Synthetic application','2026-01-01','2026-01-01');
INSERT INTO saved_jobs (id,user_id,job_listing_id,created_at,updated_at) VALUES (1,1,1,'2026-01-01','2026-01-01');
INSERT INTO user_notifications (id,user_id,title,body,created_at,updated_at)
 VALUES (1,1,'Synthetic notification','Synthetic body','2026-01-01','2026-01-01');
INSERT INTO sessions (id,user_id,payload,last_activity) VALUES
 ('synthetic-session-applicant',1,TO_BASE64('synthetic payload A'),100),
 ('synthetic-session-admin',3,TO_BASE64('synthetic payload B'),200);
SQL
```

These password hashes and session payloads are deliberately not login credentials/cookies; the fixture tests byte preservation, not interactive authentication. Prior isolated Laravel tests cover actual cookie/session and role behavior. A later MySQL application smoke test needs separately generated synthetic login accounts/sessions in this same disposable environment and no real email, before claiming MySQL authentication coverage. Extend fixtures for production-scale synthetic row sizes/volume; five accounts cannot establish production lock duration.

Set `EV_REPO` to the absolute path of this repository on the approved host, then copy **only** the three reviewed SELECT/SHOW SQL files into the private artifacts directory. Do not mount the repository into either container:

```bash
: "${EV_REPO:?Set the repository source path}"
cp "$EV_REPO/.github/sql/email-verification-"*.sql "$EV_WORK/artifacts/"
sql pwdwork_ev_rehearsal < "$EV_WORK/artifacts/email-verification-preflight.sql" \
  > "$EV_WORK/artifacts/preflight.txt"
```

**Checkpoint:** five users, two session rows, all three account roles, both employer approval states, zero ownership columns and zero migration records for the intended migration. Compare the users column list to the original-users projection SQL: these 11 original fields must be the full list. Every application table must be InnoDB. Confirm baseline migration history matches the reviewed release. Stop on unexpected existing columns/history, SQL errors, missing permissions or schema drift.

## 4. Capture a backup, restore it and prove data equality

Do this while the isolated CLI/application is idle. Never copy real production rows into this environment.

```bash
docker exec pwdwork-ev-mysql mysqldump \
  --defaults-extra-file=/run/ev-secrets/root-client.cnf \
  --single-transaction --quick --set-gtid-purged=OFF --no-tablespaces \
  --hex-blob --routines --events --triggers \
  --result-file=/rehearsal/before.sql pwdwork_ev_rehearsal
test -s "$EV_WORK/artifacts/before.sql"
sha256sum "$EV_WORK/artifacts/before.sql" > "$EV_WORK/artifacts/backup.sha256"
printf 'CREATE DATABASE pwdwork_ev_restored CHARACTER SET utf8mb4;\n' | sql
sql pwdwork_ev_restored < "$EV_WORK/artifacts/before.sql"
```

The dump omits `--databases`, so it does not select the source database. Inspect its schema selection directives before restore; restore **only** to the new empty `pwdwork_ev_restored`. Match its database charset/collation to preflight if different; do not use the generic CREATE template unchanged when rehearsal settings differ. Check every exit status. A checksum and non-empty file alone do not prove restoration. Aiven also recommends `--single-transaction` and disabling GTID output for logical backups; see [Aiven logical backup/restore](https://aiven.io/docs/products/mysql/howto/migrate-database-mysqldump).

Define a stable data fingerprint for every other application table (all tables except users and Laravel migration bookkeeping), plus a digest of **all original users fields**. Dumps contain synthetic data only; artifacts stay private. These functions issue reads only:

```bash
other_data() {
  docker exec pwdwork-ev-mysql mysqldump \
    --defaults-extra-file=/run/ev-secrets/root-client.cnf \
    --tz-utc --default-character-set=utf8mb4 --single-transaction --quick \
    --set-gtid-purged=OFF --no-tablespaces --no-create-info \
    --skip-triggers --compact --skip-comments --skip-add-locks --skip-disable-keys \
    --skip-extended-insert --complete-insert --order-by-primary --hex-blob \
    --ignore-table="$1.users" --ignore-table="$1.migrations" "$1"
}
users_digest() {
  sql "$1" < "$EV_WORK/artifacts/email-verification-original-users-digest.sql" | sha256sum
}
schema_dump() {
  docker exec pwdwork-ev-mysql mysqldump \
    --defaults-extra-file=/run/ev-secrets/root-client.cnf \
    --set-gtid-purged=OFF --no-tablespaces --no-data --skip-comments \
    --routines --events --triggers "$1"
}
schema_dump pwdwork_ev_rehearsal > "$EV_WORK/artifacts/schema-before.sql"
schema_dump pwdwork_ev_restored > "$EV_WORK/artifacts/schema-restored.sql"
cmp "$EV_WORK/artifacts/schema-before.sql" "$EV_WORK/artifacts/schema-restored.sql"
printf 'SELECT id,migration,batch FROM migrations ORDER BY id;\n' | sql pwdwork_ev_rehearsal \
  > "$EV_WORK/artifacts/history-before.txt"
printf 'SELECT id,migration,batch FROM migrations ORDER BY id;\n' | sql pwdwork_ev_restored \
  > "$EV_WORK/artifacts/history-restored.txt"
cmp "$EV_WORK/artifacts/history-before.txt" "$EV_WORK/artifacts/history-restored.txt"
other_data pwdwork_ev_rehearsal > "$EV_WORK/artifacts/other-before.sql"
other_data pwdwork_ev_restored > "$EV_WORK/artifacts/other-restored.sql"
cmp "$EV_WORK/artifacts/other-before.sql" "$EV_WORK/artifacts/other-restored.sql"
users_digest pwdwork_ev_rehearsal > "$EV_WORK/artifacts/users-before.sha256"
users_digest pwdwork_ev_restored > "$EV_WORK/artifacts/users-restored.sha256"
cmp "$EV_WORK/artifacts/users-before.sha256" "$EV_WORK/artifacts/users-restored.sha256"
sql pwdwork_ev_restored < "$EV_WORK/artifacts/email-verification-preflight.sql" \
  > "$EV_WORK/artifacts/restored-preflight.txt"
```

**Checkpoint:** compare restored schema definitions/indexes/foreign keys for every table and exact migration rows to the source, not just counts. Data comparisons must match. The unchanged tables include session payloads, profiles, employer approval, listings, applications and all other existing application tables, even empty ones. A mismatch is a failed restoration/preservation check, not permission to ignore a table. No application writes, queue workers or DDL may run between snapshots. Record restore duration, backup checksum and MySQL/image versions.

## 5. Preview and apply exactly the intended migration

```bash
EV_MIGRATION=database/migrations/2026_10_26_000001_add_email_ownership_verification_to_users.php
sha256sum "$EV_REPO/backend/$EV_MIGRATION" > "$EV_WORK/artifacts/migration.sha256"
run_image "$CANDIDATE_IMAGE" artisan migrate --path="$EV_MIGRATION" \
  --pretend --force --no-interaction > "$EV_WORK/artifacts/preview.txt"
# STOP and review preview + candidate image's migration checksum before the next command.
# In a separate approved invocation, using exactly the same image/file:
run_image "$CANDIDATE_IMAGE" artisan migrate --path="$EV_MIGRATION" \
  --force --no-interaction > "$EV_WORK/artifacts/apply.txt"
sql pwdwork_ev_rehearsal < "$EV_WORK/artifacts/email-verification-postflight.sql" \
  > "$EV_WORK/artifacts/postflight.txt"
other_data pwdwork_ev_rehearsal > "$EV_WORK/artifacts/other-after.sql"
cmp "$EV_WORK/artifacts/other-before.sql" "$EV_WORK/artifacts/other-after.sql"
users_digest pwdwork_ev_rehearsal > "$EV_WORK/artifacts/users-after.sha256"
cmp "$EV_WORK/artifacts/users-before.sha256" "$EV_WORK/artifacts/users-after.sha256"
schema_dump pwdwork_ev_rehearsal > "$EV_WORK/artifacts/schema-after.sql"
printf 'SELECT id,migration,batch FROM migrations ORDER BY id;\n' | sql pwdwork_ev_rehearsal \
  > "$EV_WORK/artifacts/history-after.txt"
# Review these two diffs manually: expected differences are described below.
diff -u "$EV_WORK/artifacts/schema-before.sql" "$EV_WORK/artifacts/schema-after.sql" || test "$?" -eq 1
diff -u "$EV_WORK/artifacts/history-before.txt" "$EV_WORK/artifacts/history-after.txt" || test "$?" -eq 1
```

The repository file checksum alone does not attest to the file inside the image: independently compare that image's file using a no-network, entrypoint-bypassed read before approving apply. Preview runs migration PHP and is not a general read-only guarantee; this file only describes schema operations. The existing `migrations` table must already exist.

Expected preview, in this order:

```sql
ALTER TABLE users ADD email_ownership_verified_at TIMESTAMP NULL;
ALTER TABLE users ADD email_verification_required_at TIMESTAMP NULL;
ALTER TABLE users ADD email_verification_version BIGINT UNSIGNED NOT NULL DEFAULT '0';
```

**Checkpoint:** exactly these three new definitions (no automatic timestamp defaults/on-update clauses), one new ledger row, five original users, unchanged original-users digest, identical all-other-data dump, zero ownership claims, zero enrolled accounts, versions all zero. Every old migration row remains unchanged; the only expected bookkeeping addition is this migration's row/batch and its table's next auto-increment value. Schema diff must contain only the three users columns and that bookkeeping counter; indexes/foreign keys and original column definitions stay unchanged. `diff` exit 1 merely means differences exist, not that they are approved: manually inspect and stop on anything else. Do not run application smoke tests until these idle-data comparisons finish, because normal sessions/profile requests can legitimately write data.

Then perform separately scoped synthetic application checks on MySQL: old code on the additive schema while enrollment remains off, candidate registration/login for both public roles, existing synthetic administrator access, profile/API access and cookie restoration. Preserve test sessions/key across image changes. Record these separately from the idle preservation result. The previous SQLite compatibility results are not a substitute for these MySQL checks.

## 6. Partial failure, retry and lock rehearsal

**The current migration is NOT idempotent or automatically resumable.** MySQL atomic DDL applies to an individual supported InnoDB statement, not the sequence of three ALTERs plus Laravel's later ledger insert. DDL implicitly commits. See [MySQL atomic DDL](https://dev.mysql.com/doc/refman/8.4/en/atomic-ddl.html) and [implicit commits](https://dev.mysql.com/doc/refman/8.4/en/implicit-commit.html).

| Columns / intended ledger rows | Meaning | Safe next action |
| --- | --- | --- |
| 0 / 0 | Not applied, or first statement failed before committing | Resolve cause, rerun preflight/preview, obtain apply approval. |
| 1 or 2 / 0 | A committed prefix remains | Stop; do not rerun the migration. Rehearse/review a forward repair that checks exact existing definitions and adds only missing columns. |
| 3 / 0 | DDL completed but process/ledger recording failed | Stop; validate definitions/data, then separately review ledger reconciliation through a controlled Laravel repair process. Do not blindly insert/delete history. |
| 3 / 1 | Normal success, if all definitions match | Laravel rerun normally skips it; no need to rerun. Raw ALTERs or direct `up()` still fail on duplicate columns. |
| Any other state, wrong definition, or duplicate history | Drift/inconsistent state | Stop for investigation; no automatic repair, drop, reset or retries. |

No recovery script is authorized by this document. In particular, adding `hasColumn` checks alone could hide a wrong type/default and does not resolve missing ledger history.

To demonstrate each failure case, use **three new empty disposable schemas restored from `before.sql`**, never the successfully migrated copy or the restore evidence copy. Keep all traffic off. The following exact setup is for the existing isolated `pwdwork-ev-mysql` container only:

```bash
for ev_db in pwdwork_ev_partial_one pwdwork_ev_partial_two pwdwork_ev_unrecorded; do
  # CREATE fails if the name is already in use; never reuse a previous case.
  printf 'CREATE DATABASE `%s` CHARACTER SET utf8mb4;\n' "$ev_db" | sql
  sql "$ev_db" < "$EV_WORK/artifacts/before.sql"
  printf "GRANT ALL ON \`%s\`.* TO 'pwdwork_rehearsal'@'%%';\n" "$ev_db" | sql
done
sql pwdwork_ev_partial_one <<'SQL'
ALTER TABLE users ADD email_ownership_verified_at TIMESTAMP NULL;
SQL
sql pwdwork_ev_partial_two <<'SQL'
ALTER TABLE users ADD email_ownership_verified_at TIMESTAMP NULL;
ALTER TABLE users ADD email_verification_required_at TIMESTAMP NULL;
SQL
sql pwdwork_ev_unrecorded <<'SQL'
ALTER TABLE users ADD email_ownership_verified_at TIMESTAMP NULL;
ALTER TABLE users ADD email_verification_required_at TIMESTAMP NULL;
ALTER TABLE users ADD email_verification_version BIGINT UNSIGNED NOT NULL DEFAULT '0';
SQL
for ev_db in pwdwork_ev_partial_one pwdwork_ev_partial_two pwdwork_ev_unrecorded; do
  # Expected failure: shell keeps running ONLY to inspect this deliberate disposable failure.
  if EV_TEST_DATABASE="$ev_db" run_image "$CANDIDATE_IMAGE" artisan migrate \
      --path="$EV_MIGRATION" --force --no-interaction \
      > "$EV_WORK/artifacts/$ev_db-retry.txt" 2>&1; then
    echo 'STOP: an expected duplicate-column failure unexpectedly succeeded.'
    exit 1
  fi
  sql "$ev_db" < "$EV_WORK/artifacts/email-verification-preflight.sql" \
    > "$EV_WORK/artifacts/$ev_db-preflight.txt"
  other_data "$ev_db" > "$EV_WORK/artifacts/$ev_db-other.sql"
  cmp "$EV_WORK/artifacts/other-before.sql" "$EV_WORK/artifacts/$ev_db-other.sql"
  users_digest "$ev_db" > "$EV_WORK/artifacts/$ev_db-users.sha256"
  cmp "$EV_WORK/artifacts/users-before.sha256" "$EV_WORK/artifacts/$ev_db-users.sha256"
done
```

Match database default collation to the baseline if preflight shows it differs. Each captured retry must report the expected **duplicate-column error (MySQL 1060 / SQLSTATE 42S21)**; a generic connection, permission or image-startup failure does not prove retry behavior and invalidates that case. Review the protected result files before accepting them. The scoped GRANT above creates no credentials, alters no production user and applies only within this isolated disposable server. Expected observations:

1. First-prefix case: execute only the first reviewed ALTER; interrupt the operator sequence there. Read-only preflight should show 1/0. In this disposable copy only, invoke the unmodified targeted migration and expect duplicate-column failure with the prefix retained. Re-check data fingerprints.
2. Second-prefix case: a fresh restore, execute only the first two ALTERs, then demonstrate the same failed retry and 2/0 state.
3. Lost-ledger case: a fresh restore, execute all three ALTERs directly, omitting Artisan; preflight should show 3/0. Confirm a direct targeted retry fails instead of reconciling history.
4. Successful case: the fully migrated copy has 3/1; a targeted Artisan rerun should report nothing to migrate. Never delete a ledger row to manufacture these cases.
5. Lock case: in a fresh disposable copy, connection A starts a transaction and reads a users row while holding it open. Connection B sets **its own** `lock_wait_timeout=5` and executes the first ALTER. Expect a bounded wait/error and no column from that failed statement. Roll back A, re-inventory, then repeat through the normal approved sequence. A separate CLI `SET` does not configure the later Artisan connection. Use the actual runner's bounded same-connection timeout mechanism in a further rehearsal before production.

Record each executed statement, return status and post-error inventory. The wrapper's database allowlist and isolated network remain in force; do not extend them to arbitrary hosts or production names. Do not kill a shared server, drop columns or use `migrate:rollback/fresh/reset` as failure simulation.

Local MySQL may perform fast/instant changes. This does not establish Aiven duration: Aiven documents INPLACE/COPY fallback and backup locks. A matching additional Aiven service with synthetic data may be necessary to test those properties. See [Aiven backup/DDL constraints](https://aiven.io/docs/products/mysql/concepts/mysql-backups).

## 7. Production backup and controlled migration: future approval only

These are operator steps for a later separately approved session. **Do not execute them during this preparation or against production as part of a local rehearsal.**

1. Identify `mysql-380cabbd` by independently recorded project, endpoint, port and database; the service label alone is insufficient. Confirm MySQL 8.4.11, verified CA/hostname TLS, recent completed full backup, recovery range/retention and an acceptable recovery point. No need to change the confirmed working backup schedule.
2. Prove an actual managed restore in an approved isolated Aiven fork/service, using its Backups > Fork & restore capability where available. A fork can contain real data/users/configuration: it requires separate data-access and cost approval, restricted access, no application traffic/workers/outbound mail, protected evidence and eventual approved disposal. Alternatively, an approved logical backup requires a matching client, private TLS option file, encryption/restricted retention and a separate empty restore target. The [existing backup/restore procedure](EMAIL-VERIFICATION-ROLLOUT.md#verified-backup-and-restoration-future-operator-procedure) gives the secure dump pattern. Never put credentials on command lines or print account/password/session contents. A synthetic restore proves the method, not the recoverability of this production backup. See [Aiven fork and recovery-point selection](https://aiven.io/docs/products/mysql/howto/fork-service).
3. Review the pinned candidate release, exact migration checksum, staging evidence, rollback release and incremental runner. Deploy compatible code with `EMAIL_VERIFICATION_ENABLED=false`, verify its effective cached boolean, old-account features and existing test sessions. Keep APP_KEY and recovery/deferred settings unchanged. Do not activate email verification in this migration operation.
4. The repository's Aiven workflow refuses populated databases. Use a **separately approved incremental runner**, not that initial-only workflow or a web/startup endpoint. It must check the exact target independently, preserve certificate verification, use scoped secrets outside the image/repository, sanitize captured failures, serialize operators and apply a bounded same-connection DDL wait policy rehearsed beforehand. No runner is implemented by this preparation.
5. With appropriate read-only permissions, run `email-verification-preflight.sql` through a protected TLS option file:

   ```sh
   # FUTURE ONLY. This command reads production if pointed at that approved option file.
   # The file contains the independently checked target and TLS options, never printed.
   mysql --defaults-extra-file=/secure/approved-aiven-readonly.cnf \
     --default-character-set=utf8mb4 --init-command="SET SESSION time_zone='+00:00'" \
     --batch pwdwork_prod < email-verification-preflight.sql
   ```

   Treat the output as protected operational evidence. Demand zero new columns, zero intended ledger records and expected old schema/history; stop on drift or partial application. Confirm a recent restore-tested recovery point and adequate capacity. Schedule a low-traffic maintenance window away from backup locks; coordinate **all** writers and other migration operators. Do not assume a live system's session/count snapshots stay fixed while traffic runs.
6. Preview/apply in separate approved invocations of the same pinned runner/image, with the normal web entrypoint bypassed and only this path permitted:

   ```sh
   # FUTURE operator commands inside that independently verified incremental runner ONLY.
   php artisan migrate --path=database/migrations/2026_10_26_000001_add_email_ownership_verification_to_users.php --pretend --force --no-interaction
   # Separate approval after reviewing the exact preview/release and backup evidence:
   php artisan migrate --path=database/migrations/2026_10_26_000001_add_email_ownership_verification_to_users.php --force --no-interaction
   ```

   Never use unscoped `migrate --force`, initial provisioning, seeding, fresh/reset, rollback or automatic retry. Record duration and sanitized result, not raw credentials/exceptions. Stop and inventory after any timeout/disconnect; do not assume the server stopped an ALTER merely because the client disconnected.
7. Keep enrollment off. Run the postflight SQL using the same protected read-only TLS pattern. Expect three exact definitions, one ledger record and no ownership/enrollment backfill. Verify roles, employer approval, authorized existing test sessions/features and safe error rates. For exact production preservation evidence, use separately approved protected consistent comparisons with writers coordinated; never print row data or treat changing live counts as proof of corruption. Any inability to establish the required evidence is a stop condition. Activation remains a separate rollout gate after all delivery/enforcement checks.

Render Free lacks shell and one-off jobs; it does not require upgrading merely to migrate if an approved external runner exists. Paid Render compute or an approved external runner may be needed if none is available. A separate database/fork for managed restore and Aiven-specific rehearsal may also require paid resources. Confirm pricing/quotas with the owner before provisioning anything. See [Render Free limits](https://render.com/docs/free).

## 8. Rollback, restoration and evidence

- Before any enrollment: application rollback can use the proven pre-feature image while retaining additive columns. Do not run `down()`; it deliberately refuses to remove ownership evidence.
- After any enrollment: use a compatible application rollback retaining the ownership guard. Turning enrollment off does not remove existing requirements, but new signups would otherwise become legacy; pause signup through an approved mechanism during an incident.
- On partial DDL: stop apply and leave enrollment off; determine exact columns/definitions/history and server-side statement state. Do not restart/drop/overwrite the database, delete migration history or rerun blindly. Forward repair needs separate review/approval; none is supplied here.
- Full database restore is disaster recovery, not normal application rollback. Preserve the damaged source, stop writers/workers under incident approval, restore into a new verified target, validate it, then approve cutover. Reconcile writes since the recovery point, including ownership requirements; restoring pre-enrollment data without reconciliation can reopen access.
- Preserve private checksums, definitions, exact migration history, data-comparison results, image digests, version, preview, duration, restore evidence and each failure-case result. Do not publish dumps, credentials or session data. After evidence acceptance, dispose **only** the exact identified rehearsal containers/anonymous volumes and private artifacts under an approved local retention/cleanup process; never use global Docker prune, computed broad deletes or existing local MySQL paths.

## Readiness verdict

**READY to schedule the isolated rehearsal once the local prerequisites exist. NOT READY to approve production migration or activation.** No MySQL rehearsal, backup restore, timing measurement, partial-failure test or MySQL authentication smoke was executed here. Previously passing SQLite tests remain useful application evidence but do not fill these gaps. Automated full backups are confirmed by the owner; actual restore proof and a recent acceptable recovery point remain mandatory. Additional paid cloud resources are optional for the local synthetic rehearsal but may be necessary for managed restore proof/provider-specific behavior. No costs were incurred by this preparation.

Offline preparation checks: Bash syntax passed for all eight command blocks without executing them. The three SQL files contain 23 SELECT/SHOW statements and passed a static read-only statement check; this is not MySQL syntax/execution certification. The isolated Laravel MySQL-grammar regression passed (one test, two assertions, no MySQL connection), and whitespace checks passed. Application code, migration and production settings are unchanged by this preparation.
