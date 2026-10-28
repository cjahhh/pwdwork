# Single email ownership migration: controlled production runner

**Preparation only. No production connection, migration, setting change, commit,
push or deployment was performed.** The owner reports that the disposable MySQL
8.4.11 rehearsal passed all 26 checks. That run has not been independently
inspected during this preparation. Keep its immutable run URL, reviewed commit
and receipt with the release evidence. Review its original-schema, restore,
preservation and interrupted-DDL results before approving production use.

The workflow is `.github/workflows/email-verification-incremental.yml`. Its helper
is `.github/scripts/email-verification-incremental.php`. The initial-only Aiven
workflow stays unchanged and must not be used for this populated database.
This document supersedes any older suggestion to fork this Aiven Free service.

**28 October 2026 review: the production workflow now has a literal `false`
job condition and cannot run PREVIEW or APPLY.** Removing that release hold
requires a separate reviewed authorization. The [runner safeguard review and
disposable test plan](EMAIL-VERIFICATION-INCREMENTAL-SAFEGUARDS.md) records local
results, the unexecuted 32-scenario MySQL harness, and the external two-person
approval alternative for a private repository without Required reviewers.

## What the runner allows

Only `cjahhh/pwdwork`, `main`, a first-attempt `workflow_dispatch`, and the exact
reviewed dispatch SHA are accepted. There is no push, pull-request, schedule,
workflow-run trigger or deployment step. Re-runs are refused; start a fresh
reviewed dispatch after investigation. It shares the initial/admin workflow's
concurrency group and holds a MySQL advisory lock on the actual migration PDO.
This serializes these workflows; it does not stop other database clients.

The only executable migration command is the equivalent of:

```text
php artisan migrate --database=mysql --path=database/migrations/2026_10_26_000001_add_email_ownership_verification_to_users.php --force --no-interaction
```

It runs inside the helper, on the already verified PDO. Do not replace this with
an unrestricted command. No fresh/reset/refresh/rollback, seeders, key generation,
history repair or all-pending migration execution is provided. `PREVIEW` compiles
the migration's `up()` under Laravel's pretend mode and checks all three exact
statements without invoking the migrate command or creating bookkeeping.

The existing rehearsal manifest pins all 16 migration files and four SQL files.
Both mounted source and the migration files in the built image are checked.
The intended file's LF-normalized SHA256 is:

```text
fd28dca332d95c120293375d799e48d2941333ef5eea308af6609c0b4c51fc6b
```

The helper requires all 15 reviewed earlier migration records, exactly once,
positive batches, an existing ledger and the original users column inventory.
Missing/unknown/duplicate history, views, non-InnoDB tables, tables without primary
keys, already-applied changes and **any** of the three verification columns
before application cause a stop. It never automatically reconciles partial DDL.
Existing application schema must also match the separately approved PREVIEW hash.
The existing preflight/postflight SQL is reused without printing query results.
Insufficient read privileges (including the existing metadata-lock check) stop
the job; do not broaden permissions blindly to get past an error.

`APPLY` streams every original column of every table in primary-key order into
private hashes before/after, including passwords, roles, approvals, sessions,
application records, queues and caches. Data are not exported. The only excluded
data are the three new users columns and this one new ledger row; only the
ledger's auto-increment advance is excluded. Other counters/schema/indexes/foreign
keys must match. The new columns must be nullable timestamp NULL, nullable
timestamp NULL, and unsigned BIGINT NOT NULL DEFAULT 0, in that order. Every
existing user's values must remain NULL/NULL/0. The ledger must gain exactly one
record in the next batch. The helper reports success only if all checks pass.

**Pause every writer throughout APPLY.** This includes web/session traffic,
workers, schedulers, admin tools, health checks that write, and other schema tools.
Otherwise session/cache changes alone will correctly fail preservation checks.
The helper cannot enforce maintenance at Render or freeze external clients.
Its advisory lock is cooperative, not a global database write lock. The owner
and independent reviewer must confirm quiescence, and maintain it from before
the fresh backup until postflight is accepted. A mismatch after DDL is a failure
requiring investigation, not permission to undo data or rerun.

The exact target is `pwdwork_prod` on service `mysql-380cabbd`, MySQL **8.4.11**.
The DNS endpoint and port must match independently recorded variables, the CA
must validate the server certificate/hostname, TLS must be negotiated, and
`@@server_uuid` must match the independently recorded UUID. A failover or version
upgrade may legitimately change identity; stop and re-review rather than weaken
the guard. The source service label alone is not proof of identity.

