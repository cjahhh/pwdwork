# Isolated MySQL rehearsal on GitHub Actions

Prepared locally, **not committed, pushed or executed**. No production database, Aiven/Render secret, cloud service or environment setting was accessed or changed.

## Before approving a run: usage and billing

This uses one standard `ubuntu-24.04` GitHub-hosted job, limited to **35 minutes per attempt**. Public repositories using standard hosted runners generally have free runner minutes; private repositories consume the owner's included minutes and may incur overage charges. Failed runs and reruns consume usage too. Check the owner's billing allowance/budget and approve that usage **before pushing the opt-in commit or rerunning a job**. No account visibility, allowance or spending limit was inspected here. Do not assume the run is free. See [GitHub Actions billing](https://docs.github.com/en/billing/concepts/product-billing/github-actions).

Only a small sanitized JSON report and Markdown summary are uploaded, with **one-day retention**. Artifact storage has separate allowance/billing considerations. No Docker cache, image, database dump or raw tool log is uploaded. Public image/package downloads need network access during preparation; Docker Hub limits or dependency outages can make a run fail and still consume runner minutes. No paid database service is required for this synthetic rehearsal.

## Branch and trigger safety

The dedicated branch is **`security/email-verification-mysql-rehearsal`**. This workflow has only a push trigger for that exact branch. The job additionally requires the expected repository and an opt-in commit. Its helper checks the GitHub event against the checked-out SHA, rejects main/tags/other branches, force pushes, deleted branches and other event types, and demands this exact first commit-message line:

```text
[run-email-verification-rehearsal]
```

An ordinary push on the testing branch skips the job. Do not place this workflow on main or create a pull request that is automatically merged into main for testing. No `workflow_dispatch`, `workflow_call`, pull-request, schedule, repository-dispatch or workflow-completion trigger is enabled. No deployment environment or production concurrency group is used.

GitHub documents that manual dispatch normally requires the workflow on the default branch. The explicit opt-in branch push avoids changing main merely to register the workflow. **Do not use `gh workflow run` for this branch-only preparation.** See [GitHub manual-dispatch requirements](https://docs.github.com/en/actions/how-tos/manage-workflow-runs/manually-run-a-workflow).

The existing Aiven migration, first-admin and Docker-check workflows remain unchanged and are manual-only. This workflow never calls them, uses their concurrency group or invokes a deployment command. The token has **only `contents: read`**, checkout does not retain credentials, and no repository/organization/environment secrets are referenced. Pinned official checkout/upload action commits are used. It cannot push code, publish an image, request OIDC credentials or write deployments using its declared token permissions.

Before a future push, the owner must independently confirm Render still deploys only its intended main branch and no external automation deploys this testing branch. This preparation did not inspect or change Render settings. Repository administrators can change workflows or integrations; branch guards are operational protections, not a boundary against a malicious administrator.

## What the workflow executes

1. Authorize the exact testing event/SHA before building or starting anything.
2. Pull **`docker.io/library/mysql:8.4.11`** from the official image repository. Record its resolved digest, run that image ID, and require server version `8.4.11`; no fallback tag/version is allowed. The tag is currently published; no pull was executed here. See [official MySQL image](https://hub.docker.com/_/mysql).
3. Build the existing Dockerfile's **vendor target** locally on the runner. This skips frontend compilation and creates no published/deployed image. The Docker context excludes `.env`, credentials, databases and Git metadata. Build/public dependency downloads occur before test credentials or the database exist.
4. Generate temporary root/user passwords and an application key into private runner files. No value is printed, put in GitHub outputs, passed as a command argument or uploaded. MySQL uses `_FILE` initialization; PHP receives generated values through stdin.
5. Start MySQL with **`--network none`**, no published ports and a new anonymous data volume. PHP containers share only that isolated network namespace, run with dropped capabilities/read-only filesystem and temporary storage, and receive no host application environment. There is no route from these runtime containers to Aiven, Render, Brevo or the internet. Only public build/registry/artifact traffic uses the runner network.
6. Validate a reviewed manifest of **16 migration files and four SQL files**, both against staged source and the migration files inside the image. Hashes normalize CRLF to LF for Windows/Git compatibility; all other content must match. An unexpected migration or changed file fails closed. Do not regenerate the manifest automatically in CI to bypass a failure.
7. Initialize the pre-feature schema using an explicit allowlist of **15 existing migrations**, excluding the email migration. This does not execute old application source or claim the actual production schema matches this baseline. Seed five synthetic accounts covering jobseeker, pending/approved employers, administrator and suspension, plus profiles, a job, a hired application, a saved job, a notification and two session records. Replace fixture password placeholders with valid bcrypt hashes of random, undisclosed test-only values before taking the baseline snapshot.
8. Run the existing preflight SQL. Back up the disposable database, restore it into a second empty disposable database and compare the original data/schema fingerprints. The helper hashes all original fields/rows, including passwords, remember tokens and session payloads; it includes columns, indexes, foreign keys, table options and relevant auto-increment state. It also executes the existing original-users digest SQL. Raw row contents are never evidence artifacts.
9. Check an exact three-statement preview and execute **only** `2026_10_26_000001_add_email_ownership_verification_to_users.php`. The Laravel call specifies that one `--path`; it never runs all pending migrations at this stage.
10. Run the existing postflight SQL; require two nullable timestamp fields with no default ownership claims, and a non-null unsigned bigint version defaulting to zero. Every seeded row must have `NULL`, `NULL`, `0`. Require one intended ledger row in the next batch. Recompare every original user field, all other table data and schema. Only this ledger row/counter and the three added columns are expected differences.
11. Repeat the recorded migration and verify no data change. In three other fresh restored schemas, simulate interruption after the first ALTER, after the second, and after all three before ledger insertion. Reconnect to establish committed state, attempt the original migration, require MySQL **1060** duplicate-column failure, and verify original records stay intact. Report that these states require manual forward repair or ledger reconciliation; no repair is performed.
12. In another restored schema, hold a metadata lock and require MySQL **1205** within the configured two-second DDL wait. Verify the failed statement leaves the original schema/data intact. The actual Laravel migration connections also set a five-second metadata-lock timeout on that same connection.
13. Publish parsed PASS/FAIL results, version/image identity, backup checksum, metadata, fingerprints and reconciliation findings. Capture unexpected failures by fixed stage/category and only allowlisted native codes; no arbitrary exception messages/SQL/bindings are published. Remove only run-owned containers/anonymous volumes and private dumps/secrets. Abrupt runner loss can prevent traps/artifact upload; the GitHub-hosted disposable runner must then be discarded normally. A missing report is never a PASS.

The SQL pre/postflight files are SELECT/SHOW only. The separate synthetic-fixture file deliberately contains INSERT statements and is used only after verifying the disposable target and empty baseline. Its initial placeholder hashes are not login credentials. The workflow verifies stored session/password preservation, **not browser login/cookie restoration on MySQL**. Existing isolated Laravel authentication tests remain separate evidence.

## Exact future trigger commands — not executed here

These PowerShell commands are for a **later separately approved run**, after billing review and after all reviewed feature/workflow/helper/SQL/test files have been committed on the dedicated testing branch. Preparing/staging/committing those changes is not authorized or performed by this task. A workflow file alone is insufficient: the branch must include the implemented backend feature and reviewed manifest inputs.

The ordinary preparation commit must not use the opt-in title. Do not push main, merge to main, force push or push tags. Confirm the remote repository is the expected owner/repo using the GitHub UI without displaying credential-bearing remote URLs.

If the approved testing branch does not yet exist, its later preparation starts with `git switch --create security/email-verification-mysql-rehearsal`, followed by separately approved review/staging/commit of the complete required changes. None of those steps was performed here. Preserve unrelated work, including the pre-existing Nginx smoke change; do not use `git add .` indiscriminately.

```powershell
# FUTURE ONLY: begin from the reviewed, committed testing branch.
git switch security/email-verification-mysql-rehearsal
if ($LASTEXITCODE -ne 0) { throw 'Approved testing branch is not available.' }
$rehearsalBranch = git branch --show-current
if ($rehearsalBranch -ne 'security/email-verification-mysql-rehearsal') { throw 'Wrong branch.' }
if (git status --porcelain) { throw 'Working tree/index must be clean and reviewed before triggering.' }

# This new opt-in commit and the following exact branch push trigger billable usage.
git commit --allow-empty -m '[run-email-verification-rehearsal]'
if ($LASTEXITCODE -ne 0) { throw 'Opt-in commit failed.' }
git push origin HEAD:refs/heads/security/email-verification-mysql-rehearsal
if ($LASTEXITCODE -ne 0) { throw 'Testing-branch push failed; inspect without force pushing.' }

gh run list --repo cjahhh/pwdwork --workflow email-verification-mysql-rehearsal.yml `
  --branch security/email-verification-mysql-rehearsal --event push --limit 5
```

Select the run whose `headSha` matches that opt-in commit, then use its numeric run ID. The download directory must be new/private; it receives sanitized evidence only:

```powershell
$rehearsalRunId = 'REPLACE_WITH_THE_MATCHING_NUMERIC_RUN_ID'
if ($rehearsalRunId -notmatch '^\d+$') { throw 'Choose the matching numeric run ID first.' }
$rehearsalRun = gh run view $rehearsalRunId --repo cjahhh/pwdwork `
  --json headBranch,headSha,event,status,conclusion,workflowName | ConvertFrom-Json
if ($LASTEXITCODE -ne 0) { throw 'Could not read the selected run.' }
if ($rehearsalRun.headBranch -ne 'security/email-verification-mysql-rehearsal' -or `
    $rehearsalRun.headSha -ne (git rev-parse HEAD) -or $rehearsalRun.event -ne 'push' -or `
    $rehearsalRun.workflowName -ne 'PWDWork isolated email verification MySQL rehearsal') {
  throw 'Selected run does not match this approved rehearsal commit/workflow.'
}
gh run watch $rehearsalRunId --repo cjahhh/pwdwork --exit-status
gh run download $rehearsalRunId --repo cjahhh/pwdwork --dir ./email-verification-safe-evidence
```

Never interpret a skipped/queued/cancelled/failed run as successful. Confirm exact SHA, official MySQL digest/version, successful job conclusion and all required checks in `report.json`. Reruns operate on the original commit and consume usage again; review/approve them separately. A normal branch push without the exact opt-in title does not start another rehearsal job.

## Expected results and production prerequisites

| Check | Expected result |
| --- | --- |
| Schema initialization and original preflight | 15 original migrations; five users; two session rows; zero new columns/target ledger rows. |
| Backup restoration | Original table schema/data fingerprints match, including password/session fields. |
| Targeted migration | Three exact column definitions; seeded values `NULL`, `NULL`, `0`; one new ledger row. |
| Preservation | Original users/roles/statuses/passwords/sessions and all other application rows unchanged. |
| Successful repeat | Targeted Artisan migration skips; data fingerprints unchanged. |
| First/second committed prefix | Expected 1060; original data intact; manual forward repair required. |
| Completed DDL, missing ledger | Expected 1060; original data intact; manual ledger reconciliation required. |
| Metadata-lock failure | Expected 1205; no schema/data change from that failed statement. |

An expected 1060/1205 is a **PASS for that failure-case test**, not approval to retry/repair production automatically. The migration remains non-idempotent in partial states. No production repair/reconciliation helper is included.

Local preparation validation:

- **320 Laravel tests / 3,634 assertions passed**, including **47 new rehearsal safety tests / 78 assertions**. Database tests remain isolated SQLite `:memory:`; guard tests do not connect to a database.
- PHP syntax and Pint passed for the new PHP helper/tests.
- Bash syntax and syntax compilation of all five embedded Python blocks passed without executing the orchestrator.
- **Actionlint 1.7.12 passed** for the prepared workflow. The public Windows validator was downloaded into the ignored test cache and checked against its published release checksum. Optional ShellCheck integration was disabled because ShellCheck is unavailable locally.
- Whitespace checks passed. No application/UI source changed in this preparation, so frontend checks were not repeated.

Docker/MySQL execution and the GitHub workflow itself are intentionally unrun. The first authorized GitHub execution remains an integration check; local passes are not a claim that container orchestration already succeeded. Even a future green rehearsal does not establish live Aiven backup recoverability, provider-specific DDL algorithms/backup locks, production-scale timing, real MySQL browser-session behavior or live Render/Brevo delivery. Keep the [production rollout gates](EMAIL-VERIFICATION-ROLLOUT.md#required-migration-and-safe-production-sequence): a restore-tested production recovery point, provider/scale rehearsal where needed, approved incremental production runner, disabled compatible deployment, migration approval and separate activation approval. This workflow is **not** that production runner.

Changed for this preparation: the new workflow; Bash/PHP helpers; reviewed hash manifest; synthetic fixture SQL; local safety tests; this guide; and links in the existing MySQL rehearsal/rollout documents. Existing production workflows, application code, migration and production settings were preserved.
