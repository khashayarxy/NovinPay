<?php
declare(strict_types=1);
require_once __DIR__.'/operator-security.php';

function support_attachment_ids(mixed $value): array {
    if($value===null||$value==='')return [];
    if(is_string($value)){try{$value=json_decode($value,true,4,JSON_THROW_ON_ERROR);}catch(Throwable $e){support_failure(400,'invalid_attachment');}}
    if(!is_array($value)||!array_is_list($value)||count($value)>3||count(array_unique($value,SORT_REGULAR))!==count($value))support_failure(400,'invalid_attachment');
    foreach($value as $id)if(!is_string($id)||!preg_match('/^[a-f0-9]{32}$/D',$id))support_failure(400,'invalid_attachment');return $value;
}
function support_attachment_validate(array $s): void {
    if(!isset($s['uploads']))return;
    if(!is_array($s['uploads'])||count($s['uploads'])>2048)support_failure(503,'attachment_capacity');
    foreach($s['uploads'] as $id=>$r){
        if(!preg_match('/^[a-f0-9]{32}$/D',(string)$id)||!is_array($r)||array_diff(array_keys($r),['sender','epoch','conversation','created','expires','bytes','mime','width','height','sha256','state'])
            ||!preg_match('/^(customer|operator):[a-f0-9]{64}$/D',$r['sender']??'')||!preg_match('/^[a-f0-9]{32}$/D',$r['epoch']??'')||!support_valid_id($r['conversation']??null)
            ||!is_int($r['created']??null)||!is_int($r['expires']??null)||$r['expires']-$r['created']!==86400||!is_int($r['bytes']??null)||$r['bytes']<1||$r['bytes']>5242880
            ||($r['mime']??null)!=='image/webp'||!is_int($r['width']??null)||!is_int($r['height']??null)||$r['width']<1||$r['height']<1||$r['width']>4096||$r['height']>4096
            ||!preg_match('/^[a-f0-9]{64}$/D',$r['sha256']??'')||!in_array($r['state']??null,['uploaded','bound'],true))support_failure(503,'attachment_state_invalid');
    }
    $referenced=[];foreach($s['conversations'] as $conversation=>$c)foreach($c['messages'] as $m)foreach($m['attachments']??[] as $id){
        $r=$s['uploads'][$id]??null;if(!$r||$r['state']!=='bound'||$r['conversation']!==$conversation||$r['sender']!==($m['sender_principal']??null)||isset($referenced[$id]))support_failure(503,'attachment_state_invalid');$referenced[$id]=true;
    }
}
function support_attachment_dir(): string {return support_private_dir(support_runtime_dir().'/attachments');}
function support_attachment_path(string $id): string {
    if(!preg_match('/^[a-f0-9]{32}$/D',$id))support_failure(404,'attachment_unavailable');return support_attachment_dir().'/'.$id.'.webp';
}
function support_attachment_live(array $s,string $id): bool {
    $r=$s['uploads'][$id]??null;if(!$r||$r['state']!=='bound')return false;
    foreach($s['conversations'][$r['conversation']]['messages']??[] as $m)if(in_array($id,$m['attachments']??[],true))return true;return false;
}
function support_attachment_bind(array &$s,array $context,string $role,string $conversation,array $ids): void {
    if(!$ids)return;if(getenv('SUPPORT_ATTACHMENTS_ENABLED')!=='1')support_failure(404,'attachments_disabled');
    if(count($ids)>support_option_int('SUPPORT_ATTACHMENT_MAX_IMAGES',3,1,3))support_failure(400,'invalid_attachment');
    foreach($ids as $id){$r=$s['uploads'][$id]??null;
        if(!$r||$r['state']!=='uploaded'||$r['expires']<=time()||$r['conversation']!==$conversation||$r['sender']!==support_principal($context,$role)||$r['epoch']!==$context['auth_epoch'])support_failure(409,'attachment_unavailable');
        $path=support_attachment_path($id);if(is_link($path)||!is_file($path)||filesize($path)!==$r['bytes']||hash_file('sha256',$path)!==$r['sha256'])support_failure(503,'attachment_unavailable');
    }
    foreach($ids as $id)$s['uploads'][$id]['state']='bound';
}
function support_attachment_upload(array $context,string $role,string $conversation,array $file,string $operation): array {
    if(getenv('SUPPORT_ATTACHMENTS_ENABLED')!=='1'||!extension_loaded('gd')||!function_exists('imagewebp'))support_failure(503,'attachments_disabled');
    if(!support_valid_uuid($operation))support_failure(400,'invalid_attachment');
    if(($file['error']??null)!==UPLOAD_ERR_OK||!is_string($file['tmp_name']??null)||!is_uploaded_file($file['tmp_name'])||!is_int($file['size']??null)||$file['size']<1||$file['size']>support_option_int('SUPPORT_ATTACHMENT_MAX_BYTES',5242880,1024,5242880))support_failure(400,'invalid_attachment');
    support_throttle('upload-ip:'.support_ip_key(),20);support_throttle('upload-session:'.$context['auth_epoch'],10);support_throttle('upload-conversation:'.$conversation,10);
    support_store(function(array &$s,bool &$changed)use($conversation,$context,$role){
        if($role==='customer'&&!isset($s['conversations'][$conversation])){support_draft_conversation($conversation,$context);return;}
        $c=support_conversation($s,$conversation,$role==='customer'?$context:null,$role==='operator');
        if($role==='customer'&&($c['workflow']['status']??'')==='resolved')support_failure(409,'conversation_resolved');
    });
    $dir=support_attachment_dir();$slot=null;
    for($i=0;$i<2;$i++){$slotPath=$dir.'/slot-'.$i.'.lock';if(is_link($slotPath))support_failure(503,'attachment_state_invalid');$candidate=@fopen($slotPath,'c+b');if($candidate&&flock($candidate,LOCK_EX|LOCK_NB)){$slot=$candidate;break;}if($candidate)fclose($candidate);}
    if(!$slot)support_failure(429,'upload_busy');
    try{
        $bytes=file_get_contents($file['tmp_name']);$info=@getimagesizefromstring($bytes);
        if(!$info||!in_array($info['mime']??null,['image/jpeg','image/png','image/webp'],true)||$info[0]<1||$info[1]<1
            ||$info[0]>support_option_int('SUPPORT_ATTACHMENT_MAX_DIMENSION',4096,64,4096)||$info[1]>support_option_int('SUPPORT_ATTACHMENT_MAX_DIMENSION',4096,64,4096)
            ||$info[0]*$info[1]>support_option_int('SUPPORT_ATTACHMENT_MAX_PIXELS',12000000,4096,12000000))support_failure(400,'invalid_attachment');
        $limit=ini_get('memory_limit');$memory=match(strtolower(substr($limit,-1))){'g'=>(int)$limit*1073741824,'m'=>(int)$limit*1048576,'k'=>(int)$limit*1024,default=>(int)$limit};
        if($memory>0&&memory_get_usage(true)+$info[0]*$info[1]*10+20*1048576>$memory)support_failure(503,'image_memory_budget');
        $image=@imagecreatefromstring($bytes);unset($bytes);if(!$image)support_failure(400,'invalid_attachment');
        try{ob_start();$ok=imagewebp($image,null,82);$safe=ob_get_clean();}finally{imagedestroy($image);}
        if(!$ok||!is_string($safe)||strlen($safe)>5242880)support_failure(400,'invalid_attachment');
        $id=substr(hash_hmac('sha256','upload-v1'.support_principal($context,$role).$context['auth_epoch'].$operation,support_security_key()),0,32);$path=support_attachment_path($id);
            support_store(function(array &$s,bool &$changed)use($id,$conversation,$context,$role,$safe,$info,$path){
                if(isset($s['uploads'][$id])){if($s['uploads'][$id]['sha256']!==hash('sha256',$safe)||$s['uploads'][$id]['conversation']!==$conversation)support_failure(409,'attachment_conflict');return;}
                $rows=$s['uploads']??[];$total=array_sum(array_column($rows,'bytes'));$day=0;$conv=0;
                foreach($rows as $r){if($r['created']>time()-86400)$day+=$r['bytes'];if($r['conversation']===$conversation)$conv+=$r['bytes'];}
                $size=strlen($safe);
                if(count($rows)>=2048||$total+$size>support_option_int('SUPPORT_ATTACHMENT_GLOBAL_BYTES',268435456,1048576,1073741824)||$day+$size>support_option_int('SUPPORT_ATTACHMENT_DAY_BYTES',52428800,1048576,268435456)||$conv+$size>support_option_int('SUPPORT_ATTACHMENT_CONVERSATION_BYTES',26214400,1048576,104857600))support_failure(429,'attachment_quota');
                $files=glob(support_attachment_dir().'/*.webp');if($files===false||count($files)>=2048)support_failure(429,'attachment_quota');
                // Binary first while holding the metadata lock. A failed JSON commit
                // leaves an inaccessible bounded orphan for private cleanup.
                if(is_link($path)||(is_file($path)&&hash_file('sha256',$path)!==hash('sha256',$safe)))support_failure(409,'attachment_conflict');
                if(!is_file($path))support_atomic_replace($path,$safe);
                $now=time();$s['uploads'][$id]=['sender'=>support_principal($context,$role),'epoch'=>$context['auth_epoch'],'conversation'=>$conversation,'created'=>$now,'expires'=>$now+86400,'bytes'=>$size,'mime'=>'image/webp','width'=>$info[0],'height'=>$info[1],'sha256'=>hash('sha256',$safe),'state'=>'uploaded'];$changed=true;
            },true);
        return ['status'=>'success','id'=>$id,'bytes'=>strlen($safe),'mime'=>'image/webp'];
    }finally{flock($slot,LOCK_UN);fclose($slot);}
}
function support_attachment_prune(bool $apply=false): array {
    if(PHP_SAPI!=='cli')support_failure(403,'forbidden');
    return support_store(function(array &$s,bool &$changed)use($apply){$remove=[];$kept=[];
        foreach($s['uploads']??[] as $id=>$r){if(!support_attachment_live($s,$id)&&($r['state']==='bound'||$r['expires']<=time()))$remove[$id]=true;else $kept[$id]=true;}
        $files=glob(support_attachment_dir().'/*.webp');if($files===false||count($files)>4096)support_failure(503,'attachment_capacity');
        $orphans=[];foreach($files as $file){$id=basename($file,'.webp');if(preg_match('/^[a-f0-9]{32}$/D',$id)&&!isset($kept[$id])&&(isset($remove[$id])||filemtime($file)<time()-86400))$orphans[]=$file;}
        $failed=[];$deleted=0;
        if($apply){
            foreach($orphans as $file){if(!is_link($file)&&function_exists('unlink')&&@unlink($file))$deleted++;else $failed[basename($file,'.webp')]=true;}
            // Keep inaccessible upload metadata on deletion failure so the next
            // bounded run retries immediately, even for a recently uploaded file.
            foreach($remove as $id=>$_)if(!isset($failed[$id])){unset($s['uploads'][$id]);$changed=true;}
        }return ['metadata'=>count($remove),'files'=>count($orphans),'files_deleted'=>$deleted,'files_failed'=>count($failed),'applied'=>$apply];
    },$apply);
}
