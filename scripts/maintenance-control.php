<?php

use App\Support\SiteMaintenanceState;

// Emergency CLI only. No Composer, Laravel, environment file or database.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once dirname(__DIR__).'/app/Support/SiteMaintenanceState.php';
$root = dirname(__DIR__);
$state = new SiteMaintenanceState($root.'/storage/framework/site-maintenance');
try {
    $command = $argv[1] ?? 'status';
    if ($command === 'disable') {
        if (($argv[2] ?? '') !== '--confirm-disable' || count($argv) !== 3) {
            throw new RuntimeException('explicit_confirmation_required');
        }
        $state->change('', false, null, emergency: true);
    } elseif ($command !== 'status' || count($argv) > 2) {
        throw new RuntimeException('unknown_command');
    }
    $current = $state->read();
    echo 'ADMIN MAINTENANCE: ', $current['enabled'] ? 'ENABLED' : 'DISABLED', PHP_EOL;
    echo 'Version: ', $current['version'], PHP_EOL;
    if (is_file($root.'/storage/framework/down')) {
        echo "SEPARATE LARAVEL DEPLOYMENT MAINTENANCE REMAINS ACTIVE. No artisan up was run.\n";
    }
} catch (Throwable) {
    fwrite(STDERR, "MAINTENANCE CONTROL STOP: inspect private state/audit and filesystem access; do not delete markers blindly. No database or deployment maintenance was changed.\n");
    exit(1);
}
