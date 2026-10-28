# Incremental runner review and separate disposable safeguard test

Reviewed locally on **28 October 2026**. **Production migration NOT APPROVED;
email verification activation NOT APPROVED.** The production workflow has an
explicit `if: false && ...` release hold, covering both PREVIEW and APPLY.
No production endpoint was contacted. No Aiven credentials, GitHub production
secrets, application `.env` files or real database dumps were read. No credentials
were changed and no workflow was dispatched, committed, pushed or deployed.
The live Render flag was not inspected or changed.

The owner reports the earlier synthetic MySQL **8.4.11** rehearsal passed its
26 checks. That evidence has not been independently retrieved here. Preserve
its immutable run URL, reviewed SHA and artifact digest. Those results concern
the migration; they do not validate the new incremental runner.

The [GitHub Actions execution runbook](EMAIL-VERIFICATION-INCREMENTAL-ACTIONS.md)
now gives exact future opt-in commands, usage considerations and artifact
acceptance criteria. This follow-up adds an event/provenance gate and strict
evidence validation. The workflow remains unexecuted; no commit or push occurred.

## Review findings

| Area | Finding and release implication |
| --- | --- |
| Approval | The read-only Python gate requires one independent human approval of the correct environment and rejects missing reviews, self-review, ambiguous histories and reruns. Without supported Required reviewers it fails closed; a dispatch confirmation cannot substitute. |
| Trust boundary | `EV_APPROVAL_VERIFIED=true` is an assertion supplied by the trusted workflow after the API gate, not an independently signed approval. Direct helper callers can manufacture environment values. Do not expose an alternate invocation or set this flag manually for production. |
| Protection configuration | An approved review response alone does not prove that prevent-self-review, allowed reviewers, branch restrictions or disabled administrator bypass are configured. Inspect the actual settings and exercise the live approval gate separately without database secrets. The local mocks are not proof of GitHub plan support or live bypass behavior. |
| Target | Host/port must match independent expected values, database must be `pwdwork_prod`, user must be non-root, and actual connection UUID/version/TLS must match. Verified CA/hostname is mandatory. No production target override was added for testing. |
| Source and command | The existing manifest pins all 16 migrations and four SQL files. Mounted source and built-image migrations must agree. Only the pinned file/path and its three exact compiled ALTER statements are allowed. No unrestricted migration, reset, seed, rollback or automatic history repair is offered. |
| PREVIEW | Compiles the actual migration under pretend mode, without calling `migrate` or adding a ledger record. It still connects and reads/hashes every original table; plan a suitable read window and privileges. |
| APPLY | Requires fresh digest-bound PREVIEW and backup/restore attestations; rechecks history and backup expiry immediately before migration. Original values/schema/counters must remain equal; the new fields must be NULL/NULL/0 and the single new ledger row must use the next batch. |
| Partial DDL | One/two columns without ledger, all three without ledger, already-applied or inconsistent history must stop. No transaction can undo MySQL's committed intermediate ALTERs; recovery remains a separate reviewed forward-repair/restore decision. |
| Operational limits | The advisory lock only serializes cooperating clients. It cannot stop external writers. Backups, quiescence, restoration speed, private evidence and live flag state are human attestations, not facts the runner can independently prove. All remain release gates. |

The preparation's claims about rejecting bypass must be read with the protection
configuration limitation above. No live approval API, actual repository plan,
production privileges, failover behavior or Aiven DDL timing was tested here.

## Separate test prepared

Files:

- [Disposable orchestration](scripts/email-verification-incremental-rehearsal.sh)
- [Runner assertions](scripts/email-verification-incremental-rehearsal.php)
- [Dedicated opt-in testing workflow](workflows/email-verification-incremental-rehearsal.yml)
- [Offline approval entry-point tests](tests/test_email_verification_approval_main.py)

The new test reuses the existing Docker `vendor` build target, official
`mysql:8.4.11` image, manifest, 15-migration baseline initializer, synthetic
fixture and read-only SQL from `email-verification-rehearsal.php`. It preserves
the original rehearsal's default behavior and its successful 26-check result.
It takes a synthetic logical dump and proves restoration before every scenario.
Private generated passwords, temporary APP_KEY, CA/leaf keys and dumps are
deleted with the owned containers; they are never uploaded.

MySQL uses `--network none`, no published ports and no existing data-volume mount.
PHP CLI containers share that exact isolated namespace, have a read-only root,
only `/tmp` writable, dropped capabilities, and no Docker socket. The controller
checks the route table as well as the shell's Docker ownership/network checks.
No web entrypoint, worker, scheduler or email transport runs.

