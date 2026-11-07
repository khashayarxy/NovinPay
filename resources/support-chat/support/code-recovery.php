<?php
declare(strict_types=1);
require_once __DIR__.'/recovery.php';

function support_code_enabled(): bool {return support_secret_recovery_enabled()&&getenv('SUPPORT_RECOVERY_ISSUANCE_ENABLED')==='1';}
function support_code_email_enabled(): bool {return getenv('SUPPORT_RECOVERY_EMAIL_ENABLED')==='1';}
function support_code_test_mode(): bool {
    return getenv('SUPPORT_RECOVERY_TEST_MODE')==='1'&&(PHP_SAPI==='cli'||(PHP_SAPI==='cli-server'&&getenv('SUPPORT_LOCAL_HTTP')==='1'&&in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true)));
}
function support_code_hash(string $domain,string $value): string {return hash_hmac('sha256',"support-code-v1\0{$domain}\0{$value}",support_security_key());}
function support_code_binding(array $c): string {return support_code_hash('conversation',$c['user_id']."\0".$c['owner_id']."\0".$c['created_at']);}
function support_code_session(array $base): string {return support_code_hash('session',$base['owner_id']."\0".$base['auth_epoch']);}
function support_code_hex(mixed $v,int $length=64): bool {return is_string($v)&&preg_match('/^[a-f0-9]{'.$length.'}$/D',$v)===1;}
function support_code_exists(): bool {return file_exists(support_runtime_dir().'/code-recovery/authority.json')||file_exists(support_runtime_dir().'/code-recovery/initialized');}
function support_code_seal(array $value,string $id): string {
    if(!function_exists('openssl_encrypt'))support_failure(503,'recovery_unavailable');
    $nonce=random_bytes(12);$tag='';$cipher=openssl_encrypt(json_encode($value,JSON_THROW_ON_ERROR),'aes-256-gcm',hex2bin(support_code_hash('encryption','temporary-payload')),OPENSSL_RAW_DATA,$nonce,$tag,$id,16);
    if($cipher===false)support_failure(503,'recovery_unavailable');return base64_encode($nonce.$tag.$cipher);
}
function support_code_open(string $sealed,string $id): array {
    $bytes=base64_decode($sealed,true);if($bytes===false||strlen($bytes)<29)support_failure(503,'code_state_invalid');
    $raw=openssl_decrypt(substr($bytes,28),'aes-256-gcm',hex2bin(support_code_hash('encryption','temporary-payload')),OPENSSL_RAW_DATA,substr($bytes,0,12),substr($bytes,12,16),$id);
    if($raw===false)support_failure(503,'code_state_invalid');$value=json_decode($raw,true,8,JSON_THROW_ON_ERROR);if(!is_array($value))support_failure(503,'code_state_invalid');return $value;
}
function support_code_validate(array $s): void {
    if(($s['version']??null)!==1||array_diff(array_keys($s),['version','records','challenges','jobs','rates','events']))support_failure(503,'code_state_invalid');
    foreach(['records'=>2000,'challenges'=>128,'jobs'=>128,'rates'=>1024,'events'=>4000] as $key=>$limit)if(!is_array($s[$key]??null)||count($s[$key])>$limit)support_failure(503,'code_capacity');
    foreach($s['records'] as $id=>$r){
        if(!support_valid_id($id)||!is_array($r)||array_diff(array_keys($r),['owner','binding','selector','verifier','epoch','operation','session','state','prepared_at','expires','confirmed_at','sealed'])
            ||!support_code_hex($r['owner']??null)||!support_code_hex($r['binding']??null)||!support_code_hex($r['selector']??null,32)||!support_code_hex($r['verifier']??null)||!support_code_hex($r['epoch']??null,32)
            ||!support_valid_uuid($r['operation']??null)||!support_code_hex($r['session']??null)||!in_array($r['state']??null,['prepared','active','revoked'],true)
            ||!is_int($r['prepared_at']??null)||$r['prepared_at']<0||!is_int($r['expires']??null)||$r['expires']-$r['prepared_at']!==600||!is_int($r['confirmed_at']??null)||$r['confirmed_at']<0
            ||!is_string($r['sealed']??null)||strlen($r['sealed'])>1024||($r['state']!=='prepared'&&$r['sealed']!==''))support_failure(503,'code_state_invalid');
    }
    foreach($s['challenges'] as $id=>$c){
        if(!support_code_hex($id,32)||!is_array($c)||array_diff(array_keys($c),['conversation','epoch','session','email_key','state','verifier','tries','created','expires','sealed'])
            ||!support_valid_id($c['conversation']??null)||!support_code_hex($c['epoch']??null,32)||!support_code_hex($c['session']??null)||!support_code_hex($c['email_key']??null)
            ||!in_array($c['state']??null,['pending','accepted','verified','used','cancelled'],true)||!is_string($c['verifier']??null)||($c['verifier']!==''&&!support_code_hex($c['verifier']))
            ||!is_int($c['tries']??null)||$c['tries']<0||$c['tries']>5||!is_int($c['created']??null)||$c['created']<0||!is_int($c['expires']??null)||$c['expires']-$c['created']!==300
            ||!is_string($c['sealed']??null)||strlen($c['sealed'])>1024)support_failure(503,'code_state_invalid');
    }
    foreach($s['jobs'] as $id=>$j){
        if(!support_code_hex($id,32)||!is_array($j)||array_diff(array_keys($j),['kind','conversation','challenge','epoch','state','created','expires','payload','result'])
            ||!in_array($j['kind']??null,['verify','backup'],true)||!support_valid_id($j['conversation']??null)||!support_code_hex($j['challenge']??null,32)||!support_code_hex($j['epoch']??null,32)
            ||!in_array($j['state']??null,['pending','dispatching','accepted','failed','cancelled'],true)||!is_int($j['created']??null)||$j['created']<0||!is_int($j['expires']??null)||$j['expires']-$j['created']>600||$j['expires']<=$j['created']
            ||!is_string($j['payload']??null)||strlen($j['payload'])>2048||!in_array($j['result']??null,['','accepted','rejected','timeout','disabled'],true))support_failure(503,'code_state_invalid');
    }
    foreach($s['rates'] as $key=>$times)if(!support_code_hex($key)||!is_array($times)||count($times)>200||array_filter($times,fn($v)=>!is_int($v)||$v<0))support_failure(503,'code_state_invalid');
    foreach($s['events'] as $e)if(!is_array($e)||array_diff(array_keys($e),['at','event'])||!is_int($e['at']??null)||$e['at']<0||!in_array($e['event']??null,['prepared','confirmed','revoked','email_queued','email_accepted','email_failed','email_verified','backup_queued','backup_accepted','backup_failed','denied'],true))support_failure(503,'code_state_invalid');
}
/** Lock order: customer session -> chat -> code authority. Never call chat inside this lock. */
function support_code_store(callable $operation,bool $write=false,bool $initialize=false): mixed {
    if($initialize){if(PHP_SAPI!=='cli')support_failure(403,'forbidden');support_security_key();}
    $dir=support_private_dir(support_runtime_dir().'/code-recovery');$path=$dir.'/authority.json';$marker=$dir.'/initialized';$lockPath=$dir.'/authority.lock';
    foreach([$path,$marker,$lockPath] as $file)if(is_link($file)||(file_exists($file)&&!is_file($file)))support_failure(503,'code_state_invalid');
    $lock=@fopen($lockPath,'c+b');if(!$lock||!flock($lock,LOCK_EX)){if($lock)fclose($lock);support_failure(503,'recovery_unavailable');}
    try{
        if(!$initialize&&(!is_file($path)||!is_file($marker)))support_failure(503,'code_not_initialized');
        if(is_file($marker)&&file_get_contents($marker)!=='1')support_failure(503,'code_state_invalid');
        if($initialize&&!is_file($path)&&file_exists($marker))support_failure(503,'code_authority_missing');
        $s=['version'=>1,'records'=>[],'challenges'=>[],'jobs'=>[],'rates'=>[],'events'=>[]];
        if(is_file($path)){if(filesize($path)>4194304)support_failure(503,'code_capacity');try{$s=json_decode((string)file_get_contents($path),true,32,JSON_THROW_ON_ERROR);}catch(Throwable $e){support_failure(503,'code_state_invalid');}if(!is_array($s))support_failure(503,'code_state_invalid');}
        support_code_validate($s);$now=time();
        if($write){
            foreach($s['records'] as &$r)if($r['state']==='prepared'&&$r['expires']<=$now)$r['sealed']='';unset($r);
            foreach($s['challenges'] as $id=>$c)if($c['expires']<=$now)unset($s['challenges'][$id]);
            foreach($s['jobs'] as $id=>$j)if($j['expires']<=$now)unset($s['jobs'][$id]);
            foreach($s['rates'] as $key=>$times){$times=array_values(array_filter($times,fn($v)=>$v>$now-86400));if($times)$s['rates'][$key]=$times;else unset($s['rates'][$key]);}
            $s['events']=array_values(array_filter($s['events'],fn($e)=>$e['at']>$now-7*86400));
        }
        $error=null;$result=null;try{$result=$operation($s);}catch(SupportFailure $e){$error=$e;}
        if($write){support_code_validate($s);$bytes=json_encode($s,JSON_THROW_ON_ERROR);if(strlen($bytes)>4194304)support_failure(503,'code_capacity');support_atomic_replace($path,$bytes);}
        if($initialize&&!file_exists($marker))support_atomic_replace($marker,'1');
        if($error)throw $error;return $result;
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
function support_code_event(array &$s,string $event): void {$s['events'][]=['at'=>time(),'event'=>$event];}
function support_code_rate(array &$s,array $buckets): void {
    $now=time();foreach($buckets as [$domain,$value,$limit,$window]){$key=support_code_hash('rate-'.$domain,$value);$times=array_filter($s['rates'][$key]??[],fn($v)=>$v>$now-$window);if(count($times)>=$limit||(!isset($s['rates'][$key])&&count($s['rates'])>=1024)){header('Retry-After: 60');support_failure(429,'recovery_rate_limited');}}
    foreach($buckets as [$domain,$value])$s['rates'][support_code_hash('rate-'.$domain,$value)][]=$now;
}
function support_code_verifier(string $id,string $selector,string $secret): string {return hash_hmac('sha256',"conversation-recovery-v1\0{$id}\0{$selector}\0{$secret}",support_security_key());}
function support_code_matches(array $r,string $id,mixed $token): bool {
    return is_string($token)&&preg_match('/^ZL1\.([a-f0-9]{32})\.([A-Za-z0-9_-]{43})$/D',$token,$m)&&hash_equals($r['selector'],$m[1])&&hash_equals($r['verifier'],support_code_verifier($id,$m[1],$m[2]));
}
function support_code_credential(array $c): ?array {
    if(!support_code_exists())return null;
    return support_code_store(function(array &$s)use($c){$r=$s['records'][$c['user_id']]??null;if(!$r||$r['state']!=='active'||$r['owner']!==$c['owner_id']||!hash_equals($r['binding'],support_code_binding($c)))return null;return ['selector'=>$r['selector'],'verifier'=>$r['verifier'],'epoch'=>$r['epoch'],'revoked'=>false];});
}
function support_code_authenticate(array $chat,string $selector,string $secret,bool $valid): ?array {
    if(!support_code_exists())return null;
    return support_code_store(function(array &$s)use($chat,$selector,$secret,$valid){foreach($s['records'] as $id=>$r){
        if($r['selector']!==$selector||$r['state']!=='active')continue;$c=$chat['conversations'][$id]??null;
        if($valid&&$c&&$c['ownership']==='claimed'&&!isset($c['secret_recovery'])&&$r['owner']===$c['owner_id']&&hash_equals($r['binding'],support_code_binding($c))&&hash_equals($r['verifier'],support_code_verifier($id,$selector,$secret))&&!support_recovery_is_revoked($id))return ['conversation'=>$id,'owner'=>$c['owner_id'],'epoch'=>$r['epoch']];
    }return null;});
}
function support_code_revoke(array $c): bool {
    if(isset($c['secret_recovery'])||!support_code_exists())return false;
    return support_code_store(function(array &$s)use($c){$id=$c['user_id'];if(!isset($s['records'][$id]))return false;
        $r=&$s['records'][$id];if($r['owner']!==$c['owner_id']||$r['binding']!==support_code_binding($c))support_failure(503,'code_restore_conflict');
        if($r['state']!=='revoked'){ $r['state']='revoked';$r['sealed']='';support_code_cancel_email($s,$id);support_code_event($s,'revoked');}return true;
    },true);
}
function support_code_original(array $c,array $base,array $context): void {
    if(isset($context['_phone'])||isset($context['_recovered'])||!hash_equals($c['owner_id'],$base['owner_id']))support_failure(403,'original_session_required');
}
function support_code_action(array $base,string $action,array $fields): array {
    if(!support_code_enabled()&& !str_starts_with($action,'email_'))support_failure(404,'code_issuance_unavailable');
    if(str_starts_with($action,'email_')&&(!support_secret_recovery_enabled()||!support_code_email_enabled()))support_failure(404,'email_unavailable');
    return support_customer_transaction($base,function()use($base,$action,$fields){$context=support_customer_context();$id=support_string($fields['user_id']??null,100);
        return support_store(function(array &$chat,bool &$changed)use($base,$context,$id,$action,$fields){
            $c=support_conversation($chat,$id,$context,false);support_code_original($c,$base,$context);
            if($action==='status')return support_code_store(function(array &$s)use($id,$c,$base){$r=$s['records'][$id]??null;
                if(isset($c['secret_recovery']))return ['status'=>'success','state'=>'existing','may_prepare'=>false];
                if(!$r)return ['status'=>'success','state'=>'none','may_prepare'=>!support_recovery_is_revoked($id)];
                return ['status'=>'success','state'=>$r['state']==='prepared'&&$r['expires']<=time()?'expired':$r['state'],'may_prepare'=>$r['state']==='prepared',
                    'operation'=>$r['session']===support_code_session($base)?$r['operation']:null];
            });
            if($action==='prepare'){
                if(isset($c['secret_recovery'])||support_recovery_is_revoked($id))support_failure(409,'code_already_issued');
                $op=support_string($fields['operation_id']??null,36);if(!support_valid_uuid($op))support_failure(400,'invalid_input');
                return support_code_store(function(array &$s)use($id,$op,$base,$c){$r=$s['records'][$id]??null;$session=support_code_session($base);
                    if($r&&$r['state']!=='prepared')support_failure(409,'code_already_issued');
                    if($r&&$r['expires']>time()){
                        if($r['operation']!==$op||$r['session']!==$session||$r['owner']!==$c['owner_id']||$r['binding']!==support_code_binding($c))support_failure(409,'code_prepare_conflict');
                        return ['status'=>'success','state'=>'prepared','operation'=>$op,'recovery_secret'=>support_code_open($r['sealed'],$op)['token'],'expires_in'=>$r['expires']-time()];
                    }
                    support_code_rate($s,[['prepare-session',$session,10,86400],['prepare-ip',support_ip_key(),30,86400],['prepare-all','all',100,86400]]);
                    $selector=bin2hex(random_bytes(16));$secret=rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');$token='ZL1.'.$selector.'.'.$secret;$now=time();
                    $s['records'][$id]=['owner'=>$c['owner_id'],'binding'=>support_code_binding($c),'selector'=>$selector,'verifier'=>support_code_verifier($id,$selector,$secret),'epoch'=>bin2hex(random_bytes(16)),
                        'operation'=>$op,'session'=>$session,'state'=>'prepared','prepared_at'=>$now,'expires'=>$now+600,'confirmed_at'=>0,'sealed'=>support_code_seal(['token'=>$token],$op)];
                    support_code_event($s,'prepared');return ['status'=>'success','state'=>'prepared','operation'=>$op,'recovery_secret'=>$token,'expires_in'=>600];
                },true);
            }
            if($action==='confirm'){
                if(isset($c['secret_recovery'])||support_recovery_is_revoked($id))support_failure(409,'code_already_issued');
                return support_code_store(function(array &$s)use($id,$base,$c,$fields){if(!isset($s['records'][$id]))support_failure(409,'code_confirmation_invalid');$r=&$s['records'][$id];
                    if(!is_array($r)||$r['state']==='revoked'||$r['operation']!==($fields['operation_id']??null)||$r['session']!==support_code_session($base)||$r['owner']!==$c['owner_id']||$r['binding']!==support_code_binding($c)
                        ||($fields['acknowledged']??null)!=='1'||!support_code_matches($r,$id,$fields['recovery_secret']??null))support_failure(409,'code_confirmation_invalid');
                    if($r['state']==='prepared'){
                        if($r['expires']<=time())support_failure(409,'code_prepare_expired');
                        $r['state']='active';$r['confirmed_at']=time();$r['sealed']='';support_code_event($s,'confirmed');
                    }
                    return ['status'=>'success','state'=>'active'];
                },true);
            }
            if(str_starts_with($action,'email_'))return support_code_email_action($base,$c,$action,$fields);
            support_failure(400,'invalid_input');
        },true);
    });
}

function support_code_email(string $raw): string {
    $value=support_string($raw,254);if(!preg_match('/^[\x21-\x7e]+$/D',$value)||!filter_var($value,FILTER_VALIDATE_EMAIL))support_failure(400,'email_invalid');
    $at=strrpos($value,'@');return substr($value,0,$at).'@'.strtolower(substr($value,$at+1));
}
function support_code_email_ready(): bool {
    if(!support_secret_recovery_enabled()||!support_code_email_enabled())return false;
    if(getenv('SUPPORT_RECOVERY_EMAIL_PROVIDER')==='fake')return support_code_test_mode();
    return getenv('SUPPORT_RECOVERY_EMAIL_PROVIDER')==='php-mail'&&getenv('SUPPORT_RECOVERY_EMAIL_REAL_DELIVERY_ENABLED')==='1'&&function_exists('mail')&&function_exists('proc_open')
        && is_string(getenv('SUPPORT_RECOVERY_EMAIL_FROM'))&&filter_var(getenv('SUPPORT_RECOVERY_EMAIL_FROM'),FILTER_VALIDATE_EMAIL)!==false;
}
function support_code_cancel_email(array &$s,string $id): void {
    foreach($s['challenges'] as &$c)if($c['conversation']===$id){$c['state']='cancelled';$c['sealed']=$c['verifier']='';}unset($c);
    foreach($s['jobs'] as &$j)if($j['conversation']===$id&&!in_array($j['state'],['accepted','failed'],true)){$j['state']='cancelled';$j['payload']='';}unset($j);
}
function support_code_email_action(array $base,array $c,string $action,array $fields): array {
    $id=$c['user_id'];$session=support_code_session($base);
    if($action==='email_begin'&&!support_code_email_ready())support_failure(503,'email_unavailable');
    return support_code_store(function(array &$s)use($base,$c,$id,$session,$action,$fields){
        $r=$s['records'][$id]??null;if(!$r||$r['state']==='revoked'||$r['owner']!==$c['owner_id']||$r['binding']!==support_code_binding($c)||support_recovery_is_revoked($id))support_failure(409,'code_not_available');
        if($action==='email_begin'){
            if($r['state']==='prepared'&&$r['expires']<=time())support_failure(409,'code_prepare_expired');
            $email=support_code_email(support_string($fields['email']??null,254));$op=support_string($fields['operation_id']??null,36);if(!support_valid_uuid($op))support_failure(400,'invalid_input');
            $key=support_code_hash('email',$email);$challenge=substr(support_code_hash('email-operation',$session."\0".$op),0,32);
            if(isset($s['challenges'][$challenge])){if($s['challenges'][$challenge]['email_key']!==$key||$s['challenges'][$challenge]['conversation']!==$id||$s['challenges'][$challenge]['epoch']!==$r['epoch'])support_failure(409,'email_conflict');return ['status'=>'success','challenge'=>$challenge,'dispatch'=>'queued'];}
            support_code_rate($s,[['email-cooldown',$key,1,60],['email-day',$key,3,86400],['email-session',$session,5,86400],['email-ip',support_ip_key(),10,86400],['email-global','all',50,86400]]);
            support_code_cancel_email($s,$id);$code=str_pad((string)random_int(0,999999),6,'0',STR_PAD_LEFT);$now=time();
            $s['challenges'][$challenge]=['conversation'=>$id,'epoch'=>$r['epoch'],'session'=>$session,'email_key'=>$key,'state'=>'pending','verifier'=>support_code_hash('email-otp',$challenge."\0".$code),'tries'=>0,'created'=>$now,'expires'=>$now+300,'sealed'=>support_code_seal(['email'=>$email],$challenge)];
            $s['jobs'][$challenge]=['kind'=>'verify','conversation'=>$id,'challenge'=>$challenge,'epoch'=>$r['epoch'],'state'=>'pending','created'=>$now,'expires'=>$now+300,'payload'=>support_code_seal(['email'=>$email,'code'=>$code],$challenge),'result'=>''];
            support_code_event($s,'email_queued');return ['status'=>'success','challenge'=>$challenge,'dispatch'=>'queued'];
        }
        $challenge=support_string($fields['challenge']??null,32);$e=$s['challenges'][$challenge]??null;
        if(!$e||$e['conversation']!==$id||$e['epoch']!==$r['epoch']||$e['session']!==$session||$e['expires']<=time())support_failure(401,'email_verification_invalid');
        if($action==='email_status')return ['status'=>'success','verified'=>in_array($e['state'],['verified','used'],true)];
        if($action==='email_verify'){
            support_code_rate($s,[['email-guess-session',$session,30,600],['email-guess-ip',support_ip_key(),60,600]]);
            if($e['state']!=='accepted'||$e['tries']>=5)support_failure(401,'email_verification_invalid');
            $s['challenges'][$challenge]['tries']++;$code=strtr(support_string($fields['code']??null,24),array_combine(preg_split('//u','۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩',-1,PREG_SPLIT_NO_EMPTY),str_split('01234567890123456789')));
            if(!preg_match('/^[0-9]{6}$/D',$code)||!hash_equals($e['verifier'],support_code_hash('email-otp',$challenge."\0".$code))){support_code_event($s,'denied');support_failure(401,'email_verification_invalid');}
            $s['challenges'][$challenge]['state']='verified';$s['challenges'][$challenge]['verifier']='';support_code_event($s,'email_verified');return ['status'=>'success','verified'=>true];
        }
        if($action==='email_backup'){
            if(!support_code_email_ready())support_failure(503,'email_unavailable');
            $op=support_string($fields['operation_id']??null,36);if(!support_valid_uuid($op))support_failure(400,'invalid_input');$job=substr(support_code_hash('backup-operation',$session."\0".$op),0,32);
            if(isset($s['jobs'][$job])){if($s['jobs'][$job]['conversation']!==$id||$s['jobs'][$job]['challenge']!==$challenge)support_failure(409,'email_conflict');return ['status'=>'success','dispatch'=>$s['jobs'][$job]['state']];}
            if($e['state']!=='verified'||$r['state']!=='active'||!support_code_matches($r,$id,$fields['recovery_secret']??null))support_failure(409,'code_not_available');
            support_code_rate($s,[['backup-conversation',$id,2,3600],['backup-email',$e['email_key'],3,86400]]);
            $email=support_code_open($e['sealed'],$challenge)['email'];$now=time();
            $s['jobs'][$job]=['kind'=>'backup','conversation'=>$id,'challenge'=>$challenge,'epoch'=>$r['epoch'],'state'=>'pending','created'=>$now,'expires'=>$now+600,'payload'=>support_code_seal(['email'=>$email,'token'=>$fields['recovery_secret']],$job),'result'=>''];
            $s['challenges'][$challenge]['state']='used';$s['challenges'][$challenge]['sealed']='';support_code_event($s,'backup_queued');return ['status'=>'success','dispatch'=>'queued'];
        }
        support_failure(400,'invalid_input');
    },true);
}
