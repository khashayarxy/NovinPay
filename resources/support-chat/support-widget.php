<?php
declare(strict_types=1);
require_once __DIR__.'/support-loader.php';
require_once support_core_path('configuration.php');
$v2Enabled = support_customer_v2_enabled();
$legacyRequested = ($_GET['legacy'] ?? '') === '1';

if (support_development_ui() && getenv('SUPPORT_LOCAL_V2_ENABLED') === '1' && getenv('SUPPORT_CUSTOMER_V2_ENABLED') !== '1' && !$legacyRequested && is_file(__DIR__.'/support-v2-preview.php')) {
    require __DIR__.'/support-v2-preview.php';
    throw new \App\Support\Chat\LegacyResponse(null, http_response_code() ?: 200);
}

header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

if ($v2Enabled && (!$legacyRequested || !support_development_ui())) {
    $config=support_widget_config();
    $criticalTheme=(string)file_get_contents(__DIR__.'/support-client/critical-shell.html');
    $shell=str_replace('{{support-name}}',htmlspecialchars($config['name'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'),(string)file_get_contents(__DIR__.'/support-client/widget-v2.html'));
    header("Content-Security-Policy: default-src 'self'; frame-ancestors 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' blob:; connect-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'");
?><!doctype html>
<html lang="fa" dir="rtl" data-support-surface="customer-v2">
<head>
<meta charset="utf-8"><?= $criticalTheme ?>
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>پشتیبانی</title>
<?php foreach (['customer-v2','customer','engine','network','outbox'] as $module): ?>
<link rel="modulepreload" href="support-client/<?= $module ?>.js?v=team1">
<?php endforeach; ?>
<link rel="stylesheet" href="assets/fonts/font-face.css?v=0.1.0-r14">
<?php foreach (['customer-v2','bplus','interaction'] as $style): ?>
<link rel="stylesheet" href="support-client/<?= $style ?>.css?v=team1" data-support-visual-sheet onerror="this.dataset.visualState='error'">
<?php endforeach; ?>
<script defer src="support-client/theme-init.js?v=0.1.0-r14"></script>
</head>
<body>
<?= $shell ?>
<script type="application/json" id="supportWidgetConfig"><?= json_encode($config,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR) ?></script>
<script>
window.closeChat=function(){if(parent!==window)parent.postMessage('support-widget-close',location.origin);else document.getElementById('chatWindow').classList.remove('active');};
window.supportShellFailure=function(){
 const chat=document.getElementById('chatWindow'),notice=document.getElementById('supportInit'),retry=notice.querySelector('[data-init-retry]');
 chat.dataset.initState='failed';chat.setAttribute('aria-busy','false');notice.hidden=false;
 notice.querySelector('[data-init-text]').textContent='بارگذاری گفتگو انجام نشد.';
 retry.hidden=false;retry.onclick=function(){location.reload();};
};
setTimeout(function(){if(!window.SupportBootStarted)window.supportShellFailure();},12000);
</script>
<script type="module">
if(document.querySelector('[data-support-visual-sheet][data-visual-state="error"]'))window.supportShellFailure();
else import('./support-client/customer-v2.js?v=team1').catch(window.supportShellFailure);
</script>
</body></html><?php
    throw new \App\Support\Chat\LegacyResponse(null, http_response_code() ?: 200);
}

header("Content-Security-Policy: default-src 'self'; frame-ancestors 'self'; script-src 'self' 'unsafe-inline'; style-src 'self'; img-src 'self' blob:; connect-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'");
?><!doctype html><html lang="fa" dir="rtl" data-support-surface="customer"><head><script src="support-client/theme-init.js?v=0.1.0-r14"></script><script src="support-theme.php"></script><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>پشتیبانی</title><link rel="stylesheet" href="support-client/widget.css"><link rel="stylesheet" href="support-client/chat.css?v=0.1.0-r14"><link rel="stylesheet" href="support-client/design-tokens.css?v=icons2"><link rel="stylesheet" href="support-client/customer-layout.css?v=0.1.0-r14"><script type="module" src="support-client/icons.js?v=permissions1" defer></script></head><body>
<?php readfile(__DIR__.'/support-client/widget.html'); ?>
<script type="module" src="support-client/widget.js?v=ux1"></script></body></html>
