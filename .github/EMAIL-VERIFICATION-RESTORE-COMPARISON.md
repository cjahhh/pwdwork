# Disposable restore comparison investigation

Reviewed **29 October 2026**, with local HEAD matching the reported commit
`00d95593c7efbde65c4e93b4c85b4de60c88df61`. The owner reports run `40730422871`
failed operation `restore_compare`, table `accommodation_request_events`, with
**0/32** scenarios passed. This report is
based on that supplied diagnostic and the matching local code, not an independently
retrieved private Actions artifact. No production connection was made.

## Exact boundary and remaining uncertainty

The current call chain is:

1. Bash creates an isolated MySQL 8.4.11 server, runs the reviewed 15-migration
   initializer and synthetic fixture, then makes a logical dump.
2. For the first scenario, Bash creates the fresh disposable `pwdwork_prod`
   database and imports the dump. The name is intentionally production-shaped
   inside the network-none lab; it is not a production connection.
3. The PHP lab connects to the restored and baseline databases using hardcoded
   loopback, verifies MySQL version and selects UTC on both connections.
4. `fullSnapshot($baseline)` and `fullSnapshot($pdo)` both complete, under
   `baseline_snapshot` and `restored_snapshot` respectively.
5. `requireRestored()` calls `restoreDifference()` at `restore_compare`. Strict
   snapshot inequality identifies `accommodation_request_events`, then supplemental
   `schemaComponents()` reads classify the raw definition mismatch.

The confirmed failure is unequal SHA256 fingerprints of the **raw**
`SHOW CREATE TABLE` text. The latest evidence confirms equal Information Schema
column definitions/defaults and equal primary keys, indexes, foreign keys and
table options, but unequal parsed column tokens. `definition_parse` is true;
`definition_columns`, `definition_tokens` and `definition_clause_set` are false.
The preceding run additionally reported zero rows on each side with equal data
fingerprints; that does not identify the latest differing column.

The incremental runner has **not started** at this point. Its authorization,
certificate verification, migration and postflight are not the origin of this
specific reported assertion. Both controller PDO connections and the baseline
snapshot completed; this does not prove the runner's later TLS connection.

**The exact differing column and SQL fragment remain unconfirmed.** No raw DDL
or per-column evidence is available locally; Docker and the GitHub CLI are not
available, and no private Actions artifact was independently retrieved. The new
outcomes rule out whitespace-only differences outside protected tokens and a
pure declaration reorder. They supersede the previous foreign-key-order candidate.

The parser groups declarations starting with a backtick as columns and keeps
them in order. It preserves quoted strings/identifiers and comments, and splits
only at top-level commas. Identical raw text cannot produce different parsed
results here. More decisively, **the parser cannot cause the restore rejection**:
`fullSnapshot()` hashes raw SHOW CREATE output without calling it. Successful
parsing alone does not establish that column classification matches metadata;
the new diagnostic checks all parsed names and their order against Information
Schema on each side.

## Concrete representation candidate from existing evidence

