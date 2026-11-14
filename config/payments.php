<?php

return [
    'zibal' => [
        'enabled' => (bool) env('ZIBAL_ENABLED', false),
        // Separate release switch: development never authorizes real provider requests.
        'live_requests' => (bool) env('ZIBAL_LIVE_REQUESTS', false),
        'merchant' => env('ZIBAL_MERCHANT_ID', ''),
        'callback_url' => env('ZIBAL_CALLBACK_URL', ''),
        'api_base_url' => env('ZIBAL_API_BASE_URL', 'https://gateway.zibal.ir'),
        'min_rial' => 1001,
        'max_rial' => 499999999,
        // Local triage deadline, NOT an assertion that a gateway session expired.
        'review_after_minutes' => 30,
        'reconciliation_limit' => 8,
    ],
    'bank' => ['enabled' => (bool) env('BANK_TRANSFER_ENABLED', false), 'card_number' => env('NOVINPAY_BANK_CARD', ''), 'holder' => env('NOVINPAY_BANK_HOLDER', '')],
];
