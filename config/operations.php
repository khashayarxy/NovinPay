<?php

return [
    // Installing cron alone must never activate provider processing.
    'scheduled_payments' => (bool) env('OPERATIONS_SCHEDULED_PAYMENTS', false),
    'scheduled_notifications' => (bool) env('OPERATIONS_SCHEDULED_NOTIFICATIONS', false),
    'heartbeat_max_age_seconds' => 180,
    'queue_stale_minutes' => 10,
];
