<?php
declare(strict_types=1);
require_once __DIR__.'/operator-security.php';
function support_multi_operator(): bool {return getenv('SUPPORT_MULTI_OPERATOR_ENABLED')==='1';}
function support_ops_password(mixed $password): string {if(!is_string($password)||$password===''||strlen($password)>72||!mb_check_encoding($password,'UTF-8'))support_failure(400,'invalid_operator');return $password;}
function support_ops_version(array $a): string {return hash('sha256',$a['password']."\0".$a['epoch']."\0".(int)$a['active']);}
function support_ops_public(array $a): array {return array_intersect_key($a,array_flip(['id','username','name','role','active']));}
function support_ops_visible_accounts(array $state): array {return array_values(array_filter($state['accounts'],fn($a)=>!isset($a['deleted_at'])));}
function support_ops_store(callable $operation,bool $write=false,bool $initialize=false): mixed {
    if($initialize&&PHP_SAPI!=='cli')support_failure(403,'forbidden');
    if($initialize)support_security_key();
    $dir=support_private_dir(support_runtime_dir().'/operators');$path=$dir.'/authority.json';$marker=$dir.'/initialized';$lp=$dir.'/authority.lock';
    foreach([$path,$marker,$lp] as $f)if(is_link($f)||(file_exists($f)&&!is_file($f)))support_failure(503,'operator_state_invalid');
    $lock=@fopen($lp,'c+b');if(!$lock||!flock($lock,LOCK_EX)){if($lock)fclose($lock);support_failure(503,'operator_unavailable');}
    try{
        if(!$initialize&&(!is_file($path)||!is_file($marker)))support_failure(503,'operator_not_initialized');
        if($initialize&&!is_file($path)&&file_exists($marker))support_failure(503,'operator_authority_missing');
        $s=['version'=>1,'accounts'=>[],'replies'=>[],'events'=>[],'subscriptions'=>[],'preferences'=>[]];$originalBytes=null;
        if(is_file($path)){if(filesize($path)>2097152)support_failure(503,'operator_capacity');try{$originalBytes=(string)file_get_contents($path);$s=json_decode($originalBytes,true,32,JSON_THROW_ON_ERROR);}catch(Throwable $e){support_failure(503,'operator_state_invalid');}}
        if(!is_array($s)||($s['version']??null)!==1||array_diff(array_keys($s),['version','accounts','replies','events','subscriptions','preferences','authentication']))support_failure(503,'operator_state_invalid');
        if(isset($s['authentication'])&&$s['authentication']!=='independent-v1')support_failure(503,'operator_state_invalid');
        foreach(['accounts'=>100,'replies'=>100,'events'=>4000,'subscriptions'=>200,'preferences'=>100] as $key=>$max)if(!is_array($s[$key]??null)||count($s[$key])>$max)support_failure(503,'operator_capacity');
        foreach($s['accounts'] as $id=>$a)if(!is_array($a)||($a['id']??null)!==$id||!preg_match('/^[a-f0-9]{32}$/D',(string)$id)||!preg_match('/^[a-zA-Z0-9_.-]{3,80}$/D',$a['username']??'')||!is_string($a['name']??null)||strlen($a['name'])>200||!is_string($a['password']??null)||!(password_get_info($a['password'])['algo']??null)||!in_array($a['role']??null,['administrator','support'],true)||!is_bool($a['active']??null)||!preg_match('/^[a-f0-9]{32}$/D',$a['epoch']??''))support_failure(503,'operator_state_invalid');
        foreach($s['accounts'] as $a){if(array_key_exists('deleted_at',$a)&&(!is_int($a['deleted_at'])||$a['deleted_at']<1||$a['active']))support_failure(503,'operator_state_invalid');if(isset($a['public_profile'])||isset($a['avatar_receipts'])||isset($a['sender_aliases'])){require_once __DIR__.'/operator-profile.php';support_profile_validate($a);}}
        $s['events']=array_values(array_filter($s['events'],fn($e)=>is_array($e)&&is_int($e['at']??null)&&$e['at']>time()-604800));
        foreach($s['subscriptions'] as $id=>$sub){if(!preg_match('/^[a-f0-9]{32}$/D',(string)$id)||!is_array($sub)||!isset($s['accounts'][$sub['operator']??''])||!is_string($sub['sealed']??null)||strlen($sub['sealed'])>4096||!is_int($sub['expires']??null)||!is_int($sub['session_expires']??null))support_failure(503,'operator_state_invalid');if($write&&$sub['expires']<=time())unset($s['subscriptions'][$id]);}
        $result=$operation($s);
        if($write){if(count($s['events'])>4000||count($s['accounts'])>100||count($s['replies'])>100||count($s['subscriptions'])>200)support_failure(503,'operator_capacity');$bytes=json_encode($s,JSON_THROW_ON_ERROR);if(strlen($bytes)>2097152)support_failure(503,'operator_capacity');if($bytes!==$originalBytes)support_atomic_replace($path,$bytes);elseif(!@chmod($path,0600))support_failure(503,'operator_unavailable');}
        if($initialize&&!is_file($marker))support_atomic_replace($marker,'1');return $result;
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
function support_ops_audit(array &$s,string $actor,string $event): void {$s['events'][]=['at'=>time(),'actor'=>$actor,'event'=>$event];}
function support_ops_account(string $username,string $name,string $password,string $role): array {
    if(!preg_match('/^[a-zA-Z0-9_.-]{3,80}$/D',$username)||strlen(support_ops_password($password))<12||!in_array($role,['administrator','support'],true))support_failure(400,'invalid_operator');
    return ['id'=>bin2hex(random_bytes(16)),'username'=>$username,'name'=>support_string($name,200),'password'=>password_hash($password,PASSWORD_DEFAULT),'role'=>$role,'active'=>true,'epoch'=>bin2hex(random_bytes(16))];
}
function support_ops_authenticate(string $username,string $password): array {
    support_login_reserve($username);$account=support_ops_store(function(array &$s)use($username){if(($s['authentication']??null)!=='independent-v1')support_failure(503,'operator_setup_required');foreach($s['accounts'] as $a)if(hash_equals($a['username'],$username))return $a;return null;});
    $hash=$account['password']??'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
    $valid=password_verify($password,$hash)&&$account&&$account['active'];support_login_result((bool)$valid,$username);if(!$valid)support_failure(401,'operator_login_failed');
    // Recheck and audit in the authority before issuing any session.
    return support_ops_store(function(array &$s)use($account){$a=$s['accounts'][$account['id']]??null;if(!$a||!$a['active']||support_ops_version($a)!==support_ops_version($account))support_failure(401,'operator_login_failed');support_ops_audit($s,$a['id'],'authentication_verified');return $a;},true);
}
function support_ops_session(array $context): ?array {
    $id=$context['operator_id']??null;if(!is_string($id))return null;
    return support_ops_store(function(array &$s)use($id,$context){if(($s['authentication']??null)!=='independent-v1')return null;$a=$s['accounts'][$id]??null;
        if(!$a||!$a['active']||!hash_equals(support_ops_version($a),$context['credential_version']??''))return null;
        return $context+['operator_role'=>$a['role'],'operator_name'=>$a['name']];
    });
}
function support_ops_admin(array $context): void {if(($context['operator_role']??null)!=='administrator')support_failure(403,'operator_permission_denied');}
// Called under the existing conversation-store lock. No separate lock service or cron.
function support_reply_ownership_validate(array $conversation): void {
    if(!array_key_exists('reply_ownership',$conversation))return;
    $lease=$conversation['reply_ownership'];
    if(!is_array($lease)||array_diff(array_keys($lease),['operator_id','credential_version','last_reply_at','episode'])||!preg_match('/^[a-f0-9]{32}$/D',$lease['operator_id']??'')||!preg_match('/^[a-f0-9]{64}$/D',$lease['credential_version']??'')||!is_int($lease['last_reply_at']??null)||$lease['last_reply_at']<1||!is_int($lease['episode']??null)||$lease['episode']<1)support_failure(503,'storage_invalid');
}
function support_reply_ownership(array $conversation,array $actor,?int $now=null): array {
    support_reply_ownership_validate($conversation);$now=$now??time();$lease=$conversation['reply_ownership']??null;$empty=['operator_id'=>null,'name'=>null,'expires_at'=>null,'server_now'=>$now,'checked_at'=>(int)floor(microtime(true)*1000),'is_owner'=>false];
    if(!support_multi_operator())return $empty;
    return support_ops_store(function(array &$state)use($conversation,$actor,$now,$lease,$empty){
        support_ops_recheck($state,$actor);
        if(!$lease||($conversation['workflow']['status']??'')==='resolved'||!empty($conversation['retired_at'])||$lease['episode']!==($conversation['episode']??1)||$now>=$lease['last_reply_at']+300)return $empty;
        $account=$state['accounts'][$lease['operator_id']]??null;
        if(!$account||!$account['active']||isset($account['deleted_at'])||!hash_equals(support_ops_version($account),$lease['credential_version']))return $empty;
        return ['operator_id'=>$account['id'],'name'=>mb_strcut($account['name'],0,120,'UTF-8'),'expires_at'=>$lease['last_reply_at']+300,'server_now'=>$now,'checked_at'=>$empty['checked_at'],'is_owner'=>$account['id']===$actor['operator_id']];
    });
}
function support_reply_require(array $conversation,array $actor): void {
    $ownership=support_reply_ownership($conversation,$actor);
    if($ownership['operator_id']!==null&&!$ownership['is_owner'])support_json(['status'=>'error','code'=>'conversation_owned','message'=>$ownership['name'].' در حال پاسخگویی به این گفتگو است.','reply_ownership'=>$ownership],409);
}
function support_ops_delete(array $context,string $id): array {
    support_ops_admin($context);
    return support_ops_store(function(array &$state)use($context,$id){
        support_ops_recheck($state,$context);
        if($id===$context['operator_id'])support_failure(403,'self_deletion_forbidden');
        if(!isset($state['accounts'][$id]))support_failure(404,'operator_unavailable');
        $account=&$state['accounts'][$id];
        if($account['active']&&$account['role']==='administrator'&&count(array_filter($state['accounts'],fn($a)=>$a['active']&&$a['role']==='administrator'))<=1)support_failure(409,'last_administrator');
        if($account['active'])support_failure(409,'operator_must_be_inactive');
        if(isset($account['deleted_at']))return ['status'=>'success','replayed'=>true];
        require_once __DIR__.'/operator-profile.php';
        support_profile_invalidate($state,$account);
        $account['password']=password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT);
        $account['deleted_at']=time();
        unset($state['preferences'][$id]);
        support_profile_audit($state,$context,'account_deleted',$id);
        return ['status'=>'success','replayed'=>false];
    },true);
}
// An explicit private local allowlist filters disposable test records from inbox
// presentation. It never deletes data or changes conversation authorization.
function support_development_conversations(): array {
    $file=getenv('SUPPORT_DEVELOPMENT_CONVERSATIONS_FILE')?:'';if(!support_development_ui()||$file==='')return [];
    $real=realpath($file);if(!$real||is_link($file)||!is_file($real)||filesize($real)>262144)support_failure(503,'development_configuration_invalid');
    $parent=support_private_dir(dirname($real));if(dirname($real)!==$parent)support_failure(503,'development_configuration_invalid');
    $data=json_decode(file_get_contents($real),true,8,JSON_THROW_ON_ERROR);
    if(($data['installation']??null)!==support_installation_id()||!is_array($data['conversations']??null)||!array_is_list($data['conversations'])||count($data['conversations'])>2000)support_failure(503,'development_configuration_invalid');
    foreach($data['conversations'] as $id)if(!is_string($id)||!support_valid_id($id))support_failure(503,'development_configuration_invalid');
    return array_fill_keys($data['conversations'],true);
}
function support_ops_recheck(array $s,array $context): void {$a=$s['accounts'][$context['operator_id']]??null;if(!$a||!$a['active']||!hash_equals(support_ops_version($a),$context['credential_version']))support_failure(401,'session_expired');}
function support_workflow(array $c): array {
    $w=($c['workflow']??[])+['status'=>'new','assigned'=>null,'labels'=>[],'notes'=>[],'events'=>[],'last_customer'=>0,'last_operator'=>0,'customer_at'=>0];
    require_once __DIR__.'/productivity.php';$w=support_productivity_defaults($w);
    $w['notes']=array_values(array_filter($w['notes']??[],fn($n)=>$n['at']>time()-604800));$w['events']=array_values(array_filter($w['events']??[],fn($n)=>$n['at']>time()-604800));return $w;
}
// Committed server IDs are monotonic under the conversation-store lock. Notes,
// browser pending rows and read acknowledgements never qualify as replies.
function support_needs_reply(array $c,array $w): bool {
    if($w['status']==='resolved')return false;if($w['reopened_pending']??false)return true;
    $customer=max([0,...array_column(array_filter(support_episode_messages($c),fn($m)=>$m['type']==='user'),'id')]);
    return $customer>support_team_answered_through($c);
}
function support_workflow_message(array &$s,string $id,string $role,int $message,?string $actor=null): void {
    if(!support_multi_operator())return;require_once __DIR__.'/satisfaction.php';$w=support_workflow($s['conversations'][$id]);
    if($role==='customer'){if($w['snoozed_until']>0){$w['snoozed_until']=0;$w['snoozed_at']=0;support_workflow_event($w,'customer','snooze_interrupted');}require_once __DIR__.'/lifecycle.php';if($w['status']==='resolved')support_lifecycle_transition($w,'awaiting_reply','customer');else $w['status']=$w['last_customer']===0&&$w['last_operator']===0?'new':'awaiting_reply';$w['last_customer']=$message;$w['customer_at']=time();}
    else{require_once __DIR__.'/lifecycle.php';if($w['status']==='resolved')support_lifecycle_transition($w,'awaiting_reply',$actor??'operator');else $w['status']='awaiting_reply';$w['last_operator']=$message;$w['reopened_pending']=false;}
    support_workflow_event($w,$actor??$role,'message_'.$message);
    $s['conversations'][$id]['workflow']=$w;
}
function support_workflow_change(array $context,string $id,array $fields): array {
    return support_store(function(array &$s,bool &$changed)use($context,$id,$fields){$c=support_conversation($s,$id,null,true);require_once __DIR__.'/satisfaction.php';$w=support_workflow($c);$action=$fields['operation']??'';
        support_ops_store(function(array &$ops)use($context){support_ops_recheck($ops,$context);});
        if($action==='status'){if(($fields['value']??null)!=='resolved')support_failure(400,'status_is_automatic');require_once __DIR__.'/lifecycle.php';if(!support_resolve_conversation($s['conversations'][$id],$w,$context['operator_id'],$changed))return ['status'=>'success','workflow'=>$w,'replayed'=>true];}
        elseif($action==='priority'){if(!in_array($fields['value']??null,['normal','urgent'],true))support_failure(400,'invalid_input');$w['priority']=$fields['value'];}
        elseif($action==='snooze'){$w['snoozed_until']=support_snooze_value($fields['value']??null);$w['snoozed_at']=$w['snoozed_until']?time():0;}
        elseif($action==='assign'){$value=support_string($fields['value']??'',32,true);if($value!=='')support_ops_store(function(array &$ops)use($value){if(!isset($ops['accounts'][$value])||!$ops['accounts'][$value]['active'])support_failure(400,'invalid_input');});$owner=support_reply_ownership($c,$context);if($owner['operator_id']!==null&&!$owner['is_owner']&&($context['operator_role']??null)!=='administrator')support_failure(403,'handoff_forbidden');if(($w['assigned']??null)!==($value===''?null:$value))unset($s['conversations'][$id]['reply_ownership']);$w['assigned']=$value===''?null:$value;}
        elseif($action==='labels'){$labels=json_decode(support_string($fields['value']??'[]',1500),true);if(!is_array($labels)||!array_is_list($labels)||count($labels)>20)support_failure(400,'invalid_input');$w['labels']=array_values(array_unique(array_map(fn($v)=>support_string($v,60),$labels)));}
        elseif($action==='note'){
            if(count($w['notes'])>=100)support_failure(429,'operator_capacity');$w['notes'][]=['id'=>bin2hex(random_bytes(16)),'author'=>$context['operator_id'],'at'=>time(),'text'=>support_string($fields['value']??null,2000)];
        }elseif($action==='delete_note'){$target=$fields['value']??'';$w['notes']=array_values(array_filter($w['notes'],function($n)use($target,$context){if($n['id']!==$target)return true;if($n['author']!==$context['operator_id']&&$context['operator_role']!=='administrator')support_failure(403,'operator_permission_denied');return false;}));}
        else support_failure(400,'invalid_input');
        support_workflow_event($w,$context['operator_id'],$action==='snooze'?'snooze_until_'.$w['snoozed_until']:$action);$s['conversations'][$id]['workflow']=$w;$changed=true;return ['status'=>'success','workflow'=>$w];
    },true);
}
