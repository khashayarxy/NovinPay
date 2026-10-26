<?php

use App\Support\MaintenanceStateFault;
use App\Support\SiteMaintenanceState;

require_once dirname(__DIR__, 2).'/app/Support/SiteMaintenanceState.php';

$siteRoot = dirname(__DIR__, 2);
if (SiteMaintenanceState::adminCorridor($_SERVER['REQUEST_URI'] ?? '/')) {
    return null;
}
$siteState = new SiteMaintenanceState($siteRoot.'/storage/framework/site-maintenance');
try {
    $siteData = $siteState->read();
} catch (MaintenanceStateFault) {
    // Corrupt/unreadable control state fails closed; admin recovery still boots.
    $siteData = ['enabled' => true];
}
if (! $siteData['enabled']) {
    return null;
}
$accept = strtolower(trim(explode(',', $_SERVER['HTTP_ACCEPT'] ?? '')[0]));
$siteJson = str_contains($accept, '/json') || str_contains($accept, '+json')
    || (strcasecmp($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '', 'XMLHttpRequest') === 0 && in_array($accept, ['', '*/*'], true));

return SiteMaintenanceState::response($siteData, $siteRoot, $siteJson);
