<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../../../vendor/autoload.php';
$application = require __DIR__.'/../../../bootstrap/app.php';
$application->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!app()->environment('staging', 'testing') || (app()->environment('staging') && \Illuminate\Support\Facades\DB::connection()->getDatabaseName() !== 'novinpay_stg38')) { fwrite(STDERR, "Isolated staging/testing required.\n"); exit(1); }
$bridge = app(\App\Support\Chat\ChatBridge::class);
$bridge->begin();
require_once __DIR__.'/../support/messages.php';require_once __DIR__.'/../support/operator-notifications.php';
try{
 if($argc!==2||!in_array($argv[1],['--initialize','--inspect','--prune','--plan','--run'],true))support_failure(400,'invalid_arguments');
 if($argv[1]==='--initialize'){support_security_key();$r=support_alert_store(function(array &$s){support_push_keys(true);return ['initialized'=>true];},true,true);}
 elseif(in_array($argv[1],['--inspect','--prune'],true))$r=support_alert_store(fn(array &$s)=>['jobs'=>count($s['jobs']),'states'=>array_count_values(array_column($s['jobs'],'state'))],$argv[1]==='--prune');
 elseif($argv[1]==='--plan')$r=support_alert_plan();
 else $r=support_alert_run(getenv('SUPPORT_OPERATOR_NOTIFICATION_PROVIDER')==='fake'?new SupportFakeOperatorAlerts():new SupportPrivateOperatorAlerts());
 if($argv[1]==='--run'){require_once __DIR__.'/../support/health.php';support_health_record('operator_worker',($r['failed']??0)>0?'failure':'success',getenv('SUPPORT_OPERATOR_NOTIFICATION_PROVIDER')==='fake'?'fake':((support_alert_ready('push')||support_alert_ready('sms'))?'live':'disabled'),$r['processed']??0);}
 echo json_encode($r,JSON_THROW_ON_ERROR)."\n";
}catch(Throwable $e){if(($argv[1]??'')==='--run'){try{require_once __DIR__.'/../support/health.php';support_health_record('operator_worker','failure','local');}catch(Throwable $ignored){}}echo json_encode(['status'=>'failed','code'=>$e instanceof SupportFailure?$e->reason:'alert_unavailable'])."\n";exit(1);}
