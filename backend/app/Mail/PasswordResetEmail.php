<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class PasswordResetEmail extends Mailable
{
    // This mailable is sent inside the recovery job, never queued with a raw reset URL.
    public function __construct(
        #[\SensitiveParameter] public readonly string $actionUrl,
        public readonly int $expiresMinutes,
    ) {}

    public function build(): static
    {
        return $this->subject('Reset your PWDWork password')
            ->view('emails.password-reset')->text('emails.password-reset-text')
            ->with(['resetUrl' => $this->actionUrl, 'expiresMinutes' => $this->expiresMinutes]);
    }
}
