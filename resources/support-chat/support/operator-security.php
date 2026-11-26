<?php
declare(strict_types=1);
require_once __DIR__.'/storage.php';
const SUPPORT_AUDIT_MAX_EVENTS=14000;
const SUPPORT_SECURITY_MAX_BYTES=4194304;

/** No recovery/production credential is used; this is a chat-only installation key. */
function support_security_lock(): mixed {
    $path=support_runtime_dir().'/operator-security.lock';
    if(is_link($path)) support_failure(503,'security_unavailable');
    $file=@fopen($path,'c+b');
    if(!$file || !@flock($file,LOCK_EX)) {if($file)fclose($file);support_failure(503,'security_unavailable');}
    if(!@chmod($path,0600)) {flock($file,LOCK_UN);fclose($file);support_failure(503,'security_unavailable');}
    return $file;
}
function support_security_key(): string {
    static $key;
    if($key!==null) return $key;
    $lock=support_security_lock();
    try {
        $path=support_runtime_dir().'/installation.key';
        if(is_link($path) || (file_exists($path) && !is_file($path))) support_failure(503,'security_unavailable');
        if(!file_exists($path)) {
            if(file_exists(support_runtime_dir().'/operator-security.json')||file_exists(support_runtime_dir().'/phone-recovery/authority.json')||file_exists(support_runtime_dir().'/secret-recovery/security.json')||file_exists(support_runtime_dir().'/code-recovery/authority.json')||file_exists(support_runtime_dir().'/operators/authority.json')) support_failure(503,'security_key_missing');
            support_atomic_replace($path,random_bytes(32));
        }
        $value=@file_get_contents($path);
        if(!is_string($value) || strlen($value)!==32) support_failure(503,'security_unavailable');
        return $key=support_standalone()?hash_hmac('sha256','support-installation-v1'.support_installation_id(),$value,true):$value;
    } finally {flock($lock,LOCK_UN);fclose($lock);}
}
function support_ip_key(): string {
    $ip=$_SERVER['REMOTE_ADDR']??'';$packed=is_string($ip)?@inet_pton($ip):false;
    if($packed===false) support_failure(400,'invalid_network_context');
    // IPv4-mapped IPv6 uses the same IP bucket. Never trust forwarded headers.
    if(strlen($packed)===16 && substr($packed,0,12)===str_repeat("\0",10)."\xff\xff") $packed=substr($packed,12);
    return hash_hmac('sha256',$packed,support_security_key());
}
function support_security_store(callable $operation): mixed {
    support_security_key();$lock=support_security_lock();
    try {
        $path=support_runtime_dir().'/operator-security.json';
        if(is_link($path) || (file_exists($path) && !is_file($path))) support_failure(503,'security_unavailable');
        $state=['version'=>1,'ips'=>[],'account'=>['attempts'=>[],'blocked_until'=>0],'accounts'=>[],'rates'=>[],'events'=>[],'pruned_events'=>0];
        if(is_file($path)) {
            if(filesize($path)>SUPPORT_SECURITY_MAX_BYTES) support_failure(503,'security_invalid');
            try {$state=json_decode((string)file_get_contents($path),true,32,JSON_THROW_ON_ERROR);}
            catch(Throwable $e) {support_failure(503,'security_invalid');}
        }
        if(!is_array($state) || ($state['version']??null)!==1 || !is_array($state['ips']??null) || !is_array($state['rates']??null)
            || !is_array($state['events']??null) || !is_int($state['pruned_events']??null)
            || !is_array($state['account']['attempts']??null) || !is_int($state['account']['blocked_until']??null)) support_failure(503,'security_invalid');
        if(!isset($state['accounts']) || !is_array($state['accounts'])) $state['accounts']=[];
        if(array_diff(array_keys($state),['version','ips','account','accounts','rates','events','pruned_events']) || array_diff(array_keys($state['account']),['attempts','blocked_until'])) support_failure(503,'security_invalid');
        foreach(array_merge(array_keys($state['ips']),array_keys($state['rates']),array_keys($state['accounts'])) as $key) if(!is_string($key) || !preg_match('/^[a-f0-9]{64}$/D',$key)) support_failure(503,'security_invalid');
        if(count($state['ips'])>512 || count($state['rates'])>1024 || count($state['accounts'])>1024 || count($state['events'])>SUPPORT_AUDIT_MAX_EVENTS) support_failure(503,'security_invalid');
        foreach([$state['account']['attempts'],...array_values($state['ips']),...array_values($state['rates'])] as $timestamps)
            if(!is_array($timestamps) || count($timestamps)>200 || array_filter($timestamps,fn($t)=>!is_int($t) || $t<0)) support_failure(503,'security_invalid');
        foreach($state['accounts'] as $acc)
            if(!is_array($acc) || !is_array($acc['attempts']??null) || !is_int($acc['blocked_until']??null) || count($acc['attempts'])>200 || array_filter($acc['attempts'],fn($t)=>!is_int($t) || $t<0)) support_failure(503,'security_invalid');
        foreach($state['events'] as $event) if(!is_array($event) || !is_int($event['at']??null) || !is_string($event['event']??null) || !is_string($event['request_id']??null) || !preg_match('/^[a-f0-9]{24}$/D',$event['request_id']) || !is_string($event['ip_key']??null) || !preg_match('/^[a-f0-9]{64}$/D',$event['ip_key']) || array_diff(array_keys($event),['at','event','request_id','ip_key'])) support_failure(503,'security_invalid');
        $now=time();
        foreach($state['ips'] as $key=>$times) {$times=array_values(array_filter($times,fn($t)=>$now-$t<900));if($times)$state['ips'][$key]=$times;else unset($state['ips'][$key]);}
        foreach($state['rates'] as $key=>$times) {$times=array_values(array_filter($times,fn($t)=>$now-$t<60));if($times)$state['rates'][$key]=$times;else unset($state['rates'][$key]);}
        foreach($state['accounts'] as $key=>$acc) {
            $times=array_values(array_filter($acc['attempts'],fn($t)=>$now-$t<900));
            if($times || ($acc['blocked_until']??0)>$now) $state['accounts'][$key]=['attempts'=>$times,'blocked_until'=>$acc['blocked_until']];
            else unset($state['accounts'][$key]);
        }
        $kept=array_values(array_filter($state['events'],fn($e)=>$now-$e['at']<=7*86400));
        $state['pruned_events']+=count($state['events'])-count($kept);$state['events']=$kept;
        // Persist reservation/audit even if the operation needs to reject with 429.
        $error=null;$result=null;
        try {$result=$operation($state);} catch(SupportFailure $e) {$error=$e;}
        // Keep every accepted event for seven days; capacity never evicts fresh evidence.
        // A full private store fails closed instead of silently losing audit history.
        if(count($state['events'])>SUPPORT_AUDIT_MAX_EVENTS) support_failure(503,'security_capacity');
        $bytes=json_encode($state,JSON_THROW_ON_ERROR);
        if(strlen($bytes)>SUPPORT_SECURITY_MAX_BYTES) support_failure(503,'security_capacity');
        support_atomic_replace($path,$bytes);
        if($error) throw $error;
        return $result;
    } finally {flock($lock,LOCK_UN);fclose($lock);}
}
function support_event(array &$state,string $event,string $ip): void {
    if(!in_array($event,['login_reserved','login_failed','login_succeeded','login_throttled','logout','session_expired','security_reset'],true)) support_failure(503,'security_event_invalid');
    $now=time();
    if($event==='login_throttled') {
        for($i=count($state['events'])-1;$i>=0;$i--) {
            $e=$state['events'][$i];
            if($now-($e['at']??0)>=300) break;
            if(($e['event']??'')==='login_throttled' && ($e['ip_key']??'')===$ip) {
                return;
            }
        }
        if(count($state['events'])>=13300) {
            return;
        }
    }
    if($event==='login_reserved') {
        if(count($state['events'])>=13000) {
            return;
        }
    }
    $state['events'][]=['at'=>$now,'event'=>$event,'request_id'=>bin2hex(random_bytes(12)),'ip_key'=>$ip];
}
function support_audit(string $event): void {
    $ip=support_ip_key();support_security_store(function(array &$s) use($event,$ip) {support_event($s,$event,$ip);});
}
function support_login_reserve(?string $username = null): void {
    $ip=support_ip_key();
    support_security_store(function(array &$s) use($ip,$username) {
        $now=time();
        if(($s['account']['blocked_until']??0)>$now) {
            support_event($s,'login_throttled',$ip);
            @header('Retry-After: '.max(1,($s['account']['blocked_until']??0)-$now));
            support_failure(429,'login_limited');
        }
        $times=array_values(array_filter($s['ips'][$ip]??[],fn($t)=>$now-$t<900));
        if(count($times)>=20) {
            support_event($s,'login_throttled',$ip);
            @header('Retry-After: '.(900-($now-$times[0])));
            support_failure(429,'login_limited');
        }
        if(!isset($s['ips'][$ip]) && count($s['ips'])>=512) {
            $oldK=null;$oldT=PHP_INT_MAX;
            foreach($s['ips'] as $k=>$ts){$last=end($ts)?:0;if($last<$oldT){$oldT=$last;$oldK=$k;}}
            if($oldK!==null) unset($s['ips'][$oldK]);
        }
        if($username!==null && trim($username)!=='') {
            $clean=strtolower(trim($username));
            $account_key=hash_hmac('sha256','global:'.$clean,support_security_key());
            $compound_key=hash_hmac('sha256',$ip.':'.$clean,support_security_key());

            $acc_global=$s['accounts'][$account_key]??['attempts'=>[],'blocked_until'=>0];
            $global_times=array_values(array_filter($acc_global['attempts'],fn($t)=>$now-$t<900));
            if($acc_global['blocked_until']>$now) {
                support_event($s,'login_throttled',$ip);
                @header('Retry-After: '.max(1,$acc_global['blocked_until']-$now));
                support_failure(429,'login_limited');
            }

            $acc=$s['accounts'][$compound_key]??['attempts'=>[],'blocked_until'=>0];
            $acc_times=array_values(array_filter($acc['attempts'],fn($t)=>$now-$t<900));
            if($acc['blocked_until']>$now) {
                support_event($s,'login_throttled',$ip);
                @header('Retry-After: '.max(1,$acc['blocked_until']-$now));
                support_failure(429,'login_limited');
            }

            if(count($s['accounts'])>=1023) {
                $oldAccK=null;$oldAccT=PHP_INT_MAX;
                foreach($s['accounts'] as $k=>$aData){
                    if(($aData['blocked_until']??0)<=$now){
                        $last=end($aData['attempts'])?:0;
                        if($last<$oldAccT){$oldAccT=$last;$oldAccK=$k;}
                    }
                }
                if($oldAccK!==null) unset($s['accounts'][$oldAccK]);
            }

            $acc_times[]=$now;
            $blocked_until=count($acc_times)>=5 ? ($now+min(60,5*2**min(4,count($acc_times)-5))) : 0;
            $s['accounts'][$compound_key]=['attempts'=>$acc_times,'blocked_until'=>$blocked_until];

            $global_times[]=$now;
            if(count($global_times)>=20) {
                $s['accounts'][$account_key]=['attempts'=>array_slice($global_times,-19),'blocked_until'=>$now+60];
            } else {
                $s['accounts'][$account_key]=['attempts'=>$global_times,'blocked_until'=>0];
            }
        }
        $times[]=$now;$s['ips'][$ip]=$times;
        $attempts=array_values(array_filter($s['account']['attempts'],fn($t)=>$now-$t<900));
        $attempts[]=$now;$s['account']['attempts']=$attempts;
        if(count($attempts)>=5) $s['account']['blocked_until']=$now+min(60,5*2**min(4,count($attempts)-5));
        support_event($s,'login_reserved',$ip);
    });
}
function support_login_result(bool $success, ?string $username = null): void {
    $ip=support_ip_key();support_security_store(function(array &$s) use($success,$ip,$username) {
        if($success) {
            $s['account']=['attempts'=>[],'blocked_until'=>0];
            if($username!==null && trim($username)!=='') {
                $clean=strtolower(trim($username));
                $account_key=hash_hmac('sha256','global:'.$clean,support_security_key());
                $compound_key=hash_hmac('sha256',$ip.':'.$clean,support_security_key());
                unset($s['accounts'][$compound_key], $s['accounts'][$account_key]);
            }
        }
        support_event($s,$success?'login_succeeded':'login_failed',$ip);
    });
}
function support_throttle(string $bucket,int $limit,int $window=60): void {
    $key=hash_hmac('sha256',$bucket,support_security_key());
    $shard=substr($key,0,2);
    $dir=support_private_dir(support_runtime_dir().'/rate-shards');
    $lockPath=$dir.'/'.$shard.'.lock';
    $dataPath=$dir.'/'.$shard.'.json';
    if(is_link($lockPath)||is_link($dataPath)||(file_exists($dataPath)&&!is_file($dataPath))) support_failure(503,'security_unavailable');
    $lock=@fopen($lockPath,'c+b');
    if(!$lock||!@flock($lock,LOCK_EX)){if($lock)fclose($lock);support_failure(503,'security_unavailable');}
    @chmod($lockPath,0600);
    try {
        $now=time();
        $rates=[];
        if(is_file($dataPath)) {
            $size=(int)@filesize($dataPath);
            if($size>0&&$size<=524288) {
                $decoded=json_decode((string)@file_get_contents($dataPath),true);
                if(is_array($decoded)) $rates=$decoded;
            }
        }
        $maxWindow=max(60,$window);
        foreach($rates as $k=>$times) {
            if(!is_string($k)||!is_array($times)){unset($rates[$k]);continue;}
            $filtered=array_values(array_filter($times,fn($t)=>is_int($t)&&$now-$t<$maxWindow));
            if($filtered)$rates[$k]=$filtered;else unset($rates[$k]);
        }
        $times=array_values(array_filter($rates[$key]??[],fn($t)=>is_int($t)&&$now-$t<$window));
        if(count($times)>=$limit || (!isset($rates[$key]) && count($rates)>=256)) {
            header('Retry-After: '.$window);
            support_failure(429,'rate_limited');
        }
        $times[]=$now;
        $rates[$key]=$times;
        $tmp=$dataPath.'.tmp.'.bin2hex(random_bytes(8));
        $fh=@fopen($tmp,'x+b');
        if($fh) {
            @chmod($tmp,0600);
            $encoded=json_encode($rates,JSON_THROW_ON_ERROR);
            if(@fwrite($fh,$encoded)===strlen($encoded) && @fflush($fh)) {
                @fclose($fh);
                @rename($tmp,$dataPath);
            } else {
                @fclose($fh);
                @unlink($tmp);
            }
        }
    } finally {
        flock($lock,LOCK_UN);
        fclose($lock);
    }
}
function support_principal(array $context,string $role): string {
    if($role==='customer') return 'customer:'.$context['owner_id'];
    if(isset($context['operator_id']))return 'operator:'.hash('sha256',support_installation_id()."\0".$context['operator_id']."\0".$context['credential_version']);
    return 'operator:'.hash('sha256',ADMIN_USERNAME."\0".ADMIN_PASSWORD_HASH);
}
function support_public_context(array $context,string $role): array {
    require_once __DIR__.'/context.php';
    $scope=support_principal($context,$role);
    if(isset($context['_recovered']))$scope.="\0recovered\0".$context['_recovered']['conversation']."\0".$context['_recovered']['epoch']."\0".$context['auth_epoch'];
    if(isset($context['_phone']))$scope.="\0phone-recovered\0".$context['_phone']['conversation']."\0".$context['_phone']['epoch']."\0".$context['auth_epoch'];
    return ['source_paths'=>$role==='customer'?support_source_allowed():[],'protocol_version'=>2,'role'=>$role,'scope_id'=>hash_hmac('sha256',$scope,support_security_key()),'session_epoch'=>$context['auth_epoch'],
        'secret_recovery_enabled'=>function_exists('support_secret_recovery_enabled')&&support_secret_recovery_enabled(),
        'phone_recovery_enabled'=>getenv('SUPPORT_PHONE_RECOVERY_ENABLED')==='1',
        'phone_recovery_session'=>isset($context['phone_access']),
        'can_issue_recovery_code'=>!isset($context['_phone'])&&!isset($context['_recovered']),
        'retention_enabled'=>getenv('SUPPORT_RETENTION_ENABLED')==='1'];
}

function support_attention_context(array $context,string $role): array {
    $public=support_public_context($context,$role);return ['installation'=>support_installation_id(),'scope'=>$public['scope_id'],'epoch'=>$public['session_epoch']];
}
