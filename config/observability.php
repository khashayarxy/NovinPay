<?php

return [
    'client_telemetry' => [
        'enabled' => filter_var(env('OBSERVABILITY_CLIENT_TELEMETRY_ENABLED', env('APP_ENV') === 'production'), FILTER_VALIDATE_BOOL),
        'rate_limit_per_minute' => (int) env('OBSERVABILITY_CLIENT_TELEMETRY_RATE_LIMIT_PER_MINUTE', 10),
    ],

    'performance' => [
        'slow_request_ms' => (int) env('OBSERVABILITY_SLOW_REQUEST_MS', 2000),
        'slow_query_ms' => (int) env('OBSERVABILITY_SLOW_QUERY_MS', 500),
    ],

    'inventory' => [
        'low_stock_threshold' => (int) env('OBSERVABILITY_LOW_STOCK_THRESHOLD', 3),
    ],

    'backups' => [
        'path' => (string) env('OBSERVABILITY_BACKUP_PATH', '/home/novinpay/novinpay-private-backups'),
        'max_age_hours' => (int) env('OBSERVABILITY_BACKUP_MAX_AGE_HOURS', 72),
        'scheduled_enabled' => filter_var(env('OBSERVABILITY_SCHEDULED_DB_BACKUP_ENABLED', true), FILTER_VALIDATE_BOOL),
        'passphrase' => (string) env('OBSERVABILITY_BACKUP_PASSPHRASE', ''),
        'key_file' => (string) env('OBSERVABILITY_BACKUP_KEY_FILE', '/home/novinpay/.novinpay-maint10-key'),
        'retain_scheduled' => (int) env('OBSERVABILITY_BACKUP_RETAIN_COUNT', 6),
    ],

    'telegram_topics' => [
        'enabled' => filter_var(env('TELEGRAM_TOPICS_ENABLED', false), FILTER_VALIDATE_BOOL),
        'bot_token' => (string) env('TELEGRAM_TOPICS_BOT_TOKEN', env('NOTIFICATIONS_TELEGRAM_BOT_TOKEN', env('TELEGRAM_BOT_TOKEN', ''))),
        'chat_id' => (string) env('TELEGRAM_TOPICS_CHAT_ID', env('TELEGRAM_ADMIN_CHAT_ID', '')),
        'api_base' => rtrim((string) env('TELEGRAM_TOPICS_API_BASE', env('NOTIFICATIONS_TELEGRAM_API_BASE', env('TELEGRAM_API_BASE_URL', 'https://api.telegram.org'))), '/'),
        'throttle_seconds' => (int) env('TELEGRAM_TOPICS_THROTTLE_SECONDS', 300),
        'fallback_to_general' => filter_var(env('TELEGRAM_TOPICS_FALLBACK_TO_GENERAL', true), FILTER_VALIDATE_BOOL),
        'threads' => [
            'payments' => env('TELEGRAM_TOPIC_PAYMENTS_ID') ? (int) env('TELEGRAM_TOPIC_PAYMENTS_ID') : null,
            'orders' => env('TELEGRAM_TOPIC_ORDERS_ID') ? (int) env('TELEGRAM_TOPIC_ORDERS_ID') : null,
            'repairs' => env('TELEGRAM_TOPIC_REPAIRS_ID') ? (int) env('TELEGRAM_TOPIC_REPAIRS_ID') : null,
            'users' => env('TELEGRAM_TOPIC_USERS_ID') ? (int) env('TELEGRAM_TOPIC_USERS_ID') : null,
            'sms' => env('TELEGRAM_TOPIC_SMS_ID') ? (int) env('TELEGRAM_TOPIC_SMS_ID') : null,
            'inventory' => env('TELEGRAM_TOPIC_INVENTORY_ID') ? (int) env('TELEGRAM_TOPIC_INVENTORY_ID') : null,
            'errors' => env('TELEGRAM_TOPIC_ERRORS_ID') ? (int) env('TELEGRAM_TOPIC_ERRORS_ID') : null,
            'backups' => env('TELEGRAM_TOPIC_BACKUPS_ID') ? (int) env('TELEGRAM_TOPIC_BACKUPS_ID') : null,
            'performance' => env('TELEGRAM_TOPIC_PERFORMANCE_ID') ? (int) env('TELEGRAM_TOPIC_PERFORMANCE_ID') : null,
            'digest' => env('TELEGRAM_TOPIC_DIGEST_ID') ? (int) env('TELEGRAM_TOPIC_DIGEST_ID') : null,
            'consultations' => env('TELEGRAM_TOPIC_CONSULTATIONS_ID') ? (int) env('TELEGRAM_TOPIC_CONSULTATIONS_ID') : null,
            'support_chat' => env('TELEGRAM_TOPIC_SUPPORT_CHAT_ID') ? (int) env('TELEGRAM_TOPIC_SUPPORT_CHAT_ID') : null,
            'reviews' => env('TELEGRAM_TOPIC_REVIEWS_ID') ? (int) env('TELEGRAM_TOPIC_REVIEWS_ID') : null,
            'documents' => (env('TELEGRAM_TOPIC_DOCUMENTS') ?: env('TELEGRAM_TOPIC_DOCUMENTS_ID'))
                ? (int) (env('TELEGRAM_TOPIC_DOCUMENTS') ?: env('TELEGRAM_TOPIC_DOCUMENTS_ID'))
                : null,
        ],
    ],
];
