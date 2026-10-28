<?php

declare(strict_types=1);

// Test controller only. Never included by the production workflow or runner.

namespace Pwdwork\EmailIncrementalLab;

use const Pwdwork\EmailIncremental\DDL;
use const Pwdwork\EmailIncremental\FAILURE_CATEGORIES;
use const Pwdwork\EmailIncremental\MIGRATION_SHA;
use const Pwdwork\EmailIncremental\MYSQL_CODES;
use const Pwdwork\EmailIncremental\PHASES;
use const Pwdwork\EmailRehearsal\MIGRATION;

use PDO;
use RuntimeException;
use Throwable;

use function Pwdwork\EmailIncremental\failureDetails;
use function Pwdwork\EmailIncremental\hashStream;
use function Pwdwork\EmailIncremental\schemaHash;
use function Pwdwork\EmailIncremental\snapshot;
use function Pwdwork\EmailRehearsal\columns;
use function Pwdwork\EmailRehearsal\encode;
use function Pwdwork\EmailRehearsal\identifier;
use function Pwdwork\EmailRehearsal\inventory;
use function Pwdwork\EmailRehearsal\rows;
use function Pwdwork\EmailRehearsal\state;

const CASES = [
    'preview_readonly' => null, 'apply_success' => null,
    'preview_unapproved' => 'authorization', 'apply_unapproved' => 'authorization',
    'wrong_database' => 'authorization', 'wrong_host' => 'authorization', 'wrong_port' => 'authorization',
    'wrong_uuid' => 'tls_connection', 'wrong_ca' => 'tls_connection', 'wrong_tls_hostname' => 'tls_connection',
    'changed_migration' => 'reviewed_source', 'extra_migration' => 'reviewed_source', 'changed_image_migration' => 'reviewed_source',
    'partial_one' => 'schema_history', 'partial_two' => 'schema_history', 'unrecorded' => 'schema_history',
    'already_applied' => 'schema_history', 'history_missing' => 'schema_history',
    'history_unknown' => 'schema_history', 'history_duplicate' => 'schema_history',
    'backup_missing' => 'backup_proof', 'backup_hash' => 'backup_proof', 'backup_expired' => 'backup_proof',
    'preview_missing' => 'preview_evidence', 'preview_hash' => 'preview_evidence', 'preview_expired' => 'preview_evidence',
    'preview_wrong_sha' => 'preview_evidence', 'preview_wrong_schema' => 'preview_evidence',
    'schema_drift' => 'preview_evidence', 'advisory_lock' => 'tls_connection',
    'enrollment_enabled' => 'authorization', 'rerun' => 'authorization',
];

const OPERATIONS = ['container_boundary', 'network_boundary', 'load_runner', 'fixture_credentials',
    'connect_target', 'connect_baseline', 'baseline_snapshot', 'restored_snapshot', 'restore_compare', 'baseline_state',
    'original_snapshot', 'server_identity', 'evidence_fixture', 'prepare_scenario', 'before_snapshot',
    'runner_start', 'runner_output', 'runner_receipt', 'preview_result', 'apply_result',
    'postflight_state', 'preservation', 'repeat_result', 'expected_rejection', 'preview_rejection', 'baseline_preservation'];

// Display names only. Unexpected table names are never reflected into evidence.
const TABLE_NAMES = ['users', 'password_reset_tokens', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches',
    'failed_jobs', 'personal_access_tokens', 'accommodations', 'applicant_profiles', 'employers', 'job_listings',
    'applicant_accommodation', 'job_listing_accommodation', 'applications', 'saved_jobs', 'user_notifications',
    'user_reports', 'admin_audit_logs', 'accommodation_requests', 'accommodation_request_messages',
    'accommodation_request_events', 'resumes', 'resume_application_shares', 'migrations'];

class RestoreComparisonFailure extends RuntimeException
{
    public function __construct(public readonly array $comparison)
    {
        parent::__construct('disposable_restore_comparison_failed');
    }
}

function comparisonReport(?string $table, array $checks, ?int $beforeRows, ?int $afterRows): array
{
    return ['table' => in_array($table, TABLE_NAMES, true) ? $table : null, 'checks' => $checks,
        'before_rows' => $beforeRows, 'after_rows' => $afterRows];
}

