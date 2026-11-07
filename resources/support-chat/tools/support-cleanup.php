<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../../../vendor/autoload.php';
$application = require __DIR__.'/../../../bootstrap/app.php';
$application->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!app()->environment('staging', 'testing') || (app()->environment('staging') && \Illuminate\Support\Facades\DB::connection()->getDatabaseName() !== 'novinpay_stg38')) { fwrite(STDERR, "Isolated staging/testing required.\n"); exit(1); }
$bridge = app(\App\Support\Chat\ChatBridge::class);
$bridge->begin();
require_once __DIR__.'/../support/retention.php';
try{
    $apply=false;$initialize=false;$limit=500;
    foreach(array_slice($argv,1) as $arg){if($arg==='--apply')$apply=true;elseif($arg==='--dry-run')$apply=false;elseif($arg==='--initialize')$initialize=true;elseif(preg_match('/^--limit=([0-9]{1,4})$/D',$arg,$m))$limit=(int)$m[1];else support_failure(400,'invalid_arguments');}
    if($initialize&&$apply)support_failure(400,'invalid_arguments');
    $report=$initialize?support_retention_initialize():support_retention_cleanup($apply,$limit);
    if(!$initialize){require_once __DIR__.'/../support/attachments.php';$report['media']=support_attachment_prune($apply);}
    if($apply&&is_file(support_runtime_dir().'/satisfaction/initialized')){require_once __DIR__.'/../support/satisfaction.php';$report['feedback']=support_satisfaction_store(fn(array &$s)=>['retained'=>count($s['ratings'])],true);}
    if($apply){require_once __DIR__.'/../support/health.php';support_health_record('retention_apply',($report['media']['files_failed']??0)>0?'failure':'success','local',$report['messages']??0);}
    echo json_encode($report,JSON_THROW_ON_ERROR)."\n";if(($report['media']['files_failed']??0)>0)exit(2);
}catch(Throwable $e){echo json_encode(['status'=>'failed','code'=>$e instanceof SupportFailure?$e->reason:'cleanup_unavailable','sensitive_content_logged'=>false])."\n";exit(1);}
