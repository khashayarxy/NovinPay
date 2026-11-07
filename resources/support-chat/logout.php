<?php
declare(strict_types=1);
require_once __DIR__.'/support-loader.php';
require_once support_core_path('bootstrap.php');
try {
    support_request_method('POST');$operator=support_operator();support_csrf($operator);support_security_audit('logout');support_destroy_session('operator');
    header('Cache-Control: no-store');header('Location: /admin/support/confirm',true,303);throw new \App\Support\Chat\LegacyResponse(null, http_response_code() ?: 200);
} catch(Throwable $e) { support_error_response($e); }