Inside that isolated server only, a fresh restored database is deliberately
named `pwdwork_prod`, and a private `/etc/hosts` mount maps the fictional
`incremental-rehearsal.aivencloud.com` hostname to loopback. A new disposable CA
signs that name. This satisfies the production helper's existing identity guards
without changing its bytes, adding a test bypass or providing a production route.
The controller's own connections are hardcoded to loopback and generated fixture
credentials. The production shell wrapper and live GitHub secret release are
outside this harness: the exact PHP runner is launched in a fresh subprocess
with a complete synthetic environment instead.

There are **32 scenarios**. Shared guard failures run in both PREVIEW and APPLY.

| Scenarios | Required assertion |
| --- | --- |
| PREVIEW succeeds | Entire schema, counters, data and ledger unchanged; zero new fields/rows. |
| APPLY succeeds | Uses a real runner-generated PREVIEW receipt, adds exactly the three fields and one next-batch ledger row; all original records/schema preserved; a repeated invocation refuses further work. |
| PREVIEW/APPLY unapproved | Missing approval flag stops at authorization. Offline Python entry-point tests separately exercise empty review history, API errors, wrong actors and wrong provenance. |
| Wrong database, host, port, UUID | Stops before any migration; UUID case supplies matching synthetic attestations so the real connected-identity guard is reached. |
| Wrong CA and wrong TLS hostname | Real PDO connection fails with certificate verification enabled. Both aliases resolve only to loopback. |
| Changed migration, extra migration, changed image migration | Manifest or mounted/image comparison rejects before connection. Only private copied fixtures/mounts are altered. |
| One/two/three unrecorded fields; already recorded migration | Both operations reject before further DDL or bookkeeping, preserving the deliberate partial/completed state. |
| Missing, unknown, duplicate history | Both operations reject without history repair. |
| Missing, mismatched-hash or expired backup | APPLY stops without DDL. |
| Missing, mismatched-hash, expired, wrong-SHA or wrong-schema PREVIEW | APPLY stops without DDL. |
| Schema drifts after PREVIEW | APPLY rejects the stale schema binding. |
| Advisory lock held, enrollment enabled, rerun | Rejects; no additional schema/data changes. |

Every rejection compares an independent fingerprint of **all columns, all ledger
rows, CREATE TABLE definitions and counters**, including deliberately added partial
fields. This is stricter than comparing the production runner's original-column
projection alone, which intentionally excludes new fields and its new ledger row.
The untouched baseline is also checked after each scenario. Failure must occur at
the expected fixed runner phase, so an unrelated startup error cannot count as
a successful negative test.

The backup evidence fields and approval context are **synthetic fixtures only**.
Their fictional release/run IDs and timing/duration attestations cannot authorize
production or establish a measured production recovery time. Python mocks use
the documented GitHub API response shape and forbid actual network calls.

## Local results

| Check actually run on this Windows host | Result |
| --- | --- |
| Incremental + existing rehearsal PHP safety suites | **126 tests, 205 assertions passed**; includes actual entry-point rejections, mocked PDO identity checks, manifest tamper checks and offline MySQL grammar. |
| Python approval and disposable-CI authorization/evidence suites | **28 tests passed**, including 12 new CI tests plus the earlier 16 approval tests; no GitHub token or network. |
| PHP syntax, Pint, Bash syntax, actionlint 1.7.12 | Passed for the added/changed runner test files and workflows. ShellCheck is unavailable. |
| Disposable MySQL harness invocation | **NOT RUN**: stopped at `missing docker` before creating credentials, pulling/building images or connecting to any database. |

Local PHP is **8.2.12** and these PHP tests opened no MySQL connection. They do not
substitute for PHP 8.4.27 in the built image or MySQL 8.4.11 integration. Docker
and MySQL are absent from PATH; WSL enumeration returned access denied. XAMPP's
database/MariaDB was not used. No tools or services were installed.

To reproduce the safe offline checks:

```powershell
python -B -m unittest discover -s .github/tests -p 'test_email_verification_*.py'
php backend/vendor/phpunit/phpunit/phpunit -c backend/phpunit.xml --filter 'EmailVerification(Incremental|Rehearsal)SafetyTest'
```

To run the database test later on a reviewed Linux Docker host, from the reviewed
checkout, with no production environment/credentials loaded:

```bash
bash .github/scripts/email-verification-incremental-rehearsal.sh --disposable-only
```

The script accepts no target/credential arguments and fails if prerequisites are
missing. Builds/downloads use network before the isolated database is created;
they can consume bandwidth, storage and CI minutes. Do not install tools or incur
cloud charges automatically to run this preparation.

