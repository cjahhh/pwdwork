<?php

declare(strict_types=1);

namespace Pwdwork\EmailIncremental;

use const Pwdwork\EmailRehearsal\MIGRATION;
use const Pwdwork\EmailRehearsal\NEW_COLUMNS;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Monolog\Handler\NullHandler;
use PDO;
use RuntimeException;
use Throwable;

use function Pwdwork\EmailRehearsal\columns;
use function Pwdwork\EmailRehearsal\encode;
use function Pwdwork\EmailRehearsal\identifier;
use function Pwdwork\EmailRehearsal\inventory;
use function Pwdwork\EmailRehearsal\manifest;
use function Pwdwork\EmailRehearsal\readStatements;
use function Pwdwork\EmailRehearsal\rows;
use function Pwdwork\EmailRehearsal\state;

require_once __DIR__.'/email-verification-rehearsal.php';

const MIGRATION_PATH = 'database/migrations/'.MIGRATION.'.php';
const MIGRATION_SHA = 'fd28dca332d95c120293375d799e48d2941333ef5eea308af6609c0b4c51fc6b';
const DDL = [
    'alter table `users` add `email_ownership_verified_at` timestamp null',
    'alter table `users` add `email_verification_required_at` timestamp null',
    "alter table `users` add `email_verification_version` bigint unsigned not null default '0'",
];

const PHASES = ['authorization', 'reviewed_source', 'backup_proof', 'tls_connection', 'schema_history', 'original_snapshot', 'laravel_runtime', 'compiled_ddl', 'preview_evidence', 'final_preflight', 'single_migration', 'postflight'];

function phase(string $stage): void
{
    demand(in_array($stage, PHASES, true), 'phase_rejected');
    $GLOBALS['ev_incremental_phase'] = $stage;
}

function safeFailure(): array
{
    $stage = $GLOBALS['ev_incremental_phase'] ?? 'authorization';

    return ['status' => 'FAIL', 'phase' => in_array($stage, PHASES, true) ? $stage : 'authorization',
        'action' => 'STOP; no retry, rollback or automatic repair. Inspect schema/history privately; follow the recovery runbook.'];
}

function demand(bool $condition, string $code): void
{
    if (! $condition) {
        throw new RuntimeException($code);
    }
}

function setting(string $name): string
{
    $value = getenv($name);
    demand(is_string($value) && $value !== '', 'required_setting_missing');

    return $value;
}

function authorize(array $env): void
{
    demand(($env['GITHUB_ACTIONS'] ?? '') === 'true' && ($env['RUNNER_OS'] ?? '') === 'Linux', 'runner_rejected');
    demand(($env['GITHUB_REPOSITORY'] ?? '') === 'cjahhh/pwdwork'
        && ($env['GITHUB_REF'] ?? '') === 'refs/heads/main'
        && ($env['GITHUB_EVENT_NAME'] ?? '') === 'workflow_dispatch'
        && ($env['GITHUB_RUN_ATTEMPT'] ?? '') === '1', 'dispatch_rejected');
    demand((bool) preg_match('/\A[a-f0-9]{40}\z/', $env['GITHUB_SHA'] ?? '')
        && ($env['REVIEWED_SHA'] ?? '') === $env['GITHUB_SHA'], 'reviewed_sha_rejected');
    demand(in_array($env['OPERATION'] ?? '', ['PREVIEW', 'APPLY'], true), 'operation_rejected');
    demand(($env['EMAIL_VERIFICATION_ENABLED'] ?? '') === 'false', 'enrollment_must_be_off');
    demand(($env['EV_APPROVAL_VERIFIED'] ?? '') === 'true', 'separate_approval_missing');
    if ($env['OPERATION'] === 'APPLY') {
        demand(($env['CONFIRMATION'] ?? '') === 'APPLY email ownership pwdwork_prod', 'confirmation_rejected');
    }
}

