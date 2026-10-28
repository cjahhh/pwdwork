# Run the incremental runner safeguards on GitHub Actions

Prepared **28 October 2026**. **Not committed, pushed, dispatched or executed.**
No production database, credentials, environment or activation flag was accessed
or changed. The actual production PREVIEW/APPLY workflow remains disabled by its
literal `if: false && ...` job condition. This preparation does not authorize a
production migration or email verification activation.

## Files for this preparation

| File | Purpose |
| --- | --- |
| [Workflow](workflows/email-verification-incremental-rehearsal.yml) | One GitHub-hosted Ubuntu 24.04 job; explicit testing-branch push only; read-only contents permission; exact checkout; offline/syntax checks; existing harness; validated evidence. |
| [CI gate and evidence validator](scripts/email-verification-incremental-ci.py) | Rejects unsafe event/SHA and removal of the production hold before image builds; emits only allowlisted evidence. |
| [CI safety tests](tests/test_email_verification_incremental_ci.py) | Offline tests for wrong events, branches, ordinary commits, force/deletion, reruns, hold removal, forged/incomplete evidence and output redaction. |
| [Existing harness](scripts/email-verification-incremental-rehearsal.sh) | Reused; namespace selection/inspection now compares Docker's canonical full container ID. The 32 scenarios and PHP assertions are unchanged. |
| [Safeguard review](EMAIL-VERIFICATION-INCREMENTAL-SAFEGUARDS.md) | Updated link, current validation counts and artifact instructions. |
| This runbook | Exact future trigger, expected results, usage and remaining blockers. |

The workflow also needs the reviewed runner, approval scripts/tests, unchanged
PHP test controller, original rehearsal helper/manifest/SQL, the incremental
migration runbook, Dockerfile/build inputs and all manifest-pinned migrations in
the testing commit. A workflow-only commit is insufficient. Preserve all existing
uncommitted work and review/stage an explicit file list later; do not use
`git add .` or merge anything into main for this test.

## Execution restrictions

All of the following must hold before a database is started:

- Repository is exactly `cjahhh/pwdwork`.
- Event is `push`, ref is exactly
  `refs/heads/security/email-verification-mysql-rehearsal`, and run attempt is 1.
- Push is neither forced nor a branch deletion.
- The **entire head commit message**, without a body, is exactly:

  ```text
  [run-email-verification-incremental-rehearsal]
  ```

- Event `after`, head-commit ID, checked-out HEAD and `GITHUB_SHA` agree.
- The production workflow's explicit disabled condition remains present.

The job condition and a separate Python event gate enforce these checks. Ordinary
commits skip the job. PRs, main/tags/other branches, schedules, dispatches and
workflow-completion events cannot start this workflow. A skipped run is not a
test pass. Do not use the UI's **Re-run jobs**: reruns are rejected. A later
retry requires another reviewed, separately authorized opt-in commit/push.

