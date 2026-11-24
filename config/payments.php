<?php

return [
    'zibal' => [
        'enabled' => (bool) env('ZIBAL_ENABLED', false),
        // Separate release switch: development never authorizes real provider requests.
        'live_requests' => (bool) env('ZIBAL_LIVE_REQUESTS', false),
        // Sandbox mode is only honored in local/testing environments and never on production.
        'sandbox' => (bool) env('ZIBAL_SANDBOX', false),
        'merchant' => env('ZIBAL_MERCHANT_ID', env('ZIBAL_MERCHANT', '')),
        'callback_url' => env('ZIBAL_CALLBACK_URL', ''),
        'api_base_url' => env('ZIBAL_API_BASE_URL', 'https://gateway.zibal.ir'),
        'min_rial' => 1000,
        // Single-transaction Shaparak/Zibal ceiling (100,000,000 Toman = 1,000,000,000 IRR)
        // so high-value POS terminal orders are never rejected.
        'max_rial' => 1000000000,
        // Local triage deadline, NOT an assertion that a gateway session expired.
        'review_after_minutes' => 30,
        'reconciliation_limit' => 8,
    ],
    'connectivity' => [
        'ranges_path' => resource_path('payments/country-ranges.json'),
        // Exact IPs of verified ingress peers only, never a wildcard or network range.
        'trusted_proxies' => explode(',', (string) env('PAYMENTS_TRUSTED_PROXY_IPS', '')),
    ],
    'bank' => ['enabled' => (bool) env('BANK_TRANSFER_ENABLED', false), 'card_number' => env('NOVINPAY_BANK_CARD', ''), 'holder' => env('NOVINPAY_BANK_HOLDER', '')],
    'test_product' => [
        'slug' => trim((string) env('PAYMENT_TEST_PRODUCT_SLUG', 'zibal-gateway-test-5000')),
        'allowed_phones' => explode(',', (string) env('PAYMENT_TEST_ALLOWED_PHONES', '')),
    ],
];
