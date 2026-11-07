<?php
declare(strict_types=1);
require_once __DIR__.'/operators.php';

function support_profile_id(mixed $id): string {
    if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id))support_failure(400,'invalid_operator');
    return $id;
}
function support_profile_name(mixed $name): string {
    $name=support_string($name,120);
    if(trim($name)===''||preg_match('/[\x00-\x1f\x7f]/u',$name))support_failure(400,'invalid_operator');
    return trim($name);
}
function support_profile_meta(mixed $m): bool {
    return is_array($m)&&!array_diff(array_keys($m),['id','sha256','bytes','width','height'])&&preg_match('/^[a-f0-9]{32}$/D',$m['id']??'')&&preg_match('/^[a-f0-9]{64}$/D',$m['sha256']??'')&&is_int($m['bytes']??null)&&$m['bytes']>0&&$m['bytes']<=262144&&is_int($m['width']??null)&&$m['width']>0&&$m['width']<=512&&is_int($m['height']??null)&&$m['height']>0&&$m['height']<=512;
}
function support_profile_validate(array $a): void {
    if(isset($a['sender_aliases'])){if(!is_array($a['sender_aliases'])||!array_is_list($a['sender_aliases'])||count($a['sender_aliases'])>32||count(array_unique($a['sender_aliases']))!==count($a['sender_aliases']))support_failure(503,'operator_state_invalid');foreach($a['sender_aliases'] as $principal)if(!is_string($principal)||!preg_match('/^operator:[a-f0-9]{64}$/D',$principal))support_failure(503,'operator_state_invalid');}
    if(isset($a['public_profile'])){
        $p=$a['public_profile'];
        if(!is_array($p)||array_diff(array_keys($p),['name','enabled','avatar'])||!is_string($p['name']??null)||strlen($p['name'])>120||!mb_check_encoding($p['name'],'UTF-8')||!is_bool($p['enabled']??null)||$p['enabled']&&trim($p['name'])===''||!array_key_exists('avatar',$p)||$p['avatar']!==null&&!support_profile_meta($p['avatar']))support_failure(503,'operator_state_invalid');
    }
    if(isset($a['avatar_receipts'])){
        if(!is_array($a['avatar_receipts'])||count($a['avatar_receipts'])>8)support_failure(503,'operator_state_invalid');
        foreach($a['avatar_receipts'] as $id=>$r)if(!support_valid_uuid($id)||!is_array($r)||!support_profile_meta($r['asset']??null)||!is_int($r['expires']??null))support_failure(503,'operator_state_invalid');
    }
}
function support_profile_public(array $a): array {
    return support_ops_public($a)+['public_profile'=>$a['public_profile']??['name'=>$a['name'],'enabled'=>false,'avatar'=>null]];
}
function support_profile_audit(array &$s,array $op,string $event,string $target): void {
    support_ops_audit($s,$op['operator_id'],$event);$s['events'][array_key_last($s['events'])]['target']=$target;
}
function support_profile_target(array $op,mixed $id): string {
    $id=$id===null?$op['operator_id']:support_profile_id($id);
    if($id!==$op['operator_id'])support_ops_admin($op);
    return $id;
}
function support_profile_invalidate(array &$s,array &$a): void {
    $old='operator:'.hash('sha256',support_installation_id()."\0".$a['id']."\0".support_ops_version($a));$a['sender_aliases']=array_slice(array_values(array_unique(array_merge($a['sender_aliases']??[],[$old]))),-32);
    $a['epoch']=bin2hex(random_bytes(16));
    foreach($s['subscriptions'] as $key=>$sub)if($sub['operator']===$a['id'])unset($s['subscriptions'][$key]);
}
function support_profile_action(array $op,string $action,array $input): array {
    $id=support_profile_target($op,$input['id']??null);
    if($action==='account_update'||$action==='account_password')support_ops_admin($op);
    if($action==='profile_password'||$action==='account_password')support_throttle('password-change:'.$op['operator_id'],5,900);
    else support_throttle('profile-change:'.$op['operator_id'],30,3600);
    return support_ops_store(function(array &$s)use($op,$id,$action,$input){
        support_ops_recheck($s,$op);
        if(!isset($s['accounts'][$id]))support_failure(404,'operator_unavailable');
        $a=&$s['accounts'][$id];
        if($action==='profile_password'||$action==='account_password'){
            if($action==='profile_password'&&($id!==$op['operator_id']||!password_verify(support_ops_password($input['current_password']??null),$a['password'])))support_failure(403,'current_password_required');
            if($action==='account_password'&&$id===$op['operator_id'])support_failure(400,'use_self_password_flow');
            $password=support_ops_password($input['password']??null);
            if(strlen($password)<12)support_failure(400,'invalid_operator');
            support_profile_invalidate($s,$a);$a['password']=password_hash($password,PASSWORD_DEFAULT);
            support_profile_audit($s,$op,$action==='profile_password'?'password_changed':'password_reset',$id);
        }else{
            $name=support_profile_name($input['name']??null);
            if($action==='account_update'){
                $role=$input['role']??null;$active=$input['active']??null;
                if(!in_array($role,['support','administrator'],true)||!in_array($active,['0','1'],true))support_failure(400,'invalid_operator');
                $active=$active==='1';
                if($a['active']&&$a['role']==='administrator'&&(!$active||$role!=='administrator')&&count(array_filter($s['accounts'],fn($x)=>$x['active']&&$x['role']==='administrator'))<2)support_failure(409,'last_administrator');
                if($role!==$a['role']||$active!==$a['active'])support_profile_invalidate($s,$a);
                $a['role']=$role;$a['active']=$active;
            }
            $a['name']=$name;$p=$a['public_profile']??['name'=>$name,'enabled'=>false,'avatar'=>null];$p['name']=$name;
            if(array_key_exists('public',$input)){if(!in_array($input['public'],['0','1'],true))support_failure(400,'invalid_operator');$p['enabled']=$input['public']==='1';}
            $a['public_profile']=$p;support_profile_audit($s,$op,$action==='account_update'?'account_updated':'profile_updated',$id);
        }
        return ['status'=>'success','profile'=>support_profile_public($a),'reauthenticate'=>$id===$op['operator_id']&&!hash_equals(support_ops_version($a),$op['credential_version'])];
    },true);
}
function support_profile_directory(): string {return support_private_dir(support_runtime_dir().'/brand-images');}
function support_profile_avatar(array $op,array $input,?array $file): array {
    $id=support_profile_target($op,$input['id']??null);support_throttle('profile-avatar:'.$op['operator_id'],10,3600);
    $encoded=null;$remove=($input['remove']??'')==='1';$operation=$input['upload_id']??null;
    if(!$remove&&!support_valid_uuid($operation))support_failure(400,'invalid_message_id');
    if(!$remove){require_once __DIR__.'/profile-images.php';$encoded=support_profile_image_encode($file??[],true);}
    return support_ops_store(function(array &$s)use($op,$id,$remove,$operation,$encoded){
        support_ops_recheck($s,$op);if(!isset($s['accounts'][$id]))support_failure(404,'operator_unavailable');$a=&$s['accounts'][$id];
        $p=$a['public_profile']??['name'=>mb_strcut($a['name'],0,120,'UTF-8'),'enabled'=>false,'avatar'=>null];
        if($remove){$p['avatar']=null;}else{
            $receipts=$a['avatar_receipts']??[];foreach($receipts as $key=>$r)if($r['expires']<=time())unset($receipts[$key]);
            if(isset($receipts[$operation])){
                if(!hash_equals($receipts[$operation]['asset']['sha256'],$encoded['meta']['sha256']))support_failure(409,'avatar_conflict');
                return ['status'=>'success','profile'=>support_profile_public($a),'replayed'=>true];
            }
            if(count($receipts)>=8)support_failure(429,'avatar_capacity');
            $dir=support_profile_directory();$files=glob($dir.'/*.webp');if($files===false||count($files)>=256)support_failure(429,'avatar_capacity');$bytes=0;
            foreach($files as $f){if(is_link($f)||!is_file($f))support_failure(503,'avatar_invalid');$bytes+=filesize($f);}
            if($bytes+strlen($encoded['binary'])>33554432)support_failure(429,'avatar_capacity');
            $path=$dir.'/'.$encoded['meta']['id'].'.webp';if(file_exists($path)||is_link($path))support_failure(503,'avatar_invalid');support_atomic_replace($path,$encoded['binary']);
            $p['avatar']=$encoded['meta'];$receipts[$operation]=['asset'=>$encoded['meta'],'expires'=>time()+86400];$a['avatar_receipts']=$receipts;
        }
        $a['public_profile']=$p;support_profile_audit($s,$op,$remove?'avatar_removed':'avatar_changed',$id);
        return ['status'=>'success','profile'=>support_profile_public($a)];
    },true);
}
function support_profile_sender_map(): array {
    if(!support_multi_operator())return [];
    try{return support_ops_store(function(array &$s){$map=[];foreach($s['accounts'] as $a){$p=$a['public_profile']??null;if(!$a['active']||!$p||!$p['enabled'])continue;
        $principal='operator:'.hash('sha256',support_installation_id()."\0".$a['id']."\0".support_ops_version($a));
        $sender=['id'=>substr(hash_hmac('sha256','public-profile:'.$a['id'],support_security_key()),0,32),'name'=>$p['name'],'avatar'=>$p['avatar']?'operator-avatar.php?id='.$p['avatar']['id']:null];foreach(array_merge([$principal],$a['sender_aliases']??[]) as $alias)$map[$alias]=$sender;
    }return $map;});}catch(SupportFailure $e){$dir=support_runtime_dir().'/operators';if($e->reason==='operator_not_initialized'&&!file_exists($dir.'/initialized')&&!file_exists($dir.'/authority.json'))return [];throw $e;}
}

// These opaque aliases affect presentation only; they never authenticate an old session.
function support_profile_groups(array $op): array {
    return support_ops_store(function(array &$s)use($op){support_ops_recheck($s,$op);$a=$s['accounts'][$op['operator_id']];$principals=array_merge([support_principal($op,'operator')],$a['sender_aliases']??[]);return array_map(fn($p)=>substr(hash_hmac('sha256','sender-group:'.$p,support_security_key()),0,32),$principals);});
}

function support_profile_assigned(array $conversation): ?array {
    $id=$conversation['workflow']['assigned']??null;if(!$id||!support_multi_operator())return null;
    return support_ops_store(function(array &$s)use($id){$a=$s['accounts'][$id]??null;$p=$a['public_profile']??null;if(!$a||!$a['active']||!$p||!$p['enabled'])return null;return ['name'=>$p['name'],'avatar'=>$p['avatar']?'operator-avatar.php?id='.$p['avatar']['id']:null];});
}
