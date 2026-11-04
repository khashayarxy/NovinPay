<?php
require_once __DIR__.'/../support-loader.php';
require_once support_core_path('configuration.php');
header('Content-Type: application/manifest+json; charset=utf-8');header('Cache-Control: no-cache');header('X-Content-Type-Options: nosniff');
$icon=static function(string $key,string $fallback): string {$path=getenv($key)?:$fallback;return preg_match('~^(?!//)[a-zA-Z0-9_./-]+\.png$~D',$path)&&!str_contains($path,'..')?$path:$fallback;};
echo json_encode(['id'=>'./','name'=>'پشتیبانی '.support_site_name(),'short_name'=>support_site_name(),'lang'=>'fa','dir'=>'rtl','start_url'=>'./','scope'=>'./','description'=>'گفتگوها و پاسخ‌های تیم پشتیبانی، با ورود امن اپراتور','display'=>'standalone','background_color'=>'#101012','theme_color'=>'#0866ff','icons'=>[['src'=>$icon('SUPPORT_PWA_ICON_192','icons/icon-192.png'),'sizes'=>'192x192','type'=>'image/png'],['src'=>$icon('SUPPORT_PWA_ICON_512','icons/icon-512.png'),'sizes'=>'512x512','type'=>'image/png'],['src'=>'icons/maskable-512.png','sizes'=>'512x512','type'=>'image/png','purpose'=>'maskable']]],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