/** Same strict equality as before; diagnostic output contains no fingerprints. */
function restoreDifference(array $before, array $after): ?array
{
    if ($before === $after) {
        return null;
    }
    if (array_keys($before) !== array_keys($after)) {
        $left = array_keys($before);
        $right = array_keys($after);
        sort($left);
        sort($right);

        return comparisonReport(null, ['table_inventory' => $left === $right, 'table_order' => false], null, null);
    }
    foreach ($before as $table => $left) {
        $right = $after[$table];
        if ($left !== $right) {
            $checks = ['table_inventory' => true, 'snapshot_structure' => array_keys($left) === array_keys($right)
                && array_keys($left['records']) === array_keys($right['records']),
                'definition' => $left['definition'] === $right['definition'],
                'row_count' => $left['records']['rows'] === $right['records']['rows'],
                'data_fingerprint' => $left['records']['data_sha256'] === $right['records']['data_sha256']];
            if ($table === 'migrations') {
                $checks['migration_history'] = $left['records'] === $right['records'];
            }

            return comparisonReport($table, $checks, $left['records']['rows'], $right['records']['rows']);
        }
    }

    throw new RuntimeException('disposable_snapshot_structure_rejected');
}

/** Private SQL tokens only. Quotes/comments stay byte-exact; no SQL is executed. */
function definitionParts(string $ddl): array
{
    $pattern = <<<'REGEX'
~`(?:``|[^`])*`|'(?:''|\\.|[^'\\])*'|"(?:""|\\.|[^"\\])*"|/\*.*?\*/|--[ \t][^\r\n]*|\#[^\r\n]*|[A-Za-z0-9_$]+|[ \t\r\n]+|.~s
REGEX;
    demand(preg_match_all($pattern, $ddl, $matches) !== false && implode('', $matches[0]) === $ddl);
    $tokens = array_values(array_filter($matches[0], fn ($token) => ! ctype_space($token)));
    demand(count($tokens) >= 6 && array_slice($tokens, 0, 2) === ['CREATE', 'TABLE']
        && str_starts_with($tokens[2], '`') && $tokens[3] === '(');
    demand(array_intersect($tokens, ["'", '"', '`']) === []);
    $clauses = [];
    $clause = [];
    $depth = 1;
    for ($i = 4; $i < count($tokens); $i++) {
        $token = $tokens[$i];
        if ($token === '(') {
            $depth++;
        } elseif ($token === ')') {
            $depth--;
            if ($depth === 0) {
                break;
            }
        }
        if ($token === ',' && $depth === 1) {
            demand($clause !== []);
            $clauses[] = $clause;
            $clause = [];
        } else {
            $clause[] = $token;
        }
    }
    demand($depth === 0 && $clause !== []);
    $clauses[] = $clause;
    $header = array_slice($tokens, 0, 4);
    $options = array_slice($tokens, $i + 1);
    $groups = ['definition_columns' => [], 'definition_primary_key' => [], 'definition_indexes' => [],
        'definition_foreign_keys' => [], 'definition_checks' => [], 'definition_other_clauses' => []];
    foreach ($clauses as $clause) {
        $kind = $clause[0] === 'CONSTRAINT' ? ($clause[2] ?? '') : $clause[0];
        $group = match (true) {
            str_starts_with($clause[0], '`') => 'definition_columns',
            $kind === 'PRIMARY' => 'definition_primary_key',
            in_array($kind, ['UNIQUE', 'KEY', 'INDEX', 'FULLTEXT', 'SPATIAL'], true) => 'definition_indexes',
            $kind === 'FOREIGN' => 'definition_foreign_keys',
            $kind === 'CHECK' => 'definition_checks',
            default => 'definition_other_clauses',
        };
        $groups[$group][] = $clause;
    }
    $sorted = function (array $items): array {
        usort($items, fn ($a, $b) => strcmp(encode($a), encode($b)));

        return $items;
    };
    // Diagnostic multiset comparisons retain names, duplicate clauses and all tokens.
    // Column and unrecognized-clause order remains visible in separate categories.
    foreach (['definition_primary_key', 'definition_indexes', 'definition_foreign_keys', 'definition_checks'] as $group) {
        $groups[$group] = $sorted($groups[$group]);
    }
    $defaultActions = array_map(function ($clause) {
        for ($i = 0; $i < count($clause); $i++) {
            if ($clause[$i] === 'ON' && in_array($clause[$i + 1] ?? '', ['DELETE', 'UPDATE'], true)) {
                $length = ($clause[$i + 2] ?? '') === 'RESTRICT' ? 3
                    : (array_slice($clause, $i + 2, 2) === ['NO', 'ACTION'] ? 4 : 0);
                if ($length !== 0) {
                    array_splice($clause, $i, $length);
                    $i--;
                }
            }
        }

        return $clause;
    }, $groups['definition_foreign_keys']);
    $counter = [];
    for ($i = 0; $i < count($options) - 2; $i++) {
        if (array_slice($options, $i, 2) === ['AUTO_INCREMENT', '='] && ctype_digit($options[$i + 2])) {
            $counter[] = array_slice($options, $i, 3);
        }
    }

    return ['definition_tokens' => $tokens, 'definition_header' => $header,
        'definition_clause_set' => [$header, $sorted($clauses), $options],
        ...$groups, 'definition_table_options' => $options, 'definition_auto_increment_option' => $counter,
        'definition_fk_default_actions' => $sorted($defaultActions)];
}

