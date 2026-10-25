<?php

namespace App\Jobs;

use App\Services\PasswordRecoveryMail;
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
        try {
            // Validate the selected transport even for unknown addresses. Never fall back to logs.
            app(PasswordRecoveryMail::class)->mailer();
            Password::sendResetLink(['email' => $this->email]);
        } catch (\Throwable) {
            // Worker/deferred exceptions must not expose SMTP transcripts, URLs or API keys.
            throw new \RuntimeException('Password recovery delivery failed. Check the mail configuration and provider dashboard.');
        }
    }
}