The later authorized owner can obtain the independent identity using only
`SELECT VERSION(), DATABASE(), @@server_uuid;` and
`SHOW SESSION STATUS LIKE 'Ssl_cipher';` over a verified TLS connection. Review
the existing preflight SQL privately for table sizes and metadata-lock counts.
The workflow is not a free-space or provider-backup monitor: confirm sufficient
disk/headroom and avoid backup locks separately. Aiven can fall back to rebuilding
tables for DDL; three additive statements are not a promise of instant changes
or zero downtime. Stop if the maintenance window/resources are insufficient.

The runner forces `EMAIL_VERIFICATION_ENABLED=false`, uses a private temporary
configuration and null logger, and bypasses the Docker web entrypoint. It starts
no workers and changes no Render environment, password recovery configuration,
APP_KEY, application permissions or credentials. **This does not prove Render's
live flag is false:** check the deployed compatible release and effective flag
independently and record that in the approved evidence. Keep the stable existing
APP_KEY, session configuration and Brevo/deferred-delivery configuration.

## One-time prerequisites, requiring later owner approval

1. Review/merge/register this workflow on the default branch through the approved
   release process. Merging `main` can trigger Render's existing auto-deploy;
   coordinate the compatible, flag-off release first. Nothing here merges,
   pushes, deploys or changes Render settings. Do not dispatch an unreviewed SHA.
2. Pre-create **two** GitHub environments:
   `pwdwork-email-migration-preview` and `pwdwork-email-migration-apply`.
   Restrict both to `main`, require independent human reviewers, enable prevent
   self-review, and disable administrator bypass. Protect workflow/helper changes
   with the repository's independent code review process.