/** Private diagnostic hashes only; never change the raw DDL or acceptance rule. */
function schemaComponents(PDO $pdo, string $table): array
{
    demand(in_array($table, TABLE_NAMES, true));
    $fields = columns($pdo, $table);
    $defaults = array_map(fn ($field) => [$field['COLUMN_NAME'], $field['COLUMN_DEFAULT']], $fields);
    $definitions = array_map(function ($field) {
        unset($field['COLUMN_DEFAULT']);

        return $field;
    }, $fields);
    $indexes = rows($pdo, 'SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, COLLATION, SUB_PART, INDEX_TYPE, IS_VISIBLE, EXPRESSION FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX', [$table]);
    $foreign = rows($pdo, "SELECT k.CONSTRAINT_NAME,k.COLUMN_NAME,k.ORDINAL_POSITION,IF(k.REFERENCED_TABLE_SCHEMA=DATABASE(),'local','external') AS reference_scope,IF(k.REFERENCED_TABLE_SCHEMA=DATABASE(),NULL,k.REFERENCED_TABLE_SCHEMA) AS external_schema,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.UPDATE_RULE,r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME=? ORDER BY k.CONSTRAINT_NAME,k.ORDINAL_POSITION", [$table]);
    $metadata = array_values(array_filter(inventory($pdo), fn ($item) => $item['TABLE_NAME'] === $table));
    demand(count($metadata) === 1);
    $options = $metadata[0];
    $counter = $options['AUTO_INCREMENT'];
    unset($options['TABLE_NAME'], $options['AUTO_INCREMENT']);
    $ddl = $pdo->query('SHOW CREATE TABLE '.identifier($table))->fetch(PDO::FETCH_NUM)[1];
    $lines = array_map(fn ($line) => hash('sha256', $line), explode("\n", $ddl));
    sort($lines);

    $components = [
        'column_definitions' => $definitions, 'column_defaults' => $defaults, 'indexes' => $indexes,
        'foreign_keys' => $foreign, 'table_options' => $options, 'auto_increment_metadata' => $counter,
        'definition_line_set' => $lines,
    ];
    $parsed = true;
    try {
        $components += definitionParts($ddl);
    } catch (Throwable) {
        $parsed = false;
    }

    return ['definition_parse' => $parsed, ...array_map(fn ($value) => hash('sha256', encode($value)), $components)];
}

/** Restore must still match every original SHOW CREATE byte and record. */
function requireRestored(array $before, array $after, PDO $baseline, PDO $restored): void
{
    $difference = restoreDifference($before, $after);
    if ($difference === null) {
        return;
    }
    if ($difference['table'] !== null && ($difference['checks']['definition'] ?? true) === false) {
        try {
            $left = schemaComponents($baseline, $difference['table']);
            $right = schemaComponents($restored, $difference['table']);
            foreach ($left as $name => $hash) {
                $difference['checks'][$name] = $name === 'definition_parse' ? $hash && $right[$name]
                    : $hash === ($right[$name] ?? null);
            }
        } catch (Throwable) {
            // Keep the original failed comparison even if extra diagnostics fail.
            $difference['checks']['metadata_read'] = false;
        }
    }
    throw new RestoreComparisonFailure($difference);
}

function operation(string $value): void
{
    demand(in_array($value, OPERATIONS, true));
    $GLOBALS['ev_lab_operation'] = $value;
}