[MySQL bug #110825](https://bugs.mysql.com/bug.php?id=110825), verified for MySQL
8.0.32, documents SHOW CREATE instability when recreating a table from its own
output: a character column initially displays COLLATE alone, and after recreation
also displays an explicit CHARACTER SET clause. Effective charset, collation and
the remaining column definition stay the same. This is a primary-source example,
not confirmation of behavior on this specific MySQL 8.4.11 run.

The local Laravel MySQL grammar supplies the table charset/collation; the reviewed
workflow migration does not set per-column charset/collation. Its first character
column is `actor_role` (ordinal 4). That is a concrete candidate **if** the reported
MySQL behavior is the cause, not an identified runtime mismatch. The offline
regression reproduces the latest reported pattern with that synthetic declaration:
equal metadata/defaults, unequal column/token/clause-set comparisons and successful
parsing. It still rejects the restore. Comments, explicit defaults, quoting and
attributes remain possible alternatives until per-column evidence is obtained.

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

The confirmed diagnostic defect is corrected by adding SQL-token and declaration
comparisons **only after** a raw DDL mismatch. The tokenizer preserves quoted
identifiers, quoted values, escaped/doubled quotes and comments byte for byte.
It splits declarations at commas only outside quotes/comments and nested
parentheses. Token arrays are privately hashed with explicit JSON boundaries.
No database SQL is executed by the classifier.

Whitespace-only and declaration-order equivalence can now be recognized in
diagnostics without accepting the failed restore. A multiset preserves duplicate
clauses and exact names/tokens; separate column-order and unknown-clause-order
checks prevent interpreting a multiset match alone as structural equivalence.
The raw definition fingerprint and all acceptance checks remain unchanged. A
parser failure reports `definition_parse: false`, retains available metadata
comparisons, and leaves the original failure intact.

The existing Actions workflow now obtains a focused `comparison.column` object
through its existing helper/cleanup/validator path; no workflow, network, dump or
restore change is needed. It reports only the first differing ordinal, names from
the 14-column `accommodation_request_events` allowlist (other names become null),
fixed token classes and fixed boolean checks. There are no SQL tokens/values,
charset names, defaults, comments, expression text, hashes or exception messages
in that object.

The checks establish parser-to-metadata alignment on both sides, column order,
per-column ordinal/type/default/nullability/extra/charset/collation/generated
expression equality, and whether the difference is solely an explicit
metadata-matching CHARACTER SET clause. The hint removes at most one such
top-level clause for private comparison; quoted literals, comments, generated
expressions, nonmatching charsets and duplicate clauses are retained. A separate
`all_columns_charset_spelling` outcome prevents a first-column hint from hiding
later real changes. Freshness checks compare reread DDL hashes with the original
fullSnapshot hashes, so drift during diagnostics is visible.

These are **diagnostic comparisons only**. If a subsequent isolated run confirms
both freshness checks, parser alignment, equal metadata, and
`all_columns_charset_spelling: true`, a possible correction is canonicalizing only
those metadata-matching explicit charset clauses while continuing to compare all
remaining tokens and all records. No acceptance canonicalization is implemented
while the actual differing declaration is unknown. The metadata query does not
include COLUMN_COMMENT/SRS_ID; these are not discarded from the raw DDL guard.

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
| `definition_parse` | Both DDL texts were classified successfully; false never promotes a restore to PASS. |
| `definition_tokens` | Exact token sequence excluding whitespace outside quotes/comments. True with raw definition false identifies a whitespace-only representation difference. |
| `definition_header` | CREATE TABLE header, including the exact quoted table name. |
| `definition_clause_set` | Header, declaration multiset and trailing options match after tokenization. Separators are parsed, so final-declaration comma movement is handled. Column order must still be checked separately. |
| `definition_columns` | Ordered column declarations, preserving types, defaults, literals, expressions, charset/collation and comments. |
| `definition_primary_key`, `definition_indexes` | Declaration multisets; preserve names, column order, uniqueness, prefixes, visibility, expressions and all remaining tokens. |
| `definition_foreign_keys`, `definition_checks` | Exact declaration multisets including names, targets, actions, expressions and versioned comments. |
| `definition_other_clauses` | Any unclassified declarations, in order; none are discarded. |
| `definition_table_options` | All tokens after the closing table parenthesis, including comments, counters and partition declarations. |
| `definition_auto_increment_option` | The actual DDL AUTO_INCREMENT option, separately from potentially cached Information Schema metadata. No value is printed. |
| `definition_fk_default_actions` | Diagnostic-only FK comparison after removing explicit RESTRICT/NO ACTION actions, scoped to FK tokens; CASCADE, SET NULL, names and targets remain exact. This hint never changes acceptance. |

MySQL documents default NO ACTION omission in SHOW CREATE and explicit RESTRICT
display. This is background for the scoped hint, **not evidence** that this run
lost an action: [MySQL foreign-key actions](https://dev.mysql.com/doc/refman/8.4/en/create-table-foreign-keys.html).

Only fixed category/token-class names, reviewed table/column names, the column
ordinal, boolean outcomes and safe row counts are published. No raw DDL, defaults, index/constraint names, schema names,
fingerprints, passwords, personal data, dumps, bindings, exception messages or
credentials are emitted. The Python validator checks the exact nested shape and
allowlists before propagating it through the existing cleanup/report/summary
pipeline. A comparison cannot accompany PASS evidence.

## Changes and validation

- `.github/scripts/email-verification-incremental-rehearsal.php`: comparison
  first-column diagnostics with metadata alignment and scoped charset hints,
  retaining the strict guard and original fullSnapshot capture.
- `.github/scripts/email-verification-incremental-ci.py`: nested comparison
  allowlists, strict first-column object validation and sanitized summary rendering.
- `backend/tests/Unit/EmailVerificationRestoreComparisonTest.php`: mocked-PDO
  regressions for identical restore, schema/default/index/FK/options/counter
  differences, row and ledger changes, table/order differences, missing primary
  keys, redaction and diagnostic-read failures. Additional regressions reproduce
  final-clause comma movement and distinguish whitespace/order equivalence from
  meaningful changes. Even diagnostic-equivalent raw differences still fail.
  New regressions cover the public MySQL example, first-column reporting, actual
  type/default/nullability/charset/collation/attribute/generated-expression changes,
  comments, unknown names, parser misalignment, protected tokens and later-column
  changes. No MySQL connection is opened.
- `.github/tests/test_email_verification_incremental_ci.py`: safe nested-schema
  validation and actual cleanup-heredoc-to-artifact propagation regressions,
  including new categories and zero-row failures. Equivalence hints cannot
  promote failed evidence to PASS. New first-column evidence traverses the actual
  cleanup heredoc and summary path; raw SQL, unknown names, nonboolean flags and
  malformed objects are rejected and redacted.
- This investigation record.

Local results: **197 PHP tests / 772 assertions**, **41 Python tests**, PHP syntax
and Pint, Bash syntax, compilation of all four incremental-rehearsal Python
heredocs (ten across the three email-verification Bash scripts), actionlint and
whitespace checks passed. ShellCheck is unavailable. Docker is still unavailable
locally; no MySQL integration run or new GitHub run occurred during this task.

The Bash backup/restore procedure, all **32 scenario names**, production runner,
actual migration, authentication/approval code, both workflows and feature
configuration are unchanged. The production workflow retains its explicit
`if: false && ...` condition. Normal lab runner environments explicitly set
`EMAIL_VERIFICATION_ENABLED=false`; application configuration still defaults to
false and the production runner still rejects enrollment-enabled execution.
The existing deliberately invalid `enrollment_enabled` scenario still tests
rejection before activation or database access. No deployed environment or
production configuration was accessed to verify its runtime state.

Another separately authorized isolated diagnostic run is justified because the
existing evidence cannot identify the first column or confirm the candidate.
Expected focused evidence for the candidate is `before_name/after_name: actor_role`,
ordinal 4, token classes `collation` / `character_set`, both metadata alignment and
freshness checks true, and both charset-spelling hints true. These are expectations
for the offline example, not claims about the private run. Any false alignment,
freshness or all-column hint requires further investigation; no raw SQL or values
should be published. Do not claim the restore problem is fixed or change
acceptance until evidence establishes a concrete, harmless difference.
Use a new reviewed opt-in commit through the existing runbook, not an old-commit
rerun. No commit, push, merge, deployment, production access or credential change
was performed here. Production migration and activation remain unapproved.
