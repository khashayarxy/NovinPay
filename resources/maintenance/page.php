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
<meta name="theme-color" content="#ffffff">
<title>در حال تعمیرات و به‌روزرسانی سایت نوین‌پی هستیم | <?= $escape($business['name']) ?></title>
<style>
@font-face{font-family:MaintenanceVazir;src:url('<?= $media('Vazirmatn-Regular.woff2', 'font/woff2') ?>') format('woff2');font-style:normal;font-weight:400;font-display:swap}
@font-face{font-family:MaintenanceVazir;src:url('<?= $media('Vazirmatn-Bold.woff2', 'font/woff2') ?>') format('woff2');font-style:normal;font-weight:700;font-display:swap}
*,*::before,*::after{box-sizing:border-box}
html{min-height:100%;background:#fff;color-scheme:light}
body{margin:0;min-width:0;min-height:100svh;color:#516784;font-family:MaintenanceVazir,Tahoma,sans-serif;background:#fff;text-align:center}
.maintenance{position:relative;isolation:isolate;overflow:hidden;width:100%;min-height:100svh;padding:clamp(20px,3vh,38px) 20px;display:flex;flex-direction:column;align-items:center;justify-content:center}
.maintenance::before,.maintenance::after{content:"";position:absolute;z-index:-2;pointer-events:none;background:#eef7ff;border-radius:50%}
.maintenance::before{width:1000px;height:330px;left:-580px;bottom:-150px;transform:rotate(29deg)}
.maintenance::after{width:1000px;height:330px;right:-615px;bottom:-125px;transform:rotate(-39deg)}
.brand{display:block;width:clamp(155px,14vw,220px);height:auto;flex:none}
.hero{position:relative;isolation:isolate;display:grid;place-items:center;width:min(720px,89vw,62svh);margin:clamp(8px,1.5vh,18px) 0 0;flex:none}
.hero::before{content:"";position:absolute;z-index:-1;inset:17% 9% 10%;border-radius:50%;background:radial-gradient(ellipse,#daefff 4%,#eef8ff 58%,transparent 75%)}
.illustration{display:block;width:100%;height:auto;aspect-ratio:1100/733;object-fit:contain}
h1{max-width:800px;margin:0;color:#071a3b;font-size:clamp(23px,3vw,42px);font-weight:700;line-height:1.65;text-wrap:balance}
h1 span{color:#098cf7}
.message{margin:8px 0 0;font-size:clamp(15px,1.35vw,19px);line-height:1.8;color:#526784}
.contact{margin-top:clamp(12px,2vh,22px)}
.contact::before{content:"";display:block;width:34px;height:3px;margin:0 auto 16px;border-radius:3px;background:#098cf7}
.contact p{margin:0;color:#526784;font-size:clamp(14px,1.2vw,17px);line-height:1.8}
.phones{display:flex;justify-content:center;align-items:center;gap:10px 14px;flex-wrap:wrap;margin-top:14px}
.phones a{display:inline-flex;align-items:center;justify-content:center;min-width:195px;min-height:48px;padding:7px 16px;gap:12px;border:1px solid #c8e4ff;border-radius:16px;color:#0674cf;background:#f3f9ff;font-size:17px;font-weight:700;text-decoration:none}
.phones svg{width:19px;height:19px;flex:none;fill:currentColor}
.phones a:hover{background:#e7f4ff;border-color:#86caff}
.phones a:focus-visible{outline:2px solid #075da6;outline-offset:3px}
@media(max-width:480px){.maintenance{padding:20px 16px}.brand{width:155px}.hero{width:min(94vw,48svh);margin-top:8px}h1{font-size:23px;line-height:1.65}.message{margin-top:7px}.contact{margin-top:12px}.contact::before{margin-bottom:10px}.phones{gap:8px;margin-top:10px}.phones a{min-width:0;flex:1 1 145px;padding:7px 9px;gap:7px;font-size:15px}}
@media(max-height:560px){.maintenance{padding-block:14px}.hero{width:min(370px,60vw,43svh);margin-top:4px}.contact{margin-top:6px}.contact::before{margin-bottom:6px}.phones{margin-top:6px}.message{margin-top:4px}}
</style>
</head>
<body>
<main class="maintenance" aria-labelledby="maintenance-title">
<img class="brand" src="<?= $logo ?>" alt="<?= $escape($business['name']) ?>" width="1195" height="236">
<div class="hero"><img class="illustration" src="<?= $media('pos-repair-transparent.webp', 'image/webp') ?>" width="1100" height="733" alt="تصویر کارتخوان در کنار ابزار تعمیرات"></div>
<h1 id="maintenance-title">در حال تعمیرات و به‌روزرسانی سایت <span>نوین‌پی</span> هستیم</h1>
<p class="message">از صبر و شکیبایی شما صمیمانه سپاسگزاریم.</p>
<section class="contact" aria-label="تماس با نوین‌پی">
<p>برای ارتباط با ما با شماره‌های زیر تماس بگیرید.</p>
<div class="phones">
<?php foreach ([$business['secondary_phone'], $business['phone']] as $phone) { ?>
<a href="tel:<?= $escape($phone) ?>" aria-label="تماس با <?= $escape($digits($phone)) ?>"><bdi dir="ltr"><?= $escape($digits($phone)) ?></bdi><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M6.62 10.79a15.05 15.05 0 0 0 6.59 6.59l2.2-2.2a1 1 0 0 1 1.02-.24c1.12.37 2.33.56 3.57.56a1 1 0 0 1 1 1V20a1 1 0 0 1-1 1C10.61 21 3 13.39 3 4a1 1 0 0 1 1-1h3.5a1 1 0 0 1 1 1c0 1.24.19 2.45.56 3.57a1 1 0 0 1-.25 1.02z"/></svg></a>
<?php } ?>
</div>
</section>
</main>
</body>
</html>
