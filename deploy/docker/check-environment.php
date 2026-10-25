<?php

// Container-only, read-only checks. Never connect to a database or print values.
use App\Support\RuntimeRequirements;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Encryption\Encrypter;

// Standalone worker permission check: no Laravel bootstrap or DB connection.
// Actually open the configured file, but never read or print its contents/path.
if (($argv[1] ?? '') === '--ca-readable') {
    $ca = getenv('MYSQL_ATTR_SSL_CA');
    $handle = is_string($ca) && $ca !== '' && @is_file($ca) ? @fopen($ca, 'rb') : false;
    if ($handle === false) {
        fwrite(STDERR, "Container configuration: Aiven CA cannot be opened by the application user. Check Secret File path and group access.\n");
        exit(1);
    }
    fclose($handle);
    fwrite(STDOUT, "Container configuration: application user can open the Aiven CA.\n");
    exit(0);
}

require '/var/www/backend/vendor/autoload.php';
$app = require '/var/www/backend/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$require = function (bool $condition, string $message): void {
    if (! $condition) {
        fwrite(STDERR, 'Container configuration: '.$message."\n");
        exit(1);
    }
};
$origin = function ($url): bool {
    return is_string($url) && filter_var($url, FILTER_VALIDATE_URL)
        && parse_url($url, PHP_URL_SCHEME) === 'https'
        && ! parse_url($url, PHP_URL_USER) && ! parse_url($url, PHP_URL_PASS)
        && ! parse_url($url, PHP_URL_QUERY) && ! parse_url($url, PHP_URL_FRAGMENT)
        && in_array(parse_url($url, PHP_URL_PATH), [null, '', '/'], true);
};
$require(RuntimeRequirements::patchedPhp(PHP_VERSION), 'Update the PHP image to the reviewed security baseline.');
$require($app->environment('production') && config('app.debug') === false, 'Use production mode with debug disabled.');
$key = (string) config('app.key');
$key = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
$require(is_string($key) && Encrypter::supported($key, config('app.cipher')), 'Supply a valid, stable APP_KEY.');
$url = rtrim((string) config('app.url'), '/');
$require($origin($url) && $url === rtrim((string) config('app.frontend_url'), '/'), 'APP_URL and FRONTEND_URL must be the same HTTPS origin.');
$host = parse_url($url, PHP_URL_HOST);
$port = parse_url($url, PHP_URL_PORT);
$require(config('cors.allowed_origins') === [$url] && empty(config('cors.allowed_origins_patterns')), 'CORS must allow only this service origin.');
$require(config('sanctum.stateful') === [$host.($port ? ':'.$port : '')], 'Sanctum must contain only this service hostname.');
$require(config('trustedproxy.proxies') === [], 'Leave TRUSTED_PROXIES empty; container Nginx normalizes Render headers.');
$require(config('session.driver') === 'database' && config('session.secure') === true
    && config('session.http_only') === true && config('session.domain') === null
    && in_array(config('session.same_site'), ['lax', 'strict'], true), 'Use secure, host-only database sessions.');
$require(config('cache.default') === 'database' && config('queue.default') === 'database', 'Use database cache and the database queue.');
$require(config('database.default') === 'mysql' && empty(config('database.connections.mysql.url'))
    && filled(config('database.connections.mysql.host')) && filled(config('database.connections.mysql.database'))
    && filled(config('database.connections.mysql.username')) && config('database.connections.mysql.username') !== 'root'
    && filled(config('database.connections.mysql.password')), 'Set explicit MySQL connection settings with a non-root account; leave DB_URL unset.');
$ca = getenv('MYSQL_ATTR_SSL_CA');
$require(is_string($ca) && is_file($ca) && is_readable($ca)
    && openssl_x509_read(file_get_contents($ca)) !== false, 'Mount a readable Aiven CA PEM file using MYSQL_ATTR_SSL_CA.');
$options = config('database.connections.mysql.options');
$require(($options[PDO::MYSQL_ATTR_SSL_CA] ?? null) === $ca
    && ($options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] ?? null) === true, 'MySQL must verify its certificate using the mounted CA.');
$require(config('filesystems.resume_disk') === 's3' && config('filesystems.disks.s3.visibility') === 'private'
    && filled(config('filesystems.disks.s3.bucket')) && filled(config('filesystems.disks.s3.region'))
    && filled(config('filesystems.disks.s3.key')) && filled(config('filesystems.disks.s3.secret')), 'Configure private S3-compatible resume storage; Render disk is temporary.');
$endpoint = config('filesystems.disks.s3.endpoint');
$require(! $endpoint || (is_string($endpoint) && parse_url($endpoint, PHP_URL_SCHEME) === 'https'), 'Custom object storage endpoints must use HTTPS.');
$require(config('mail.default') === 'brevo' && (config('mail.password_reset_mailer') ?: config('mail.default')) === 'brevo'
    && filled(config('services.brevo.key')) && filter_var(config('mail.from.address'), FILTER_VALIDATE_EMAIL) !== false,
    'Explicitly configure Brevo HTTPS, an API key, and a verified sender.');
$require(config('logging.default') === 'stderr' && in_array(config('logging.channels.stderr.level'), ['warning', 'error', 'critical', 'alert', 'emergency'], true), 'Use stderr logging at warning level or higher.');
$recoveryQueue = config('queue.password_reset_connection');
$require(in_array($recoveryQueue, ['deferred', 'database'], true), 'Select deferred recovery for Free, or database recovery with an external worker.');
$require(config('administration.email_notifications') === false || $recoveryQueue === 'database', 'Administrator email requires the documented external queue worker.');
if ($recoveryQueue === 'deferred') {
    fwrite(STDERR, "Free mode: recovery email runs after the response; interrupted sends are not retried. Use an external worker for durable delivery.\n");
}
