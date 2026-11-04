<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../../../vendor/autoload.php';
$application = require __DIR__.'/../../../bootstrap/app.php';
$application->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!app()->environment('staging', 'testing') || (app()->environment('staging') && \Illuminate\Support\Facades\DB::connection()->getDatabaseName() !== 'novinpay_stg38')) { fwrite(STDERR, "Isolated staging/testing required.\n"); exit(1); }
$bridge = app(\App\Support\Chat\ChatBridge::class);
$bridge->begin();
require_once __DIR__.'/../support/notifications.php';
if($argc===2&&$argv[1]==='--mail-child')support_notification_mail_child();
$lock=null;
try{
    $dry=true;$limit=50;$seconds=30;$timeout=5;
    foreach(array_slice($argv,1) as $arg){if(in_array($arg,['--dry-run','--inspect'],true))$dry=true;elseif($arg==='--run')$dry=false;
        elseif(preg_match('/^--(limit|max-seconds|mail-timeout)=([0-9]{1,3})$/D',$arg,$m)){if($m[1]==='limit')$limit=(int)$m[2];elseif($m[1]==='max-seconds')$seconds=(int)$m[2];else $timeout=(int)$m[2];}else support_failure(400,'invalid_arguments');}
    if($limit<1||$limit>100||$seconds<2||$seconds>120||$timeout<1||$timeout>10||$seconds<$timeout+1)support_failure(400,'invalid_arguments');
    if(!$dry&&!support_notification_enabled())support_failure(403,'notification_disabled');
    if(!$dry)foreach(['proc_open','proc_get_status','proc_terminate','proc_close'] as $function)if(!function_exists($function))support_failure(503,'notification_process_unavailable');
    $lock=support_notification_lock();$report=['dry_run'=>$dry,'processed'=>0,'mail_accepted'=>0,'mail_rejected'=>0,'transport_error'=>0,'transport_timeout'=>0];
    if(!$dry){$started=microtime(true);while($report['processed']<$limit&&microtime(true)-$started<$seconds-$timeout-1){
        $job=support_notification_claim();if($job===null)break;$code=support_notification_transport($job['body'],$timeout);support_notification_finish($job,$code);unset($job);$report['processed']++;$report[$code]++;
    }}
    if(!$dry){require_once __DIR__.'/../support/health.php';support_health_record('gmail_worker',($report['mail_rejected']+$report['transport_error']+$report['transport_timeout'])>0?'failure':'success',function_exists('mail')?((new ReflectionFunction('mail'))->isInternal()?'live':'fake'):'disabled',$report['processed']);}
    $report['queue']=support_notification_inspect();echo json_encode($report,JSON_THROW_ON_ERROR)."\n";
    $permanent=($report['queue']['codes']['attempts_exhausted']??0)+($report['queue']['codes']['age_expired']??0)+($report['queue']['codes']['retention_expired']??0);
    exit($permanent>0?3:(($report['mail_rejected']+$report['transport_error']+$report['transport_timeout'])>0?2:0));
}catch(Throwable $e){if(in_array('--run',$argv,true)){try{require_once __DIR__.'/../support/health.php';support_health_record('gmail_worker','failure','local');}catch(Throwable $ignored){}}echo json_encode(['status'=>'failed','code'=>$e instanceof SupportFailure?$e->reason:'notification_unavailable'])."\n";exit($e instanceof SupportFailure&&$e->reason==='notification_worker_busy'?75:1);}
finally{if(is_resource($lock)){flock($lock,LOCK_UN);fclose($lock);}}
