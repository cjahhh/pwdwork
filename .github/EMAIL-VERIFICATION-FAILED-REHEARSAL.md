# Failed disposable incremental rehearsal: safe diagnostic repair

Reviewed **29 October 2026**, against the local clean checkout of opt-in commit
`830ddce` before these edits. The owner reports GitHub Actions run `40720035621`
passed authorization, Python tests and syntax, then failed at `preview_readonly`
with **0/32** scenarios completed. The remaining 31 were not executed.

## What is and is not confirmed

The matching source and owner-supplied sanitized summary were reviewed. The
private GitHub artifact was not independently retrieved: GitHub is not connected,
`gh` is absent locally, and an unauthenticated page fetch did not return the run.
No local copy of the sanitized artifact was found in the checked evidence paths.
Access to GitHub or a local copy of its sanitized report is needed for direct
artifact review. No production credentials were read to obtain access.

The **underlying runtime root cause is unconfirmed**. The scenario label is set
before target reset/restore and before the PHP invocation. It does not establish
whether reset/restore, Docker create/start, TLS/PDO, runner validation or the
scenario's schema/data assertions failed. Reaching it indicates earlier setup
and synthetic baseline initialization/dumping completed; it does not prove the
incremental runner established its TLS connection.

One reporting defect is confirmed by source and regression tests:

1. The lab caught every PHP exception and returned only
   `FAIL / disposable_runner_assertion`.
2. The Bash wrapper noticed a nonzero helper exit but did not propagate that
   receipt into evidence, then deleted the private result during cleanup.
3. CI therefore retained only the outer scenario name. Safe runner phase detail
   was lost even when the runner had provided it. No raw logs should be published
   to work around this.

There is no evidence for disabling CA/hostname verification, changing database
permissions or weakening authorization. No such change was made. The two earlier
pasted syntax concerns are absent from the actual source.

## Minimal behavior change

This repair changes failure diagnostics, not migration/approval behavior:

- The incremental runner classifies failures by exception **type** and a small
  allowlist of native MySQL integer codes. It never inspects/emits exception
  messages, SQL text, bindings, connection URLs, credentials or private contents.
- The lab records fixed operation names around boundaries, target connections,
  restoration comparisons, snapshots, subprocess receipt parsing and assertions.
  A validated failed runner receipt carries its safe phase/category/native code
  through the subsequent scenario assertion. A later preservation failure does
  not reuse an earlier expected runner rejection.
- Bash records Docker/reset/restore/helper operations, clears stale helper output,
  and extracts only the validated diagnostic before deleting private temporary
  files. Raw/fatal/malformed helper output falls back to fixed shell codes.
- The CI validator accepts diagnostics **only on FAIL**, with exact keys and
  allowlisted values. It preserves valid diagnostics in both the JSON artifact
  and Markdown summary. Invalid fields make evidence fail closed and are omitted.
  Legacy reports without diagnostic fields remain readable.

Example of the non-secret shape (illustrative, **not the observed run error**):

```json
{
  "operation": "preview_result",
  "category": "tls",
  "runner_phase": "tls_connection",
  "mysql_code": 2026
}
```

Interpretation of the next report:

| Fixed operation/category | Investigation boundary |
| --- | --- |
| `database_reset` / `database_restore`, `mysql_command` | Scoped disposable reset/import failed before the test helper. |
| `container_create` / `container_start` / `container_exit`, `docker` | Docker orchestration failed; raw errors stay private. |
| `connect_target` / `connect_baseline`, `mysql_connectivity` or `mysql_permissions` | Controller PDO connection failed before the runner. |
| `restore_compare`, `validation` | Synthetic restored schema/data comparison failed. |
| `runner_output` / `runner_receipt`, `runner_protocol` | Child output/exit/receipt contract failed; raw output is withheld. |
| `preview_result`, with a safe `runner_phase` | The runner rejected PREVIEW; phase plus category identifies the failing boundary. |
| `preservation`, `validation` | A full schema/data/counter/ledger equality assertion failed. |

Native codes are evidence, not proof beyond their scope: for example, code 2002
can cover a connection failure involving TCP or TLS; do not conclude an exact
certificate/network cause solely from that code. Unknown native codes are null.
Any further diagnosis must retain the same private/sanitized boundary.

## Files and regression checks

- `.github/scripts/email-verification-incremental.php`: safe failure classifier.
- `.github/scripts/email-verification-incremental-rehearsal.php`: operation tracking
  and strict failed-receipt propagation.
- `.github/scripts/email-verification-incremental-rehearsal.sh`: safe capture during
  cleanup and shell operation tracking.
- `.github/scripts/email-verification-incremental-ci.py`: strict diagnostic schema
  and final artifact/summary propagation.
- `.github/tests/test_email_verification_incremental_ci.py`: added pipeline,
  malformed-output/redaction and FAIL-only diagnostic tests; compiles all four
  Python heredocs and executes the actual cleanup heredoc with synthetic files.
- `backend/tests/Unit/EmailVerificationIncrementalLabSafetyTest.php`: added type/
  native-code classification, runner-to-lab propagation, stale-reason rejection,
  boundary refusal and sensitive-message redaction tests.
- This investigation record.

Local verification: **139 PHP tests / 229 assertions** and **33 Python tests**
passed. PHP syntax/Pint, Bash syntax, all four embedded Python compilations,
actionlint and whitespace checks passed. ShellCheck remains unavailable.
All **32** scenario names and their expected phases are preserved. Production
PREVIEW/APPLY still has its literal disabled job condition; neither production
workflow nor trigger policy was edited. Network-none/loopback isolation, synthetic
credentials, source pins, exact DDL, preservation and approval checks remain.

## Readiness

Ready for another **separately authorized controlled diagnostic rehearsal** on
GitHub-hosted Ubuntu with disposable MySQL **8.4.11**. This is not a claim that the
underlying MySQL failure is fixed or that any integration scenario has now passed.
Docker remains unavailable locally. Use a new reviewed opt-in commit/push under
the [existing execution runbook](EMAIL-VERIFICATION-INCREMENTAL-ACTIONS.md), not a
rerun of the old immutable commit. Inspect the safe diagnostic if it fails again;
do not weaken guards or automatically retry.

No workflow was triggered here. No production database, Aiven/Render credential
or environment was accessed or changed. No commit, push, merge or deployment
was performed. Production migration and email activation remain unapproved.
