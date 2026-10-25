<?php

// Workflow-only helper. INSPECT deliberately never boots Laravel or Artisan.
declare(strict_types=1);

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
    $process = proc_open([PHP_BINARY, 'artisan', ...$arguments], [
        0 => ['file', '/dev/null', 'r'], 1 => $output, 2 => $output,
    ], $pipes, '/var/www/backend');
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

    setting('APP_KEY'); // Existing production key only; never generate a key here.
    chdir('/var/www/backend');
    require 'vendor/autoload.php';
    $app = require 'bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    demand(! $app->configurationIsCached(), 'Unexpected cached configuration.');
    $config = $app['config']->get('database.connections.mysql');
    demand($app['config']->get('database.default') === 'mysql', 'Laravel must use mysql.');
    foreach (['host', 'port', 'database'] as $key) {
        demand((string) $config[$key] === $target[$key], 'Laravel database target differs from verified target.');
    }
    demand(empty($config['url']) && empty($config['unix_socket']) && $config['prefix'] === '', 'Laravel connection overrides are not permitted.');
    demand($config['username'] === $username && $config['password'] === $password, 'Laravel credentials differ from verified credentials.');
    demand(($config['options'][PDO::MYSQL_ATTR_SSL_CA] ?? null) === $ca && ($config['options'][PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] ?? null) === true, 'Laravel must verify TLS certificates and hostnames.');
    demand($app['config']->get('database.migrations.table') === 'migrations', 'Unexpected migration bookkeeping table.');
    $laravelPdo = $app['db']->connection('mysql')->getPdo();
    demand($laravelPdo->query('SELECT DATABASE()')->fetchColumn() === 'pwdwork_prod', 'Laravel selected an unexpected database.');
    demand(($laravelPdo->query("SHOW SESSION STATUS LIKE 'Ssl_cipher'")->fetch(PDO::FETCH_NUM)[1] ?? '') !== '', 'Laravel connection did not negotiate TLS.');

    $files = $app['migrator']->getMigrationFiles([database_path('migrations')]);
    demand(count($files) > 0, 'No migrations found in the image.');
    foreach (array_keys($files) as $name) {
        echo 'Pending migration: '.json_encode($name, JSON_THROW_ON_ERROR).PHP_EOL;
    }
    // Recheck immediately before the chosen operation. No automatic retries.
    [$tables, $history] = inventory($laravelPdo);
    validateInitialState($tables, $history);
    if ($operation === 'PREVIEW') {
        echo 'WARNING: Laravel may create the migrations table during PREVIEW. Application migration SQL is only printed.'.PHP_EOL;
        artisan(['migrate', '--pretend', '--force']);
        [$tables, $history] = inventory($laravelPdo);
        validateInitialState($tables, $history);
        echo 'PREVIEW completed. Review SQL and commit SHA before a separate APPLY invocation.'.PHP_EOL;
    } else {
        artisan(['migrate', '--force']);
        artisan(['migrate:status']);
        $ran = $laravelPdo->query('SELECT migration FROM `migrations` ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN);
        demand(count($ran) === count($files) && array_diff(array_keys($files), $ran) === [], 'Post-APPLY verification found pending or unexpected migrations.');
        echo 'APPLY verified: every migration in this commit is recorded.'.PHP_EOL;
    }
} catch (Throwable $error) {
    // Never print PDO/framework exception messages, traces, DSNs or SQL bindings.
    $safe = $error instanceof RuntimeException && ! $error instanceof PDOException
        && $error->getFile() === __FILE__;
    fwrite(STDERR, 'Stopped safely: '.($safe ? $error->getMessage() : 'Connection or framework check failed. Check secret presence, expected target variables, CA/hostname, MySQL 8.4, privileges and approved network reachability. No connection details are logged.').PHP_EOL);
    exit(1);
}
