# Disposable restore comparison investigation

Reviewed **29 October 2026**, with local HEAD matching the reported commit
`bf432d59481e259b2545e3b5016410864824c491`. The owner reports run `40723965550`
failed `preview_readonly`, operation `restore_compare`, category `validation`,
runner phase/native code null, with **0/32** scenarios passed. This report is
based on that supplied diagnostic and the matching local code, not an independently
retrieved private Actions artifact. No production connection was made.

## Exact boundary and remaining uncertainty

The original call chain was:

1. Bash creates an isolated MySQL 8.4.11 server, runs the reviewed 15-migration
   initializer and synthetic fixture, then makes a logical dump.
2. For the first scenario, Bash creates the fresh disposable `pwdwork_prod`
   database and imports the dump. The name is intentionally production-shaped
   inside the network-none lab; it is not a production connection.
3. The PHP lab connects to the restored and baseline databases using hardcoded
   loopback, verifies MySQL version and selects UTC on both connections.
4. `fullSnapshot($baseline)` succeeds before operation `restore_compare`.
5. At `restore_compare`, it evaluates
   `demand($baselineFull === fullSnapshot($pdo))`.

That expression contains **two** possible validation failures: a missing primary
key inside the restored `fullSnapshot()`, or strict inequality of the completed
snapshot arrays. Other native PDO failures would have a database category rather
than this validation category. The current diagnostic does not distinguish those
two assertions or identify a differing table/property.

The incremental runner has **not started** at this point. Its authorization,
certificate verification, migration and postflight are not the origin of this
specific reported assertion. Both controller PDO connections and the baseline
snapshot completed; this does not prove the runner's later TLS connection.

**The underlying differing value is not confirmed.** There is no evidence yet
that records are identical, that AUTO_INCREMENT differs, or that a mismatch is
nonessential generated metadata. No counter, schema field, constraint name or
DDL text is stripped/ignored to make the test pass.

## Backup and equality review

The dump uses the same server's MySQL 8.4 tools with `--single-transaction`,
`--quick`, `--set-gtid-purged=OFF`, `--no-tablespaces`, `--hex-blob`, and inclusion
of routines/events/triggers. The wrapper requires a nonempty dump and refuses
active `USE`/`CREATE DATABASE` directives before importing. UTC is used by the
dump and import sessions. Each target is reset/imported only after checking the
owned container's `network none` state and absence of published ports. No web,
worker or scheduler writes are started. No change to dump/import options is
justified by the current evidence, so none was made.

The snapshot compares a sorted table-name inventory, the SHA256 of every raw
`SHOW CREATE TABLE` result, and per-table exact row counts/data hashes from all
columns ordered by the primary key. This includes every migration-ledger row,
ID and batch. It does not export rows or values. Unlike the production runner's
original-column projection, this lab snapshot excludes no columns or ledger rows.

