<?php

namespace App\Services;

use App\Jobs\SendEmailVerification;
use App\Models\User;
use App\Support\EmailVerificationDiagnostics;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use RuntimeException;

class EmailVerification
{
    public const COLUMNS = ['email_ownership_verified_at', 'email_verification_required_at', 'email_verification_version'];

    public function available(User $user): bool
    {
        return (bool) config('email-verification.enabled') || $user->email_verification_required_at !== null;
    }

    public function assertReady(): void
    {
        if (! Schema::hasColumns('users', self::COLUMNS)) {
            throw new RuntimeException('Email verification schema is not ready.');
        }
        $this->connection();
        $this->mailer();
        $this->frontend();
    }

    public function connection(): string
    {
        $connection = config('email-verification.connection');
        if (! is_string($connection) || ! in_array(config("queue.connections.{$connection}.driver"), ['deferred', 'database', 'redis', 'sqs', 'beanstalkd'], true)) {
            throw new RuntimeException('Email verification requires deferred or asynchronous delivery.');
        }

        return $connection;
    }

    public function mailer(): string
    {
        // Never inherit the application's log/failover/default mailer.
        if (config('mail.mailers.brevo.transport') !== 'brevo' || ! filled(config('services.brevo.key'))) {
            throw new RuntimeException('Email verification requires configured Brevo delivery.');
        }

        return 'brevo';
    }

    private function frontend(): string
    {
        $url = config('app.frontend_url');
        $parts = is_string($url) ? parse_url($url) : false;
        $local = $parts && ($parts['scheme'] ?? '') === 'http'
            && app()->environment(['local', 'testing', 'browser_test'])
            && in_array(strtolower($parts['host'] ?? ''), ['localhost', '127.0.0.1', '[::1]'], true);
        if (! $parts || ! filter_var($url, FILTER_VALIDATE_URL)
            || (! $local && ($parts['scheme'] ?? '') !== 'https')
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw new RuntimeException('Email verification frontend origin is not configured safely.');
        }

        return rtrim($url, '/');
    }

    /** Return zero when accepted, or the number of seconds before another send is allowed. */
    public function request(User $user): int
    {
        if (! $this->available($user) || $user->status !== 'active' || $user->hasVerifiedEmail()) {
            return 0;
        }
        $this->assertReady();
        $result = Cache::lock('email-verification:send:'.$user->id, 15)->get(function () use ($user) {
            return DB::transaction(function () use ($user) {
                $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                if ($locked->status !== 'active' || $locked->hasVerifiedEmail()) {
                    return 0;
                }
                $minute = 'email-verification:minute:'.$locked->id;
                $hour = 'email-verification:hour:'.$locked->id;
                if (RateLimiter::tooManyAttempts($minute, 1) || RateLimiter::tooManyAttempts($hour, 5)) {
                    return max(RateLimiter::tooManyAttempts($minute, 1) ? RateLimiter::availableIn($minute) : 0,
                        RateLimiter::tooManyAttempts($hour, 5) ? RateLimiter::availableIn($hour) : 0, 1);
                }
                RateLimiter::hit($minute, 60);
                RateLimiter::hit($hour, 3600);
                $locked->forceFill(['email_verification_version' => (int) $locked->email_verification_version + 1])->save();
                SendEmailVerification::dispatch($locked->id, (int) $locked->email_verification_version)
                    ->onConnection($this->connection())->onQueue(config('email-verification.queue'))->afterCommit();
                EmailVerificationDiagnostics::record('dispatch_accepted');

                return 0;
            });
        });

        return $result === false ? 1 : $result;
    }

    public function proof(User $user): string
    {
        return URL::temporarySignedRoute('verification.confirm', now()->addMinutes(60), [
            'user' => $user->id,
            'email' => hash('sha256', $user->getEmailForVerification()),
            'version' => (int) $user->email_verification_version,
        ], absolute: false);
    }

    public function link(User $user): string
    {
        // The proof never travels in the HTTP URL, access logs or Referer header.
        return $this->frontend().'/verify-email#'.http_build_query(['proof' => $this->proof($user)], '', '&', PHP_QUERY_RFC3986);
    }

    public function confirm(User $user, #[\SensitiveParameter] string $proof): bool
    {
        // Accept only our relative endpoint and exactly the signed fields, never an arbitrary URL.
        $parts = parse_url($proof);
        if (! $parts || array_diff(array_keys($parts), ['path', 'query'])
            || ($parts['path'] ?? '') !== route('verification.confirm', [], false)
            || count(explode('&', $parts['query'] ?? '')) !== 5) {
            return false;
        }
        parse_str($parts['query'] ?? '', $fields);
        if (count($fields) !== 5 || array_diff(array_keys($fields), ['user', 'email', 'version', 'expires', 'signature'])) {
            return false;
        }
        foreach (['user', 'version', 'expires'] as $field) {
            if (! is_string($fields[$field] ?? null) || ! ctype_digit($fields[$field]) || strlen($fields[$field]) > 20) {
                return false;
            }
        }
        foreach (['email', 'signature'] as $field) {
            if (! is_string($fields[$field] ?? null) || ! preg_match('/\A[a-f0-9]{64}\z/', $fields[$field])) {
                return false;
            }
        }
        $signed = Request::create('https://verification.invalid'.$proof);
        if (! URL::hasValidSignature($signed, absolute: false)) {
            return false;
        }

        return DB::transaction(function () use ($user, $fields, $signed) {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'active'
                || ! hash_equals((string) $locked->id, $fields['user'])
                || ! hash_equals(hash('sha256', $locked->getEmailForVerification()), $fields['email'])
                || ! URL::hasValidSignature($signed, absolute: false)) {
                return false;
            }
            if ($locked->hasVerifiedEmail()) {
                return true; // Repeat clicks do not change timestamps or emit another event.
            }
            if ((string) $locked->email_verification_version !== $fields['version']) {
                return false;
            }
            $locked->markEmailAsVerified();
            event(new Verified($locked));

            return true;
        });
    }
}
