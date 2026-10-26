<?php

// Disposable-container helper. No production mode, host option or secret-bearing output.

namespace Pwdwork\EmailRehearsal;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
use Throwable;

const BRANCH = 'refs/heads/security/email-verification-mysql-rehearsal';
const SUBJECT = '[run-email-verification-rehearsal]';
const MIGRATION = '2026_10_26_000001_add_email_ownership_verification_to_users';
const NEW_COLUMNS = ['email_ownership_verified_at', 'email_verification_required_at', 'email_verification_version'];
const DATABASES = ['pwdwork_ev_rehearsal', 'pwdwork_ev_restored', 'pwdwork_ev_partial_one', 'pwdwork_ev_partial_two', 'pwdwork_ev_unrecorded', 'pwdwork_ev_locked'];
const MODES = ['init', 'preflight', 'postflight', 'snapshot', 'apply', 'repeat', 'partial-one', 'partial-two', 'unrecorded', 'locked'];

function demand(bool $condition, string $code): void
{
    if (! $condition) {
        throw new RuntimeException($code);
    }
}

function authorize(array $environment, array $event): void
{
    demand(($environment['GITHUB_ACTIONS'] ?? '') === 'true' && ($environment['RUNNER_OS'] ?? '') === 'Linux', 'runner_rejected');
    demand(($environment['GITHUB_REPOSITORY'] ?? '') === 'cjahhh/pwdwork', 'repository_rejected');
    demand(($environment['GITHUB_REF'] ?? '') === BRANCH && ($environment['GITHUB_EVENT_NAME'] ?? '') === 'push', 'event_rejected');
    $sha = $environment['GITHUB_SHA'] ?? '';
    demand((bool) preg_match('/\A[a-f0-9]{40}\z/', $sha), 'sha_rejected');
    demand(($event['ref'] ?? '') === BRANCH && ($event['after'] ?? '') === $sha
        && ($event['head_commit']['id'] ?? '') === $sha, 'event_sha_rejected');
    demand(($event['deleted'] ?? true) === false && ($event['forced'] ?? true) === false, 'unsafe_push_rejected');
    demand(explode("\n", $event['head_commit']['message'] ?? '')[0] === SUBJECT, 'opt_in_rejected');
}

function target(string $database): void
{
    demand(in_array($database, DATABASES, true), 'database_rejected');
}

function partialState(int $columns, int $records): string
{
    return match (true) {
        $columns === 0 && $records === 0 => 'not_applied',
        in_array($columns, [1, 2], true) && $records === 0 => 'manual_forward_repair_required',
        $columns === 3 && $records === 0 => 'manual_ledger_reconciliation_required',
        $columns === 3 && $records === 1 => 'complete',
        default => 'inconsistent_stop',
    };
}

function readStatements(string $sql): array
{
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    $statements = array_values(array_filter(array_map('trim', explode(';', $sql))));
    foreach ($statements as $statement) {
        demand((bool) preg_match('/\A(?:SELECT|SHOW)\b/i', $statement)
            && ! preg_match('/\b(?:OUTFILE|DUMPFILE|FOR\s+UPDATE|LOCK\s+IN\s+SHARE)\b/i', $statement), 'readonly_sql_rejected');
    }

    return $statements;
}

function manifest(string $root, array $expected): array
{
    demand(array_keys($expected) === ['baseline_commit', 'files'], 'manifest_format_rejected');
    demand($expected['baseline_commit'] === 'd495aa8eed7a683b3213f3f59440b9700adfedcb', 'baseline_rejected');
    $migrations = [];
    foreach ($expected['files'] as $relative => $hash) {
        demand((bool) preg_match('~\A(?:backend/database/migrations/[a-z0-9_]+\.php|\.github/sql/email-verification-[a-z-]+\.sql)\z~', $relative), 'manifest_path_rejected');
        demand((bool) preg_match('/\A[a-f0-9]{64}\z/', $hash), 'manifest_hash_rejected');
        $path = $root.'/'.$relative;
        demand(is_file($path) && ! is_link($path) && hash_equals($hash, reviewedHash($path)), 'reviewed_file_changed');
        if (str_starts_with($relative, 'backend/database/migrations/')) {
            $migrations[] = basename($relative);
        }
    }
    sort($migrations);
    $actual = array_map('basename', glob($root.'/backend/database/migrations/*.php'));
    sort($actual);
    demand($actual === $migrations && count($migrations) === 16 && in_array(MIGRATION.'.php', $migrations, true), 'migration_inventory_rejected');

    return array_values(array_filter($migrations, fn ($file) => $file !== MIGRATION.'.php'));
}

