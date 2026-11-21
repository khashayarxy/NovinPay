<?php

return [
    'test_otp_enabled' => (bool) env('CUSTOMER_TEST_OTP_ENABLED', false),
    'test_otp_phones' => explode(',', (string) env('CUSTOMER_TEST_OTP_PHONES', '')),
    'test_sms_phone' => (string) env('CUSTOMER_TEST_SMS_PHONE', ''),
    'test_reset_on_logout' => (bool) env('CUSTOMER_TEST_RESET_ON_LOGOUT', true),
    'sms_provider' => 'ippanel',
    'ippanel' => [
        'enabled' => (bool) env('IPPANEL_ENABLED', false),
        'api_key' => env('IPPANEL_API_KEY', ''),
        'pattern_code' => env('IPPANEL_PATTERN_CODE', ''),
        'parameter' => env('IPPANEL_PATTERN_PARAMETER', ''),
        'sender' => env('IPPANEL_SENDER', ''),
    ],
];
