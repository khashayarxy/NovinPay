<?php

// Emergency endpoint: no autoloader, application bootstrap, session or database.
// Always returns 503, even while the normal application is online.
http_response_code(503);
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Retry-After: 60');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer');

require dirname(__DIR__).'/resources/maintenance/page.php';
