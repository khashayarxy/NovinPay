<?php
declare(strict_types=1);
require_once __DIR__.'/recovery.php';
require_once __DIR__.'/sms.php';

function support_phone_enabled(): bool {return getenv('SUPPORT_PHONE_RECOVERY_ENABLED')==='1';}
function support_phone_digits(string $value): string {
    return strtr($value,array_combine(preg_split('//u','۰۱۲۳۴۵۶۷۸۹٠١٢٣٤٥٦٧٨٩',-1,PREG_SPLIT_NO_EMPTY),str_split('01234567890123456789')));
}
function support_phone_normalize(mixed $value): string {
    $s=support_phone_digits(support_string($value,80));$s=preg_replace('/[ ()-]/','',$s);
    if(preg_match('/^09[0-9]{9}$/D',$s))return $s;
    if(preg_match('/^\+?989[0-9]{9}$/D',$s))return '0'.substr(ltrim($s,'+'),2);
    support_failure(400,'phone_invalid');
}
function support_phone_hash(string $domain,string $value): string {return hash_hmac('sha256',"support-phone-v1\0{$domain}\0{$value}",support_security_key());}
function support_phone_conversation_binding(array $c): string {return substr(support_phone_hash('conversation',$c['user_id']."\0".$c['owner_id']."\0".$c['created_at']),0,32);}
function support_phone_hex(mixed $v,int $n=64): bool {return is_string($v)&&preg_match('/^[a-f0-9]{'.$n.'}$/D',$v)===1;}
function support_phone_seal(array $payload,string $id): string {
    if(!function_exists('openssl_encrypt'))support_failure(503,'phone_unavailable');
    $nonce=random_bytes(12);$tag='';$key=hex2bin(support_phone_hash('encryption','dispatch'));
    $cipher=openssl_encrypt(json_encode($payload,JSON_THROW_ON_ERROR),'aes-256-gcm',$key,OPENSSL_RAW_DATA,$nonce,$tag,$id,16);
    if($cipher===false)support_failure(503,'phone_unavailable');return base64_encode($nonce.$tag.$cipher);
}
function support_phone_open(string $payload,string $id): array {
    $bytes=base64_decode($payload,true);if($bytes===false||strlen($bytes)<29)support_failure(503,'phone_invalid_state');
    $raw=openssl_decrypt(substr($bytes,28),'aes-256-gcm',hex2bin(support_phone_hash('encryption','dispatch')),OPENSSL_RAW_DATA,substr($bytes,0,12),substr($bytes,12,16),$id);
    if($raw===false)support_failure(503,'phone_invalid_state');
    $p=json_decode($raw,true,8,JSON_THROW_ON_ERROR);
    if(!is_array($p)||!preg_match('/^09[0-9]{9}$/D',$p['mobile']??'')||!preg_match('/^[0-9]{6}$/D',$p['code']??''))support_failure(503,'phone_invalid_state');return $p;
}
function support_phone_validate(array $s): void {
    if(($s['version']??null)!==1||array_diff(array_keys($s),['version','links','challenges','rates','events']))support_failure(503,'phone_invalid_state');
    foreach(['links'=>2000,'challenges'=>256,'rates'=>2048,'events'=>4000] as $field=>$max)if(!is_array($s[$field]??null)||count($s[$field])>$max)support_failure(503,'phone_capacity');
    foreach($s['links'] as $id=>$l){
        if(!support_valid_id($id)||!is_array($l)||array_diff(array_keys($l),['phone','epoch','owner','binding','at','mask'])
            ||!support_phone_hex($l['phone']??null)||!support_phone_hex($l['epoch']??null,32)||!support_phone_hex($l['owner']??null)||!support_phone_hex($l['binding']??null,32)
            ||!is_int($l['at']??null)||$l['at']<0||!is_string($l['mask']??null)||!preg_match('/^09\*{7}[0-9]{2}$/D',$l['mask']))support_failure(503,'phone_invalid_state');
    }
    foreach($s['challenges'] as $id=>$c){
        if(!support_phone_hex($id,32)||!is_array($c)||array_diff(array_keys($c),['phone','session','purpose','conversation','expected','owner','binding','created','expires','state','tries','verifier','payload'])
            ||!support_phone_hex($c['phone']??null)||!support_phone_hex($c['session']??null)||!in_array($c['purpose']??null,['link','recover'],true)
            ||!is_string($c['conversation']??null)||($c['conversation']!==''&&!support_valid_id($c['conversation']))
            ||!is_string($c['expected']??null)||($c['expected']!==''&&!support_phone_hex($c['expected'],32))
            ||!is_string($c['owner']??null)||($c['owner']!==''&&!support_phone_hex($c['owner']))
            ||!is_string($c['binding']??null)||($c['binding']!==''&&!support_phone_hex($c['binding'],32))
            ||!is_int($c['created']??null)||!is_int($c['expires']??null)||$c['expires']-$c['created']!==300
            ||!in_array($c['state']??null,['pending','dispatching','accepted','failed','used','cancelled'],true)||!is_int($c['tries']??null)||$c['tries']<0||$c['tries']>5
            ||!is_string($c['verifier']??null)||($c['verifier']!==''&&!support_phone_hex($c['verifier']))||!is_string($c['payload']??null)||strlen($c['payload'])>512)support_failure(503,'phone_invalid_state');
    }
    foreach($s['rates'] as $key=>$times)if(!support_phone_hex($key)||!is_array($times)||count($times)>300||array_filter($times,fn($v)=>!is_int($v)||$v<0))support_failure(503,'phone_invalid_state');
    foreach($s['events'] as $e)if(!is_array($e)||array_diff(array_keys($e),['at','event'])||!is_int($e['at']??null)||!in_array($e['event']??null,['queued','accepted','failed','denied','linked','unlinked','verified'],true))support_failure(503,'phone_invalid_state');
}
/** Never call chat/session functions from inside this lock. Chat -> phone only. */
function support_phone_store(callable $operation,bool $write=false,bool $initialize=false): mixed {
    if($initialize){if(PHP_SAPI!=='cli')support_failure(403,'forbidden');support_security_key();}
    $dir=support_private_dir(support_runtime_dir().'/phone-recovery');$path=$dir.'/authority.json';$lockPath=$dir.'/authority.lock';
    if(is_link($lockPath)||(file_exists($lockPath)&&!is_file($lockPath)))support_failure(503,'phone_unavailable');
    $lock=@fopen($lockPath,'c+b');if(!$lock||!flock($lock,LOCK_EX)){if($lock)fclose($lock);support_failure(503,'phone_unavailable');}
    try{
        $marker=$dir.'/initialized';if(is_link($path)||is_link($marker)||(file_exists($path)&&!is_file($path)))support_failure(503,'phone_unavailable');
        if(!$initialize&&(!is_file($path)||!is_file($marker)))support_failure(503,'phone_not_initialized');
        if(is_file($marker)&&file_get_contents($marker)!=='1')support_failure(503,'phone_invalid_state');
        if($initialize&&PHP_SAPI!=='cli')support_failure(403,'forbidden');
        if(!is_file($path)&&file_exists($marker))support_failure(503,'phone_authority_missing');
        $s=['version'=>1,'links'=>[],'challenges'=>[],'rates'=>[],'events'=>[]];
        if(is_file($path)){
            if(filesize($path)>2097152)support_failure(503,'phone_capacity');
            try{$s=json_decode((string)file_get_contents($path),true,32,JSON_THROW_ON_ERROR);}catch(Throwable $e){support_failure(503,'phone_invalid_state');}
            if(!is_array($s))support_failure(503,'phone_invalid_state');
        }
        support_phone_validate($s);
        if($write){$now=time();foreach($s['challenges'] as $id=>$c)if($c['expires']<=$now)unset($s['challenges'][$id]);
            foreach($s['links'] as $id=>$l)if($l['at']<$now-30*86400)unset($s['links'][$id]);
            foreach($s['rates'] as $key=>$times){$kept=array_values(array_filter($times,fn($v)=>$v>$now-86400));if($kept)$s['rates'][$key]=$kept;else unset($s['rates'][$key]);}
            $s['events']=array_values(array_filter($s['events'],fn($e)=>$e['at']>$now-7*86400));}
        $error=null;$result=null;try{$result=$operation($s);}catch(SupportFailure $e){$error=$e;}
        if($write){support_phone_validate($s);$bytes=json_encode($s,JSON_THROW_ON_ERROR);if(strlen($bytes)>2097152)support_failure(503,'phone_capacity');support_atomic_replace($path,$bytes);}
        if($initialize&&!file_exists($marker))support_atomic_replace($marker,'1');
        if($error)throw $error;return $result;
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
function support_phone_event(array &$s,string $event): void {$s['events'][]=['at'=>time(),'event'=>$event];}
function support_phone_rate(array &$s,array $buckets): void {
    $now=time();foreach($buckets as [$domain,$value,$limit,$window]){
        $key=support_phone_hash('rate-'.$domain,$value);$times=array_filter($s['rates'][$key]??[],fn($v)=>$v>$now-$window);
        if(count($times)>=$limit||(!isset($s['rates'][$key])&&count($s['rates'])>=2048)){header('Retry-After: 60');support_failure(429,'phone_rate_limited');}
    }
    foreach($buckets as [$domain,$value])$s['rates'][support_phone_hash('rate-'.$domain,$value)][]=$now;
}
function support_phone_binding(array $base): string {return support_phone_hash('session',$base['owner_id']."\0".$base['auth_epoch']);}
function support_phone_begin(array $base,array $context,string $purpose,string $mobile,string $operation,string $id): array {
    if(!support_sms_ready())support_failure(503,'sms_unavailable');
    if(!support_valid_uuid($operation)||!in_array($purpose,['link','recover'],true))support_failure(400,'invalid_input');
    if($purpose==='recover'&&$id!=='')support_failure(400,'invalid_input');
    if($purpose==='link'&&isset($context['_phone']))support_failure(403,'original_session_required');
    $phone=support_phone_hash('lookup',$mobile);$session=support_phone_binding($base);$challenge=substr(support_phone_hash('operation',$session."\0".$operation),0,32);$network=support_ip_key();
    $make=function(array &$s,string $owner='',string $binding='')use($phone,$session,$challenge,$purpose,$id,$mobile,$network){
        if(isset($s['challenges'][$challenge])){
            $c=$s['challenges'][$challenge];if($c['phone']!==$phone||$c['purpose']!==$purpose||$c['conversation']!==$id)support_failure(409,'phone_challenge_conflict');
            return ['status'=>'success','challenge'=>$challenge,'expires_in'=>max(0,$c['expires']-time()),'dispatch'=>'queued'];
        }
        support_phone_rate($s,[['cooldown',$phone,1,60],['phone-day',$phone,5,86400],['session-day',$session,10,86400],['network-day',$network,20,86400],['global-day','all',100,86400]]);
        // A new request supersedes only this session's older challenge for this purpose.
        foreach($s['challenges'] as &$c)if($c['session']===$session&&$c['purpose']===$purpose){$c['state']='cancelled';$c['verifier']=$c['payload']='';}unset($c);
        $code=str_pad((string)random_int(0,999999),6,'0',STR_PAD_LEFT);$now=time();
        $s['challenges'][$challenge]=['phone'=>$phone,'session'=>$session,'purpose'=>$purpose,'conversation'=>$id,'expected'=>$purpose==='link'?($s['links'][$id]['epoch']??''):'',
            'owner'=>$owner,'binding'=>$binding,'created'=>$now,'expires'=>$now+300,'state'=>'pending','tries'=>0,'verifier'=>support_phone_hash('otp',$challenge."\0".$code),'payload'=>support_phone_seal(['mobile'=>$mobile,'code'=>$code],$challenge)];
        support_phone_event($s,'queued');return ['status'=>'success','challenge'=>$challenge,'expires_in'=>300,'dispatch'=>'queued'];
    };
    if($purpose==='recover')return support_phone_store(fn(array &$s)=>$make($s),true);
    return support_store(function(array &$chat,bool &$changed)use($context,$id,$make){$c=support_conversation($chat,$id,$context,false);return support_phone_store(fn(array &$s)=>$make($s,$c['owner_id'],support_phone_conversation_binding($c)),true);},true);
}
function support_phone_challenge(array &$s,array $base,string $id,string $code,string $purpose): array {
    $binding=support_phone_binding($base);$c=$s['challenges'][$id]??null;
    // Throttle guesses even for missing/mismatched challenge IDs, in persistent state.
    support_phone_rate($s,[['verify-session',$binding,30,600],['verify-network',support_ip_key(),60,600]]);
    if(!$c||$c['session']!==$binding||$c['purpose']!==$purpose)support_failure(401,'otp_invalid');
    support_phone_rate($s,[['verify-phone',$c['phone'],30,600]]);
    if($c['state']!=='accepted'||$c['expires']<=time()||$c['tries']>=5)support_failure(401,'otp_invalid');
    $s['challenges'][$id]['tries']++;
    if(!preg_match('/^[0-9]{6}$/D',$code)||!hash_equals($c['verifier'],support_phone_hash('otp',$id."\0".$code))){support_phone_event($s,'denied');support_failure(401,'otp_invalid');}
    return $c;
}
function support_phone_consume(array &$s,string $id): void {$s['challenges'][$id]['state']='used';$s['challenges'][$id]['payload']=$s['challenges'][$id]['verifier']='';}
function support_phone_verify(array $base,array $context,string $id,string $code,string $purpose): array {
    if(!support_phone_hex($id,32)||!in_array($purpose,['link','recover'],true))support_failure(400,'invalid_input');
    if($purpose==='recover'){
        $links=support_phone_store(function(array &$s)use($base,$id,$code){$c=support_phone_challenge($s,$base,$id,$code,'recover');$links=[];
            foreach($s['links'] as $cid=>$l)if(hash_equals($c['phone'],$l['phone'])&&$l['at']>time()-30*86400)$links[$cid]=$l['epoch'];
            support_phone_consume($s,$id);support_phone_event($s,'verified');return $links;
        },true);
        // A lost response can be recovered with a fresh OTP; it never revokes links.
        support_recovery_replace_session(['phone_access'=>['at'=>time(),'links'=>$links,'selected'=>null]]);return ['status'=>'success'];
    }
    // Inspect target without nesting phone -> chat locks, then revalidate all fields.
    $target=support_phone_store(fn(array &$s)=>$s['challenges'][$id]['conversation']??'');
    return support_store(function(array &$chat,bool &$changed)use($target,$context,$base,$id,$code){
        $conversation=support_conversation($chat,$target,$context,false);
        return support_phone_store(function(array &$s)use($conversation,$chat,$base,$id,$code,$target,$context){
            $c=support_phone_challenge($s,$base,$id,$code,'link');
            if($c['owner']!==$conversation['owner_id']||$c['binding']!==support_phone_conversation_binding($conversation)||$c['expected']!==($s['links'][$target]['epoch']??''))support_failure(409,'phone_link_changed');
            // A shared/reassigned phone cannot add or replace a recovery method.
            // The original browser or existing conversation-specific ZL1 can.
            if(isset($context['_phone']))support_failure(403,'original_session_required');
            $count=count(array_filter($s['links'],fn($l)=>$l['phone']===$c['phone']));if($count>=20&&($s['links'][$target]['phone']??'')!==$c['phone'])support_failure(409,'phone_link_limit');
            $p=support_phone_open($c['payload'],$id);
            $s['links'][$target]=['phone'=>$c['phone'],'epoch'=>bin2hex(random_bytes(16)),'owner'=>$conversation['owner_id'],'binding'=>support_phone_conversation_binding($conversation),'at'=>time(),'mask'=>'09*******'.substr($p['mobile'],-2)];
            support_phone_consume($s,$id);support_phone_event($s,'linked');return ['status'=>'success'];
        },true);
    },true);
}
function support_phone_owned(array $c,array $context): bool {
    $a=$context['_phone']??null;if(!support_phone_enabled()||!is_array($a)||($a['conversation']??null)!==$c['user_id'])return false;
    return support_phone_store(function(array &$s)use($a,$c){$l=$s['links'][$c['user_id']]??null;return $l&&$c['ownership']==='claimed'&&$l['owner']===$c['owner_id']&&$l['binding']===$a['binding']&&$l['binding']===support_phone_conversation_binding($c)&&$l['epoch']===$a['epoch']&&$l['at']>time()-30*86400;});
}
function support_phone_context(array $base): array {
    if(!support_phone_enabled()){unset($base['phone_access']);return $base;}
    $a=$base['phone_access'];if(!is_array($a)||!is_int($a['at']??null)||$a['at']<time()-86400||!is_array($a['links']??null)||count($a['links'])>20)support_failure(401,'identity_changed');
    if(($a['selected']??null)===null)return $base;
    return support_store(function(array &$s,bool &$changed)use($a,$base){$id=$a['selected'];$c=$s['conversations'][$id]??null;if(!$c||!isset($a['links'][$id]))support_failure(401,'identity_changed');
        $base['owner_id']=$c['owner_id'];$base['_phone']=['conversation'=>$id,'epoch'=>$a['links'][$id],'binding'=>support_phone_conversation_binding($c)];
        if(!support_phone_owned($c,$base))support_failure(401,'identity_changed');return $base;
    });
}
function support_phone_list(array $base): array {
    $a=$base['phone_access']??null;if(!is_array($a)||!is_int($a['at']??null)||$a['at']<time()-86400||!is_array($a['links']??null)||count($a['links'])>20)support_failure(401,'otp_required');
    return support_store(function(array &$s,bool &$changed)use($a){return support_phone_store(function(array &$p)use($a,$s){$list=[];
        foreach($a['links'] as $id=>$epoch){$l=$p['links'][$id]??null;$c=$s['conversations'][$id]??null;
            if($l&&$c&&$l['epoch']===$epoch&&$l['binding']===support_phone_conversation_binding($c)&&$l['owner']===$c['owner_id']&&$c['ownership']==='claimed'&&$l['at']>time()-30*86400)$list[]=['id'=>$id,'name'=>$c['user_name'],'created_at'=>$c['created_at']];}
        return ['status'=>'success','conversations'=>$list];});});
}
function support_phone_action(array $base,string $action,array $fields): array {
    return support_customer_transaction($base,function()use($base,$action,$fields){
        if($action==='list')return support_phone_list($base);
        if($action==='select'){
            $id=support_string($fields['user_id']??null,100);$list=support_phone_list($base)['conversations'];
            if(!in_array($id,array_column($list,'id'),true))support_failure(404,'conversation_unavailable');
            $grant=$base['phone_access'];$grant['selected']=$id;support_recovery_replace_session(['phone_access'=>$grant]);return ['status'=>'success'];
        }
        // get_recovery_context + raw session remain usable after revoked selection.
        if(in_array($action,['begin','verify'],true)&&($fields['purpose']??null)==='recover')$context=$base;else $context=support_customer_context();
        if($action==='begin')return support_phone_begin($base,$context,support_string($fields['purpose']??null,10),support_phone_normalize($fields['phone']??null),support_string($fields['operation_id']??null,36),support_string($fields['user_id']??'',100,true));
        if($action==='verify')return support_phone_verify($base,$context,support_string($fields['challenge']??null,32),support_phone_digits(support_string($fields['code']??null,24)),support_string($fields['purpose']??null,10));
        $id=support_string($fields['user_id']??null,100);
        if($action==='status'||$action==='unlink')return support_store(function(array &$chat,bool &$changed)use($id,$context,$action){support_conversation($chat,$id,$context,false);
            return support_phone_store(function(array &$s)use($id,$context,$action){
                if($action==='status')return ['status'=>'success','mask'=>$s['links'][$id]['mask']??null];
                unset($s['links'][$id]);foreach($s['challenges'] as &$c)if($c['conversation']===$id){$c['state']='cancelled';$c['verifier']=$c['payload']='';}unset($c);
                support_phone_event($s,'unlinked');return ['status'=>'success'];
            },$action==='unlink');},$action==='unlink');
        support_failure(400,'invalid_input');
    });
}

function support_phone_dispatch_claim(): ?array {
    return support_phone_store(function(array &$s){foreach($s['challenges'] as $id=>&$c){
        if($c['state']!=='pending'||$c['expires']<=time())continue;
        $c['state']='dispatching';return ['id'=>$id,'payload'=>$c['payload']];
    }return null;},true);
}
function support_phone_dispatch_finish(string $id,string $outcome): void {
    support_phone_store(function(array &$s)use($id,$outcome){if(!isset($s['challenges'][$id])||$s['challenges'][$id]['state']!=='dispatching')return;
        $s['challenges'][$id]['state']=$outcome==='accepted'?'accepted':'failed';
        // Keep encrypted phone/code only until link verification or expiry.
        if($outcome!=='accepted')$s['challenges'][$id]['payload']=$s['challenges'][$id]['verifier']='';
        support_phone_event($s,$outcome==='accepted'?'accepted':'failed');
    },true);
}
function support_phone_dispatch(SupportSmsProvider $provider,int $limit=10): array {
    if(PHP_SAPI!=='cli'||!support_phone_enabled()||!support_sms_ready()||$limit<1||$limit>10)support_failure(403,'sms_disabled');
    $path=support_runtime_dir().'/phone-recovery/worker.lock';if(is_link($path))support_failure(503,'phone_unavailable');$lock=@fopen($path,'c+b');
    if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){if($lock)fclose($lock);support_failure(409,'sms_worker_busy');}
    try{$report=['processed'=>0,'accepted'=>0,'failed'=>0,'outcomes'=>[]];$deadline=microtime(true)+30;
        while($report['processed']<$limit&&microtime(true)<$deadline-8){$job=support_phone_dispatch_claim();if(!$job)break;$p=support_phone_open($job['payload'],$job['id']);
            try{$outcome=$provider->send($p['mobile'],$p['code']);}catch(Throwable $e){$outcome='timeout';}unset($p);
            if(!in_array($outcome,['accepted','rejected','timeout','quota','credentials','template','limited','malformed'],true))$outcome='malformed';
            support_phone_dispatch_finish($job['id'],$outcome);$report['processed']++;$report[$outcome==='accepted'?'accepted':'failed']++;$report['outcomes'][$outcome]=($report['outcomes'][$outcome]??0)+1;
        }return $report;
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
