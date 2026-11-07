<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../../../vendor/autoload.php';
$application = require __DIR__.'/../../../bootstrap/app.php';
$application->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!app()->environment('staging', 'testing') || (app()->environment('staging') && \Illuminate\Support\Facades\DB::connection()->getDatabaseName() !== 'novinpay_stg38')) { fwrite(STDERR, "Isolated staging/testing required.\n"); exit(1); }
$bridge = app(\App\Support\Chat\ChatBridge::class);
$bridge->begin();
require_once __DIR__.'/../support/messages.php';require_once __DIR__.'/../support/satisfaction.php';
try{
    if($argc!==2||!in_array($argv[1],['--initialize','--inspect','--prune'],true))support_failure(400,'invalid_arguments');
    $result=$argv[1]==='--inspect'?support_satisfaction_summary():support_satisfaction_store(fn(array &$s)=>['records'=>count($s['ratings'])],true,$argv[1]==='--initialize');if($argv[1]==='--prune'){require_once __DIR__.'/../support/health.php';support_health_record('satisfaction_prune');}echo json_encode($result,JSON_THROW_ON_ERROR)."\n";
}catch(Throwable $e){echo json_encode(['status'=>'failed','code'=>$e instanceof SupportFailure?$e->reason:'satisfaction_unavailable'])."\n";exit(1);}
