<?php
declare(strict_types=1);
require_once __DIR__.'/storage.php';
const SUPPORT_NOTIFICATION_ACTIVE_LIMIT=500;
const SUPPORT_NOTIFICATION_MAX_ATTEMPTS=32;
function support_notification_enabled(): bool {return getenv('SUPPORT_NOTIFICATION_QUEUE_ENABLED')==='1';}
function support_notification_active(array $n): bool {return in_array($n['state'],['pending','retry','inflight'],true);}
function support_notification_terminal(array $n): bool {return in_array($n['state'],['sent','failed','closed'],true);}
function support_notification_validate(mixed $n,array $m,bool $receipt=false): void {
    $codes=['queued','capacity_deferred','sending','mail_accepted','mail_rejected','transport_error','transport_timeout','worker_interrupted','attempts_exhausted','age_expired','retention_expired'];
    if(!is_array($n)||($n['v']??null)!==1||!in_array($n['state']??null,['pending','deferred','retry','inflight','sent','failed','closed'],true)
        ||!in_array($n['code']??null,$codes,true)||!isset($m['client_message_id'],$m['accepted_at'],$m['sender_principal'])||!str_starts_with($m['sender_principal'],'customer:')
        ||(!$receipt&&$m['type']!=='user')||($receipt&&$n['state']!=='closed'))support_failure(503,'notification_invalid');
    if($n['state']==='closed'){if(array_diff(array_keys($n),['v','state','code'])||!in_array($n['code'],['mail_accepted','attempts_exhausted','age_expired','retention_expired'],true))support_failure(503,'notification_invalid');return;}
    if(array_diff(array_keys($n),['v','state','code','attempts','next_at','lease_until','finished_at']))support_failure(503,'notification_invalid');
    foreach(['attempts','next_at','lease_until','finished_at'] as $k)if(!is_int($n[$k]??null)||$n[$k]<0)support_failure(503,'notification_invalid');
    if($n['attempts']>SUPPORT_NOTIFICATION_MAX_ATTEMPTS||($n['state']==='inflight'&&$n['lease_until']===0))support_failure(503,'notification_invalid');
    if(in_array($n['state'],['pending','deferred'],true)&&($n['attempts']!==0||$n['next_at']!==0||$n['lease_until']!==0||$n['finished_at']!==0||$n['code']!==($n['state']==='pending'?'queued':'capacity_deferred')))support_failure(503,'notification_invalid');
    if(in_array($n['state'],['retry','inflight','sent'],true)&&$n['attempts']<1)support_failure(503,'notification_invalid');
    if($n['state']!=='inflight'&&$n['lease_until']!==0)support_failure(503,'notification_invalid');
    if(in_array($n['state'],['sent','failed'],true)?$n['finished_at']===0:$n['finished_at']!==0)support_failure(503,'notification_invalid');
    $validCodes=['inflight'=>['sending'],'retry'=>['mail_rejected','transport_error','transport_timeout','worker_interrupted'],'sent'=>['mail_accepted'],'failed'=>['attempts_exhausted','age_expired']];
    if(isset($validCodes[$n['state']])&&!in_array($n['code'],$validCodes[$n['state']],true))support_failure(503,'notification_invalid');
}
function support_notification_intent(array $s): array {
    $active=0;foreach($s['conversations'] as $c)foreach($c['messages'] as $m)if(isset($m['notification'])&&support_notification_active($m['notification']))$active++;
    $deferred=$active>=SUPPORT_NOTIFICATION_ACTIVE_LIMIT;
    return ['v'=>1,'state'=>$deferred?'deferred':'pending','attempts'=>0,'next_at'=>0,'lease_until'=>0,'finished_at'=>0,'code'=>$deferred?'capacity_deferred':'queued'];
}
function support_notification_id(string $id,array $m): string {return hash('sha256',"support-mail-v1\0{$id}\0{$m['sender_principal']}\0{$m['client_message_id']}");}
function support_notification_retired(array $n): array {return ['v'=>1,'state'=>'closed','code'=>support_notification_terminal($n)?$n['code']:'retention_expired'];}
function support_notification_lock(): mixed {
    $path=support_runtime_dir().'/notification-worker.lock';if(is_link($path)||(file_exists($path)&&!is_file($path)))support_failure(503,'notification_unavailable');
    $lock=@fopen($path,'c+b');if(!$lock)support_failure(503,'notification_unavailable');
    if(!@chmod($path,0600)){fclose($lock);support_failure(503,'notification_unavailable');}
    if(!flock($lock,LOCK_EX|LOCK_NB)){fclose($lock);support_failure(409,'notification_worker_busy');}return $lock;
}
function support_notification_inspect(): array {
    return support_store(function(array &$s,bool &$changed){$counts=array_fill_keys(['pending','deferred','retry','inflight','sent','failed','closed'],0);$codes=[];$oldest=null;$now=time();$due=0;
        foreach($s['conversations'] as $c)foreach(array_merge($c['messages'],$c['retained_receipts']??[]) as $m)if(isset($m['notification'])){
            $n=$m['notification'];$counts[$n['state']]++;$codes[$n['code']]=($codes[$n['code']]??0)+1;if(!support_notification_terminal($n)){$at=strtotime($m['accepted_at']);$oldest=$oldest===null?$at:min($oldest,$at);if($n['next_at']<=$now&&$n['lease_until']<=$now)$due++;}
        }
        return ['states'=>$counts,'codes'=>$codes,'due'=>$due,'oldest_pending_age_seconds'=>$oldest===null?0:max(0,$now-$oldest),'active_limit'=>SUPPORT_NOTIFICATION_ACTIVE_LIMIT,'shared_store_max_bytes'=>32*1024*1024];
    });
}
function support_notification_claim(): ?array {
    return support_store(function(array &$s,bool &$changed){
        $now=time();$active=0;$eligible=[];
        foreach($s['conversations'] as $id=>&$c)foreach($c['messages'] as $i=>&$m)if(isset($m['notification'])){
            $n=&$m['notification'];
            if(support_notification_terminal($n)){
                if($n['state']!=='closed'&&$now-$n['finished_at']>=7*86400){$n=support_notification_retired($n);$changed=true;}continue;
            }
            if($now-strtotime($m['accepted_at'])>=7*86400){$n['state']='failed';$n['code']='age_expired';$n['finished_at']=$now;$n['lease_until']=0;$changed=true;continue;}
            if($n['state']==='inflight'&&$n['lease_until']<=$now){$n['state']='retry';$n['code']='worker_interrupted';$n['lease_until']=0;$changed=true;}
            if($n['attempts']>=SUPPORT_NOTIFICATION_MAX_ATTEMPTS&&$n['state']!=='inflight'){$n['state']='failed';$n['code']='attempts_exhausted';$n['finished_at']=$now;$changed=true;continue;}
            if(support_notification_active($n))$active++;
            if($n['next_at']<=$now&&$n['lease_until']<=$now)$eligible[]=['id'=>$id,'index'=>$i,'sequence'=>$m['id'],'deferred'=>$n['state']==='deferred'];
        }
        unset($m,$n,$c);usort($eligible,fn($a,$b)=>$a['sequence']<=>$b['sequence']);
        foreach($eligible as $candidate){
            if($candidate['deferred']&&$active>=SUPPORT_NOTIFICATION_ACTIVE_LIMIT)continue;
            $m=&$s['conversations'][$candidate['id']]['messages'][$candidate['index']];$n=&$m['notification'];
            $n['state']='inflight';$n['code']='sending';$n['attempts']++;$n['lease_until']=$now+180;$changed=true;
            return ['conversation'=>$candidate['id'],'sequence'=>$m['id'],'job_id'=>support_notification_id($candidate['id'],$m),'attempt'=>$n['attempts'],'body'=>$m['text']];
        }
        return null;
    },true);
}
function support_notification_finish(array $job,string $code): void {
    support_store(function(array &$s,bool &$changed)use($job,$code){
        $c=$s['conversations'][$job['conversation']]??null;$found=false;
        foreach($c['messages']??[] as $i=>$m)if($m['id']===$job['sequence']){
            if(!isset($m['notification'])||!hash_equals(support_notification_id($job['conversation'],$m),$job['job_id'])||$m['notification']['state']!=='inflight'||$m['notification']['attempts']!==$job['attempt'])support_failure(503,'notification_reference_invalid');
            $n=&$s['conversations'][$job['conversation']]['messages'][$i]['notification'];$now=time();$n['lease_until']=0;$n['code']=$code;
            if($code==='mail_accepted'){$n['state']='sent';$n['finished_at']=$now;}
            elseif($n['attempts']>=SUPPORT_NOTIFICATION_MAX_ATTEMPTS){$n['state']='failed';$n['code']='attempts_exhausted';$n['finished_at']=$now;}
            else{$n['state']='retry';$n['next_at']=$now+min(21600,60*(2**min(15,$n['attempts']-1)));}
            $found=true;$changed=true;break;
        }
        if(!$found)support_failure(503,'notification_reference_invalid');
    },true);
}
// Separate process: body only through a private pipe, never command arguments/logs.
function support_notification_transport(string $body,int $timeout): string {
    if(PHP_SAPI!=='cli'||!function_exists('proc_open')||!function_exists('proc_terminate'))return 'transport_error';
    // Native Windows PHP cannot reliably poll output pipes without blocking.
    // This private scratch file contains only a fixed outcome token, never text.
    $dir=support_runtime_dir();$old=glob($dir.'/notification-result.*.tmp');if($old===false||count($old)>1000)return 'transport_error';$remaining=0;
    foreach($old as $path){if(is_link($path)||!is_file($path))return 'transport_error';if(filemtime($path)<time()-180){if(!@unlink($path))return 'transport_error';}else $remaining++;}if($remaining>=1000)return 'transport_error';
    $resultPath=$dir.'/notification-result.'.bin2hex(random_bytes(16)).'.tmp';$resultFile=@fopen($resultPath,'x+b');
    if(!$resultFile)return 'transport_error';if(!@chmod($resultPath,0600)){fclose($resultFile);@unlink($resultPath);return 'transport_error';}
    $args=[PHP_BINARY];if($ini=php_ini_loaded_file())array_push($args,'-c',$ini);
    array_push($args,'-d','disable_functions='.ini_get('disable_functions'),'-d','auto_prepend_file="'.str_replace('"','\\"',(string)ini_get('auto_prepend_file')).'"','-d','auto_append_file=""','-d','display_errors=0','-d','log_errors=0',__DIR__.'/../tools/support-notifications.php','--mail-child');
    $pipes=[];$process=@proc_open($args,[0=>['pipe','r'],1=>$resultFile,2=>['file',PHP_OS_FAMILY==='Windows'?'NUL':'/dev/null','a']],$pipes,null,null,['bypass_shell'=>true]);
    if(!is_resource($process)){fclose($resultFile);@unlink($resultPath);return 'transport_error';}
    $result='transport_error';$start=microtime(true);
    try {
        // At most 2000 raw bytes fit the native Windows/Linux pipe buffer; no
        // JSON expansion or Windows-unsupported nonblocking input pipe mode.
        if(strlen($body)>2000)return 'transport_error';
        if(@fwrite($pipes[0],$body)!==strlen($body))return 'transport_error';
        fclose($pipes[0]);unset($pipes[0]);
        while(true){
            $status=proc_get_status($process);
            if(fstat($resultFile)['size']>1024)return 'transport_error';
            if(!$status['running']){rewind($resultFile);$output=stream_get_contents($resultFile,1025);$result=trim($output)==='MAIL_ACCEPTED'?'mail_accepted':(trim($output)==='MAIL_REJECTED'?'mail_rejected':'transport_error');break;}
            if(microtime(true)-$start>=$timeout){$result='transport_timeout';break;}usleep(20000);
        }
    }finally{
        $status=proc_get_status($process);if($status['running']){@proc_terminate($process);usleep(20000);$status=proc_get_status($process);if($status['running'])@proc_terminate($process,9);}
        foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);proc_close($process);fclose($resultFile);@unlink($resultPath);
    }
    return $result;
}
function support_notification_mail_child(): never {
    if(PHP_SAPI!=='cli'||!support_notification_enabled()){exit(64);}
    try{$body=stream_get_contents(STDIN,2001);if(strlen($body)>2000||!mb_check_encoding($body,'UTF-8'))exit(64);
        $notify_email=getenv('SUPPORT_NOTIFY_EMAIL');
        $recipient=(is_string($notify_email)&&trim($notify_email)!=='')?trim($notify_email):null;
        if($recipient===null||!filter_var($recipient,FILTER_VALIDATE_EMAIL)){echo 'MAIL_ACCEPTED';exit(0);}
        $origin=getenv('SUPPORT_SITE_URL')?:'';$from=getenv('SUPPORT_NOTIFY_FROM')?:'';
        if(!filter_var($origin,FILTER_VALIDATE_URL)||!str_starts_with($origin,'https://')||!filter_var($from,FILTER_VALIDATE_EMAIL)){echo 'MAIL_REJECTED';exit(1);}
        $notify_text='پیام جدیدی در پشتیبانی ثبت شد: '.rtrim($origin,'/').'/support-chat/operator/';
        $ok=@mail($recipient,'پیام جدید پشتیبانی نوین‌پی',getenv('TEST_MAIL_EVENTS')?$body:$notify_text,'From: '.$from);
        echo $ok?'MAIL_ACCEPTED':'MAIL_REJECTED';exit($ok?0:1);
    }catch(Throwable $ignored){echo 'MAIL_ERROR';exit(1);}
}
