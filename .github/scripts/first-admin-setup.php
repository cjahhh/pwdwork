<?php

declare(strict_types=1);

use App\Models\AdminAuditLog;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Monolog\Handler\NullHandler;

// Workflow-only code, mounted at runtime and excluded by .dockerignore.
final class FirstAdminRefusal extends RuntimeException {}

final class FirstAdminSetup
{
    public static function demand(bool $condition, string $status): void
    {
        if (! $condition) {
            throw new FirstAdminRefusal($status);
        }
    }

    public static function authorize(string $confirmation): void
    {
        self::demand($confirmation === 'CREATE FIRST PWDWORK ADMIN', 'confirmation_required');
    }

    public static function validateTarget(array $target): void
    {
        self::demand(preg_match('/\A[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\.aivencloud\.com\z/i', $target['host']) === 1, 'invalid_aiven_hostname');
        self::demand($target['host'] === $target['expected_host'], 'unexpected_hostname');
        self::demand(preg_match('/\A[1-9][0-9]{0,4}\z/', $target['port']) === 1 && (int) $target['port'] <= 65535, 'invalid_port');
        self::demand($target['port'] === $target['expected_port'], 'unexpected_port');
        self::demand($target['database'] === 'pwdwork_prod', 'unexpected_database');
    }

    public static function promote(string $email, string $confirmation): void
    {
        self::authorize($confirmation);
        self::demand($email === trim($email) && strlen($email) <= 255 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false, 'invalid_email_secret');
        $connection = DB::connection();
        if ($connection->getDriverName() === 'mysql') {
            // Predicate/gap locking also protects the initially empty admin set.
            // This affects only the next transaction on this disposable connection.
            $connection->statement('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
        } else {
            self::demand(app()->environment('testing') && $connection->getDriverName() === 'sqlite'
                && $connection->getDatabaseName() === ':memory:', 'mysql_required');
        }

        $connection->transaction(function () use ($email): void {
            $admins = DB::table('users')->where('role', 'admin')->orderBy('id')->lockForUpdate()->get(['id']);
            self::demand($admins->isEmpty(), 'administrator_already_exists');
            // SQL lookup is bound; strict comparison defeats case-insensitive
            // MySQL collations and trailing-space equivalence for this operation.
            $matches = DB::table('users')->where('email', $email)->lockForUpdate()->get();
            self::demand($matches->count() === 1 && $matches->first()->email === $email, 'exact_account_not_found');
            $user = $matches->first();
            self::demand($user->role === 'applicant' && $user->status === 'active'
                && is_string($user->password) && $user->password !== '', 'account_not_eligible');
            self::demand(! DB::table('employers')->where('user_id', $user->id)->lockForUpdate()->exists(), 'employer_account_refused');

            // Query builder deliberately changes ONLY role: no password hashing,
            // timestamps, profile changes, session changes or model side effects.
            $changed = DB::table('users')->where('id', $user->id)->where('role', 'applicant')
                ->where('status', 'active')->update(['role' => 'admin']);
            self::demand($changed === 1, 'promotion_not_completed');
            AdminAuditLog::record(null, 'admin.first_bootstrapped', 'user', (int) $user->id,
                'applicant', 'admin', 'First administrator promoted through manually authorized GitHub Actions bootstrap.');
            $after = DB::table('users')->where('role', 'admin')->lockForUpdate()->get(['id', 'status']);
            self::demand($after->count() === 1 && $after->first()->id === $user->id
                && $after->first()->status === 'active', 'administrator_verification_failed');
        }, 1); // No automatic retries after errors or partial uncertainty.
    }
}

// Tests may import definitions; importing never executes the workflow operation.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) {
    return;
}

