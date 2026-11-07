<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../../../vendor/autoload.php';
$application = require __DIR__.'/../../../bootstrap/app.php';
$application->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!app()->environment('staging', 'testing') || (app()->environment('staging') && \Illuminate\Support\Facades\DB::connection()->getDatabaseName() !== 'novinpay_stg38')) { fwrite(STDERR, "Isolated staging/testing required.\n"); exit(1); }
$bridge = app(\App\Support\Chat\ChatBridge::class);
$bridge->begin();
if (($argv[1] ?? '') === '--initialize') { fwrite(STDERR, "Use php artisan support:initialize; operator accounts belong to Laravel.\n"); exit(1); }
require_once __DIR__.'/../support/messages.php';require_once __DIR__.'/../support/operators.php';
try{
    if($argc!==2||!in_array($argv[1],['--initialize','--inspect','--prune'],true))support_failure(400,'invalid_arguments');
    if($argv[1]==='--prune'){
        $r=support_store(function(array &$s,bool &$changed){$removed=0;foreach($s['conversations'] as &$c)if(isset($c['workflow'])){$w=support_workflow($c);$removed+=count($c['workflow']['notes'])-count($w['notes']);if($w!==$c['workflow']){$c['workflow']=$w;$changed=true;}}return ['notes_removed'=>$removed];},true);
        support_ops_store(fn(array &$s)=>null,true);require_once __DIR__.'/../support/health.php';support_health_record('operator_prune','success','local',$r['notes_removed']??0);echo json_encode($r,JSON_THROW_ON_ERROR)."\n";exit;
    }
    if($argv[1]==='--inspect')$r=support_ops_store(fn(array &$s)=>['accounts'=>count($s['accounts']),'active'=>count(array_filter($s['accounts'],fn($a)=>$a['active']))]);
    else{
        $raw=stream_get_contents(STDIN,4097);if(strlen($raw)>4096)support_failure(400,'invalid_input');$input=json_decode($raw,true,8,JSON_THROW_ON_ERROR);
        $a=support_ops_account(support_string($input['username']??null,80),support_string($input['name']??null,200),support_ops_password($input['password']??null),'administrator');
        $r=support_ops_store(function(array &$s)use($a){if($s['accounts'])support_failure(409,'operators_already_initialized');$s['accounts'][$a['id']]=$a;support_ops_audit($s,$a['id'],'initialized');return ['initialized'=>true];},true,true);
    }echo json_encode($r,JSON_THROW_ON_ERROR)."\n";
}catch(Throwable $e){echo json_encode(['status'=>'failed','code'=>$e instanceof SupportFailure?$e->reason:'operator_unavailable'])."\n";exit(1);}
