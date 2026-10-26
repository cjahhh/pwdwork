<?php

return [
    // Enrollment only. Already-enrolled accounts remain protected when disabled.
    'enabled' => (bool) env('EMAIL_VERIFICATION_ENABLED', false),
    'connection' => env('EMAIL_VERIFICATION_QUEUE_CONNECTION', env('PASSWORD_RESET_QUEUE_CONNECTION', 'database')),
    'queue' => 'email-verifications',
];
