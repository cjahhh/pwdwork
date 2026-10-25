<?php

// Workflow-only helper. INSPECT deliberately never boots Laravel or Artisan.
declare(strict_types=1);

const LARAVEL_ROOT = '/var/www/backend';

function checkpoint(string $stage): void
{
    // Call sites supply fixed labels only; never interpolate environment/errors.
    $GLOBALS['migration_stage'] = $stage;
    echo 'Checkpoint: '.$stage.PHP_EOL;
}

function runtimePreflight(): void
{
    checkpoint('runtime extensions');
    foreach (['pdo_mysql', 'openssl', 'mbstring', 'tokenizer', 'xml', 'ctype', 'fileinfo', 'session'] as $extension) {
        demand(extension_loaded($extension), "Required PHP extension is missing: {$extension}.");
    }
    demand(is_callable('proc_open'), 'PHP process execution is unavailable.');
    checkpoint('Laravel filesystem permissions');
    demand(chdir(LARAVEL_ROOT), 'Cannot enter the Laravel working directory.');
    foreach (['vendor/autoload.php', 'bootstrap/app.php', 'composer.json', 'config/database.php', 'artisan'] as $file) {
        demand(is_readable(LARAVEL_ROOT.'/'.$file), "Required Laravel file is unreadable: {$file}.");
    }
    foreach (['bootstrap/cache', 'storage', 'storage/framework', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs'] as $directory) {
        $path = LARAVEL_ROOT.'/'.$directory;
        demand(is_dir($path) && is_readable($path) && is_writable($path) && is_executable($path), "Laravel directory must be accessible and writable: {$directory}. Run as the image's www-data user.");
    }
    demand(is_readable(LARAVEL_ROOT.'/database/migrations'), 'Migration directory is unreadable.');
    demand(is_writable(sys_get_temp_dir()), 'PHP temporary directory is not writable.');
    checkpoint('Composer autoload');
    require_once LARAVEL_ROOT.'/vendor/autoload.php';
    demand(class_exists(Illuminate\Foundation\Application::class), 'Laravel application class is unavailable.');
    checkpoint('runtime preflight complete');
}

function demand(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function setting(string $name): string
{
    $value = getenv($name);
    demand(is_string($value) && $value !== '', "Required setting {$name} is missing.");
    return $value;
}

function validateTarget(array $target): void
{
    demand((bool) preg_match('/\A[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\z/i', $target['host']), 'Invalid database DNS hostname.');
    demand(str_ends_with(strtolower($target['host']), '.aivencloud.com'), 'Expected an Aiven DNS hostname.');
    demand($target['host'] === $target['expected_host'], 'Database hostname does not match the independent deployment variable.');
    demand((bool) preg_match('/\A[1-9][0-9]{0,4}\z/', $target['port']) && (int) $target['port'] <= 65535, 'Invalid database port.');
    demand($target['port'] === $target['expected_port'], 'Database port does not match the independent deployment variable.');
    demand($target['database'] === 'pwdwork_prod', 'Database must be exactly pwdwork_prod.');
}

function validateAuthorization(string $operation, string $confirmation, string $reviewed, string $sha): void
{
    demand(in_array($operation, ['INSPECT', 'PREVIEW', 'APPLY'], true), 'Unknown operation.');
    if ($operation === 'APPLY') {
        demand($confirmation === 'APPLY pwdwork_prod', 'APPLY confirmation is incorrect.');
        demand((bool) preg_match('/\A[0-9a-f]{40}\z/', $reviewed) && $reviewed === $sha, 'Reviewed PREVIEW SHA must match this exact main commit.');
    }
}

function validateInitialState(array $tables, int $history): void
{
    demand(array_diff($tables, ['migrations']) === [], 'Existing application tables or views found. Initial migration workflow refuses this database.');
    demand($history === 0, 'Migration history is populated. Initial migration workflow refuses this database.');
}

function inventory(PDO $pdo): array
{
    $tables = $pdo->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_COLUMN);
    $history = in_array('migrations', $tables, true)
        ? (int) $pdo->query('SELECT COUNT(*) FROM `migrations`')->fetchColumn() : 0;
    echo 'Table count: '.count($tables).PHP_EOL;
    foreach ($tables as $table) {
        echo 'Table: '.json_encode($table, JSON_THROW_ON_ERROR).PHP_EOL;
    }
    echo "Migration history rows: {$history}\n";
    return [$tables, $history];
}

function artisan(array $arguments): void
{
    // Fixed argument arrays, never shell interpolation. Failed command output is
    // withheld because framework exceptions can contain connection details.
    $output = tmpfile();
    demand($output !== false, 'Cannot allocate private command output.');
    $process = proc_open([PHP_BINARY, LARAVEL_ROOT.'/artisan', ...$arguments], [
        0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $output,
    ], $pipes, LARAVEL_ROOT);
    demand(is_resource($process), 'Cannot start Artisan.');
    $exit = proc_close($process);
    if ($exit !== 0) {
        fclose($output);
        throw new RuntimeException('Artisan failed; output withheld to protect secrets. Review migration compatibility and database privileges. If APPLY partially ran, stop and investigate; do not retry or reset the schema.');
    }
    rewind($output);
    $text = stream_get_contents($output);
    fclose($output);
    foreach (['DB_HOST', 'DB_USERNAME', 'DB_PASSWORD', 'APP_KEY'] as $name) {
        $secret = getenv($name);
        if (is_string($secret) && $secret !== '') {
            $text = str_replace($secret, '[redacted]', $text);
        }
    }
    // Prefix every line so untrusted SQL/table text cannot emit runner commands.
    foreach (explode("\n", $text) as $line) {
        echo 'artisan | '.$line.PHP_EOL;
    }
}

// An offline entry point for guard verification; no extensions or DB required.
if (($argv[1] ?? '') === '--self-test') {
    $valid = ['host' => 'example.aivencloud.com', 'expected_host' => 'example.aivencloud.com', 'port' => '12345', 'expected_port' => '12345', 'database' => 'pwdwork_prod'];
    validateTarget($valid);
    validateInitialState([], 0);
    validateInitialState(['migrations'], 0);
    $sha = str_repeat('a', 40);
    validateAuthorization('INSPECT', '', '', $sha);
    validateAuthorization('PREVIEW', '', '', $sha);
    validateAuthorization('APPLY', 'APPLY pwdwork_prod', $sha, $sha);
    $rejections = [
        fn () => validateTarget(array_replace($valid, ['host' => 'other.aivencloud.com'])),
        fn () => validateTarget(array_replace($valid, ['port' => '3306'])),
        fn () => validateTarget(array_replace($valid, ['database' => 'pwdwork'])),
        fn () => validateTarget(array_replace($valid, ['port' => '12345; id'])),
        fn () => validateTarget(array_replace($valid, ['host' => 'example.aivencloud.com;id'])),
        fn () => validateInitialState(['users'], 0),
        fn () => validateInitialState(['migrations'], 1),
        fn () => validateAuthorization('APPLY', '', $sha, $sha),
        fn () => validateAuthorization('APPLY', 'APPLY pwdwork_prod', str_repeat('b', 40), $sha),
        fn () => validateAuthorization('APPLY', 'APPLY pwdwork_prod', 'main', $sha),
        fn () => validateAuthorization('$(id)', '', '', $sha),
    ];
    foreach ($rejections as $reject) {
        try {
            $reject();
        } catch (RuntimeException) {
            continue;
        }
        throw new RuntimeException('Offline guard test failed to reject unsafe input.');
    }
    echo 'Offline guards passed: 6 accepted cases, 11 rejected cases. No database accessed.'.PHP_EOL;
    exit(0);
}

try {
    // This mode checks the built image without credentials, Laravel bootstrap,
    // database access or migration execution. The workflow disables networking.
    if (($argv[1] ?? '') === '--runtime-check') {
        runtimePreflight();
        exit(0);
    }
    checkpoint('manual authorization and target validation');
    $operation = setting('OPERATION');
    validateAuthorization($operation, getenv('CONFIRMATION') ?: '', getenv('REVIEWED_SHA') ?: '', setting('GITHUB_SHA'));
    $target = [
        'host' => setting('DB_HOST'), 'port' => setting('DB_PORT'), 'database' => setting('DB_DATABASE'),
        'expected_host' => setting('EXPECTED_DB_HOST'), 'expected_port' => setting('EXPECTED_DB_PORT'),
    ];
    validateTarget($target);
    $username = setting('DB_USERNAME');
    $password = setting('DB_PASSWORD');
    $ca = setting('MYSQL_ATTR_SSL_CA');
    demand($ca === '/run/aiven-ca.pem' && is_readable($ca), 'Mounted Aiven CA is missing or unreadable.');
    demand(openssl_x509_read(file_get_contents($ca)) !== false, 'Aiven CA is not a valid PEM certificate.');
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 15,
        PDO::MYSQL_ATTR_SSL_CA => $ca, PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => true];
    checkpoint('direct PDO verified TLS connection');
    $pdo = new PDO("mysql:host={$target['host']};port={$target['port']};dbname={$target['database']};charset=utf8mb4", $username, $password, $options);
    demand($pdo->query('SELECT DATABASE()')->fetchColumn() === 'pwdwork_prod', 'Server selected an unexpected database.');
    $cipher = $pdo->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'")->fetch(PDO::FETCH_NUM)[1] ?? '';
    demand($cipher !== '', 'TLS was not negotiated. Refusing all operations.');
    demand(str_starts_with((string) $pdo->query('SELECT VERSION()')->fetchColumn(), '8.4.'), 'Expected MySQL 8.4.');
    echo 'Selected database: pwdwork_prod; verified TLS cipher: '.json_encode($cipher, JSON_THROW_ON_ERROR).PHP_EOL;
    [$tables, $history] = inventory($pdo);
    validateInitialState($tables, $history);
    echo 'Suitable for initial migrations (empty, or only empty migration bookkeeping).'.PHP_EOL;
    if ($operation === 'INSPECT') {
        exit(0);
    }

    checkpoint('production application key presence');
    setting('APP_KEY'); // Existing production key only; never generate a key here.
    runtimePreflight();
    checkpoint('Laravel application construction');
    $app = require LARAVEL_ROOT.'/bootstrap/app.php';
    demand($app->basePath() === LARAVEL_ROOT, 'Unexpected Laravel base path.');
    checkpoint('configuration cache precheck');
    demand($app->getCachedConfigPath() === '/tmp/pwdwork-migration-config.php', 'Unexpected configuration cache path.');
    demand(! $app->configurationIsCached(), 'Unexpected cached configuration.');
    // Laravel dispatches before/after events for each individual bootstrapper.
    foreach ([
        Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables::class => 'Laravel environment loading',
        Illuminate\Foundation\Bootstrap\LoadConfiguration::class => 'Laravel configuration loading',
        Illuminate\Foundation\Bootstrap\HandleExceptions::class => 'Laravel exception handler setup',
        Illuminate\Foundation\Bootstrap\RegisterFacades::class => 'Laravel facade registration',
        Illuminate\Foundation\Bootstrap\SetRequestForConsole::class => 'Laravel console request setup',
        Illuminate\Foundation\Bootstrap\RegisterProviders::class => 'Laravel provider registration',
        Illuminate\Foundation\Bootstrap\BootProviders::class => 'Laravel provider boot',
    ] as $bootstrapper => $label) {
        $app->beforeBootstrapping($bootstrapper, static fn () => checkpoint($label));
        $app->afterBootstrapping($bootstrapper, static function () use ($bootstrapper, $label): void {
            checkpoint($label.' complete');
            if ($bootstrapper === Illuminate\Foundation\Bootstrap\BootProviders::class) {
                checkpoint('Laravel deferred providers and command discovery');
            }
        });
    }
    checkpoint('Laravel console kernel resolution');
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    checkpoint('Laravel effective configuration validation');
    demand(! $app->configurationIsCached(), 'Unexpected cached configuration.');
    demand($app['config']->get('logging.default') === 'null', 'Workflow logging must resolve to the null channel.');
    demand($app['config']->get('logging.channels.null.handler') === Monolog\Handler\NullHandler::class, 'Workflow null logger is unsupported.');
    checkpoint('Laravel null logger resolution');
    $app['log']->channel('null');
    checkpoint('Laravel effective database configuration validation');
    $config = $app['config']->get('database.connections.mysql');
    demand($app['config']->get('database.default') === 'mysql', 'Laravel must use mysql.');
    foreach (['host', 'port', 'database'] as $key) {
        demand((string) $config[$key] === $target[$key], 'Laravel database target differs from verified target.');
    }
    demand(empty($config['url']) && empty($config['unix_socket']) && $config['prefix'] === '', 'Laravel connection overrides are not permitted.');
    demand($config['username'] === $username && $config['password'] === $password, 'Laravel credentials differ from verified credentials.');
    demand(($config['options'][PDO::MYSQL_ATTR_SSL_CA] ?? null) === $ca && ($config['options'][PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] ?? null) === true, 'Laravel must verify TLS certificates and hostnames.');
    demand($app['config']->get('database.migrations.table') === 'migrations', 'Unexpected migration bookkeeping table.');
    checkpoint('Laravel verified TLS connection');
    $laravelPdo = $app['db']->connection('mysql')->getPdo();
    demand($laravelPdo->query('SELECT DATABASE()')->fetchColumn() === 'pwdwork_prod', 'Laravel selected an unexpected database.');
    demand(($laravelPdo->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'")->fetch(PDO::FETCH_NUM)[1] ?? '') !== '', 'Laravel connection did not negotiate TLS.');

    checkpoint('Laravel migration discovery');
    demand($app->databasePath('migrations') === LARAVEL_ROOT.'/database/migrations', 'Unexpected Laravel migration path.');
    $files = $app['migrator']->getMigrationFiles([LARAVEL_ROOT.'/database/migrations']);
    demand(count($files) > 0, 'No migrations found in the image.');
    foreach (array_keys($files) as $name) {
        echo 'Pending migration: '.json_encode($name, JSON_THROW_ON_ERROR).PHP_EOL;
    }
    // Recheck immediately before the chosen operation. No automatic retries.
    checkpoint('final initial database state check');
    [$tables, $history] = inventory($laravelPdo);
    validateInitialState($tables, $history);
    if ($operation === 'PREVIEW') {
        echo 'WARNING: Laravel may create the migrations table during PREVIEW. Application migration SQL is only printed.'.PHP_EOL;
        checkpoint('Artisan PREVIEW');
        artisan(['migrate', '--pretend', '--force']);
        checkpoint('PREVIEW bookkeeping verification');
        [$tables, $history] = inventory($laravelPdo);
        validateInitialState($tables, $history);
        echo 'PREVIEW completed. Review SQL and commit SHA before a separate APPLY invocation.'.PHP_EOL;
    } else {
        checkpoint('Artisan APPLY');
        artisan(['migrate', '--force']);
        checkpoint('APPLY migration status verification');
        artisan(['migrate:status']);
        $ran = $laravelPdo->query('SELECT migration FROM `migrations` ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN);
        demand(count($ran) === count($files) && array_diff(array_keys($files), $ran) === [], 'Post-APPLY verification found pending or unexpected migrations.');
        echo 'APPLY verified: every migration in this commit is recorded.'.PHP_EOL;
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Failed stage: '.($GLOBALS['migration_stage'] ?? 'startup').PHP_EOL);
    // Never print PDO/framework exception messages, traces, DSNs or SQL bindings.
    $safe = $error instanceof RuntimeException && ! $error instanceof PDOException
        && $error->getFile() === __FILE__;
    fwrite(STDERR, 'Stopped safely: '.($safe ? $error->getMessage() : 'Connection or framework check failed. Check secret presence, expected target variables, CA/hostname, MySQL 8.4, privileges and approved network reachability. No connection details are logged.').PHP_EOL);
    exit(1);
}
