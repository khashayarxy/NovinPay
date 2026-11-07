<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../../../vendor/autoload.php';
$application = require __DIR__.'/../../../bootstrap/app.php';
$application->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!app()->environment('staging', 'testing') || (app()->environment('staging') && \Illuminate\Support\Facades\DB::connection()->getDatabaseName() !== 'novinpay_stg38')) { fwrite(STDERR, "Isolated staging/testing required.\n"); exit(1); }
$bridge = app(\App\Support\Chat\ChatBridge::class);
$bridge->begin();
require_once __DIR__.'/../support/recovery-email.php';
if($argc===2&&$argv[1]==='--mail-child')support_code_mail_child();
try{
    if($argc!==2||!in_array($argv[1],['--initialize','--inspect','--prune','--run'],true))support_failure(400,'invalid_arguments');
    if($argv[1]==='--initialize')$r=support_code_store(fn(array &$s)=>['initialized'=>true],true,true);
    elseif(in_array($argv[1],['--inspect','--prune'],true))$r=support_code_store(fn(array &$s)=>['records'=>count($s['records']),'challenges'=>count($s['challenges']),'jobs'=>count($s['jobs']),'states'=>array_count_values(array_column($s['jobs'],'state'))],$argv[1]==='--prune');
    else $r=support_code_email_dispatch(getenv('SUPPORT_RECOVERY_EMAIL_PROVIDER')==='fake'?new SupportFakeRecoveryEmail():new SupportPrivateRecoveryEmail());
    echo json_encode($r,JSON_THROW_ON_ERROR)."\n";if(($r['failed']??0)>0)exit(2);
}catch(Throwable $e){echo json_encode(['status'=>'failed','code'=>$e instanceof SupportFailure?$e->reason:'email_unavailable'])."\n";exit(1);}
