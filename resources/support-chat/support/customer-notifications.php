<?php
declare(strict_types=1);
require_once __DIR__.'/operator-notifications.php';require_once __DIR__.'/phone.php';
function support_customer_push_enabled(): bool {return getenv('SUPPORT_CUSTOMER_PUSH_ENABLED')==='1';}
function support_customer_push_store(callable $operation,bool $write=false,bool $initialize=false): mixed {
    if($initialize&&PHP_SAPI!=='cli')support_failure(403,'forbidden');
    $dir=support_private_dir(support_runtime_dir().'/customer-notifications');$path=$dir.'/authority.json';$marker=$dir.'/initialized';$lp=$dir.'/authority.lock';
    foreach([$path,$marker,$lp] as $file)if(is_link($file)||(file_exists($file)&&!is_file($file)))support_failure(503,'customer_push_unavailable');
    $lock=@fopen($lp,'c+b');if(!$lock||!flock($lock,LOCK_EX))support_failure(503,'customer_push_unavailable');
    try{
        if(!$initialize&&(!is_file($marker)||!is_file($path)))support_failure(503,'customer_push_not_initialized');
        if($initialize&&is_file($marker)&&!is_file($path))support_failure(503,'customer_push_unavailable');
        $s=['version'=>1,'subscriptions'=>[],'jobs'=>[]];if(is_file($path)){if(filesize($path)>2097152)support_failure(503,'customer_push_capacity');$s=json_decode((string)file_get_contents($path),true,32,JSON_THROW_ON_ERROR);}
        if(!is_array($s)||($s['version']??null)!==1||array_diff(array_keys($s),['version','subscriptions','jobs'])||!is_array($s['subscriptions']??null)||!is_array($s['jobs']??null)||count($s['subscriptions'])>200||count($s['jobs'])>512)support_failure(503,'customer_push_unavailable');
        foreach($s['subscriptions'] as $key=>$sub)if(!preg_match('/^[a-f0-9]{32}$/D',(string)$key)||!is_array($sub)||!is_int($sub['expires']??null)||!is_string($sub['binding']??null)||!support_valid_id($sub['conversation']??null)||!preg_match('/^[a-f0-9]{32}$/D',$sub['epoch']??''))support_failure(503,'customer_push_unavailable');
        foreach($s['jobs'] as $key=>$job)if(!preg_match('/^[a-f0-9]{64}$/D',(string)$key)||!is_array($job)||!is_int($job['at']??null)||!in_array($job['state']??null,['accepted','failed','cancelled','dispatching'],true))support_failure(503,'customer_push_unavailable');
        if($write){foreach($s['subscriptions'] as $key=>$sub)if($sub['expires']<=time())unset($s['subscriptions'][$key]);foreach($s['jobs'] as $key=>$job)if($job['at']<time()-604800)unset($s['jobs'][$key]);}
        $result=$operation($s);if($write){if(count($s['subscriptions'])>200||count($s['jobs'])>512)support_failure(503,'customer_push_capacity');support_atomic_replace($path,json_encode($s,JSON_THROW_ON_ERROR));}
        if($initialize&&!is_file($marker))support_atomic_replace($marker,'1');return $result;
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
function support_customer_push_subscribe(array $context,string $conversation,string $raw): array {
    if(!support_customer_push_enabled())support_failure(404,'push_disabled');
    $after=support_store(function(array &$s,bool &$changed)use($context,$conversation){$c=support_conversation($s,$conversation,$context,false);return max([0,...array_column($c['messages'],'id')]);});
    $value=json_decode($raw,true,8,JSON_THROW_ON_ERROR);if(!is_array($value)||!is_string($value['endpoint']??null)||!is_array($value['keys']??null))support_failure(400,'invalid_subscription');support_push_endpoint($value['endpoint']);
    foreach(['p256dh'=>65,'auth'=>16] as $key=>$length){$v=$value['keys'][$key]??null;if(!is_string($v)||!preg_match('/^[a-zA-Z0-9_-]+={0,2}$/D',$v)||strlen((string)base64_decode(strtr($v,'-_','+/'),true))!==$length)support_failure(400,'invalid_subscription');}
    $id=substr(hash_hmac('sha256','customer-push:'.$value['endpoint'],support_security_key()),0,32);
    $binding=['endpoint'=>$value['endpoint'],'session'=>session_id(),'context'=>array_intersect_key($context,array_flip(['owner_id','issued_at','auth_epoch','_recovered','_phone']))];
    $sealed=support_code_seal($binding,'customer-push-'.$id);
    support_customer_push_store(function(array &$s)use($id,$context,$conversation,$sealed,$after){
        $old=$s['subscriptions'][$id]??null;if($old&&$old['epoch']!==$context['auth_epoch']){ $previous=support_code_open($old['binding'],'customer-push-'.$id);if(!is_file(support_runtime_dir().'/sessions-customer/sess_'.$previous['session']))$old=null;}if($old&&$old['epoch']!==$context['auth_epoch'])support_failure(409,'subscription_in_use');
        if(!$old&&count(array_filter($s['subscriptions'],fn($v)=>$v['epoch']===$context['auth_epoch']))>=5)support_failure(429,'subscription_capacity');
        $s['subscriptions'][$id]=['expires'=>min(time()+7*86400,$context['issued_at']+30*86400),'epoch'=>$context['auth_epoch'],'conversation'=>$conversation,'binding'=>$sealed,'after'=>$after];
    },true);return ['status'=>'success'];
}
function support_customer_push_valid(array $sub,array $binding,array $chat): bool {
    return $sub['expires']>time()&&preg_match('/^[a-zA-Z0-9,-]{16,128}$/D',$binding['session']??'')&&is_file(support_runtime_dir().'/sessions-customer/sess_'.$binding['session'])
        &&isset($chat['conversations'][$sub['conversation']])&&support_owned($chat['conversations'][$sub['conversation']],$binding['context']);
}
function support_customer_push_ready(): bool {
    if(!support_customer_push_enabled())return false;
    if(getenv('SUPPORT_OPERATOR_NOTIFICATION_PROVIDER')==='fake')return support_alert_test();
    return getenv('SUPPORT_CUSTOMER_PUSH_REAL_ENABLED')==='1'&&function_exists('curl_exec')&&is_file(support_runtime_dir().'/customer-notifications/vapid.pem')&&str_starts_with(getenv('SUPPORT_PUSH_CONTACT')?:'','mailto:');
}
function support_customer_push_run(SupportOperatorAlertTransport $transport): array {
    if(PHP_SAPI!=='cli')support_failure(403,'forbidden');
    if(!support_customer_push_ready())return ['processed'=>0,'accepted'=>0,'failed'=>0];
    $path=support_private_dir(support_runtime_dir().'/customer-notifications').'/worker.lock';if(is_link($path)||(file_exists($path)&&!is_file($path)))support_failure(503,'customer_push_unavailable');
    $lock=@fopen($path,'c+b');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){if($lock)fclose($lock);support_failure(409,'customer_push_worker_busy');}
    try{return support_customer_push_work($transport);}finally{flock($lock,LOCK_UN);fclose($lock);}
}
function support_customer_push_work(SupportOperatorAlertTransport $transport): array {
    if(PHP_SAPI!=='cli')support_failure(403,'forbidden');$report=['processed'=>0,'accepted'=>0,'failed'=>0];if(!support_customer_push_ready())return $report;
    // Atomic claim before transport; a crash never replays an uncertain delivery.
    $deadline=microtime(true)+30;for($i=0;$i<10&&microtime(true)<$deadline-5;$i++){
        $claim=support_store(function(array &$chat,bool &$changed){return support_customer_push_store(function(array &$s)use($chat){foreach($s['subscriptions'] as $id=>$sub){
            $binding=support_code_open($sub['binding'],'customer-push-'.$id);
            if(!support_customer_push_valid($sub,$binding,$chat)){unset($s['subscriptions'][$id]);continue;}
            $unread=array_filter($chat['conversations'][$sub['conversation']]['messages'],fn($m)=>$m['type']==='admin'&&($m['customer_read']??true)===false);if(!$unread)continue;
            $head=max(array_column($unread,'id'));if($head<=($sub['after']??0))continue;$key=hash('sha256',$id.':'.$head);if(isset($s['jobs'][$key]))continue;
            // At most one alert per subscription per 30 seconds; coalesce bursts.
            if(array_filter($s['jobs'],fn($j)=>($j['subscription']??null)===$id&&$j['at']>time()-30))continue;
            if(count($s['jobs'])>=512)support_failure(503,'customer_push_capacity');$s['jobs'][$key]=['at'=>time(),'state'=>'dispatching','subscription'=>$id];return ['id'=>$id,'key'=>$key,'payload'=>['endpoint'=>$binding['endpoint']]];
        }return null;},true);});
        if(!$claim)break;try{$outcome=$transport->send('push',[],$claim['payload']);}catch(Throwable $ignored){$outcome='timeout';}
        support_customer_push_store(function(array &$s)use($claim,$outcome){$s['jobs'][$claim['key']]['state']=$outcome==='accepted'?'accepted':'failed';if($outcome==='gone')unset($s['subscriptions'][$claim['id']]);},true);
        $report['processed']++;$report[$outcome==='accepted'?'accepted':'failed']++;
    }return $report;
}
