<?php

// Test-only HTTP entry point. Never registered by the real public/index.php.
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Http\Request;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;

$database = getenv('DB_DATABASE');
$directory = $database ? realpath(dirname($database)) : false;
if (getenv('PWDWORK_BROWSER_TEST') !== '1' || getenv('APP_ENV') !== 'browser_test'
    || ! $directory || realpath(dirname($directory)) !== realpath(sys_get_temp_dir())
    || ! str_starts_with(basename($directory), 'pwdwork-browser-')
    || basename($database) !== 'browser.sqlite') {
    throw new RuntimeException('Browser router requires an isolated test fixture.');
}
define('LARAVEL_START', microtime(true));
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->booted(function () use ($database, $directory) {
    if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== $database
        || config('database.connections.sqlite.url') || config('mail.default') !== 'array') {
        throw new RuntimeException('Browser mail capture requires SQLite and the array transport.');
    }
    Event::listen(NotificationSent::class, function (NotificationSent $event) use ($directory) {
        if ($event->notification instanceof ResetPassword) {
            // Synthetic test inbox only. No application log, queue output or production route exposes this.
            file_put_contents($directory.'/reset-mail.json', json_encode([
                'url' => $event->notification->toMail($event->notifiable)->actionUrl,
            ], JSON_THROW_ON_ERROR), LOCK_EX);
        }
    });
});
$app->handleRequest(Request::capture());
