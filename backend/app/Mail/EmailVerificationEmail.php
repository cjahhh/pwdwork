<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;

class EmailVerificationEmail extends Mailable
{
    // Sent inside the encrypted job; this mailable is never queued separately.
    public function __construct(#[\SensitiveParameter] public readonly string $actionUrl) {}

    public function build(): static
    {
        return $this->subject('Verify your PWDWork email address')
            ->view('emails.verify-email')->text('emails.verify-email-text');
    }
}
