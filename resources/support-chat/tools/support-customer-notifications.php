<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../../../vendor/autoload.php';
$application = require __DIR__.'/../../../bootstrap/app.php';
$application->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!app()->environment('staging', 'testing') || (app()->environment('staging') && \Illuminate\Support\Facades\DB::connection()->getDatabaseName() !== 'novinpay_stg38')) { fwrite(STDERR, "Isolated staging/testing required.\n"); exit(1); }
$bridge = app(\App\Support\Chat\ChatBridge::class);
$bridge->begin();
require_once __DIR__.'/../support/customer-notifications.php';
try{
    $action=$argv[1]??'status';
    if($action==='init'){support_push_keys(true,'customer');support_customer_push_store(fn(array &$s)=>null,true,true);$report=['initialized'=>true];}
    elseif($action==='run'){$transport=getenv('SUPPORT_OPERATOR_NOTIFICATION_PROVIDER')==='fake'?new SupportFakeOperatorAlerts():new SupportPrivateOperatorAlerts('customer');$report=support_customer_push_run($transport);}
    else $report=['enabled'=>support_customer_push_enabled(),'ready'=>support_customer_push_ready(),'external_delivery'=>getenv('SUPPORT_CUSTOMER_PUSH_REAL_ENABLED')==='1'];
    if($action==='run'){require_once __DIR__.'/../support/health.php';support_health_record('customer_worker',($report['failed']??0)>0?'failure':'success',getenv('SUPPORT_OPERATOR_NOTIFICATION_PROVIDER')==='fake'?'fake':(support_customer_push_ready()?'live':'disabled'),$report['processed']??0);}
    echo json_encode($report,JSON_THROW_ON_ERROR).PHP_EOL;
}catch(Throwable $e){if(($argv[1]??'')==='run'){try{require_once __DIR__.'/../support/health.php';support_health_record('customer_worker','failure','local');}catch(Throwable $ignored){}}fwrite(STDERR,"Customer notification operation failed.\n");exit(1);}
