<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

class EmailVerificationDiagnostics
{
    public static function record(string $stage): void
    {
        if (! in_array($stage, ['dispatch_accepted', 'dispatch_failed', 'job_started', 'job_completed', 'job_failed'], true)) {
            return;
        }
        try {
            Log::log(str_ends_with($stage, 'failed') ? 'warning' : 'info', 'Email verification processing.', ['stage' => $stage]);
        } catch (Throwable) {
            // Monitoring cannot block delivery or expose request/provider details.
        }
    }
}