function target(array $env): void
{
    $host = $env['DB_HOST'] ?? '';
    demand((bool) preg_match('/\A[a-z0-9][a-z0-9.-]*\.aivencloud\.com\z/', $host)
        && $host === ($env['EXPECTED_DB_HOST'] ?? ''), 'host_rejected');
    $port = $env['DB_PORT'] ?? '';
    demand((bool) preg_match('/\A[1-9][0-9]{0,4}\z/', $port) && (int) $port <= 65535
        && $port === ($env['EXPECTED_DB_PORT'] ?? ''), 'port_rejected');
    demand(($env['DB_DATABASE'] ?? '') === 'pwdwork_prod', 'database_rejected');
    demand((bool) preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/', $env['EXPECTED_SERVER_UUID'] ?? ''), 'server_identity_missing');
    demand(($env['DB_USERNAME'] ?? '') !== '' && strtolower($env['DB_USERNAME']) !== 'root', 'account_rejected');
}

function evidence(string $raw, string $expected): array
{
    demand(strlen($raw) < 20000 && (bool) preg_match('/\A[a-f0-9]{64}\z/', $expected)
        && hash_equals($expected, hash('sha256', $raw)), 'evidence_digest_rejected');
    $record = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    demand(is_array($record), 'evidence_format_rejected');

    return $record;
}

function utc(mixed $value): int
{
    demand(is_string($value) && (bool) preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z\z/', $value), 'evidence_time_rejected');
    $time = strtotime($value);
    demand($time !== false && gmdate('Y-m-d\TH:i:s\Z', $time) === $value, 'evidence_time_rejected');

    return $time;
}

function backup(array $record, string $uuid, string $sha, string $runbookHash, int $now): void
{
    demand(($record['format'] ?? null) === 1 && ($record['service'] ?? '') === 'mysql-380cabbd'
        && ($record['database'] ?? '') === 'pwdwork_prod' && ($record['server_uuid'] ?? '') === $uuid
        && ($record['compatible_release_sha'] ?? '') === $sha, 'backup_identity_rejected');
    foreach (['backup_sha256', 'restored_manifest_sha256'] as $key) {
        demand((bool) preg_match('/\A[a-f0-9]{64}\z/', $record[$key] ?? ''), 'backup_proof_missing');
    }
    demand(($record['recovery_runbook_sha256'] ?? '') === $runbookHash, 'recovery_procedure_changed');
    demand((bool) preg_match('/\A[A-Za-z0-9_-]{8,80}\z/', $record['private_storage_reference'] ?? '')
        && ($record['recovery_target'] ?? '') === 'isolated-mysql-8.4.11', 'recovery_target_rejected');
    foreach (['restore_test_passed', 'schema_and_data_match', 'writers_quiesced'] as $key) {
        demand(($record[$key] ?? null) === true, 'recovery_or_maintenance_unproven');
    }
    demand(($record['live_email_verification_enabled'] ?? null) === false
        && ($record['rpo_seconds'] ?? null) === 0
        && is_int($record['restore_duration_seconds'] ?? null) && $record['restore_duration_seconds'] > 0, 'recovery_objective_rejected');
    $stopped = utc($record['writers_stopped_utc'] ?? null);
    $created = utc($record['backup_utc'] ?? null);
    $restored = utc($record['restore_verified_utc'] ?? null);
    $expires = utc($record['valid_until_utc'] ?? null);
    demand($stopped <= $created && $created <= $restored && $restored <= $now
        && $created >= $now - 21600 && $expires > $now && $expires <= $created + 21600, 'backup_stale_or_invalid');
}

function history(array $records, array $baseline, int $columnCount, bool $complete = false): void
{
    $expected = array_map(fn ($name) => substr($name, 0, -4), $baseline);
    if ($complete) {
        $expected[] = MIGRATION;
    }
    $actual = array_column($records, 'migration');
    sort($expected);
    sort($actual);
    demand($expected === $actual, 'history_missing_duplicate_or_unknown');
    demand($columnCount === ($complete ? 3 : 0), 'partial_or_already_applied_stop');
    foreach ($records as $record) {
        demand(ctype_digit((string) $record['batch']) && (int) $record['batch'] > 0, 'history_batch_rejected');
    }
}

function preview(array $record, string $uuid, string $sha, string $schema, int $now): void
{
    demand(($record['format'] ?? null) === 1 && ($record['status'] ?? '') === 'PASS'
        && ($record['operation'] ?? '') === 'PREVIEW'
        && ($record['sha'] ?? '') === $sha && ($record['server_uuid'] ?? '') === $uuid
        && ($record['migration_sha256'] ?? '') === MIGRATION_SHA
        && ($record['schema_sha256'] ?? '') === $schema, 'preview_identity_or_schema_changed');
    $created = utc($record['created_utc'] ?? null);
    demand($created <= $now && $created >= $now - 21600, 'preview_expired');
}

/** Streaming hash: no rows, credentials, session payloads or passwords leave memory. */
function hashStream(iterable $records): array
{
    $hash = hash_init('sha256');
    $count = 0;
    foreach ($records as $record) {
        $encoded = encode(array_map(fn ($value) => $value === null ? null : base64_encode((string) $value), array_values($record)));
        hash_update($hash, strlen($encoded).':'.$encoded);
        $count++;
    }

    return ['rows' => $count, 'data_sha256' => hash_final($hash)];
}

function snapshot(PDO $pdo): array
{
    $result = [];
    $pdo->beginTransaction();
    try {
        foreach (inventory($pdo) as $table) {
            demand($table['ENGINE'] === 'InnoDB', 'non_transactional_table_rejected');
            $name = $table['TABLE_NAME'];
            $counter = $name === 'migrations' ? null : $table['AUTO_INCREMENT'];
            unset($table['AUTO_INCREMENT']);
            $fields = array_values(array_filter(columns($pdo, $name), fn ($field) => $name !== 'users' || ! in_array($field['COLUMN_NAME'], NEW_COLUMNS, true)));
            if ($name === 'users') {
                demand(array_column($fields, 'COLUMN_NAME') === ['id', 'name', 'email', 'email_verified_at', 'password', 'role', 'status', 'remember_token', 'created_at', 'updated_at', 'admin_version'], 'original_users_schema_rejected');
            }
            $indexes = rows($pdo, 'SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, COLLATION, SUB_PART, INDEX_TYPE, IS_VISIBLE, EXPRESSION FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX', [$name]);
            $primary = array_column(array_filter($indexes, fn ($index) => $index['INDEX_NAME'] === 'PRIMARY'), 'COLUMN_NAME');
            demand($primary !== [] && ! in_array(null, $primary, true), 'table_without_primary_key_rejected');
            $foreign = rows($pdo, 'SELECT k.CONSTRAINT_NAME,k.COLUMN_NAME,k.ORDINAL_POSITION,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.UPDATE_RULE,r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME=? ORDER BY k.CONSTRAINT_NAME,k.ORDINAL_POSITION', [$name]);
            $sql = 'SELECT '.implode(',', array_map(fn ($field) => identifier($field['COLUMN_NAME']), $fields)).' FROM '.identifier($name)
                .($name === 'migrations' ? ' WHERE migration <> ?' : '').' ORDER BY '.implode(',', array_map('Pwdwork\\EmailRehearsal\\identifier', $primary));
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
            try {
                $statement = $pdo->prepare($sql);
                $statement->execute($name === 'migrations' ? [MIGRATION] : []);
                $data = hashStream((function () use ($statement) {
                    while ($row = $statement->fetch(PDO::FETCH_NUM)) {
                        yield $row;
                    }
                })());
                $statement->closeCursor();
            } finally {
                $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
            }
            $result[$name] = $data + ['schema_sha256' => hash('sha256', encode([$table, $fields, $indexes, $foreign])), 'auto_increment' => $counter];
        }
    } finally {
        $pdo->rollBack();
    }

    return $result;
}

function schemaHash(array $snapshot): string
{
    return hash('sha256', encode(array_map(fn ($table) => $table['schema_sha256'], $snapshot)));
}

function preserved(array $before, array $after): void
{
    demand($before === $after, 'existing_schema_or_records_changed_stop');
}

function migrationArguments(): array
{
    return ['--database' => 'mysql', '--path' => [MIGRATION_PATH], '--force' => true, '--no-interaction' => true];
}

function verifyServer(PDO $pdo, string $uuid): void
{
    demand($pdo->query('SELECT DATABASE()')->fetchColumn() === 'pwdwork_prod'
        && $pdo->query('SELECT VERSION()')->fetchColumn() === '8.4.11'
        && $pdo->query('SELECT @@server_uuid')->fetchColumn() === $uuid, 'connected_identity_rejected');
    demand(($pdo->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'")->fetch(PDO::FETCH_NUM)[1] ?? '') !== '', 'tls_missing');
    $pdo->exec("SET SESSION time_zone = '+00:00'");
    $pdo->exec('SET SESSION lock_wait_timeout = 5');
}

/** Reuse reviewed read-only SQL; discard results, never log production rows. */
function readonlyChecks(PDO $pdo, string $path): void
{
    foreach (readStatements(file_get_contents($path)) as $sql) {
        $statement = $pdo->query($sql);
        while ($statement->fetch(PDO::FETCH_NUM) !== false) {
            // No evidence from individual users enters CI artifacts.
        }
        $statement->closeCursor();
    }
}

function execute(array $env, string $root): array
{
    phase('authorization');
    authorize($env);
    target($env);
    phase('reviewed_source');
    $baseline = manifest($root, json_decode(file_get_contents($root.'/.github/scripts/email-verification-rehearsal-manifest.json'), true, flags: JSON_THROW_ON_ERROR));
    demand(\Pwdwork\EmailRehearsal\reviewedHash($root.'/backend/'.MIGRATION_PATH) === MIGRATION_SHA, 'exact_migration_hash_rejected');
    foreach ([...$baseline, MIGRATION.'.php'] as $file) {
        demand(\Pwdwork\EmailRehearsal\reviewedHash('/var/www/backend/database/migrations/'.$file)
            === \Pwdwork\EmailRehearsal\reviewedHash($root.'/backend/database/migrations/'.$file), 'image_migration_differs');
    }
    setting('APP_KEY'); // Existing key only: never generate, rotate or print it.
    $apply = $env['OPERATION'] === 'APPLY';
    if ($apply) {
        phase('backup_proof');
        backup(evidence(setting('EV_APPROVED_BACKUP'), setting('BACKUP_SHA256')), $env['EXPECTED_SERVER_UUID'], $env['GITHUB_SHA'], hash('sha256', str_replace("\r\n", "\n", file_get_contents($root.'/.github/EMAIL-VERIFICATION-INCREMENTAL-MIGRATION.md'))), time());
    }
    phase('tls_connection');
    $ca = '/run/aiven-ca.pem';
    demand(is_readable($ca) && openssl_x509_read(file_get_contents($ca)) !== false, 'trusted_ca_missing');
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 15, PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => true, PDO::MYSQL_ATTR_SSL_CA => $ca, PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true];
    $pdo = new PDO('mysql:host='.$env['DB_HOST'].';port='.$env['DB_PORT'].';dbname=pwdwork_prod;charset=utf8mb4', setting('DB_USERNAME'), setting('DB_PASSWORD'), $options);
    verifyServer($pdo, $env['EXPECTED_SERVER_UUID']);
    demand((int) $pdo->query("SELECT GET_LOCK('pwdwork:email-ownership-migration',0)")->fetchColumn() === 1, 'another_runner_active');
    try {
        phase('schema_history');
        // Preview is a consistent read-only snapshot, not a production data export.
        history(rows($pdo, 'SELECT migration,batch FROM migrations ORDER BY id'), $baseline, count(array_filter(columns($pdo, 'users'), fn ($col) => in_array($col['COLUMN_NAME'], NEW_COLUMNS, true))));
        demand(rows($pdo, 'SELECT TABLE_NAME FROM information_schema.VIEWS WHERE TABLE_SCHEMA=DATABASE()') === [], 'unexpected_view_rejected');
        readonlyChecks($pdo, $root.'/.github/sql/email-verification-preflight.sql');
        phase('original_snapshot');
        $before = snapshot($pdo);
        phase('laravel_runtime');
        require '/var/www/backend/vendor/autoload.php';
        foreach (['APP_CONFIG_CACHE' => '/tmp/ev-incremental-config.php', 'APP_SERVICES_CACHE' => '/tmp/ev-incremental-services.php', 'APP_PACKAGES_CACHE' => '/tmp/ev-incremental-packages.php', 'APP_ROUTES_CACHE' => '/tmp/ev-incremental-routes.php', 'DB_URL' => '', 'DB_SOCKET' => '', 'EMAIL_VERIFICATION_ENABLED' => 'false', 'LOG_CHANNEL' => '"null"', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'ADMIN_NOTIFICATION_MAIL' => 'false', 'MYSQL_ATTR_SSL_CA' => $ca] as $key => $value) {
            putenv($key.'='.$value);
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
        $app = require '/var/www/backend/bootstrap/app.php';
        $app->useStoragePath('/tmp/ev-incremental-storage');
        foreach (['framework/cache/data', 'framework/sessions', 'framework/views', 'logs'] as $path) {
            mkdir('/tmp/ev-incremental-storage/'.$path, 0700, true);
        }
        demand(! $app->configurationIsCached(), 'cached_config_rejected');
        $app->make(Kernel::class)->bootstrap();
        demand(config('email-verification.enabled') === false && config('logging.default') === 'null'
            && config('logging.channels.null.handler') === NullHandler::class
            && config('database.default') === 'mysql' && config('database.migrations.table') === 'migrations', 'runtime_config_rejected');
        // Force the reviewed, already TLS-verified PDO, preserving advisory lock and lock timeout.
        $connection = DB::connection('mysql');
        $connection->setPdo($pdo)->setReadPdo($pdo);
        $connection->setReconnector(fn () => throw new RuntimeException('reconnection_forbidden'));
        $connection->disableQueryLog();
        phase('compiled_ddl');
        $migration = require '/var/www/backend/'.MIGRATION_PATH;
        $queries = $connection->pretend(fn () => $migration->up());
        demand(array_column($queries, 'query') === DDL, 'compiled_ddl_changed');
        if ($apply) {
            phase('preview_evidence');
            preview(evidence(setting('EV_APPROVED_PREVIEW'), setting('PREVIEW_SHA256')), $env['EXPECTED_SERVER_UUID'], $env['GITHUB_SHA'], schemaHash($before), time());
            // Recheck evidence expiry immediately before the write, after potentially slow hashing/build.
            phase('final_preflight');
            backup(evidence(setting('EV_APPROVED_BACKUP'), setting('BACKUP_SHA256')), $env['EXPECTED_SERVER_UUID'], $env['GITHUB_SHA'], hash('sha256', str_replace("\r\n", "\n", file_get_contents($root.'/.github/EMAIL-VERIFICATION-INCREMENTAL-MIGRATION.md'))), time());
            history(rows($pdo, 'SELECT migration,batch FROM migrations ORDER BY id'), $baseline, count(array_filter(columns($pdo, 'users'), fn ($col) => in_array($col['COLUMN_NAME'], NEW_COLUMNS, true))));
            phase('single_migration');
            demand(Artisan::call('migrate', migrationArguments()) === 0, 'exact_migration_failed');
            phase('postflight');
            state($pdo, 3, 1);
            history(rows($pdo, 'SELECT migration,batch FROM migrations ORDER BY id'), $baseline, 3, true);
            preserved($before, snapshot($pdo));
            readonlyChecks($pdo, $root.'/.github/sql/email-verification-postflight.sql');
        }

        return ['format' => 1, 'status' => 'PASS', 'operation' => $env['OPERATION'], 'sha' => $env['GITHUB_SHA'],
            'run_id' => $env['GITHUB_RUN_ID'], 'server_uuid' => $env['EXPECTED_SERVER_UUID'], 'migration_sha256' => MIGRATION_SHA,
            'schema_sha256' => schemaHash($before), 'created_utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'existing_records_preserved' => $apply ? true : null];
    } finally {
        $pdo->query("SELECT RELEASE_LOCK('pwdwork:email-ownership-migration')");
    }
}

function main(): int
{
    // Suppress framework/SQL exception output, signed links, bindings and credentials.
    ini_set('display_errors', '0');
    ini_set('log_errors', '0');
    ob_start();
    $status = 1;
    try {
        $env = getenv();
        $result = execute($env, '/run/ev-source');
        $status = 0;
    } catch (Throwable) {
        $result = safeFailure();
    }
    ob_end_clean();
    // The only output is fixed-vocabulary status and a safe receipt. No raw exception.
    echo encode($result).PHP_EOL;

    return $status;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(main());
}