function diagnostic(Throwable $error): array
{
    $op = $GLOBALS['ev_lab_operation'] ?? 'container_boundary';
    $op = in_array($op, OPERATIONS, true) ? $op : 'container_boundary';
    $details = function_exists('Pwdwork\\EmailIncremental\\failureDetails')
        ? failureDetails($error) : ['category' => $error instanceof RuntimeException ? 'validation' : 'php_runtime', 'mysql_code' => null];
    if (in_array($op, ['runner_start', 'runner_output', 'runner_receipt'], true)) {
        $details = ['category' => 'runner_protocol', 'mysql_code' => null];
    }
    $runnerPhase = null;
    if (in_array($op, ['preview_result', 'apply_result', 'repeat_result', 'expected_rejection', 'preview_rejection'], true)) {
        $failure = $GLOBALS['ev_lab_runner_failure'] ?? null;
        if (is_array($failure) && in_array($failure['phase'] ?? null, PHASES, true)
            && in_array($failure['category'] ?? null, FAILURE_CATEGORIES, true)
            && (($failure['mysql_code'] ?? null) === null || in_array($failure['mysql_code'], MYSQL_CODES, true))) {
            $details = ['category' => $failure['category'], 'mysql_code' => $failure['mysql_code']];
            $runnerPhase = $failure['phase'];
        }
    }

    $result = ['operation' => $op, 'category' => $details['category'], 'runner_phase' => $runnerPhase, 'mysql_code' => $details['mysql_code']];
    if ($error instanceof RestoreComparisonFailure) {
        $result['comparison'] = $error->comparison;
    }

    return $result;
}

function demand(bool $condition): void
{
    if (! $condition) {
        throw new RuntimeException('disposable_assertion_failed');
    }
}

