<?php

// Offline audit: actual pre-feature source/tests plus ONLY the new migration;
// --old-schema instead runs those unchanged tests on current code WITHOUT that migration.
// Requires the retained baseline Git object and the installed, unchanged dev dependencies.
require __DIR__.'/../backend/vendor/autoload.php';

use Symfony\Component\Process\Process;

$root = realpath(__DIR__.'/..');
$baseline = 'd495aa8eed7a683b3213f3f59440b9700adfedcb';
if ($argc > 2 || (isset($argv[1]) && $argv[1] !== '--old-schema')) {
    throw new RuntimeException('Only the optional --old-schema argument is supported.');
}
$oldSchema = isset($argv[1]);
$temporaryRoot = realpath(sys_get_temp_dir());
$snapshot = $temporaryRoot.DIRECTORY_SEPARATOR.'pwdwork-legacy-'.bin2hex(random_bytes(10));
mkdir($snapshot, 0700);

try {
    $archive = new Process([
        'git', 'archive', '--format=tar', $baseline,
        'backend/app', 'backend/bootstrap', 'backend/config', 'backend/routes',
        'backend/database', 'backend/tests', 'backend/resources', 'backend/phpunit.xml',
        'backend/composer.json', 'deploy', '.github/scripts',
    ], $root);
    $archive->mustRun();
    file_put_contents($snapshot.'/source.tar', $archive->getOutput());
    (new PharData($snapshot.'/source.tar'))->extractTo($snapshot);
    $backend = $snapshot.'/backend';
    foreach (['vendor', 'bootstrap/cache', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs'] as $directory) {
        if (! is_dir($backend.'/'.$directory)) {
            mkdir($backend.'/'.$directory, 0700, true);
        }
    }
    // No .env, credentials or databases are copied; only source is overlaid below.
    if (file_exists($backend.'/.env')) {
        throw new RuntimeException('Unexpected environment file in isolated snapshot.');
    }
    $xml = simplexml_load_file($backend.'/phpunit.xml');
    foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => ''] as $name => $value) {
        $entries = $xml->xpath('/phpunit/php/env[@name="'.$name.'"]');
        if (count($entries) !== 1 || (string) $entries[0]['value'] !== $value || (string) $entries[0]['force'] !== 'true') {
            throw new RuntimeException('Baseline suite must force isolated in-memory testing.');
        }
    }
    $migration = '2026_10_26_000001_add_email_ownership_verification_to_users.php';
    if ($oldSchema) {
        foreach (['app', 'config', 'routes', 'resources'] as $directory) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/backend/'.$directory, FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $relative = substr($file->getPathname(), strlen($root.'/backend/'));
                $target = $backend.'/'.$relative;
                if (! is_dir(dirname($target))) {
                    mkdir(dirname($target), 0700, true);
                }
                copy($file->getPathname(), $target);
            }
        }
        foreach (['app.php', 'providers.php'] as $file) {
            copy($root.'/backend/bootstrap/'.$file, $backend.'/bootstrap/'.$file);
        }
        foreach (['app/Models/User.php', 'app/Http/Controllers/AuthController.php', 'bootstrap/app.php', 'routes/api.php', 'config/email-verification.php'] as $file) {
            if (hash_file('sha256', $root.'/backend/'.$file) !== hash_file('sha256', $backend.'/'.$file)) {
                throw new RuntimeException('Current-code snapshot must match the working source.');
            }
        }
    } else {
        copy($root.'/backend/database/migrations/'.$migration, $backend.'/database/migrations/'.$migration);
    }

    // Composer may have optimized class maps. Override every baseline class path,
    // rather than allowing a fallback to the modified application in this workspace.
    $classes = [];
    foreach (['app' => 'App\\', 'tests' => 'Tests\\', 'database/factories' => 'Database\\Factories\\'] as $directory => $namespace) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($backend.'/'.$directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $relative = substr($file->getPathname(), strlen($backend.'/'.$directory) + 1, -4);
                $classes[$namespace.str_replace(['/', '\\'], '\\', $relative)] = $file->getPathname();
            }
        }
    }
    $autoload = '<?php $loader = require '.var_export($root.'/backend/vendor/autoload.php', true).';'
        .'$loader->addClassMap('.var_export($classes, true).');'
        .'foreach ([App\\Models\\User::class, App\\Http\\Controllers\\AuthController::class, Tests\\TestCase::class] as $class) {'
        .'if (!str_starts_with(realpath((new ReflectionClass($class))->getFileName()), '.var_export(realpath($backend), true).')) {'
        .'throw new RuntimeException("Application source escaped the isolated snapshot."); }} return $loader;';
    file_put_contents($backend.'/vendor/autoload.php', $autoload);
    $suite = new Process([
        PHP_BINARY, $root.'/backend/vendor/phpunit/phpunit/phpunit',
        '--configuration', $backend.'/phpunit.xml', '--no-progress', '--do-not-cache-result',
    ], $backend, [
        'APP_BASE_PATH' => $backend,
        'COMPOSER_VENDOR_DIR' => $root.'/backend/vendor',
        'EMAIL_VERIFICATION_ENABLED' => 'false',
        'EMAIL_VERIFICATION_QUEUE_CONNECTION' => 'deferred',
        'APP_CONFIG_CACHE' => 'bootstrap/cache/config.php',
        'APP_ROUTES_CACHE' => 'bootstrap/cache/routes.php',
        'APP_SERVICES_CACHE' => 'bootstrap/cache/services.php',
        'APP_PACKAGES_CACHE' => 'bootstrap/cache/packages.php',
    ]);
    $suite->setTimeout(180);
    echo 'Baseline application: '.$baseline.PHP_EOL;
    echo ($oldSchema ? 'Current application, OLD schema, enrollment disabled.' : 'Baseline application, NEW additive schema: '.$migration).PHP_EOL;
    echo 'Unchanged baseline tests; SQLite :memory: only.'.PHP_EOL;
    $suite->run(function ($type, $output) {
        if ($type === Process::OUT) {
            echo $output;
        }
    });
    $exitCode = $suite->getExitCode();
    if ($exitCode !== 0) {
        // The isolated suite has synthetic settings only; retain failure diagnostics.
        fwrite(STDERR, $suite->getErrorOutput());
    }
} finally {
    // Delete only this exact random directory directly under the verified system temp root.
    $resolved = realpath($snapshot);
    if ($resolved === false || realpath(dirname($resolved)) !== $temporaryRoot || ! str_starts_with(basename($resolved), 'pwdwork-legacy-')) {
        throw new RuntimeException('Refusing cleanup outside the isolated snapshot.');
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        if ($file->isLink()) {
            unlink($file->getPathname());
        } elseif ($file->isDir()) {
            rmdir($file->getPathname());
        } else {
            unlink($file->getPathname());
        }
    }
    rmdir($resolved);
}
exit($exitCode);
