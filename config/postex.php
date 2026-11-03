<?php

return [
    'enabled' => (bool) env('POSTEX_QUOTES_ENABLED', false),
    'api_key' => env('POSTEX_API_KEY', ''),
    'base_url' => env('POSTEX_BASE_URL', 'https://api.postex.ir'),
    'cache_store' => env('POSTEX_CATALOG_CACHE_STORE', 'file'),
    // Local validity limit; Postex's response supplies no contractual expiry.
    'quote_ttl_seconds' => 300,
    // A Postex contract quote is NOT proof of the independent post-office tariff.
    'independent_postage_verified' => (bool) env('POSTEX_INDEPENDENT_POSTAGE_VERIFIED', false),
    'tariff_evidence' => env('POSTEX_TARIFF_EVIDENCE', ''),
];
