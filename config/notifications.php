<?php

use App\Services\Notifications\PatternSms;

$patternSmsPatterns = [];
$patternSmsEnabled = env('NOTIFICATIONS_PATTERN_SMS_ENABLED');
$panelChiEnabled = env('NOTIFICATIONS_PANELCHI_ENABLED', env('PANELCHI_ENABLED'));
foreach (PatternSms::PATTERNS as $event => $pattern) {
    $suffix = strtoupper($event);
    $patternSmsPatterns[$event] = trim((string) env('NOTIFICATIONS_SMS_PATTERN_'.$suffix))
        ?: trim((string) env('NOTIFICATIONS_IPPANEL_PATTERN_'.$suffix))
        ?: trim((string) env('NOTIFICATIONS_PANELCHI_PATTERN_'.$suffix))
        ?: $pattern;
}

$adminPhonesRaw = trim((string) env('NOTIFICATIONS_ADMIN_PHONES', ''));
$adminEmailsRaw = trim((string) env('NOTIFICATIONS_ADMIN_EMAILS', ''));
$smsEnabledEnv = env('NOTIFICATIONS_SMS_ENABLED');
$emailEnabledEnv = env('NOTIFICATIONS_EMAIL_ENABLED');

return [
    'admin' => [
        'phones' => array_values(array_filter(array_map('trim', explode(',', $adminPhonesRaw)))),
        'emails' => array_values(array_filter(array_map('trim', explode(',', $adminEmailsRaw)))),
    ],

    'sms' => [
        'enabled' => in_array($smsEnabledEnv, [null, ''], true)
            ? ($adminPhonesRaw !== '')
            : filter_var($smsEnabledEnv, FILTER_VALIDATE_BOOL),
        'admin_shared_pattern_fallback' => filter_var(env('NOTIFICATIONS_ADMIN_SHARED_PATTERN_FALLBACK', true), FILTER_VALIDATE_BOOL),
        'patterns' => [
            'admin_order_placed' => trim((string) env('NOTIFICATIONS_SMS_PATTERN_ADMIN_ORDER', env('NOTIFICATIONS_IPPANEL_PATTERN_ADMIN_ORDER', ''))),
            'admin_order_paid' => trim((string) env('NOTIFICATIONS_SMS_PATTERN_ADMIN_ORDER_PAID', env('NOTIFICATIONS_IPPANEL_PATTERN_ADMIN_ORDER_PAID', ''))),
            'admin_repair_submitted' => trim((string) env('NOTIFICATIONS_SMS_PATTERN_ADMIN_REPAIR', env('NOTIFICATIONS_IPPANEL_PATTERN_ADMIN_REPAIR', ''))),
            'admin_consultation_submitted' => trim((string) env('NOTIFICATIONS_SMS_PATTERN_ADMIN_CONSULTATION', env('NOTIFICATIONS_IPPANEL_PATTERN_ADMIN_CONSULTATION', ''))),
            'admin_chat_first_message' => trim((string) env('NOTIFICATIONS_SMS_PATTERN_ADMIN_CHAT_FIRST', env('NOTIFICATIONS_IPPANEL_PATTERN_ADMIN_CHAT_FIRST', ''))),
            'customer_order_placed' => trim((string) env('NOTIFICATIONS_SMS_PATTERN_CUSTOMER_ORDER_PLACED', env('NOTIFICATIONS_IPPANEL_PATTERN_CUSTOMER_ORDER_PLACED', ''))),
            'customer_order_shipped' => trim((string) env('NOTIFICATIONS_SMS_PATTERN_CUSTOMER_ORDER_SHIPPED', env('NOTIFICATIONS_IPPANEL_PATTERN_CUSTOMER_ORDER_SHIPPED', ''))),
            'customer_repair_submitted' => trim((string) env('NOTIFICATIONS_SMS_PATTERN_CUSTOMER_REPAIR_SUBMITTED', env('NOTIFICATIONS_IPPANEL_PATTERN_CUSTOMER_REPAIR_SUBMITTED', ''))),
            'customer_repair_status' => trim((string) env('NOTIFICATIONS_SMS_PATTERN_CUSTOMER_REPAIR_STATUS', env('NOTIFICATIONS_IPPANEL_PATTERN_CUSTOMER_REPAIR_STATUS', ''))),
            'customer_consultation_submitted' => trim((string) (env('IPPANEL_PATTERN_CONSULTATION_SUBMITTED') ?: env('NOTIFICATIONS_IPPANEL_PATTERN_CONSULTATION_SUBMITTED') ?: env('NOTIFICATIONS_SMS_PATTERN_CONSULTATION_SUBMITTED') ?: env('NOTIFICATIONS_IPPANEL_PATTERN_CUSTOMER_CONSULTATION') ?: env('NOTIFICATIONS_SMS_PATTERN_CUSTOMER_CONSULTATION', ''))),
            'documents_revision' => trim((string) (env('IPPANEL_PATTERN_DOCUMENTS_REVISION') ?: env('NOTIFICATIONS_IPPANEL_PATTERN_DOCUMENTS_REVISION') ?: env('NOTIFICATIONS_SMS_PATTERN_DOCUMENTS_REVISION', ''))),
            'documents_approved' => trim((string) (env('IPPANEL_PATTERN_DOCUMENTS_APPROVED') ?: env('NOTIFICATIONS_IPPANEL_PATTERN_DOCUMENTS_APPROVED') ?: env('NOTIFICATIONS_SMS_PATTERN_DOCUMENTS_APPROVED', ''))),
            'consultation_submitted' => trim((string) (env('IPPANEL_PATTERN_CONSULTATION_SUBMITTED') ?: env('NOTIFICATIONS_IPPANEL_PATTERN_CONSULTATION_SUBMITTED') ?: env('NOTIFICATIONS_SMS_PATTERN_CONSULTATION_SUBMITTED') ?: env('NOTIFICATIONS_IPPANEL_PATTERN_CUSTOMER_CONSULTATION') ?: env('NOTIFICATIONS_SMS_PATTERN_CUSTOMER_CONSULTATION', ''))),
        ],
    ],

    'pattern_sms' => [
        'provider' => env('NOTIFICATIONS_PATTERN_SMS_PROVIDER') ?: env('SMS_PROVIDER') ?: 'ippanel',
        'enabled' => in_array($patternSmsEnabled, [null, ''], true) ? null : filter_var($patternSmsEnabled, FILTER_VALIDATE_BOOL),
        'patterns' => $patternSmsPatterns,
    ],

    'panelchi' => [
        'enabled' => $panelChiEnabled === null ? null : (bool) $panelChiEnabled,
        'api_key' => trim((string) env('NOTIFICATIONS_PANELCHI_API_KEY')) ?: trim((string) env('PANELCHI_API_KEY', '')),
        'sender' => trim((string) env('NOTIFICATIONS_PANELCHI_SENDER'))
            ?: trim((string) env('PANELCHI_SOURCE_NUMBER', env('PANELCHI_SENDER', ''))),
    ],

    'email' => [
        'enabled' => in_array($emailEnabledEnv, [null, ''], true)
            ? ($adminEmailsRaw !== '' || in_array(strtolower(trim((string) env('MAIL_MAILER', ''))), ['smtp', 'resend', 'failover'], true))
            : filter_var($emailEnabledEnv, FILTER_VALIDATE_BOOL),
    ],

    'bale' => [
        'enabled' => filter_var(env('NOTIFICATIONS_BALE_ENABLED', (bool) (env('BALE_BOT_TOKEN') && env('BALE_ADMIN_CHAT_ID'))), FILTER_VALIDATE_BOOL),
        'bot_token' => (string) env('NOTIFICATIONS_BALE_BOT_TOKEN', env('BALE_BOT_TOKEN', '')),
        'chat_id' => (string) env('NOTIFICATIONS_BALE_CHAT_ID', env('BALE_ADMIN_CHAT_ID', '')),
        'api_base' => rtrim((string) env('NOTIFICATIONS_BALE_API_BASE', env('BALE_API_BASE_URL', 'https://tapi.bale.ai')), '/'),
    ],

    'telegram' => [
        'enabled' => filter_var(env('NOTIFICATIONS_TELEGRAM_ENABLED', (bool) (env('TELEGRAM_BOT_TOKEN') && env('TELEGRAM_ADMIN_CHAT_ID'))), FILTER_VALIDATE_BOOL),
        'bot_token' => (string) env('NOTIFICATIONS_TELEGRAM_BOT_TOKEN', env('TELEGRAM_BOT_TOKEN', '')),
        'chat_id' => (string) env('NOTIFICATIONS_TELEGRAM_CHAT_ID', env('TELEGRAM_ADMIN_CHAT_ID', '')),
        'api_base' => rtrim((string) env('NOTIFICATIONS_TELEGRAM_API_BASE', env('TELEGRAM_API_BASE_URL', 'https://api.telegram.org')), '/'),
    ],
];
