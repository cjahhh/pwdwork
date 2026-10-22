<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Password;

class SendPasswordResetLink implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public string $email) {}

    public function handle(): void
    {
        // Account lookup and mail delivery run out of band for every request.
        // Exceptions propagate to Laravel's retry and failed-jobs mechanisms.
        Password::sendResetLink(['email' => $this->email]);
    }
}
