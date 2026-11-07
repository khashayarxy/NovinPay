<?php
declare(strict_types=1);
require_once __DIR__.'/operator-security.php';
function support_secret_recovery_enabled(): bool {return getenv('SUPPORT_SECRET_RECOVERY_ENABLED')==='1';}
function support_recovery_validate(array $r): void {
    if(array_diff(array_keys($r),['version','selector','verifier','epoch','created_at','revoked']) || ($r['version']??null)!==1
        || !is_string($r['selector']??null) || !preg_match('/^[a-f0-9]{32}$/D',$r['selector'])
        || !is_string($r['epoch']??null) || !preg_match('/^[a-f0-9]{32}$/D',$r['epoch'])
        || !is_string($r['created_at']??null) || !preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/D',$r['created_at'])
        || !is_bool($r['revoked']??null) || !is_string($r['verifier']??null)
        || ($r['revoked']?$r['verifier']!=='':!preg_match('/^[a-f0-9]{64}$/D',$r['verifier']))) support_failure(503,'storage_invalid');
}
function support_recovered_owned(array $c,array $context): bool {
    $a=$context['_recovered']??null;$r=$c['secret_recovery']??null;
    if($r===null){require_once __DIR__.'/code-recovery.php';$r=support_code_credential($c);}
    return support_secret_recovery_enabled() && is_array($a) && is_array($r) && !$r['revoked'] && !support_recovery_is_revoked($c['user_id'])
        && ($c['ownership']??null)==='claimed' && ($a['conversation']??null)===$c['user_id']
        && is_string($a['epoch']??null) && hash_equals($r['epoch'],$a['epoch'])
        && hash_equals($c['owner_id'],$context['owner_id']);
}
function support_customer_context(bool $initialize=false): array {
    $base=support_session('customer',$initialize);
    if(isset($base['phone_access'])) {require_once __DIR__.'/phone.php';return support_phone_context($base);}
    if(!isset($base['recovery_access']))return $base;
    if(!support_secret_recovery_enabled()){unset($base['recovery_access']);return $base;}
    $a=$base['recovery_access'];
    if(!is_array($a) || !support_valid_id($a['conversation']??null) || !is_string($a['owner']??null)
        || !preg_match('/^[a-f0-9]{64}$/D',$a['owner']) || !is_string($a['epoch']??null) || !preg_match('/^[a-f0-9]{32}$/D',$a['epoch']))support_failure(401,'identity_changed');
    $context=$base;$context['owner_id']=$a['owner'];$context['_recovered']=$a;
    support_store(function(array &$s,bool &$changed) use($a,$context) {
        $c=$s['conversations'][$a['conversation']]??null;
        if(!$c || !support_recovered_owned($c,$context))support_failure(401,'identity_changed');
    });
    return $context;
}
function support_recovery_renew(array $expected,?array $access): void {
    support_customer_transaction($expected,fn()=>support_recovery_replace_session($access===null?[]:['recovery_access'=>$access]));
}
/** Lock order: customer session -> chat store -> recovery authority. */
function support_customer_transaction(array $base,callable $operation): mixed {
    if(session_name()!==support_session_name('customer') || !session_start())support_failure(503,'session_unavailable');
    try {
        if(!is_string($_SESSION['auth_epoch']??null) || !hash_equals($base['auth_epoch'],$_SESSION['auth_epoch'])
            || !is_string($_SESSION['owner_id']??null) || !hash_equals($base['owner_id'],$_SESSION['owner_id'])
            || time()-($_SESSION['issued_at']??0)>30*86400 || time()-($_SESSION['last_seen']??0)>7*86400)support_failure(401,'session_expired');
        return $operation();
    } finally {support_close_session();}
}
function support_recovery_replace_session(array $grant): void {
    if(session_status()!==PHP_SESSION_ACTIVE || !session_regenerate_id(true))support_failure(503,'session_unavailable');
    $_SESSION=['role'=>'customer','owner_id'=>bin2hex(random_bytes(32)),'issued_at'=>time(),'last_seen'=>time(),
        'csrf_token'=>bin2hex(random_bytes(32)),'auth_epoch'=>bin2hex(random_bytes(16))]+$grant;
}
function support_recovery_security(callable $operation,bool $write=true): mixed {
    $dir=support_private_dir(support_runtime_dir().'/secret-recovery');$path=$dir.'/security.json';$lockPath=$dir.'/security.lock';
    if(is_link($lockPath) || (file_exists($lockPath)&&!is_file($lockPath)))support_failure(503,'recovery_unavailable');
    $lock=@fopen($lockPath,'c+b');if(!$lock||!flock($lock,LOCK_EX)){if($lock)fclose($lock);support_failure(503,'recovery_unavailable');}
    try {
        if(is_link($path)||(file_exists($path)&&!is_file($path)))support_failure(503,'recovery_unavailable');
        if(!file_exists($path)&&file_exists($dir.'/required'))support_failure(503,'recovery_unavailable');
        $s=['version'=>1,'rates'=>[],'events'=>[]];
        if(file_exists($path)){
            if(filesize($path)>4194304)support_failure(503,'recovery_unavailable');
            try{$s=json_decode((string)file_get_contents($path),true,32,JSON_THROW_ON_ERROR);}catch(Throwable $e){support_failure(503,'recovery_unavailable');}
        }
        if(!is_array($s)||($s['version']??null)!==1||array_diff(array_keys($s),['version','rates','events','revocations'])||!is_array($s['rates']??null)||!is_array($s['events']??null)||count($s['rates'])>1024||count($s['events'])>14000)support_failure(503,'recovery_unavailable');
        $s['revocations']??=[];
        if(!is_array($s['revocations'])||count($s['revocations'])>4096)support_failure(503,'recovery_unavailable');
        foreach($s['revocations'] as $id=>$at)if(!support_valid_id($id)||!is_int($at)||$at<0)support_failure(503,'recovery_unavailable');
        foreach($s['rates'] as $key=>$times){if(!is_string($key)||!preg_match('/^[a-f0-9]{64}$/D',$key)||!is_array($times)||count($times)>200||array_filter($times,fn($t)=>!is_int($t)||$t<0))support_failure(503,'recovery_unavailable');}
        foreach($s['events'] as $e)if(!is_array($e)||array_diff(array_keys($e),['at','event'])||!is_int($e['at']??null)||!in_array($e['event']??null,['attempt','denied','recovered','created','revoked','throttled'],true))support_failure(503,'recovery_unavailable');
        $now=time();foreach($s['rates'] as $k=>$times){$times=array_values(array_filter($times,fn($t)=>$now-$t<600));if($times)$s['rates'][$k]=$times;else unset($s['rates'][$k]);}
        $s['events']=array_values(array_filter($s['events'],fn($e)=>$now-$e['at']<7*86400));
        $error=null;try{$result=$operation($s);}catch(SupportFailure $e){$error=$e;$result=null;}
        if(count($s['events'])>14000||count($s['revocations'])>4096)support_failure(503,'recovery_unavailable');
        $bytes=json_encode($s,JSON_THROW_ON_ERROR);if(strlen($bytes)>4194304)support_failure(503,'recovery_unavailable');
        if($write){if(!file_exists($dir.'/required'))support_atomic_replace($dir.'/required','1');support_atomic_replace($path,$bytes);}
        if($error)throw $error;return $result;
    } finally {flock($lock,LOCK_UN);fclose($lock);}
}
function support_recovery_is_revoked(string $id): bool {
    return support_recovery_security(fn(array &$s)=>isset($s['revocations'][$id]),false);
}
function support_recovery_event(string $event): void {support_recovery_security(function(array &$s)use($event){$s['events'][]=['at'=>time(),'event'=>$event];});}
function support_recovery_attempt(): void {
    $ip=$_SERVER['REMOTE_ADDR']??'';$packed=is_string($ip)?@inet_pton($ip):false;if($packed===false)support_failure(400,'invalid_input');
    if(strlen($packed)===16&&substr($packed,0,12)===str_repeat("\0",10)."\xff\xff")$packed=substr($packed,12);
    $network=hash_hmac('sha256','recovery-network:'.$packed,support_security_key());$global=hash_hmac('sha256','recovery-global',support_security_key());
    support_recovery_security(function(array &$s)use($network,$global){
        foreach([[$network,10],[$global,100]] as [$key,$limit]){
            if(count($s['rates'][$key]??[])>=$limit||(!isset($s['rates'][$key])&&count($s['rates'])>=1024)){$s['events'][]=['at'=>time(),'event'=>'throttled'];header('Retry-After: 600');support_failure(429,'rate_limited');}
        }
        foreach([$network,$global] as $key)$s['rates'][$key][]=time();$s['events'][]=['at'=>time(),'event'=>'attempt'];
    });
}
function support_recovery_create(array $context,string $id): array {
    // Both initial issuance and rotation have unsafe delivery semantics. Existing
    // ZL1 credentials remain usable; no caller (HTTP or CLI) can issue another.
    support_failure(409,'secret_issuance_disabled');
}
function support_recovery_revoke(array $context,string $id): void {
    support_store(function(array &$s,bool &$changed)use($context,$id){
        $c=support_conversation($s,$id,$context,false);
        require_once __DIR__.'/code-recovery.php';if(support_code_revoke($c))return;
        if(!isset($c['secret_recovery']))return; // Nothing was issued; do not forbid first issuance by accident.
        // Verifier snapshot is historical only; this authority survives chat restore.
        // Revocation and honest audit commit in ONE file. No post-commit audit.
        support_recovery_security(function(array &$r)use($id){$r['revocations'][$id]=time();$r['events'][]=['at'=>time(),'event'=>'revoked'];});
    },true);
}
function support_recovery_authenticate(mixed $token): array {
    support_recovery_attempt();$valid=is_string($token)&&preg_match('/^ZL1\.([a-f0-9]{32})\.([A-Za-z0-9_-]{43})$/D',$token,$m);
    $selector=$valid?$m[1]:str_repeat('0',32);$secret=$valid?$m[2]:str_repeat('0',43);
    $access=support_store(function(array &$s,bool &$changed)use($selector,$secret,$valid){
        $found=null;foreach($s['conversations'] as $id=>$c){
            $r=$c['secret_recovery']??null;if(!$r||$r['selector']!==$selector)continue;
            $expected=hash_hmac('sha256',"conversation-recovery-v1\0{$id}\0{$selector}\0{$secret}",support_security_key());
            if($valid&&!$r['revoked']&&$c['ownership']==='claimed'&&hash_equals($r['verifier'],$expected)&&!support_recovery_is_revoked($id))$found=['conversation'=>$id,'owner'=>$c['owner_id'],'epoch'=>$r['epoch']];
        }
        // A missing selector still performs a keyed digest and constant-time comparison.
        if(!$found){require_once __DIR__.'/code-recovery.php';$found=support_code_authenticate($s,$selector,$secret,$valid);}
        if(!$found)hash_equals(str_repeat('0',64),hash_hmac('sha256',$secret,support_security_key()));return $found;
    });
    support_recovery_event($access?'recovered':'denied');if(!$access)support_failure(401,'recovery_denied');return $access;
}