$stage = 'authorization';
try {
    FirstAdminSetup::authorize(getenv('CONFIRMATION') ?: '');
    FirstAdminSetup::demand(getenv('GITHUB_REF') === 'refs/heads/main'
        && getenv('GITHUB_REPOSITORY') === 'cjahhh/pwdwork', 'main_repository_required');
    $required = static function (string $name): string {
        $value = getenv($name);
        FirstAdminSetup::demand(is_string($value) && $value !== '', 'required_setting_missing');

        return $value;
    };
    $target = ['host' => $required('DB_HOST'), 'port' => $required('DB_PORT'),
        'database' => $required('DB_DATABASE'), 'expected_host' => $required('EXPECTED_DB_HOST'),
        'expected_port' => $required('EXPECTED_DB_PORT')];
    FirstAdminSetup::validateTarget($target);
    $email = $required('PWDWORK_BOOTSTRAP_ADMIN_EMAIL');
    $required('APP_KEY');
    $username = $required('DB_USERNAME');
    $password = $required('DB_PASSWORD');
    $ca = $required('MYSQL_ATTR_SSL_CA');
    FirstAdminSetup::demand($ca === '/run/aiven-ca.pem' && is_readable($ca)
        && @openssl_x509_read(file_get_contents($ca)) !== false, 'invalid_mounted_ca');

    $stage = 'Laravel initialization';
    require '/var/www/backend/vendor/autoload.php';
    $app = require '/var/www/backend/bootstrap/app.php';
    FirstAdminSetup::demand($app->basePath() === '/var/www/backend'
        && $app->getCachedConfigPath() === '/tmp/pwdwork-first-admin-config.php'
        && ! $app->configurationIsCached(), 'unexpected_cached_configuration');
    $app->make(Kernel::class)->bootstrap();
    FirstAdminSetup::demand($app->environment('production') && config('app.debug') === false
        && config('logging.default') === 'null'
        && config('logging.channels.null.handler') === NullHandler::class, 'unsafe_runtime_configuration');
    $app['log']->channel('null');

    $stage = 'verified database configuration';
    $config = config('database.connections.mysql');
    FirstAdminSetup::demand(config('database.default') === 'mysql' && empty($config['url'])
        && empty($config['unix_socket']) && empty($config['read']) && empty($config['write'])
        && $config['prefix'] === '', 'connection_override_refused');
    foreach (['host', 'port', 'database'] as $key) {
        FirstAdminSetup::demand((string) $config[$key] === $target[$key], 'unexpected_laravel_target');
    }
    FirstAdminSetup::demand($config['username'] === $username && $config['password'] === $password, 'unexpected_laravel_credentials');
    FirstAdminSetup::demand(($config['options'][PDO::MYSQL_ATTR_SSL_CA] ?? null) === $ca
        && ($config['options'][PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] ?? null) === true, 'verified_tls_required');
    $stage = 'verified TLS connection';
    $pdo = DB::connection()->getPdo();
    FirstAdminSetup::demand($pdo->query('SELECT DATABASE()')->fetchColumn() === 'pwdwork_prod', 'unexpected_selected_database');
    FirstAdminSetup::demand(str_starts_with((string) $pdo->query('SELECT VERSION()')->fetchColumn(), '8.4.'), 'mysql_84_required');
    FirstAdminSetup::demand(($pdo->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'")->fetch(PDO::FETCH_NUM)[1] ?? '') !== '', 'tls_not_negotiated');
    // Atomic promotion/audit requires transactional tables; fail rather than risk
    // an irreversible role change if an unexpected storage engine is deployed.
    $statement = $pdo->prepare('SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN (?, ?, ?)');
    $statement->execute(['users', 'employers', 'admin_audit_logs']);
    $engines = $statement->fetchAll(PDO::FETCH_KEY_PAIR);
    FirstAdminSetup::demand(count($engines) === 3 && count(array_filter($engines, static fn ($engine) => $engine === 'InnoDB')) === 3, 'transactional_schema_required');

    $stage = 'first administrator transaction';
    FirstAdminSetup::promote($email, getenv('CONFIRMATION') ?: '');
    echo "First administrator activated; exactly one active administrator verified and audit recorded.\n";
} catch (Throwable $error) {
    // Only our fixed refusal codes are allowed. Never render external exceptions.
    $status = $error instanceof FirstAdminRefusal ? $error->getMessage() : 'operation_failed_details_withheld';
    fwrite(STDERR, 'First admin setup stopped: '.$status.'; stage: '.$stage.". No private details logged.\n");
    exit(1);
}
