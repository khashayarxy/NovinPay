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
    $criticalShell=explode('<!--SUPPORT_BOOT_BODY-->',(string)file_get_contents(__DIR__.'/support-client/critical-shell.html'),2);
    header("Content-Security-Policy: default-src 'self'; frame-ancestors 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' blob:; connect-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'");
?><!doctype html><html lang="fa" dir="rtl" data-support-surface="customer-v2"><head><meta charset="utf-8"><?= $criticalShell[0] ?><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><link rel="modulepreload" href="support-client/customer-v2.js?v=team1"><link rel="modulepreload" href="support-client/customer.js?v=team1"><link rel="modulepreload" href="support-client/engine.js?v=team1"><link rel="modulepreload" href="support-client/network.js?v=team1"><link rel="modulepreload" href="support-client/outbox.js?v=team1"><script defer src="support-client/theme-init.js?v=0.1.0-r14"></script><title>پشتیبانی</title><link rel="stylesheet" href="assets/fonts/font-face.css?v=0.1.0-r14" media="print" fetchpriority="high" onload="this.media='all';this.dataset.visualState='ready';dispatchEvent(new Event('support-style-ready'));" onerror="this.dataset.visualState='error';dispatchEvent(new Event('support-style-ready'));"><link rel="stylesheet" href="support-client/customer-v2.css?v=team1" data-support-visual-sheet="" media="print" fetchpriority="high" onload="this.media='all';this.dataset.visualState='ready';dispatchEvent(new Event('support-style-ready'));" onerror="this.dataset.visualState='error';dispatchEvent(new Event('support-style-ready'));"><link rel="stylesheet" href="support-client/bplus.css?v=sidebar1" data-support-visual-sheet="" media="print" fetchpriority="high" onload="this.media='all';this.dataset.visualState='ready';dispatchEvent(new Event('support-style-ready'));" onerror="this.dataset.visualState='error';dispatchEvent(new Event('support-style-ready'));"><link rel="stylesheet" href="support-client/interaction.css?v=team1" data-support-visual-sheet="" media="print" fetchpriority="high" onload="this.media='all';this.dataset.visualState='ready';dispatchEvent(new Event('support-style-ready'));" onerror="this.dataset.visualState='error';dispatchEvent(new Event('support-style-ready'));"><script src="support-client/icons.js?v=permissions1" defer></script><script src="support-client/compat-bootstrap.js?v=compat1"></script></head><body><?= $criticalShell[1] ?>
<?php echo str_replace(['class="chat-window"','aria-live="polite" hidden><span data-init-text','class="chat-send"'],['class="chat-window active" aria-busy="true" data-init-state="initializing"','aria-live="polite"><span data-init-text','class="chat-send" disabled'],(string)file_get_contents(__DIR__.'/support-client/widget.html')); ?>
<script type="module">window.closeChat=()=>{if(parent!==window)parent.postMessage('support-widget-close',location.origin);else document.getElementById('chatWindow').classList.remove('active');};const fail=()=>{const shell=document.getElementById('chatWindow'),notice=document.getElementById('supportInit');window.closeChat=()=>{if(parent!==window)parent.postMessage('support-widget-close',location.origin);else shell.classList.remove('active');};shell.classList.add('active');shell.dataset.initState='failed';shell.setAttribute('aria-busy','false');shell.querySelector('.chat-send').disabled=true;document.getElementById('chatInput').disabled=true;notice.hidden=false;notice.querySelector('[data-init-text]').textContent='بارگذاری چت انجام نشد.';const retry=notice.querySelector('[data-init-retry]');retry.hidden=false;retry.onclick=()=>location.reload();};const timer=setTimeout(()=>{if(!window.SupportBootStarted)fail();},12000);import('./support-client/customer-v2.js?v=team1').then(()=>clearTimeout(timer)).catch(()=>{clearTimeout(timer);fail();});</script></body></html><?php
    throw new \App\Support\Chat\LegacyResponse(null, http_response_code() ?: 200);
}

header("Content-Security-Policy: default-src 'self'; frame-ancestors 'self'; script-src 'self' 'unsafe-inline'; style-src 'self'; img-src 'self' blob:; connect-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'");
?><!doctype html><html lang="fa" dir="rtl" data-support-surface="customer"><head><script src="support-client/theme-init.js?v=0.1.0-r14"></script><script src="support-theme.php"></script><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>پشتیبانی</title><link rel="stylesheet" href="support-client/widget.css"><link rel="stylesheet" href="support-client/chat.css?v=0.1.0-r14"><link rel="stylesheet" href="support-client/design-tokens.css?v=icons2"><link rel="stylesheet" href="support-client/customer-layout.css?v=0.1.0-r14"><script src="support-client/icons.js?v=permissions1" defer></script></head><body>
<?php readfile(__DIR__.'/support-client/widget.html'); ?>
<script type="module" src="support-client/widget.js?v=ux1"></script></body></html>