/** Hardcoded loopback only; original rehearsal database and local restored copy. */
function connect(string $database, #[\SensitiveParameter] string $password): PDO
{
    demand(in_array($database, ['pwdwork_ev_rehearsal', 'pwdwork_prod'], true));
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname='.$database.';charset=utf8mb4', 'pwdwork_rehearsal', $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_STRINGIFY_FETCHES => true]);
    demand($pdo->query('SELECT VERSION()')->fetchColumn() === '8.4.11');
    $pdo->exec("SET SESSION time_zone = '+00:00'");

    return $pdo;
}

/** Includes ALL columns, ALL ledger rows and counters, even in rejected cases. */
function fullSnapshot(PDO $pdo): array
{
    $result = [];
    foreach (inventory($pdo) as $table) {
        $name = identifier($table['TABLE_NAME']);
        $fields = array_map(fn ($field) => identifier($field['COLUMN_NAME']), columns($pdo, $table['TABLE_NAME']));
        $primary = array_column(rows($pdo, "SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME='PRIMARY' ORDER BY SEQ_IN_INDEX", [$table['TABLE_NAME']]), 'COLUMN_NAME');
        if ($primary === []) {
            throw new RestoreComparisonFailure(comparisonReport($table['TABLE_NAME'], ['primary_key' => false], null, null));
        }
        $result[$table['TABLE_NAME']] = [
            'definition' => hash('sha256', $pdo->query('SHOW CREATE TABLE '.$name)->fetch(PDO::FETCH_NUM)[1]),
            'records' => hashStream(rows($pdo, 'SELECT '.implode(',', $fields).' FROM '.$name.' ORDER BY '.implode(',', array_map('Pwdwork\\EmailRehearsal\\identifier', $primary)))),
        ];
    }

    return $result;
}

/** A fresh process executes the byte-identical runner's actual main()/execute(). */
function runner(array $env): array
{
    operation('runner_start');
    $GLOBALS['ev_lab_runner_failure'] = null;
    $process = proc_open([PHP_BINARY, '/run/ev-source/.github/scripts/email-verification-incremental.php'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, '/var/www/backend', $env);
    demand(is_resource($process));
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $code = proc_close($process);
    operation('runner_output');
    demand($errors === '' && strlen($output) < 4000);
    operation('runner_receipt');
    $receipt = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    demand(is_array($receipt));
    if ($code !== 0) {
        demand($code === 1 && $receipt === [
            'status' => 'FAIL', 'phase' => $receipt['phase'],
            'action' => 'STOP; no retry, rollback or automatic repair. Inspect schema/history privately; follow the recovery runbook.',
            'category' => $receipt['category'] ?? null, 'mysql_code' => $receipt['mysql_code'] ?? null,
        ]);
        demand(in_array($receipt['phase'], PHASES, true) && in_array($receipt['category'], FAILURE_CATEGORIES, true)
            && ($receipt['mysql_code'] === null || in_array($receipt['mysql_code'], MYSQL_CODES, true)));
        $GLOBALS['ev_lab_runner_failure'] = $receipt;
    } else {
        demand(array_keys($receipt) === ['format', 'status', 'operation', 'sha', 'run_id', 'server_uuid', 'migration_sha256', 'schema_sha256', 'created_utc', 'existing_records_preserved']);
        demand($receipt['status'] === 'PASS' && $receipt['migration_sha256'] === MIGRATION_SHA);
    }

    return [$code, $receipt];
}

function execute(string $case, #[\SensitiveParameter] string $password, #[\SensitiveParameter] string $key): void
{
    demand(array_key_exists($case, CASES));
    operation('connect_target');
    $pdo = connect('pwdwork_prod', $password);
    operation('connect_baseline');
    $baseline = connect('pwdwork_ev_rehearsal', $password);
    operation('baseline_snapshot');
    $baselineFull = fullSnapshot($baseline);
    // Every scenario first proves the exact synthetic dump restored correctly.
    operation('restored_snapshot');
    $restoredFull = fullSnapshot($pdo);
    operation('restore_compare');
    requireRestored($baselineFull, $restoredFull, $baseline, $pdo);
    operation('baseline_state');
    state($pdo, 0, 0);
    operation('original_snapshot');
    $original = snapshot($pdo);
    operation('server_identity');
    $uuid = $pdo->query('SELECT @@server_uuid')->fetchColumn();
    $sha = str_repeat('a', 40); // Deliberately fictional; never production approval.
    $env = [
        'PATH' => '/usr/local/bin:/usr/bin:/bin', 'GITHUB_ACTIONS' => 'true', 'RUNNER_OS' => 'Linux',
        'GITHUB_REPOSITORY' => 'cjahhh/pwdwork', 'GITHUB_REF' => 'refs/heads/main',
        'GITHUB_EVENT_NAME' => 'workflow_dispatch', 'GITHUB_RUN_ATTEMPT' => '1',
        'GITHUB_RUN_ID' => '900002', 'GITHUB_SHA' => $sha, 'REVIEWED_SHA' => $sha,
        'OPERATION' => 'APPLY', 'CONFIRMATION' => 'APPLY email ownership pwdwork_prod',
        'EV_APPROVAL_VERIFIED' => 'true', 'EMAIL_VERIFICATION_ENABLED' => 'false',
        'APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'APP_KEY' => $key, 'DB_CONNECTION' => 'mysql',
        'DB_HOST' => 'incremental-rehearsal.aivencloud.com', 'EXPECTED_DB_HOST' => 'incremental-rehearsal.aivencloud.com',
        'DB_PORT' => '3306', 'EXPECTED_DB_PORT' => '3306', 'DB_DATABASE' => 'pwdwork_prod',
        'DB_USERNAME' => 'pwdwork_rehearsal', 'DB_PASSWORD' => $password, 'EXPECTED_SERVER_UUID' => $uuid,
    ];
    operation('evidence_fixture');
    $receipt = ['format' => 1, 'status' => 'PASS', 'operation' => 'PREVIEW', 'sha' => $sha,
        'run_id' => '900001', 'server_uuid' => $uuid, 'migration_sha256' => MIGRATION_SHA,
        'schema_sha256' => schemaHash($original), 'created_utc' => gmdate('Y-m-d\TH:i:s\Z')];
    $proof = ['format' => 1, 'service' => 'mysql-380cabbd', 'database' => 'pwdwork_prod', 'server_uuid' => $uuid,
        'compatible_release_sha' => $sha, 'backup_sha256' => trim(file_get_contents('/run/ev-tools/dump.sha256')),
        'restored_manifest_sha256' => hash('sha256', encode(fullSnapshot($pdo))),
        'recovery_runbook_sha256' => hash('sha256', str_replace("\r\n", "\n", file_get_contents('/run/ev-source/.github/EMAIL-VERIFICATION-INCREMENTAL-MIGRATION.md'))),
        'private_storage_reference' => 'synthetic_record_only', 'recovery_target' => 'isolated-mysql-8.4.11',
        'restore_test_passed' => true, 'schema_and_data_match' => true, 'writers_quiesced' => true,
        'live_email_verification_enabled' => false, 'rpo_seconds' => 0, 'restore_duration_seconds' => 1,
        'writers_stopped_utc' => gmdate('Y-m-d\TH:i:s\Z', time() - 3), 'backup_utc' => gmdate('Y-m-d\TH:i:s\Z', time() - 2),
        'restore_verified_utc' => gmdate('Y-m-d\TH:i:s\Z', time() - 1), 'valid_until_utc' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600)];
    // Positive APPLY must consume an actual PREVIEW receipt from this runner.
    if ($case === 'apply_success') {
        [$code, $receipt] = runner(array_replace($env, ['OPERATION' => 'PREVIEW', 'GITHUB_RUN_ID' => '900001']));
        operation('preview_result');
        demand($code === 0 && fullSnapshot($baseline) === fullSnapshot($pdo));
    }
    if (in_array($case, ['preview_readonly', 'preview_unapproved'], true)) {
        $env['OPERATION'] = 'PREVIEW';
    }
    operation('prepare_scenario');
    switch ($case) {
        case 'preview_unapproved': case 'apply_unapproved': unset($env['EV_APPROVAL_VERIFIED']);
            break;
        case 'wrong_database': $env['DB_DATABASE'] = 'pwdwork_ev_rehearsal';
            break;
        case 'wrong_host': $env['DB_HOST'] = 'wrong-rehearsal.aivencloud.com';
            break;
        case 'wrong_port': $env['DB_PORT'] = '3307';
            break;
        case 'wrong_uuid':
            $env['EXPECTED_SERVER_UUID'] = '00000000-0000-0000-0000-000000000000';
            $proof['server_uuid'] = $receipt['server_uuid'] = $env['EXPECTED_SERVER_UUID'];
            break;
        case 'wrong_tls_hostname': $env['DB_HOST'] = $env['EXPECTED_DB_HOST'] = 'wrong-rehearsal.aivencloud.com';
            break;
        case 'partial_one': case 'partial_two': case 'unrecorded': case 'already_applied':
            $count = match ($case) {
                'partial_one' => 1, 'partial_two' => 2, default => 3
            };
            foreach (array_slice(DDL, 0, $count) as $sql) {
                $pdo->exec($sql);
            }
            if ($case === 'already_applied') {
                $pdo->prepare('INSERT INTO migrations (migration,batch) SELECT ?,MAX(batch)+1 FROM migrations')->execute([MIGRATION]);
            }
            break;
        case 'history_missing': $pdo->exec('DELETE FROM migrations ORDER BY id LIMIT 1');
            break;
        case 'history_unknown': $pdo->exec("INSERT INTO migrations (migration,batch) VALUES ('unknown_migration',1)");
            break;
        case 'history_duplicate': $pdo->exec('INSERT INTO migrations (migration,batch) SELECT migration,batch FROM migrations ORDER BY id LIMIT 1');
            break;
        case 'backup_expired': $proof['valid_until_utc'] = gmdate('Y-m-d\TH:i:s\Z', time() - 1);
            break;
        case 'preview_expired': $receipt['created_utc'] = gmdate('Y-m-d\TH:i:s\Z', time() - 21601);
            break;
        case 'preview_wrong_sha': $receipt['sha'] = str_repeat('b', 40);
            break;
        case 'preview_wrong_schema': $receipt['schema_sha256'] = str_repeat('b', 64);
            break;
        case 'schema_drift': $pdo->exec('ALTER TABLE sessions ADD lab_schema_drift INT NULL');
            break;
        case 'advisory_lock': demand((int) $pdo->query("SELECT GET_LOCK('pwdwork:email-ownership-migration',0)")->fetchColumn() === 1);
            break;
        case 'enrollment_enabled': $env['EMAIL_VERIFICATION_ENABLED'] = 'true';
            break;
        case 'rerun': $env['GITHUB_RUN_ATTEMPT'] = '2';
            break;
    }
    foreach (['BACKUP' => $proof, 'PREVIEW' => $receipt] as $kind => $record) {
        $raw = encode($record);
        $env['EV_APPROVED_'.$kind] = $raw;
        $env[$kind.'_SHA256'] = hash('sha256', $raw);
    }
    if ($case === 'backup_missing') {
        unset($env['EV_APPROVED_BACKUP']);
    } elseif ($case === 'preview_missing') {
        unset($env['EV_APPROVED_PREVIEW']);
    } elseif ($case === 'backup_hash') {
        $env['BACKUP_SHA256'] = str_repeat('0', 64);
    } elseif ($case === 'preview_hash') {
        $env['PREVIEW_SHA256'] = str_repeat('0', 64);
    }
    operation('before_snapshot');
    $before = fullSnapshot($pdo);
    [$code, $result] = runner($env);
    if ($case === 'apply_success') {
        operation('apply_result');
        demand($code === 0 && $result['operation'] === 'APPLY' && $result['existing_records_preserved'] === true);
        operation('postflight_state');
        state($pdo, 3, 1);
        operation('preservation');
        demand($original === snapshot($pdo));
        $complete = fullSnapshot($pdo);
        // A second authorized invocation must stop without altering completed state.
        [$repeatCode, $repeat] = runner($env);
        operation('repeat_result');
        demand($repeatCode === 1 && $repeat['phase'] === 'schema_history' && fullSnapshot($pdo) === $complete);
    } elseif ($case === 'preview_readonly') {
        operation('preview_result');
        demand($code === 0 && $result['operation'] === 'PREVIEW' && $result['existing_records_preserved'] === null);
        operation('postflight_state');
        state($pdo, 0, 0);
        operation('preservation');
        demand(fullSnapshot($pdo) === $before);
    } else {
        operation('expected_rejection');
        demand($code === 1 && $result['status'] === 'FAIL' && $result['phase'] === CASES[$case]);
        operation('preservation');
        demand(fullSnapshot($pdo) === $before);
        // Shared authorization/target/source/schema guards must protect PREVIEW too.
        if (in_array($case, ['wrong_database', 'wrong_host', 'wrong_port', 'wrong_uuid', 'wrong_ca', 'wrong_tls_hostname',
            'changed_migration', 'extra_migration', 'changed_image_migration', 'partial_one', 'partial_two',
            'unrecorded', 'already_applied', 'history_missing', 'history_unknown', 'history_duplicate',
            'advisory_lock', 'enrollment_enabled', 'rerun'], true)) {
            [$previewCode, $previewResult] = runner(array_replace($env, ['OPERATION' => 'PREVIEW']));
            operation('preview_rejection');
            demand($previewCode === 1 && $previewResult['phase'] === CASES[$case]);
            operation('preservation');
            demand(fullSnapshot($pdo) === $before);
        }
    }
    operation('baseline_preservation');
    demand($baselineFull === fullSnapshot($baseline));
}

function main(array $arguments): int
{
    ini_set('display_errors', '0');
    ini_set('log_errors', '0');
    ob_start();
    $result = ['status' => 'FAIL', 'phase' => 'disposable_runner_assertion'];
    try {
        operation('container_boundary');
        demand(count($arguments) === 1 && getenv('EV_DISPOSABLE_CONTAINER') === 'true' && getenv('EV_NETWORK_MODE') === 'none');
        operation('network_boundary');
        demand(PHP_OS_FAMILY === 'Linux' && is_file('/.dockerenv'));
        // Independently inspect namespace routes: loopback only, not a flag alone.
        $routes = file('/proc/net/route', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        demand(is_array($routes) && count($routes) === 1);
        operation('load_runner');
        require_once '/run/ev-source/.github/scripts/email-verification-incremental.php';
        operation('fixture_credentials');
        $password = trim(fgets(STDIN));
        $key = trim(fgets(STDIN));
        demand((bool) preg_match('/\A[a-f0-9]{64}\z/', $password) && (bool) preg_match('~\Abase64:[A-Za-z0-9+/]{43}=\z~', $key));
        execute($arguments[0], $password, $key);
        $result = ['status' => 'PASS', 'case' => $arguments[0], 'synthetic_only' => true];
    } catch (Throwable $error) {
        $result['diagnostic'] = diagnostic($error);
        // No exception, SQL, credential, user content or raw subprocess output.
    }
    ob_end_clean();
    echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;

    return $result['status'] === 'PASS' ? 0 : 1;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(main(array_slice($argv, 1)));
}
