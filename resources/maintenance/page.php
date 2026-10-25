<?php
// No Laravel, Composer, Vite, sessions or database access.
$maintenanceRoot = __DIR__;
$business = require dirname(__DIR__, 2).'/config/business.php';
$escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$media = static fn (string $file, string $type): string => 'data:'.$type.';base64,'.base64_encode(file_get_contents($maintenanceRoot.'/assets/'.$file));
$digits = static fn (string $value): string => strtr($value, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
$logo = 'data:image/svg+xml;base64,'.base64_encode(file_get_contents(dirname(__DIR__, 2).'/public/brand/lockups/logo-wordmark-white-on-black.svg'));
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#073579">
<title>سایت موقتاً در دسترس نیست | <?= $escape($business['name']) ?></title>
<style>
@font-face{font-family:MaintenanceVazir;src:url('<?= $media('Vazirmatn-Regular.woff2', 'font/woff2') ?>') format('woff2');font-style:normal;font-weight:400;font-display:swap}
@font-face{font-family:MaintenanceVazir;src:url('<?= $media('Vazirmatn-Bold.woff2', 'font/woff2') ?>') format('woff2');font-style:normal;font-weight:700;font-display:swap}
*,*::before,*::after{box-sizing:border-box}
html{min-height:100%;background:#073579;color-scheme:dark}
body{margin:0;min-width:0;min-height:100svh;color:#fff;font-family:MaintenanceVazir,Tahoma,sans-serif;background:radial-gradient(ellipse at 91% 0%,#166de0 0%,transparent 57%),radial-gradient(ellipse at 48% 28%,#1253b5 0%,transparent 55%),linear-gradient(120deg,#063b8a,#022661);text-align:center}
.maintenance{width:min(100%,1100px);margin-inline:auto;padding:36px 24px 28px;min-height:100svh;display:flex;flex-direction:column;align-items:center;justify-content:center}
.brand-frame{position:relative;isolation:isolate;flex:none;width:260px;max-width:70%}
.brand-frame::before{content:"";position:absolute;inset:-10px -18px;z-index:-1;border-radius:16px;background:rgba(2,38,97,.5);border:1px solid rgba(255,255,255,.16)}
.brand{width:100%;height:auto;display:block}
.subtitle{margin:12px 0 0;color:#e0edff;font-size:16px;line-height:1.8}
.illustration{display:block;width:min(840px,100%);height:auto;aspect-ratio:860/420;object-fit:contain;margin:10px 0 12px;mask-image:linear-gradient(to right,transparent,#000 8%,#000 92%,transparent),linear-gradient(to bottom,transparent,#000 12%,#000 95%,transparent);mask-composite:intersect}
h1{font-size:46px;font-weight:700;line-height:1.5;margin:0;text-wrap:balance}
.message{font-size:23px;line-height:1.9;color:#b6d4fa;margin:12px 0 0;max-width:700px;text-wrap:pretty}
.features{list-style:none;padding:0;margin:24px 0 26px;width:900px;max-width:100%;display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:44px}
.feature{display:flex;flex-direction:row-reverse;align-items:center;justify-content:center;gap:20px;text-align:right}
.feature img{width:74px;height:74px;flex:none;mask-image:radial-gradient(circle,#000 66%,transparent 72%)}
.feature h2{font-size:21px;line-height:1.8;white-space:nowrap;margin:0;font-weight:700}
.feature p{color:#b6d4fa;font-size:16px;line-height:1.8;margin:2px 0 0;white-space:nowrap}
.contact{border-top:1px solid #4385c866;width:790px;max-width:100%;padding-top:24px}
.contact p{font-size:17px;line-height:1.9;color:#b6d4fa;margin:0 0 12px}
.phones{display:flex;justify-content:center;align-items:center;gap:28px;flex-wrap:wrap}
.phones a{color:#e6f2ff;display:inline-flex;align-items:center;justify-content:center;min-height:44px;gap:10px;padding:3px 8px;text-decoration:none;font-size:22px;border-radius:6px}
.phones a+a{border-right:1px solid #4385c866;padding-right:28px}
.phone-icon{width:25px;height:28px;object-fit:contain;flex:none;mask-image:radial-gradient(ellipse,#000 55%,transparent 80%)}
a:hover{text-decoration:underline;text-underline-offset:5px}a:focus-visible{outline:3px solid #d5edff;outline-offset:4px}
.message-line{display:block}
@media(min-width:801px) and (max-height:980px){.illustration{width:min(800px,100%)}h1{font-size:44px}.message{font-size:22px;line-height:1.8}}
@media(max-width:800px){.message-line{display:inline}.maintenance{padding:32px 22px}.brand-frame{width:240px}.subtitle{font-size:15px}.illustration{margin:14px 0 12px}h1{font-size:36px}.message{font-size:19px;max-width:620px}.features{gap:16px;margin:28px 0}.feature{flex-direction:column;gap:8px;text-align:center;justify-content:flex-start}.feature img{width:60px;height:60px}.feature h2{font-size:17px}.feature p{font-size:13px;white-space:normal}.contact p{font-size:15px}.phones a{font-size:20px}}
@media(max-width:480px){.maintenance{padding:28px 16px 26px;justify-content:flex-start}.brand-frame{width:210px}.subtitle{font-size:12px;margin-top:12px}.illustration{width:calc(100% + 20px);max-width:none;flex:none;margin:18px 0 18px}h1{font-size:30px;line-height:1.7;max-width:350px}.message{font-size:16px;line-height:1.95;margin-top:12px}.features{gap:8px;margin:28px 0 26px}.feature img{width:50px;height:50px}.feature h2{font-size:14px;line-height:1.9}.feature p{font-size:12px;line-height:1.8;max-width:100px;margin-inline:auto}.contact{padding-top:20px}.contact p{font-size:13px;line-height:2;margin-bottom:12px}.phones{gap:4px 12px}.phones a{font-size:17px;padding:2px 4px;gap:6px}.phones a+a{border:0;padding-right:4px}.phone-icon{width:20px;height:24px}}
</style>
</head>
<body>
<main class="maintenance" aria-labelledby="maintenance-title">
<div class="brand-frame"><img class="brand" src="<?= $logo ?>" alt="<?= $escape($business['name']) ?>" width="1195" height="236"></div>
<p class="subtitle">فروش و تعمیر دستگاه‌های کارتخوان در شهریار</p>
<img class="illustration" src="<?= $media('pos-repair.webp', 'image/webp') ?>" width="860" height="420" alt="تصویر کارتخوان نوین‌پی در کنار آچار، جعبه‌ابزار، چرخ‌دنده و مخروط تعمیرات">
<h1 id="maintenance-title">سایت موقتاً در دسترس نیست</h1>
<p class="message"><span class="message-line">در حال انجام به‌روزرسانی و بهبود خدمات هستیم تا تجربه‌ای سریع‌تر</span> و مطمئن‌تر برای شما فراهم کنیم.</p>
<ul class="features" aria-label="هدف به‌روزرسانی">
<li class="feature"><img src="<?= $media('service.webp', 'image/webp') ?>" width="74" height="74" alt=""><div><h2>خدمات بهتر</h2></div></li>
<li class="feature"><img src="<?= $media('security.webp', 'image/webp') ?>" width="74" height="74" alt=""><div><h2>افزایش امنیت</h2></div></li>
<li class="feature"><img src="<?= $media('performance.webp', 'image/webp') ?>" width="74" height="74" alt=""><div><h2>بهبود عملکرد</h2></div></li>
</ul>
<section class="contact" aria-label="تماس با نوین‌پی">
<p>در صورت نیاز با ما در تماس باشید. از صبر و همراهی شما سپاسگزاریم.</p>
<div class="phones">
<?php foreach ([$business['secondary_phone'], $business['phone']] as $phone) { ?>
<a href="tel:<?= $escape($phone) ?>" aria-label="تماس با <?= $escape($digits($phone)) ?>"><bdi dir="ltr"><?= $escape($digits($phone)) ?></bdi><img class="phone-icon" src="<?= $media('phone.webp', 'image/webp') ?>" width="25" height="28" alt=""></a>
<?php } ?>
</div>
</section>
</main>
</body>
</html>