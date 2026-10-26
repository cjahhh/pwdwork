<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

class PasswordRecoveryDiagnostics
{
    private const STAGES = [
        'dispatch_accepted', 'dispatch_failed', 'job_started', 'mailer_validation',
        'broker_started', 'broker_completed', 'job_failed', 'notification_started',
        'notification_ready', 'provider_started', 'provider_accepted', 'provider_failed',
    ];

    private const CATEGORIES = [
        'none', 'dispatch', 'configuration', 'processing', 'message_format', 'connection',
        'provider_authentication', 'provider_permission', 'provider_rate_limit',
        'provider_server', 'provider_rejected', 'provider_response',
    ];

    public static function record(string $stage, string $failureCategory = 'none', ?int $httpStatus = null): void
    {
        // Only fixed vocabulary and an HTTP status can reach this dedicated channel.
        if (! in_array($stage, self::STAGES, true) || ! in_array($failureCategory, self::CATEGORIES, true)) {
            return;
        }
        $context = ['stage' => $stage, 'failure_category' => $failureCategory];
        if ($httpStatus !== null && $httpStatus >= 100 && $httpStatus <= 599) {
            $context['http_status'] = $httpStatus;
        }
        try {
            Log::channel('password_recovery')->log(
                $failureCategory === 'none' ? 'info' : 'warning',
                'Password recovery processing.',
                $context,
            );
        } catch (Throwable) {
            // A monitoring failure must not prevent delivery or disclose exception details.
        }
    }
}
