<?php

namespace App\Jobs;

use App\Services\PasswordRecoveryMail;
use App\Support\PasswordRecoveryDiagnostics;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Password;

class SendPasswordResetLink implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public string $email) {}

    public function handle(): void
    {
        PasswordRecoveryDiagnostics::record('job_started');
        $failureCategory = 'configuration';
        try {
            // Validate the selected transport even for unknown addresses. Never fall back to logs.
            PasswordRecoveryDiagnostics::record('mailer_validation');
            app(PasswordRecoveryMail::class)->mailer();
            $failureCategory = 'processing';
            PasswordRecoveryDiagnostics::record('broker_started');
            Password::sendResetLink(['email' => $this->email]);
            // Deliberately do not log the broker's account-existence or throttle result.
            PasswordRecoveryDiagnostics::record('broker_completed');
        } catch (\Throwable) {
            PasswordRecoveryDiagnostics::record('job_failed', $failureCategory);
            // Worker/deferred exceptions must not expose SMTP transcripts, URLs or API keys.
            throw new \RuntimeException('Password recovery delivery failed. Check the mail configuration and provider dashboard.');
        }
    }
}
