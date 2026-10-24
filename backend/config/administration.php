<?php

return [
    // In-app decisions are always persisted in the same transaction as the audit.
    'email_notifications' => env('ADMIN_NOTIFICATION_MAIL', false),
    'notification_connection' => env('ADMIN_NOTIFICATION_QUEUE_CONNECTION', 'database'),
    'notification_queue' => env('ADMIN_NOTIFICATION_QUEUE', 'notifications'),
];