function reviewedHash(string $path): string
{
    // Permit Git's Windows CRLF conversion only; every other source byte is checked.
    return hash('sha256', str_replace("\r\n", "\n", file_get_contents($path)));
}

function encode(mixed $value): string
{
    return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

function digestRows(array $rows): string
{
    $encoded = array_map(__NAMESPACE__.'\\encode', $rows);
    sort($encoded, SORT_STRING);

    return hash('sha256', encode($encoded));
}

function identifier(string $name): string
{
    return '`'.str_replace('`', '``', $name).'`';
}

function connect(string $database, #[\SensitiveParameter] string $password): PDO
{
    target($database);
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname='.$database.';charset=utf8mb4', 'pwdwork_rehearsal', $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => true,
    ]);
    $pdo->exec("SET SESSION time_zone = '+00:00'");
    $pdo->exec('SET SESSION lock_wait_timeout = 5');
    demand($pdo->query('SELECT VERSION()')->fetchColumn() === '8.4.11', 'mysql_version_rejected');
    demand($pdo->query('SELECT DATABASE()')->fetchColumn() === $database, 'selected_database_rejected');

    return $pdo;
}

function rows(PDO $pdo, string $sql, array $values = []): array
{
    $statement = $pdo->prepare($sql);
    $statement->execute($values);

    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function inventory(PDO $pdo): array
{
    return rows($pdo, 'SELECT TABLE_NAME, ENGINE, TABLE_COLLATION, ROW_FORMAT, CREATE_OPTIONS, AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = ? ORDER BY TABLE_NAME', ['BASE TABLE']);
}

function columns(PDO $pdo, string $table): array
{
    return rows($pdo, 'SELECT COLUMN_NAME, ORDINAL_POSITION, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, CHARACTER_SET_NAME, COLLATION_NAME, GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION', [$table]);
}

function snapshot(PDO $pdo): array
{
    $result = [];
    $pdo->beginTransaction();
    try {
        foreach (inventory($pdo) as $table) {
            demand($table['ENGINE'] === 'InnoDB', 'non_transactional_table_rejected');
            $name = $table['TABLE_NAME'];
            if ($name === 'migrations') {
                // One later ledger insert advances only this bookkeeping counter.
                unset($table['AUTO_INCREMENT']);
            }
            $fields = array_values(array_filter(columns($pdo, $name), fn ($field) => $name !== 'users' || ! in_array($field['COLUMN_NAME'], NEW_COLUMNS, true)));
            $projection = implode(',', array_map(fn ($field) => identifier($field['COLUMN_NAME']), $fields));
            $data = rows($pdo, 'SELECT '.$projection.' FROM '.identifier($name).($name === 'migrations' ? ' WHERE migration <> ?' : ''), $name === 'migrations' ? [MIGRATION] : []);
            $indexes = rows($pdo, 'SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, COLLATION, SUB_PART, INDEX_TYPE, IS_VISIBLE, EXPRESSION FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX', [$name]);
            $foreign = rows($pdo, 'SELECT k.CONSTRAINT_NAME, k.COLUMN_NAME, k.ORDINAL_POSITION, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.UPDATE_RULE, r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME=? ORDER BY k.CONSTRAINT_NAME,k.ORDINAL_POSITION', [$name]);
            $result[$name] = [
                'rows' => count($data), 'data_sha256' => digestRows($data),
                'schema_sha256' => hash('sha256', encode([$table, $fields, $indexes, $foreign])),
            ];
            if ($name === 'users') {
                demand(array_column($fields, 'COLUMN_NAME') === ['id', 'name', 'email', 'email_verified_at', 'password', 'role', 'status', 'remember_token', 'created_at', 'updated_at', 'admin_version'], 'original_users_inventory_rejected');
                $projectionSql = readStatements(file_get_contents('/run/ev-source/.github/sql/email-verification-original-users-digest.sql'));
                demand(count($projectionSql) === 1, 'original_users_projection_rejected');
                $result[$name]['projection_sha256'] = digestRows(rows($pdo, $projectionSql[0]));
            }
        }
    } finally {
        $pdo->rollBack();
    }

    return $result;
}

function state(PDO $pdo, int $count, int $records): array
{
    $new = array_values(array_filter(columns($pdo, 'users'), fn ($field) => in_array($field['COLUMN_NAME'], NEW_COLUMNS, true)));
    $recorded = (int) $pdo->query("SELECT COUNT(*) FROM migrations WHERE migration = '".MIGRATION."'")->fetchColumn();
    demand(count($new) === $count && $recorded === $records, 'migration_state_rejected');
    if ($records === 1) {
        $batch = (int) $pdo->query("SELECT batch FROM migrations WHERE migration = '".MIGRATION."'")->fetchColumn();
        $previous = (int) $pdo->query("SELECT COALESCE(MAX(batch),0) FROM migrations WHERE migration <> '".MIGRATION."'")->fetchColumn();
        demand($batch === $previous + 1, 'migration_batch_rejected');
    }
    foreach ($new as $index => $field) {
        demand($field['COLUMN_NAME'] === NEW_COLUMNS[$index] && $field['EXTRA'] === '', 'new_column_order_or_extra_rejected');
        demand($field['COLUMN_TYPE'] === ($index < 2 ? 'timestamp' : 'bigint unsigned'), 'new_column_type_rejected');
        demand($field['IS_NULLABLE'] === ($index < 2 ? 'YES' : 'NO'), 'new_column_nullability_rejected');
        demand($field['COLUMN_DEFAULT'] === ($index < 2 ? null : '0'), 'new_column_default_rejected');
        $bad = $index < 2 ? identifier($field['COLUMN_NAME']).' IS NOT NULL' : '(email_verification_version IS NULL OR email_verification_version <> 0)';
        demand((int) $pdo->query('SELECT COUNT(*) FROM users WHERE '.$bad)->fetchColumn() === 0, 'new_column_values_rejected');
    }

    return ['columns' => $count, 'ledger_rows' => $records, 'recovery' => partialState($count, $records)];
}

function bootstrap(string $database, #[\SensitiveParameter] string $password, #[\SensitiveParameter] string $key): mixed
{
    target($database);
    // The container receives no host application environment or production secrets.
    $settings = [
        'APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'APP_KEY' => $key,
        'APP_CONFIG_CACHE' => '/tmp/ev-config.php', 'APP_ROUTES_CACHE' => '/tmp/ev-routes.php',
        'APP_SERVICES_CACHE' => '/tmp/ev-services.php', 'APP_PACKAGES_CACHE' => '/tmp/ev-packages.php',
        'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306',
        'DB_DATABASE' => $database, 'DB_USERNAME' => 'pwdwork_rehearsal', 'DB_PASSWORD' => $password,
        'DB_URL' => '', 'DB_SOCKET' => '', 'EMAIL_VERIFICATION_ENABLED' => 'false',
        'LOG_CHANNEL' => '"null"', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
        'MAIL_MAILER' => 'array', 'PASSWORD_RESET_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync',
        'ADMIN_NOTIFICATION_MAIL' => 'false',
    ];
    foreach ($settings as $name => $value) {
        putenv($name.'='.$value);
        $_ENV[$name] = $_SERVER[$name] = $value;
    }
    $app = require '/var/www/backend/bootstrap/app.php';
    $app->useStoragePath('/tmp/ev-storage');
    foreach (['framework/cache/data', 'framework/sessions', 'framework/views', 'logs'] as $path) {
        if (! is_dir('/tmp/ev-storage/'.$path)) {
            mkdir('/tmp/ev-storage/'.$path, 0700, true);
        }
    }
    $app->make(Kernel::class)->bootstrap();
    demand(config('database.default') === 'mysql' && config('database.connections.mysql.host') === '127.0.0.1'
        && config('database.connections.mysql.database') === $database && config('email-verification.enabled') === false, 'laravel_target_rejected');
    DB::connection()->getPdo()->exec('SET SESSION lock_wait_timeout = 5');
    DB::connection()->getPdo()->exec("SET SESSION time_zone = '+00:00'");

    return $app;
}

function migrate(array $paths): void
{
    demand($paths !== [], 'migration_paths_empty');
    $exit = Artisan::call('migrate', ['--path' => $paths, '--force' => true, '--no-interaction' => true]);
    demand($exit === 0, 'artisan_failed');
}

function safeError(Throwable $error, string $phase): array
{
    $native = isset($error->errorInfo[1]) ? (int) $error->errorInfo[1] : null;
    $phase = in_array($phase, [...MODES, 'authorization', 'container_boundary'], true) ? $phase : 'unknown';

    return ['status' => 'FAIL', 'phase' => $phase, 'category' => 'check_failed',
        'mysql_code' => in_array($native, [1060, 1205], true) ? $native : null];
}

function execute(string $mode, string $database, #[\SensitiveParameter] string $password, #[\SensitiveParameter] string $key): array
{
    demand(in_array($mode, MODES, true), 'mode_rejected');
    $root = '/var/www/backend';
    $reviewed = json_decode(file_get_contents('/run/ev-tools/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    $old = manifest('/run/ev-source', $reviewed);
    // Attest to the files actually executed inside the built image as well.
    foreach ($reviewed['files'] as $path => $hash) {
        if (str_starts_with($path, 'backend/')) {
            demand(hash_equals($hash, reviewedHash($root.'/'.substr($path, 8))), 'image_migration_changed');
        }
    }
    $pdo = connect($database, $password);
    if ($mode === 'init') {
        demand($database === DATABASES[0] && inventory($pdo) === [], 'initial_schema_not_empty');
        bootstrap($database, $password, $key);
        migrate(array_map(fn ($file) => 'database/migrations/'.$file, $old));
        state($pdo, 0, 0);
        $fixture = file_get_contents('/run/ev-source/.github/sql/email-verification-synthetic-fixture.sql');
        foreach (array_filter(array_map('trim', explode(';', preg_replace('/^\s*--.*$/m', '', $fixture)))) as $statement) {
            demand((bool) preg_match('/\AINSERT INTO /', $statement), 'fixture_statement_rejected');
            $pdo->exec($statement);
        }
        foreach ([1, 2, 3, 4, 5] as $id) {
            $temporary = bin2hex(random_bytes(32));
            $hash = password_hash($temporary, PASSWORD_BCRYPT, ['cost' => 4]);
            demand(password_verify($temporary, $hash), 'synthetic_hash_failed');
            $pdo->prepare('UPDATE users SET password=?,remember_token=? WHERE id=?')->execute([$hash, bin2hex(random_bytes(30)), $id]);
        }
        demand((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 5
            && (int) $pdo->query('SELECT COUNT(*) FROM sessions')->fetchColumn() === 2
            && (int) $pdo->query('SELECT COUNT(*) FROM applications')->fetchColumn() === 1, 'synthetic_fixture_rejected');
    } elseif (in_array($mode, ['preflight', 'postflight'], true)) {
        state($pdo, $mode === 'preflight' ? 0 : 3, $mode === 'preflight' ? 0 : 1);
        $checks = [];
        foreach (readStatements(file_get_contents('/run/ev-source/.github/sql/email-verification-'.$mode.'.sql')) as $statement) {
            $checks[] = rows($pdo, $statement);
        }

        return ['status' => 'PASS', 'phase' => $mode, 'checks' => $checks];
    } elseif ($mode === 'snapshot') {
        return ['status' => 'PASS', 'phase' => $mode, 'tables' => snapshot($pdo)];
    } elseif ($mode === 'apply' || $mode === 'repeat') {
        state($pdo, $mode === 'apply' ? 0 : 3, $mode === 'apply' ? 0 : 1);
        bootstrap($database, $password, $key);
        // Preview must compile exactly three default-connection ALTERs before applying.
        if ($mode === 'apply') {
            $sql = DB::connection()->pretend(function () use ($root) {
                (require $root.'/database/migrations/'.MIGRATION.'.php')->up();
            });
            demand(count($sql) === 3, 'preview_statement_count_rejected');
            foreach ($sql as $index => $query) {
                demand($query['query'] === match ($index) {
                    0 => 'alter table `users` add `email_ownership_verified_at` timestamp null',
                    1 => 'alter table `users` add `email_verification_required_at` timestamp null',
                    2 => "alter table `users` add `email_verification_version` bigint unsigned not null default '0'",
                } && $query['bindings'] === [], 'preview_sql_rejected');
            }
        }
        migrate(['database/migrations/'.MIGRATION.'.php']);

        return ['status' => 'PASS', 'phase' => $mode, 'state' => state($pdo, 3, 1)];
    } elseif ($mode === 'locked') {
        demand($database === 'pwdwork_ev_locked', 'lock_database_rejected');
        state($pdo, 0, 0);
        $other = connect($database, $password);
        $other->exec('SET SESSION lock_wait_timeout = 2');
        $pdo->beginTransaction();
        try {
            $pdo->query('SELECT id FROM users LIMIT 1')->fetchAll();
            try {
                $other->exec('ALTER TABLE users ADD email_ownership_verified_at TIMESTAMP NULL');
                demand(false, 'expected_lock_timeout_missing');
            } catch (\PDOException $error) {
                demand((int) ($error->errorInfo[1] ?? 0) === 1205, 'unexpected_lock_failure');
            }
        } finally {
            $pdo->rollBack();
        }

        return ['status' => 'PASS', 'phase' => $mode, 'mysql_code' => 1205, 'state' => state($pdo, 0, 0)];
    } else {
        $count = match ($mode) {
            'partial-one' => 1, 'partial-two' => 2, 'unrecorded' => 3
        };
        demand($database === match ($count) {
            1 => 'pwdwork_ev_partial_one', 2 => 'pwdwork_ev_partial_two', 3 => 'pwdwork_ev_unrecorded'
        }, 'partial_database_rejected');
        state($pdo, 0, 0);
        $alter = ['ADD email_ownership_verified_at TIMESTAMP NULL', 'ADD email_verification_required_at TIMESTAMP NULL', "ADD email_verification_version BIGINT UNSIGNED NOT NULL DEFAULT '0'"];
        foreach (array_slice($alter, 0, $count) as $fragment) {
            $pdo->exec('ALTER TABLE users '.$fragment);
        }
        // A new PDO session proves the prefix survived the end of the operator sequence.
        $pdo = connect($database, $password);
        state($pdo, $count, 0);
        bootstrap($database, $password, $key);
        try {
            migrate(['database/migrations/'.MIGRATION.'.php']);
            demand(false, 'expected_duplicate_missing');
        } catch (QueryException $error) {
            demand((int) ($error->errorInfo[1] ?? 0) === 1060, 'unexpected_retry_failure');
        }

        return ['status' => 'PASS', 'phase' => $mode, 'mysql_code' => 1060,
            'manual_reconciliation_required' => true, 'state' => state($pdo, $count, 0)];
    }

    return ['status' => 'PASS', 'phase' => $mode];
}

function main(array $arguments): int
{
    $phase = 'authorization';
    ob_start();
    try {
        if ($arguments === ['--authorize-only']) {
            $environment = [];
            foreach (['GITHUB_ACTIONS', 'RUNNER_OS', 'GITHUB_REPOSITORY', 'GITHUB_REF', 'GITHUB_EVENT_NAME', 'GITHUB_SHA', 'GITHUB_EVENT_PATH'] as $name) {
                $environment[$name] = getenv($name);
            }
            authorize($environment, json_decode(file_get_contents($environment['GITHUB_EVENT_PATH'] ?? ''), true, flags: JSON_THROW_ON_ERROR));
            $result = ['status' => 'PASS', 'phase' => $phase];
        } else {
            $phase = 'container_boundary';
            demand(count($arguments) === 2 && getenv('EV_DISPOSABLE_CONTAINER') === 'true', 'container_mode_rejected');
            demand(getenv('EV_NETWORK_MODE') === 'none', 'network_mode_rejected');
            [$mode, $database] = $arguments;
            target($database);
            demand(in_array($mode, MODES, true), 'mode_rejected');
            require '/var/www/backend/vendor/autoload.php';
            $phase = $mode;
            $password = trim(fgets(STDIN));
            $key = trim(fgets(STDIN));
            demand((bool) preg_match('/\A[a-f0-9]{64}\z/', $password)
                && (bool) preg_match('~\Abase64:[A-Za-z0-9+/]{43}=\z~', $key), 'synthetic_secrets_rejected');
            $result = execute($mode, $database, $password, $key);
        }
    } catch (Throwable $error) {
        $result = safeError($error, $phase);
    }
    ob_end_clean();
    echo encode($result).PHP_EOL;

    return $result['status'] === 'PASS' ? 0 : 1;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    // This branch does not run when PHPUnit imports the pure guard functions.
    exit(main(array_slice($argv, 1)));
}
