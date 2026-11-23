<?php

return [
    'admin' => [
        'phones' => array_values(array_filter(array_map('trim', explode(',', (string) env('NOTIFICATIONS_ADMIN_PHONES', ''))))),
        'emails' => array_values(array_filter(array_map('trim', explode(',', (string) env('NOTIFICATIONS_ADMIN_EMAILS', ''))))),
    ],

    'sms' => [
        'enabled' => (bool) env('NOTIFICATIONS_SMS_ENABLED', false),
        'patterns' => [
            'admin_order_placed' => (string) env('NOTIFICATIONS_IPPANEL_PATTERN_ADMIN_ORDER', ''),
            'admin_repair_submitted' => (string) env('NOTIFICATIONS_IPPANEL_PATTERN_ADMIN_REPAIR', ''),
            'admin_consultation_submitted' => (string) env('NOTIFICATIONS_IPPANEL_PATTERN_ADMIN_CONSULTATION', ''),
            'admin_chat_first_message' => (string) env('NOTIFICATIONS_IPPANEL_PATTERN_ADMIN_CHAT_FIRST', ''),
            'customer_order_placed' => (string) env('NOTIFICATIONS_IPPANEL_PATTERN_CUSTOMER_ORDER_PLACED', ''),
            'customer_order_shipped' => (string) env('NOTIFICATIONS_IPPANEL_PATTERN_CUSTOMER_ORDER_SHIPPED', ''),
            'customer_repair_submitted' => (string) env('NOTIFICATIONS_IPPANEL_PATTERN_CUSTOMER_REPAIR_SUBMITTED', ''),
            'customer_repair_status' => (string) env('NOTIFICATIONS_IPPANEL_PATTERN_CUSTOMER_REPAIR_STATUS', ''),
            'customer_consultation_submitted' => (string) env('NOTIFICATIONS_IPPANEL_PATTERN_CUSTOMER_CONSULTATION', ''),
        ],
    ],

    'panelchi' => [
        'enabled' => (bool) env('NOTIFICATIONS_PANELCHI_ENABLED', false),
        'api_key' => (string) env('NOTIFICATIONS_PANELCHI_API_KEY', ''),
        'sender' => (string) env('NOTIFICATIONS_PANELCHI_SENDER', ''),
        'patterns' => [
            'order_confirmed' => 'mfae2yqje8msw9e',
            'order_preparing' => '694jmegiuep02i6',
            'order_shipped' => 'yksd5440vew1loy',
            'repair_created' => '309p3u2jo5b7qds',
            'repair_payment' => 'a7h9o09htzyjk17',
            'repair_status' => 'hn7sgk679tfdxvt',
            'order_pickup_ready' => 'u2fc816s2qo1u5t',
            'order_cancelled' => 'xtawhyckra6k1tt',
            'repair_cancelled' => '8zvy6p1t482z3oy',
        ],
    ],

    'email' => [
        'enabled' => (bool) env('NOTIFICATIONS_EMAIL_ENABLED', false),
    ],

    'bale' => [
        'enabled' => (bool) env('NOTIFICATIONS_BALE_ENABLED', env('BALE_BOT_TOKEN') && env('BALE_ADMIN_CHAT_ID')),
        'bot_token' => (string) env('NOTIFICATIONS_BALE_BOT_TOKEN', env('BALE_BOT_TOKEN', '')),
        'chat_id' => (string) env('NOTIFICATIONS_BALE_CHAT_ID', env('BALE_ADMIN_CHAT_ID', '')),
        'api_base' => rtrim((string) env('NOTIFICATIONS_BALE_API_BASE', env('BALE_API_BASE_URL', 'https://tapi.bale.ai')), '/'),
    ],

    'telegram' => [
        'enabled' => (bool) env('NOTIFICATIONS_TELEGRAM_ENABLED', env('TELEGRAM_BOT_TOKEN') && env('TELEGRAM_ADMIN_CHAT_ID')),
        'bot_token' => (string) env('NOTIFICATIONS_TELEGRAM_BOT_TOKEN', env('TELEGRAM_BOT_TOKEN', '')),
        'chat_id' => (string) env('NOTIFICATIONS_TELEGRAM_CHAT_ID', env('TELEGRAM_ADMIN_CHAT_ID', '')),
        'api_base' => rtrim((string) env('NOTIFICATIONS_TELEGRAM_API_BASE', env('TELEGRAM_API_BASE_URL', 'https://api.telegram.org')), '/'),
    ],
];