3. Verify the repository plan supports these protection rules. Required reviewers
   on Free/Pro/Team are available for public repositories; private repositories
   need a supported plan. If unavailable, **stop**: do not remove the gate or
   silently create an unprotected environment. The [external two-person approval
   alternative](EMAIL-VERIFICATION-INCREMENTAL-SAFEGUARDS.md#approval-without-protected-github-environments)
   or a plan change requires a separate decision and reviewed implementation.
   The runner also checks the actual review
   history with its read-only token, rejecting absent approval, bots, self-review,
   ambiguous histories and reruns. No approval/write API permission is granted.
4. Reuse the existing approved Aiven secrets, preferably scoped to these protected
   environments: `AIVEN_DB_HOST`, `AIVEN_DB_PORT`, `AIVEN_DB_DATABASE`,
   `AIVEN_DB_USERNAME`, `AIVEN_DB_PASSWORD`, `AIVEN_CA_PEM`, `AIVEN_APP_KEY`.
   No values were requested, read, printed, created or changed in preparation.
   An existing authorized non-root account must have the necessary SELECT,
   metadata, ALTER and ledger INSERT privileges. Avoid root and excessive grants.
5. Independently set/review the environment variables `AIVEN_EXPECTED_HOST`,
   `AIVEN_EXPECTED_PORT`, `AIVEN_EXPECTED_SERVER_UUID` in both environments.
   Verify UUID with the owner-authorized read-only identity query, not by trusting
   a failed run's error. No new production network access is authorized here.
6. Confirm an already approved network route allows the GitHub runner to reach
   Aiven. Do not open the database to all addresses, disable TLS verification or
   alter credentials/network rules to make this workflow work.
7. Rehearse this **new runner** with the [separate disposable-only harness](EMAIL-VERIFICATION-INCREMENTAL-SAFEGUARDS.md).
   It exercises certificate validation and the byte-identical PHP helper; the
   GitHub approval API responses are mocked offline. A live no-database-secret
   approval-gate check remains separate. The 26-check rehearsal exercised the
   migration, not this newly prepared production helper. Do not weaken production
   identity guards or substitute production secrets in a testing branch.

GitHub-hosted Ubuntu minutes, image downloads and short-lived receipt artifacts
can consume plan quotas/billing. Public standard runners generally have free
usage; check actual repository entitlements and budget before running. No cloud
service is created. No costs or workflow execution were authorized or incurred
by this preparation. Network and image-build failures must stop the operation.

## Aiven Free backup proof and recovery

Aiven Free does not support service forking and allows one MySQL service per
organization. Its automated backup confirmation is valuable, but is **not** proof
that the owner can restore this production dataset within the required outage.
Do not assume a downloadable managed backup, self-service PITR, a free second
service, or a support/SLA recovery promise. Verify the exact available managed
recovery options separately with Aiven. Do not provision services, create another
organization to evade limits, upgrade plans, or incur costs automatically.

Use the logical backup procedure in
[the rollout runbook](EMAIL-VERIFICATION-ROLLOUT.md#verified-backup-and-restoration-future-operator-procedure)
with these Free-compatible constraints:

1. Obtain separate owner approval for production read access and the backup
   window. Pause all writers, confirm all relevant tables are InnoDB, no concurrent
   DDL, and keep enrollment off. Use matching **MySQL 8.4** client tools, private
   CA and option files outside this repository on an approved secure workstation.
   A Docker-capable local host/approved offline VM with sufficient disk/RAM is an
   alternative to a second cloud service. This Windows machine currently lacks
   Docker/MySQL tools; another approved host or installing the tools is required.
2. Create a fresh logical dump, preserving binary values/schema/constraints and
   bookkeeping; use `--defaults-extra-file` as the first option, with
   `ssl-mode=VERIFY_IDENTITY` and a trusted CA. Never put credentials on command
   lines, print them or use public GitHub Actions to handle real database dumps.
   Example for the later authorized operator (not executed here):

   ```bash
   umask 077
   mysqldump --defaults-extra-file=/private/source-client.cnf \
     --single-transaction --quick --set-gtid-purged=OFF --no-tablespaces \
     --hex-blob --routines --events --triggers \
     --result-file=/private/pwdwork-before-email.sql pwdwork_prod
   test -s /private/pwdwork-before-email.sql
   sha256sum /private/pwdwork-before-email.sql
   ```

   Check exit status and required privileges. A failed dump or missing routines/
   events/triggers is a stop condition. Do not use shell redirection for the dump
   on older PowerShell. Encrypt/store it under approved access and retention rules;
   privately retain its plaintext content checksum for restore verification.
3. Restore into a **new empty disposable isolated MySQL 8.4.11** database on that
   approved host. Verify the destination independently before importing. Do not
   overwrite an existing local database. An option file must select the disposable
   target, with events/outbound mail/workers disabled and no production route.
   Example (the new empty destination must already have been independently
   validated; no CREATE/DROP commands against an operator-supplied target):

   ```bash
   mysql --defaults-extra-file=/private/restore-client.cnf \
     --database=pwdwork_restore_check < /private/pwdwork-before-email.sql
   ```

   Resolve DEFINER/privilege portability separately; do not silently omit objects.
   Compare exact original schema, all tables/row contents/roles/passwords/session
   payloads/application records, not just row counts. Reuse the rehearsal's
   comparison design on the approved private host, including original users
   projection. Its disposable CI helper intentionally cannot connect to production;
   do not change that boundary. Record schema/data manifests privately, verify
   the stable APP_KEY is recoverable from separate protected storage, test the
   compatible app offline without email, and measure actual restoration time.
4. Keep writers paused while proving this fresh backup's restoration. This runner
   deliberately requires an RPO of zero: backup time follows the writers-stop
   time. Record acceptable outage/RTO; make expiry no more than six hours after
   backup. If this cannot fit the approved window, stop and obtain a different
   reviewed backup strategy; do not invent successful evidence.
5. The independent reviewer examines the real private dump/checksum/restore
   manifests, measured recovery result and written destination/cutover steps.
   Only then publish the **non-secret attestation JSON** below into the APPLY
   environment variable `EV_APPROVED_BACKUP`. It is an approval record, not the
   backup itself. The workflow validates structure, identity, freshness, checklist
   results and its independently supplied SHA256; it cannot inspect an offline
   encrypted artifact or independently prove a human's attestation is truthful.

For failure recovery: retain the source and all evidence, keep enrollment off,
stop writers and diagnose with the existing read-only SQL before touching data.
If original data is intact, prefer separately reviewed forward repair of missing
columns/ledger. No automated down migration or column deletion is safe/necessary.
If a full restore is genuinely required, restore into a new empty approved
isolated target first, verify it, then arrange an explicitly approved production
cutover and outage. Aiven Free cannot host a fork; a paid/new approved target may
require costs and later credentials/cutover changes, all outside this task.
Restoring **into the same Free service** is a destructive last resort, requiring
explicit authorization, a complete outage, independently verified target, another
preserved copy of the failed state and a reviewed restore operator procedure.
The runner does not implement that restore. Data written after a backup would be
lost unless separately reconciled; maintain quiescence for this zero-RPO window.
If neither a usable approved target nor a proven same-service procedure exists,
the recovery requirement is unmet and production APPLY must not be approved.

## Evidence values (never credentials or database content)

Copy the successful PREVIEW artifact's **compact JSON object**, without a trailing
newline, into APPLY environment variable `EV_APPROVED_PREVIEW`. The artifact is
uploaded only from the fixed receipt path and expires after three days; approval
requires a fresh receipt within six hours. It contains the run ID, SHA, migration
hash, target UUID and schema hash. The approval gate checks that the referenced
run is a completed successful PREVIEW of this workflow, same commit on main,
manual event and first attempt. Download it only from the reviewed run; record
its artifact identity/digest and displayed receipt SHA256.

The following is a **non-executable shape example**, deliberately not valid
evidence. Replace every placeholder only with the real privately reviewed values:

```json
{
  "format": 1,
  "service": "mysql-380cabbd",
  "database": "pwdwork_prod",
  "server_uuid": "INDEPENDENTLY-VERIFIED-UUID",
  "compatible_release_sha": "40-HEX-REVIEWED-AND-LIVE-COMPATIBLE-COMMIT",
  "backup_sha256": "64-HEX-VERIFIED-DUMP-CONTENT-CHECKSUM",
  "restored_manifest_sha256": "64-HEX-PRIVATE-MATCHING-SCHEMA-AND-DATA-MANIFEST",
  "recovery_runbook_sha256": "64-HEX-LF-NORMALIZED-HASH-OF-THIS-RUNBOOK",
  "private_storage_reference": "opaque_record_identifier",
  "recovery_target": "isolated-mysql-8.4.11",
  "restore_test_passed": true,
  "schema_and_data_match": true,
  "writers_quiesced": true,
  "live_email_verification_enabled": false,
  "rpo_seconds": 0,
  "restore_duration_seconds": 120,
  "writers_stopped_utc": "ACTUAL-UTC-YYYY-MM-DDTHH:MM:SSZ",
  "backup_utc": "ACTUAL-UTC-YYYY-MM-DDTHH:MM:SSZ",
  "restore_verified_utc": "ACTUAL-UTC-YYYY-MM-DDTHH:MM:SSZ",
  "valid_until_utc": "ACTUAL-UTC-NO-LATER-THAN-BACKUP-PLUS-SIX-HOURS"
}
```

The release SHA must match the reviewed source and compatible live revision.
The storage reference is an opaque record ID, never a path, credential, email,
signed URL or backup download link. Keep private evidence elsewhere. Use exact
UTF-8 compact JSON with no newline for both environment variables and compute
the SHA256 of those exact bytes. The dispatch hash must match the protected
variable; a dispatch checkbox or arbitrary backup ID cannot authorize APPLY.
The recovery-runbook hash normalizes CRLF to LF, matching the rehearsal manifest's
Windows handling. Edits to this runbook require renewed evidence review.

Calculate that public document checksum from the reviewed checkout without
accessing any credentials:

```powershell
python -c "import hashlib,pathlib; print(hashlib.sha256(pathlib.Path('.github/EMAIL-VERIFICATION-INCREMENTAL-MIGRATION.md').read_text(encoding='utf-8').encode('utf-8')).hexdigest())"
```

For the two JSON values, prepare the exact compact UTF-8 bytes **outside the
repository**, hash those same bytes (no trailing newline or BOM), and copy them
unchanged into the protected environment variables. Keep real backup files,
credential option files and private comparison manifests outside GitHub Actions.

## Exact controlled steps and checkpoints (future authorized operator only)

**Do not run these now.** Production access/execution requires separate approval.
The workflow must first be registered on reviewed main and its new runner
disposable tests/approval integration accepted. Use GitHub CLI, not commands
copied with secrets, and an exact independently reviewed 40-character SHA.

1. **Release gate:** verify both schema compatibility directions PASS, the actual
   26-check rehearsal artifact, new runner disposable tests, matching migration
   hash, reviewed main SHA, live compatible flag-off release, stable APP_KEY and
   protected environments/network/identity. Confirm billing allowance.
2. **Manual PREVIEW** (read-only database access, separately approved):

   ```powershell
   $evReviewedSha = '<reviewed-full-main-SHA>'
   gh workflow run email-verification-incremental.yml --repo cjahhh/pwdwork --ref main -f operation=PREVIEW -f reviewed_sha=$evReviewedSha
   gh run list --repo cjahhh/pwdwork --workflow email-verification-incremental.yml --limit 5
   ```

   A **different human** reviews the SHA/target and approves the pending
   `pwdwork-email-migration-preview` job through GitHub's environment review UI.
   Do not enable debug tracing. Require a successful receipt, not merely a green
   skipped job. Failed/missing history/partial columns/privileges/TLS means stop.
3. **Evidence gate:** download that exact run's artifact and privately review it.
   No raw production SQL output is in the artifact. Record the run/artifact ID and
   receipt checksum. An authorized environment maintainer installs the unchanged
   compact receipt as `EV_APPROVED_PREVIEW` in the APPLY environment. Prepare and
   independently approve the fresh restore-tested backup evidence after pausing
   writers, then install `EV_APPROVED_BACKUP`. Keep writer pause in force.
4. **Manual APPLY dispatch** with the two independently approved JSON checksums:

   ```powershell
   $evPreviewDigest = '<64-hex-exact-approved-preview-JSON-SHA256>'
   $evBackupDigest = '<64-hex-exact-approved-backup-JSON-SHA256>'
   gh workflow run email-verification-incremental.yml --repo cjahhh/pwdwork --ref main -f operation=APPLY -f reviewed_sha=$evReviewedSha -f 'confirmation=APPLY email ownership pwdwork_prod' -f preview_sha256=$evPreviewDigest -f backup_sha256=$evBackupDigest
   ```

   The second dispatch is **not** approval. A different human must approve the
   pending `pwdwork-email-migration-apply` job, after checking the actual private
   restore proof, expiry, writer pause and live flag. The token cannot approve
   itself. Both JSON byte hashes, preview run provenance, actual schema/history,
   CA/hostname/TLS/UUID/version and DDL are rechecked before any ALTER. No branch
   changes between PREVIEW/APPLY; a moved main SHA fails closed.
5. **Postflight gate:** require PASS, the one intended ledger entry and exact
   definitions/defaults, unchanged complete existing-record fingerprints and a
   still-disabled live flag. Retain the safe receipt privately with release
   evidence. Resume writers only after owner acceptance. Check existing
   jobseeker/employer/admin sessions and ordinary functions with controlled
   accounts; avoid repetitive failed login attempts. No automatic email enrollment,
   employer approval or administrator elevation occurs. Activation is a later
   separately approved rollout, outside this migration.
6. **Any failure/interruption:** do not rerun/cancel a running DDL casually. A GitHub
   timeout/cancel does not prove the server undid its work. Observe database state
   privately after the connection ends, keep the incident owner informed and use
   the matrix below. Never delete history to make a guard pass.

| Columns / intended ledger rows | Required response |
| --- | --- |
| 0 / 0 | Nothing applied; investigate failure/locks, renew evidence/approval before a fresh dispatch. |
| 1 or 2 / 0 | Partial DDL, manual reviewed forward repair of remaining exact statements; no automatic retry. |
| 3 / 0 | Validate every definition/value and original data; separately approved ledger reconciliation only. |
| 3 / 1 | Already completed: confirm batch/defaults/preservation privately; no APPLY repeat. |
| Anything inconsistent, duplicate or changed data | Stop, retain evidence and escalate to the recovery owner; never guess or overwrite. |

## Local checks and remaining limits

Run only offline/PHPUnit memory-safe tests:

```powershell
python -B -m unittest discover -s .github/tests -p 'test_email_verification_*.py'
php backend/vendor/bin/phpunit --configuration backend/phpunit.xml backend/tests/Unit/EmailVerificationIncrementalSafetyTest.php
```

The Laravel suite's test guard permits only isolated SQLite `:memory:`.
These tests cover authorization, identity, partial states, immutable evidence,
freshness, drift and streaming preservation. They do not prove Aiven connectivity,
actual account privileges, recovery speed, live writer suspension or effective
Render configuration. Full end-to-end testing of this newly prepared runner on
a disposable target remains a release gate. No unconditional production approval
is given until those operational requirements and recovery evidence are met.

Earlier preparation results: **393 Laravel tests passed, 3,731 assertions**, including
**73 new incremental-runner tests, 97 assertions** (pure guards/mocked PDO and
offline MySQL grammar compilation). **Seven Python approval/provenance tests
passed.** PHP syntax, Pint formatting, actionlint 1.7.12 (ShellCheck unavailable),
Bash syntax and whitespace checks passed. The full Laravel suite used only its
guarded in-memory SQLite setup; the new MySQL grammar/PDO tests opened no MySQL
connection. No Docker build or live GitHub/Aiven integration was executed here.

Primary references: [Aiven Free limitations](https://aiven.io/docs/products/mysql/concepts/mysql-free-tier),
[MySQL encrypted connections](https://dev.mysql.com/doc/refman/8.4/en/using-encrypted-connections.html),
[mysqldump consistency and privileges](https://dev.mysql.com/doc/refman/8.4/en/mysqldump.html),
[GitHub environment protection and plan availability](https://docs.github.com/en/actions/reference/workflows-and-actions/deployments-and-environments),
[GitHub review history API](https://docs.github.com/en/rest/actions/workflow-runs#get-the-review-history-for-a-workflow-run).
