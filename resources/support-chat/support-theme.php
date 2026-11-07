<?php
declare(strict_types=1);
require_once __DIR__.'/support-loader.php';
require_once support_core_path('bootstrap.php');
header('Content-Type: application/javascript; charset=utf-8');header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header('Cross-Origin-Resource-Policy: same-origin');header('Referrer-Policy: no-referrer');
try{$config=support_widget_config();echo 'window.SupportWidgetConfig='.json_encode($config,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR).';window.SupportTheme?.configure(window.SupportWidgetConfig);';}
catch(Throwable $e){http_response_code(503);echo '/* Public presentation configuration unavailable; safe theme default retained. */';}
