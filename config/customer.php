<?php

return [
    'sms_enabled' => (bool) env('SMSIR_ENABLED', false),
    'sms_api_key' => env('SMSIR_API_KEY', ''),
    'otp_template' => env('SMSIR_OTP_TEMPLATE_ID'),
    // Copy the exact name from the approved template; never guess its case.
    'otp_parameter' => env('SMSIR_OTP_PARAMETER', ''),
    'approved_template_ids' => array_values(array_filter(array_map(static function ($value): int {
        $value = trim($value);

        return ctype_digit($value) && (int) $value > 0 && (int) $value <= 2147483647 ? (int) $value : 0;
    }, explode(',', (string) env('SMSIR_APPROVED_TEMPLATE_IDS', ''))))),
    'notifications_enabled' => (bool) env('SMSIR_NOTIFICATIONS_ENABLED', false),
    'notification_parameter' => env('SMSIR_NOTIFICATION_PARAMETER', ''),
    'notification_templates' => [
        'repair_registered' => env('SMSIR_TEMPLATE_REPAIR_REGISTERED'),
        'device_received' => env('SMSIR_TEMPLATE_DEVICE_RECEIVED'),
        'quotation_available' => env('SMSIR_TEMPLATE_QUOTATION_AVAILABLE'),
        'repair_completed' => env('SMSIR_TEMPLATE_REPAIR_COMPLETED'),
        'payment_confirmed' => env('SMSIR_TEMPLATE_PAYMENT_CONFIRMED'),
        'pickup_ready' => env('SMSIR_TEMPLATE_PICKUP_READY'),
        'device_shipped' => env('SMSIR_TEMPLATE_DEVICE_SHIPPED'),
    ],
];
