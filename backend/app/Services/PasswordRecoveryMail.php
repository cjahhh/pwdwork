<?php

namespace App\Services;

use App\Mail\PasswordResetEmail;
use RuntimeException;

class PasswordRecoveryMail
{
    public function connection(): string
    {
        $connection = config('queue.password_reset_connection');
        $driver = is_string($connection) ? config("queue.connections.{$connection}.driver") : null;
        if (! in_array($driver, ['deferred', 'background', 'database', 'redis', 'sqs', 'beanstalkd'], true)) {
            throw new RuntimeException('Password recovery requires response-first or asynchronous delivery.');
        }

        return $connection;
    }

    public function mailer(): string
    {
        $mailer = config('mail.password_reset_mailer') ?: config('mail.default');
        $transport = is_string($mailer) ? config("mail.mailers.{$mailer}.transport") : null;
        $allowed = ['smtp', 'brevo'];
        if (app()->environment(['testing', 'browser_test'])) {
            $allowed[] = 'array';
        }
        // Fail closed: log/failover/roundrobin transports can disclose reset links.
        if (! in_array($transport, $allowed, true)) {
            throw new RuntimeException('Password recovery requires a configured delivery mailer.');
        }

        return $mailer;
    }

    public function url($user, #[\SensitiveParameter] string $token): string
    {
        $frontend = config('app.frontend_url');
        $parts = is_string($frontend) ? parse_url($frontend) : false;
        $localHttp = $parts && ($parts['scheme'] ?? '') === 'http'
            && app()->environment(['local', 'testing', 'browser_test'])
            && in_array(strtolower($parts['host'] ?? ''), ['localhost', '127.0.0.1', '[::1]'], true);
        if (! $parts || ! filter_var($frontend, FILTER_VALIDATE_URL)
            || (! $localHttp && ($parts['scheme'] ?? '') !== 'https')
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw new RuntimeException('Password recovery frontend URL is not configured safely.');
        }

        // Fragments are not sent to web servers or in HTTP Referer headers.
        return rtrim($frontend, '/').'/reset-password#'.http_build_query([
            'token' => $token, 'email' => $user->getEmailForPasswordReset(),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function message($user, #[\SensitiveParameter] string $token): PasswordResetEmail
    {
        $url = $this->url($user, $token);

        return (new PasswordResetEmail($url, (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire')))
            ->mailer($this->mailer())
            ->to($user->getEmailForPasswordReset());
    }
}
