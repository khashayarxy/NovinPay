<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    // Covers Laravel's pre-rendered response before Composer is loaded.
    if (is_file(__DIR__.'/../storage/framework/down')) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('X-Robots-Tag: noindex, nofollow');
        header('Retry-After: 60');
    }
    require $maintenance;

    // Laravel's pre-render stub delegates JSON requests back to the framework.
    // During dependency replacement, keep those requests unavailable as well.
    if (is_file(__DIR__.'/../storage/framework/down') && ! is_file(__DIR__.'/../vendor/autoload.php')) {
        require __DIR__.'/maintenance.php';
        exit;
    }
}

// Deployment maintenance above retains priority; this independent public-only
// control never grants a Laravel deployment bypass or changes its markers.
$siteMaintenanceResponse = require __DIR__.'/../resources/maintenance/guard.php';
if (is_array($siteMaintenanceResponse)) {
    http_response_code(503);
    foreach ($siteMaintenanceResponse['headers'] as $name => $value) {
        header($name.': '.$value);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
        echo $siteMaintenanceResponse['body'];
    }
    exit;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
