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
