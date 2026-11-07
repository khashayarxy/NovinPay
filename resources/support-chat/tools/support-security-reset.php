<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../../../vendor/autoload.php';
$application = require __DIR__.'/../../../bootstrap/app.php';
$application->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!app()->environment('staging', 'testing') || (app()->environment('staging') && \Illuminate\Support\Facades\DB::connection()->getDatabaseName() !== 'novinpay_stg38')) { fwrite(STDERR, "Isolated staging/testing required.\n"); exit(1); }
$bridge = app(\App\Support\Chat\ChatBridge::class);
$bridge->begin();
require_once __DIR__.'/../support/operator-security.php';
try {
    if($argc!==2 || $argv[1]!=='--reset-login-throttle') {fwrite(STDERR,"Usage: php tools/support-security-reset.php --reset-login-throttle\n");exit(2);}
    $_SERVER['REMOTE_ADDR']='127.0.0.1';
    $ip=support_ip_key();
    support_security_store(function(array &$s) use($ip) {
        $s['ips']=[];$s['account']=['attempts'=>[],'blocked_until'=>0];$s['accounts']=[];support_event($s,'security_reset',$ip);
    });
    echo json_encode(['status'=>'reset','scope'=>'support-login-only','credentials_changed'=>false]);
}catch(Throwable $e){fwrite(STDERR,"Support security reset failed; private state needs authorized inspection.\n");exit(1);}