The optional GitHub workflow uses the existing testing branch
`security/email-verification-mysql-rehearsal` and a separate exact opt-in message
`[run-email-verification-incremental-rehearsal]`. It has `contents: read`, no
production environment and no production secret references. Registration/push and
workflow execution need separate owner authorization; neither occurred here.
It retains only sanitized `report.json` and image identities for one day. An
incomplete run or missing report is not a pass. Local evidence is written under
the temporary directory printed by the script; it requires all 32 scenarios.

In GitHub Actions the CI validator creates fixed-path `report.json` and
`summary.md`, with validated image identities inside the report. Those two files
are the only uploaded artifacts, named `incremental-disposable-<run-id>-1` with
one-day retention. Reruns are now refused; force/deletion and event/checkout SHA
mismatches also stop execution. The harness now uses the database container's
canonical full ID to select and verify its shared network namespace. No scenario
or production identity check was removed by that compatibility correction.

## Approval without protected GitHub environments

GitHub's current documentation limits Required reviewers on Free/Pro/Team to
public repositories. Private environment access and reviewer protection are
different entitlements. This repository's actual plan was not queried.
See [GitHub environment protection and availability](https://docs.github.com/en/actions/reference/workflows-and-actions/deployments-and-environments).

Recommended alternative: **an attended two-person change on an approved secure
operator host, with independent approval and credential custody outside GitHub**.
Leave the current production workflow held. This is a proposed process, not an
implemented alternate launcher or production authorization:

1. An owner/operator and a different named reviewer inspect the exact source SHA,
   migration/manifest and container digest, the prior 26-check evidence and the
   new successful runner evidence. Agree the exact target identity, maintenance
   window and incident/recovery owner. No self-approval or repository visibility
   change is needed.
2. Record separate PREVIEW and APPLY approval in an access-controlled change
   record outside repository Actions. PREVIEW also needs approval because it
   connects to and reads production. The credential custodian only makes the
   already approved access available on that host after independent approval;
   database credentials are never entered into a ticket, chat, dispatch input,
   source file, shell argument or CI log. No credentials are changed by this plan.
3. PREVIEW's exact receipt/hash, immutable source/image identities and target UUID
   become part of the APPLY decision. Before APPLY, pause all writers and prove
   the fresh private backup restores with matching schema/data and acceptable
   recovery time; record runbook hash, RPO, expiry and the verified flag-off state.
4. A separately reviewed operator launcher must enforce that decision, with an
   independently verified approval artifact (for example, reviewer-signed bytes
   verified against an approved public key held outside the operator's editable
   checkout). Bind approval to operation, source/image/migration hashes, target,
   PREVIEW/backup/runbook digests, distinct operator/reviewer, nonce and short
   expiry. Persist nonce consumption in the independent control system to reject
   replay; the operator must not be able to create the reviewer's approval.
   Preserve the existing TLS, identity, history, exact-path and preservation checks.
5. The reviewer witnesses execution and postflight, the owner accepts the result,
   and the custodian ends the approved access window. Email activation remains a
   separate change. Keep safe receipts plus private recovery evidence under the
   approved retention/access policy.

**Do not implement this by deleting the Python approval step, using an
unprotected environment, accepting a checkbox/comment as authorization, moving
production secrets into test CI, or manually setting `EV_APPROVAL_VERIFIED`.**
The current helper requires GitHub provenance; it cannot execute this alternative
unchanged. Implementing and rehearsing an external approval adapter requires a
separate decision, code review and disposable test. If independent authorization
and credential custody cannot be enforced, production must remain blocked. A
supported GitHub plan is the other option; no plan change is made here.

## Recommended next steps

1. Keep the literal production hold and email enrollment off. Independently
   verify the compatible live release and effective flag later under authorized
   read-only access; this local work does not assert their live state.
2. Have a reviewer examine this uncommitted harness, then run it on an approved
   Docker host using synthetic MySQL 8.4.11 only. Retain the exact tested source
   and image digests plus a complete PASS report; investigate failures before any
   new run. Do not reinterpret the earlier 26 checks as runner test results.
3. Decide between supported protected environments and the external two-person
   process. Validate the chosen enforcement without database secrets, including
   unauthorized execution, self-approval, bypass and replay attempts.
4. Only after separate authorization, establish verified production backup/restore
   evidence and a maintenance window. Any removal of the production hold and any
   production PREVIEW/APPLY require a separate reviewed approval. Email enrollment
   activation remains outside that migration approval.

Primary references: [GitHub review-history response](https://docs.github.com/en/rest/actions/workflow-runs#get-the-review-history-for-a-workflow-run),
[Docker isolated/shared container networking](https://docs.docker.com/engine/network/).