`SHOW CREATE TABLE` contains definitions, defaults, indexes, constraints and table
options, including a displayed AUTO_INCREMENT counter. It is not a pure logical
row comparison. MySQL can display generated keys/options in it; this is a reason
to diagnose differences, not proof that the observed difference is harmless.
See [MySQL SHOW CREATE TABLE](https://dev.mysql.com/doc/refman/8.4/en/show-create-table.html).

Information Schema statistics, including AUTO_INCREMENT, can be cached. Therefore
the supplemental `auto_increment_metadata` outcome is a clue rather than sole
proof of a live counter. The raw DDL equality check remains decisive; no session
statistics setting was changed. See [MySQL statistics caching](https://dev.mysql.com/doc/refman/8.4/en/server-system-variables.html#sysvar_information_schema_stats_expiry).
Volatile row estimates, index cardinality, data sizes and internal object IDs are
not fields of the original equality snapshot; no new exception for them is added.

## Smallest safe correction: precise comparison diagnostics

The restored snapshot is now collected under `restored_snapshot`, then compared
under `restore_compare`. A missing primary key reports `primary_key: false`.
Strict inequality reports the **first** affected table and individual equality
outcomes, so a nested snapshot assertion and completed-snapshot mismatch can be
distinguished. Unknown table names are replaced with null.

The original `definition` and `records` fingerprints and strict acceptance
criteria remain intact. Supplementary metadata is read **only after a DDL
mismatch**, solely to classify it. A failure of those extra diagnostic reads
reports `metadata_read: false` and still rejects the original mismatch.

| Safe comparison category | Meaning |
| --- | --- |
| `table_inventory`, `table_order`, `snapshot_structure` | Table membership/order and fingerprint-structure equality. |
| `definition` | Exact original raw SHOW CREATE hash equality, without normalization. |
| `row_count`, `data_fingerprint` | Exact counts and all-column/all-row equality. |
| `migration_history` | Full ledger fingerprint equality, including IDs and batches. |
| `primary_key` | The table has the required primary key for ordered hashing. |
| `column_definitions`, `column_defaults` | Ordinals, types, nullability, extra/generated properties, charset/collation and defaults. |
| `indexes` | Names, uniqueness, order, columns, prefixes, visibility, type and expressions. |
| `foreign_keys` | Names, ordered columns, reference scope/target, update/delete rules. Local schema references are classified as local only in diagnostic metadata, not removed from the raw DDL check. |
| `table_options` | Engine, collation, row format and create options. |
| `auto_increment_metadata` | Information Schema counter equality; no counter value is printed. |
| `definition_line_set` | Whether raw DDL has the same hashed lines regardless of order; diagnostics only. Even a line-order-only difference still fails. |

Only fixed category names, reviewed table names, boolean outcomes and safe row
counts are published. No raw DDL, defaults, index/constraint names, schema names,
fingerprints, passwords, personal data, dumps, bindings, exception messages or
credentials are emitted. The Python validator checks the exact nested shape and
allowlists before propagating it through the existing cleanup/report/summary
pipeline. A comparison cannot accompany PASS evidence.

## Changes and validation

- `.github/scripts/email-verification-incremental-rehearsal.php`: comparison
  decomposition and safe metadata classification, retaining the strict guard.
- `.github/scripts/email-verification-incremental-ci.py`: nested comparison
  allowlists and sanitized JSON/Markdown rendering.
- `backend/tests/Unit/EmailVerificationRestoreComparisonTest.php`: mocked-PDO
  regressions for identical restore, schema/default/index/FK/options/counter
  differences, row and ledger changes, table/order differences, missing primary
  keys, redaction and diagnostic-read failures. No MySQL connection is opened.
- `.github/tests/test_email_verification_incremental_ci.py`: safe nested-schema
  validation and actual cleanup-heredoc-to-artifact propagation regressions.
- This investigation record.

Local results: **156 PHP tests / 317 assertions**, **37 Python tests**, PHP syntax
and Pint, Bash syntax, compilation of all four Python heredocs, actionlint and
whitespace checks passed. ShellCheck is unavailable. Docker is still unavailable
locally; no MySQL integration run or new GitHub run occurred during this task.

The Bash backup/restore procedure, all **32 scenario names**, production runner,
actual migration, authentication/approval code, both workflows and feature
configuration are unchanged. The production workflow retains its explicit
`if: false && ...` condition. Email enrollment remains disabled in the actual
runner/configuration; the existing deliberately invalid `enrollment_enabled`
scenario still tests rejection before activation or database access.

Another separately authorized isolated diagnostic run is justified to obtain the
first affected table and comparison outcomes. Do not claim the restore problem
is fixed or normalize metadata until those outcomes establish a concrete cause.
Use a new reviewed opt-in commit through the existing runbook, not an old-commit
rerun. No commit, push, merge, deployment, production access or credential change
was performed here. Production migration and activation remain unapproved.