The workflow is branch-only so it does not require merging/registering it on
main for manual dispatch. GitHub requires a workflow on the default branch for
`workflow_dispatch`; see [manual workflow requirements](https://docs.github.com/en/actions/how-tos/manage-workflow-runs/manually-run-a-workflow).

## Exact future trigger instructions — do not execute yet

First obtain approval for the testing-branch commit/push and Actions usage.
Review and commit the complete required preparation using an ordinary title
without the opt-in text. Do not push production branches, force push, push tags,
create/merge a main PR or change repository visibility/settings for this test.
Independently confirm in the existing hosting/integration settings that pushing
this testing branch cannot cause a deployment; those settings were not inspected
or changed here. Verify that `origin` is the intended repository using the UI
without printing a potentially credential-bearing remote URL.

After the complete reviewed preparation is committed and the working tree is
clean, these are the **future PowerShell trigger commands**, not actions taken
by this task:

```powershell
git switch security/email-verification-mysql-rehearsal
if ($LASTEXITCODE -ne 0) { throw 'Testing branch unavailable.' }
if ((git branch --show-current) -ne 'security/email-verification-mysql-rehearsal') {
    throw 'Wrong branch.'
}
if (git status --porcelain) { throw 'Review and commit the preparation first; working tree must be clean.' }

# Creates the explicit opt-in head commit. No body or additional title text.
git commit --allow-empty -m '[run-email-verification-incremental-rehearsal]'
if ($LASTEXITCODE -ne 0) { throw 'Opt-in commit failed.' }
$incrementalTestSha = git rev-parse HEAD
if ($LASTEXITCODE -ne 0 -or $incrementalTestSha -notmatch '^[a-f0-9]{40}$') { throw 'Cannot identify opt-in SHA.' }

# This exact branch push starts the separately authorized, potentially billable run.
git push origin HEAD:refs/heads/security/email-verification-mysql-rehearsal
if ($LASTEXITCODE -ne 0) { throw 'Push failed; investigate without force pushing.' }
```

Use the normal reviewed commit/signing/hooks policy when making the opt-in
commit. If it adds a body or changes the subject, the job will skip; resolve that
through the approved process rather than weakening the trigger.

Then, either use the repository Actions UI to select **Disposable incremental
migration runner safeguards**, or use the following read-only commands after the
run has been separately authorized and triggered:

```powershell
gh run list --repo cjahhh/pwdwork --workflow email-verification-incremental-rehearsal.yml `
    --branch security/email-verification-mysql-rehearsal --event push --limit 5

# Choose the run whose headSha equals $incrementalTestSha. Do not choose by title alone.
$incrementalRunId = 'REPLACE_WITH_MATCHING_NUMERIC_RUN_ID'
if ($incrementalRunId -notmatch '^[1-9][0-9]*$') { throw 'Choose the matching run ID.' }
$incrementalRun = gh run view $incrementalRunId --repo cjahhh/pwdwork `
    --json headBranch,headSha,event,status,conclusion,workflowName | ConvertFrom-Json
if ($LASTEXITCODE -ne 0) { throw 'Cannot inspect selected run.' }
if ($incrementalRun.headBranch -ne 'security/email-verification-mysql-rehearsal' -or
    $incrementalRun.headSha -ne $incrementalTestSha -or $incrementalRun.event -ne 'push' -or
    $incrementalRun.workflowName -ne 'Disposable incremental migration runner safeguards') {
    throw 'Selected run does not match the reviewed test.'
}
gh run watch $incrementalRunId --repo cjahhh/pwdwork --exit-status
if ($LASTEXITCODE -ne 0) { throw 'Run did not succeed; inspect sanitized FAIL evidence.' }

$incrementalArtifact = "incremental-disposable-$incrementalRunId-1"
$incrementalEvidence = Join-Path $env:TEMP "pwdwork-incremental-safe-evidence-$incrementalRunId"
if (Test-Path -LiteralPath $incrementalEvidence) { throw 'Use a new evidence directory.' }
gh run download $incrementalRunId --repo cjahhh/pwdwork --name $incrementalArtifact --dir $incrementalEvidence
if ($LASTEXITCODE -ne 0) { throw 'Evidence download failed.' }
```

For a failed run, download that run's same named sanitized artifact separately
for diagnosis; do not treat its existence as a pass. No `gh workflow run` or
production command is part of this procedure. Retain the immutable run URL, exact
SHA, artifact ID/digest and validated image IDs privately before artifacts expire.

## Expected PASS and FAIL evidence

The job runs the existing
`bash .github/scripts/email-verification-incremental-rehearsal.sh --disposable-only`.
It first creates the same 15-migration baseline/fixture and a synthetic logical
backup. Each scenario starts from a restored fresh target and checks restoration
against the untouched baseline.

Expected successful workflow evidence:

- GitHub concludes the workflow/job **success**, and the opt-in source SHA matches.
- `report.json` has `status: PASS`, `phase: complete`, `passed_scenarios: 32` and
  `required_scenarios: 32`. All four workflow checks are `true`.
- Every named scenario is PASS. Negative scenarios count as PASS only when the
  actual helper exits with the expected failure phase and the full schema/data/
  counters/ledger stay unchanged; shared guards run for both PREVIEW and APPLY.
- `preview_readonly`: no DDL, ledger changes or data changes.
- `apply_success`: real PREVIEW receipt consumed; exactly three columns and one
  next-batch migration record added. Original synthetic users/passwords/roles,
  sessions, employers, applications and all other original records/schema remain
  equal. A repeated APPLY refuses further work.
- Unapproved execution, wrong database/host/port/UUID, wrong CA/hostname, changed/
  extra/image-mismatched migration files, missing/duplicate/unknown history and
  one/two/three unrecorded columns or already-applied schemas are all refused.
- Backup/PREVIEW evidence expiry/hash/binding errors, schema drift, a held advisory
  lock, enabled enrollment and reruns are also refused as specified in the
  [full 32-scenario review](EMAIL-VERIFICATION-INCREMENTAL-SAFEGUARDS.md#separate-test-prepared).
- Report includes the official MySQL digest and application image ID, with
  `synthetic_only: true`, `production_access: false`, `production_approval: false`.

Missing, malformed, duplicated or unexpected evidence fields, wrong image IDs,
missing scenarios, or failed/skipped/cancelled prerequisite steps produce **FAIL**
and make the evidence step fail. A valid partial report retains only completed
checks and its known failed stage. Invalid raw evidence is discarded and replaced
by fixed `ci_evidence` failure; arbitrary strings/exceptions never enter outputs.
Expected runner rejections do not fail the workflow when their assertions pass.

Only validated `report.json` and `summary.md` are uploaded in artifact
`incremental-disposable-<run-id>-1`, with **one-day retention**. Images are recorded
by identity in the JSON; images themselves, dumps, generated credentials/keys,
raw logs and environment files are never uploaded. The Markdown summary also
appears in the run's job summary. A missing artifact is an error. Abrupt runner
loss or checkout failure may prevent artifact creation; that is not a pass.

## Isolation and production hold

The workflow declares only `contents: read`; all unspecified token permissions
are none. Checkout/upload actions stay pinned to reviewed commit IDs; checkout
does not retain its Git credentials. No production environment, repository/
organization/environment secret expression, OIDC grant, image publishing or
deployment action is used. The built-in read-only checkout token and Actions
artifact transport are not production GitHub secrets.

MySQL is exactly **8.4.11**, started by resolved image ID with `--network none`,
no published ports and no reused data volume. PHP shares that exact isolated
namespace; it has no Docker socket and cannot route to Aiven, Render or mail
providers. The controller checks the route table. Server/user passwords, APP_KEY
and CA certificates are generated only for this run; PHP receives credentials
through stdin. Only owned containers/volumes and the private temporary directory
are removed during cleanup.

Within this isolated lab, `pwdwork_prod` is deliberately the name of the restored
**synthetic** database, and a fake `.aivencloud.com` hostname resolves to loopback
through a private hosts file. This exercises the unchanged production PHP
helper's strict checks without contacting Aiven or introducing a production test
bypass. The actual production shell/workflow is never invoked. Download/build
network access occurs before generated test credentials/database creation.

GitHub approval responses and release/run attestations remain offline synthetic
fixtures. Their PASS does not establish live approval protection, a real backup
restore, provider-specific DDL timing or production availability. The Docker
namespace compatibility correction follows
[Moby's name-to-ID canonicalization](https://github.com/moby/moby/blob/master/daemon/daemon_unix.go);
the isolation check still requires that exact database container.

## Usage and local validation

One standard `ubuntu-24.04` hosted job has a **35-minute timeout**, serialized
against other runs of this same test without cancelling a running one. Actual
duration depends on image/dependency downloads and building PHP extensions. The
private repository consumes the owner's included Actions minutes and storage;
overage may cost money. Check billing allowance/budget before the eventual opt-in
push. No plan, spend limit or repository visibility was queried or changed.
See [GitHub Actions billing](https://docs.github.com/en/billing/concepts/product-billing/github-actions).

One-day retention limits artifact storage duration; logs/job summaries have the
repository's own retention. No persistent Docker build cache is uploaded. Failed
attempts still consume minutes; Docker Hub limits, package outages and disk/RAM
limits can cause safe failure. No paid database/cloud service is required.

Local validation for this preparation:

- **126 PHP tests / 205 assertions passed** using the isolated guard/mocked-PDO/
  offline-grammar suites; no MySQL connection.
- **28 Python tests passed**, including 12 new CI authorization/evidence tests
  in addition to the earlier 16 approval tests; no GitHub API access.
- PHP/Bash syntax, actionlint 1.7.12, and whitespace checks passed. ShellCheck is
  unavailable locally. Local PHP is 8.2.12; the integration build uses the reviewed
  Docker runtime, not XAMPP's database.
- **MySQL integration remains unexecuted.** Docker is unavailable locally; the
  GitHub Actions workflow has not been triggered by this task.

## Remaining production blockers

The new 32-scenario run must execute and its immutable evidence must be reviewed.
Choose and validate enforceable independent approval: supported protected GitHub
environments or the separately reviewed external two-person adapter described
in the [safeguard runbook](EMAIL-VERIFICATION-INCREMENTAL-SAFEGUARDS.md#approval-without-protected-github-environments).
An approval flag or dispatch checkbox is insufficient.

Later production access also needs separate authorization, independently verified
target/TLS/account privileges, the compatible live release/effective flag-off
state, a maintenance window with every writer paused, a fresh verified logical
backup and proved restoration with acceptable RPO/RTO. Only a separately reviewed
change may remove the production workflow hold. Production PREVIEW/APPLY and
email verification activation each remain unapproved; activation is a separate
later rollout. No merge into main is required or authorized for this test.
