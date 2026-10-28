<?php

declare(strict_types=1);

// Test controller only. Never included by the production workflow or runner.

namespace Pwdwork\EmailIncrementalLab;

use const Pwdwork\EmailIncremental\DDL;
use const Pwdwork\EmailIncremental\MIGRATION_SHA;
use const Pwdwork\EmailRehearsal\MIGRATION;

use PDO;
use RuntimeException;
use Throwable;

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
        demand($primary !== []);
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
    $process = proc_open([PHP_BINARY, '/run/ev-source/.github/scripts/email-verification-incremental.php'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, '/var/www/backend', $env);
    demand(is_resource($process));
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $errors = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $code = proc_close($process);
    demand($errors === '' && strlen($output) < 4000);
    $receipt = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
    demand(is_array($receipt));
    if ($code !== 0) {
        demand($code === 1 && $receipt === [
            'status' => 'FAIL', 'phase' => $receipt['phase'],
            'action' => 'STOP; no retry, rollback or automatic repair. Inspect schema/history privately; follow the recovery runbook.',
        ]);
    } else {
        demand(array_keys($receipt) === ['format', 'status', 'operation', 'sha', 'run_id', 'server_uuid', 'migration_sha256', 'schema_sha256', 'created_utc', 'existing_records_preserved']);
        demand($receipt['status'] === 'PASS' && $receipt['migration_sha256'] === MIGRATION_SHA);
    }

    return [$code, $receipt];
}

function execute(string $case, #[\SensitiveParameter] string $password, #[\SensitiveParameter] string $key): void
{
    demand(array_key_exists($case, CASES));
    $pdo = connect('pwdwork_prod', $password);
    $baseline = connect('pwdwork_ev_rehearsal', $password);
    $baselineFull = fullSnapshot($baseline);
    // Every scenario first proves the exact synthetic dump restored correctly.
    demand($baselineFull === fullSnapshot($pdo));
    state($pdo, 0, 0);
    $original = snapshot($pdo);
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
        demand($code === 0 && fullSnapshot($baseline) === fullSnapshot($pdo));
    }
    if (in_array($case, ['preview_readonly', 'preview_unapproved'], true)) {
        $env['OPERATION'] = 'PREVIEW';
    }
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
    $before = fullSnapshot($pdo);
    [$code, $result] = runner($env);
    if ($case === 'apply_success') {
        demand($code === 0 && $result['operation'] === 'APPLY' && $result['existing_records_preserved'] === true);
        state($pdo, 3, 1);
        demand($original === snapshot($pdo));
        $complete = fullSnapshot($pdo);
        // A second authorized invocation must stop without altering completed state.
        [$repeatCode, $repeat] = runner($env);
        demand($repeatCode === 1 && $repeat['phase'] === 'schema_history' && fullSnapshot($pdo) === $complete);
    } elseif ($case === 'preview_readonly') {
        demand($code === 0 && $result['operation'] === 'PREVIEW' && $result['existing_records_preserved'] === null);
        state($pdo, 0, 0);
        demand(fullSnapshot($pdo) === $before);
    } else {
        demand($code === 1 && $result['status'] === 'FAIL' && $result['phase'] === CASES[$case]);
        demand(fullSnapshot($pdo) === $before);
        // Shared authorization/target/source/schema guards must protect PREVIEW too.
        if (in_array($case, ['wrong_database', 'wrong_host', 'wrong_port', 'wrong_uuid', 'wrong_ca', 'wrong_tls_hostname',
            'changed_migration', 'extra_migration', 'changed_image_migration', 'partial_one', 'partial_two',
            'unrecorded', 'already_applied', 'history_missing', 'history_unknown', 'history_duplicate',
            'advisory_lock', 'enrollment_enabled', 'rerun'], true)) {
            [$previewCode, $previewResult] = runner(array_replace($env, ['OPERATION' => 'PREVIEW']));
            demand($previewCode === 1 && $previewResult['phase'] === CASES[$case]);
            demand(fullSnapshot($pdo) === $before);
        }
    }
    demand($baselineFull === fullSnapshot($baseline));
}

function main(array $arguments): int
{
    ini_set('display_errors', '0');
    ini_set('log_errors', '0');
    ob_start();
    $result = ['status' => 'FAIL', 'phase' => 'disposable_runner_assertion'];
    try {
        demand(count($arguments) === 1 && getenv('EV_DISPOSABLE_CONTAINER') === 'true' && getenv('EV_NETWORK_MODE') === 'none');
        demand(PHP_OS_FAMILY === 'Linux' && is_file('/.dockerenv'));
        // Independently inspect namespace routes: loopback only, not a flag alone.
        $routes = file('/proc/net/route', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        demand(is_array($routes) && count($routes) === 1);
        require_once '/run/ev-source/.github/scripts/email-verification-incremental.php';
        $password = trim(fgets(STDIN));
        $key = trim(fgets(STDIN));
        demand((bool) preg_match('/\A[a-f0-9]{64}\z/', $password) && (bool) preg_match('~\Abase64:[A-Za-z0-9+/]{43}=\z~', $key));
        execute($arguments[0], $password, $key);
        $result = ['status' => 'PASS', 'case' => $arguments[0], 'synthetic_only' => true];
    } catch (Throwable) {
        // No exception, SQL, credential, user content or raw subprocess output.
    }
    ob_end_clean();
    echo json_encode($result, JSON_THROW_ON_ERROR).PHP_EOL;

    return $result['status'] === 'PASS' ? 0 : 1;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(main(array_slice($argv, 1)));
}
