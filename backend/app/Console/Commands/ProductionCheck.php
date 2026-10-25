<?php

namespace App\Console\Commands;

use App\Support\RuntimeRequirements;
use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;

class ProductionCheck extends Command
{
    protected $signature = 'pwdwork:production-check';

    protected $description = 'Check production configuration without printing secrets, connecting to services, or changing data';

    public function handle(): int
    {
        $checks = [];
        $check = function (string $label, bool $ok) use (&$checks) {
            $checks[] = [$ok ? 'PASS' : 'FAIL', $label];
        };
        $https = fn ($url) => is_string($url) && filter_var($url, FILTER_VALIDATE_URL)
            && parse_url($url, PHP_URL_SCHEME) === 'https' && ! parse_url($url, PHP_URL_USER)
            && ! parse_url($url, PHP_URL_PASS) && ! parse_url($url, PHP_URL_QUERY) && ! parse_url($url, PHP_URL_FRAGMENT)
            && in_array(parse_url($url, PHP_URL_PATH), [null, '', '/'], true)
            && ! in_array(strtolower(parse_url($url, PHP_URL_HOST) ?? ''), ['localhost', '127.0.0.1', '::1', '[::1]'], true);
        $check('APP_ENV is production', app()->environment('production'));
        $check('PHP includes the 21 October 2026 security fixes (recheck advisories)', RuntimeRequirements::patchedPhp(PHP_VERSION));
        $check('Debug output is disabled', config('app.debug') === false);
        $key = (string) config('app.key');
        $key = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
        $check('Application key matches the configured cipher', is_string($key) && Encrypter::supported($key, config('app.cipher')));
        $check('APP_URL and FRONTEND_URL use explicit public HTTPS origins', $https(config('app.url')) && $https(config('app.frontend_url')));
        $check('Session cookie is Secure and HttpOnly', config('session.secure') === true && config('session.http_only') === true);
        $check('Session uses database and SameSite lax/strict', config('session.driver') === 'database' && in_array(config('session.same_site'), ['lax', 'strict'], true));
        $origins = config('cors.allowed_origins', []);
        $check('CORS uses exact HTTPS origins and no patterns', count($origins) > 0 && collect($origins)->every($https) && empty(config('cors.allowed_origins_patterns')));
        $check('Frontend origin is included in CORS', in_array(rtrim((string) config('app.frontend_url'), '/'), $origins, true));
        $frontendHost = parse_url((string) config('app.frontend_url'), PHP_URL_HOST);
        $frontendPort = parse_url((string) config('app.frontend_url'), PHP_URL_PORT);
        $frontendAuthority = $frontendHost.($frontendPort ? ':'.$frontendPort : '');
        $domains = config('sanctum.stateful', []);
        $check('Sanctum includes the frontend host and excludes wildcards/local defaults', in_array($frontendAuthority, $domains, true) && collect($domains)->every(fn ($domain) => is_string($domain) && ! preg_match('~[*/\s]|localhost|127\.0\.0\.1|::1~i', $domain)));
        $proxies = config('trustedproxy.proxies', []);
        $check('Trusted proxies are empty or explicit IPs/CIDRs', is_array($proxies) && collect($proxies)->every(function ($proxy) {
            $parts = explode('/', $proxy);
            if (! filter_var($parts[0], FILTER_VALIDATE_IP) || count($parts) > 2) {
                return false;
            }

            return count($parts) === 1 || (ctype_digit($parts[1]) && (int) $parts[1] > 0 && (int) $parts[1] <= (str_contains($parts[0], ':') ? 128 : 32));
        }));
        $check('MySQL has a named non-root database account', config('database.default') === 'mysql' && filled(config('database.connections.mysql.database')) && filled(config('database.connections.mysql.username')) && config('database.connections.mysql.username') !== 'root' && filled(config('database.connections.mysql.password')));
        $check('Cache is shared; queue and recovery are asynchronous', in_array(config('cache.default'), ['database', 'redis'], true) && in_array(config('queue.default'), ['database', 'redis'], true) && in_array(config('queue.password_reset_connection'), ['database', 'redis'], true));
        $disk = config('filesystems.disks.'.config('filesystems.resume_disk'), []);
        $check('Resume storage is private with automatic serving disabled', in_array($disk['driver'] ?? null, ['local', 's3'], true) && ($disk['visibility'] ?? 'private') !== 'public' && ($disk['serve'] ?? false) === false && ! isset($disk['url']));
        $check('Mail uses a delivery transport instead of log/array', in_array(config('mail.default'), ['smtp', 'ses', 'ses-v2', 'postmark', 'resend', 'mailgun', 'sendmail'], true));
        $check('Mail sender is configured and SMTP has a host', filter_var(config('mail.from.address'), FILTER_VALIDATE_EMAIL) !== false && filled(config('mail.from.name')) && (config('mail.default') !== 'smtp' || filled(config('mail.mailers.smtp.host'))));
        $check('Logs use daily/stderr and exclude debug verbosity', in_array(config('logging.default'), ['daily', 'stderr'], true) && in_array(config('logging.channels.'.config('logging.default').'.level'), ['info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'], true));
        $check('Required runtime extensions are available', collect(['ctype', 'curl', 'dom', 'fileinfo', 'filter', 'hash', 'mbstring', 'openssl', 'pdo', 'pdo_mysql', 'session', 'tokenizer', 'xml'])->every(fn ($extension) => extension_loaded($extension)));
        $check('Framework cache/views and logs are writable', collect([storage_path('framework/cache'), storage_path('framework/views'), storage_path('logs'), base_path('bootstrap/cache')])->every(fn ($path) => is_dir($path) && is_writable($path)));
        $this->table(['Result', 'Configuration requirement'], $checks);
        $this->line('Read-only configuration check only. It does not verify DNS, TLS, database connectivity/migrations, SMTP delivery, worker health, backups, frontend policies or legal readiness. No values or secrets printed.');

        return collect($checks)->every(fn ($row) => $row[0] === 'PASS') ? self::SUCCESS : self::FAILURE;
    }
}
