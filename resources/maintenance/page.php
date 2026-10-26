<?php
// No Laravel, Composer, Vite, sessions or database access.
$maintenanceRoot = __DIR__;
$business = require dirname(__DIR__, 2).'/config/business.php';
$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$media = static fn (string $file, string $type): string => 'data:'.$type.';base64,'.base64_encode(file_get_contents($maintenanceRoot.'/assets/'.$file));
$digits = static fn (string $value): string => strtr($value, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
$logo = 'data:image/svg+xml;base64,'.base64_encode(file_get_contents(dirname(__DIR__, 2).'/public/brand/lockups/logo-wordmark-black-on-white.svg'));
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#f8fafc">
<title>در حال تعمیرات و به‌روزرسانی سایت نوین پی هستیم | <?= $escape($business['name']) ?></title>
<style>
@font-face{font-family:MaintenanceVazir;src:url('<?= $media('Vazirmatn-Regular.woff2', 'font/woff2') ?>') format('woff2');font-style:normal;font-weight:400;font-display:swap}
@font-face{font-family:MaintenanceVazir;src:url('<?= $media('Vazirmatn-Bold.woff2', 'font/woff2') ?>') format('woff2');font-style:normal;font-weight:700;font-display:swap}
*,*::before,*::after{box-sizing:border-box}
html{min-height:100%;background:#f8fafc;color-scheme:light}
body{margin:0;min-width:0;min-height:100svh;color:#1e293b;font-family:MaintenanceVazir,Tahoma,sans-serif;background:#f8fafc;text-align:center}
.maintenance{width:min(100%,680px);min-height:100svh;margin-inline:auto;padding:24px 20px;display:flex;flex-direction:column;align-items:center;justify-content:center}
.brand{display:block;width:min(180px,55vw);height:auto}
.illustration{display:block;width:min(370px,80vw,36svh);height:auto;aspect-ratio:860/420;object-fit:contain;margin:26px 0 24px;border-radius:14px}
h1{max-width:620px;margin:0;color:#0f172a;font-size:clamp(22px,3.2vw,30px);font-weight:700;line-height:1.7;text-wrap:balance}
.message{margin:10px 0 0;font-size:16px;line-height:1.9;color:#475569}
.contact{margin-top:24px}
.contact p{margin:0;color:#475569;font-size:15px;line-height:1.9}
.phones{display:flex;justify-content:center;align-items:center;gap:4px 16px;flex-wrap:wrap;margin-top:6px}
.phones a{display:inline-flex;align-items:center;justify-content:center;min-height:44px;padding:4px 6px;border-radius:6px;color:#075da6;font-size:17px;font-weight:700;text-decoration:none}
.phones a:hover{text-decoration:underline;text-underline-offset:5px}
.phones a:focus-visible{outline:2px solid #075da6;outline-offset:3px}
@media(max-width:480px){.maintenance{padding:20px 18px}.brand{width:160px}.illustration{margin:22px 0 20px}.message{font-size:15px}.contact{margin-top:20px}.contact p{font-size:14px}.phones{gap:0 10px}.phones a{font-size:16px}}
@media(max-height:560px){.maintenance{padding-block:16px}.illustration{width:min(290px,65vw,32svh);margin:14px 0}.contact{margin-top:12px}.message{margin-top:6px}}
</style>
</head>
<body>
<main class="maintenance" aria-labelledby="maintenance-title">
<img class="brand" src="<?= $logo ?>" alt="<?= $escape($business['name']) ?>" width="1195" height="236">
<img class="illustration" src="<?= $media('pos-repair.webp', 'image/webp') ?>" width="860" height="420" alt="تصویر کارتخوان در کنار ابزار تعمیرات">
<h1 id="maintenance-title">در حال تعمیرات و به‌روزرسانی سایت نوین پی هستیم</h1>
<p class="message">از صبر و شکیبایی شما صمیمانه سپاسگزاریم.</p>
<section class="contact" aria-label="تماس با نوین‌پی">
<p>برای ارتباط با ما با شماره‌های زیر تماس بگیرید.</p>
<div class="phones">
<?php foreach ([$business['secondary_phone'], $business['phone']] as $phone) { ?>
<a href="tel:<?= $escape($phone) ?>" aria-label="تماس با <?= $escape($digits($phone)) ?>"><bdi dir="ltr"><?= $escape($digits($phone)) ?></bdi></a>
<?php } ?>
</div>
</section>
</main>
</body>
</html>
