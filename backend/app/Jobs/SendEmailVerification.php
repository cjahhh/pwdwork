<?php

namespace App\Jobs;

use App\Mail\EmailVerificationEmail;
use App\Models\User;
use App\Services\EmailVerification;
use App\Support\EmailVerificationDiagnostics;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

class SendEmailVerification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    // Never serialize an email address, rendered message or signed proof into a queue payload.
    public function __construct(public int $userId, public int $version) {}

    public function handle(EmailVerification $verification): void
    {
        EmailVerificationDiagnostics::record('job_started');
        try {
            $user = User::find($this->userId);
            if (! $user || ! $verification->available($user) || $user->status !== 'active'
                || $user->hasVerifiedEmail() || (int) $user->email_verification_version !== $this->version) {
                return;
            }
            Mail::mailer($verification->mailer())->to($user->getEmailForVerification())
                ->send(new EmailVerificationEmail($verification->link($user)));
            EmailVerificationDiagnostics::record('job_completed');
        } catch (Throwable) {
            EmailVerificationDiagnostics::record('job_failed');
            // No previous exception, message, recipient, URL or provider response may escape.
            throw new RuntimeException('Email verification delivery failed. Check protected delivery monitoring.');
        }
    }
}
