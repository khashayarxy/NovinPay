<?php
declare(strict_types=1);
require_once __DIR__.'/operators.php';
function support_satisfaction_enabled(): bool {return getenv('SUPPORT_SATISFACTION_ENABLED')==='1';}
function support_resolution_transition(array &$w,string $status): void {
    if($status==='resolved'){
        if($w['status']!=='resolved'||!isset($w['resolution_event'])){$w['resolution_event']=bin2hex(random_bytes(16));$w['resolution_at']=time();}
    }else{unset($w['resolution_event'],$w['resolution_at']);}
    $w['status']=$status;
}
function support_satisfaction_validate(array $conversation): void {
    $w=$conversation['workflow']??[];if(!array_key_exists('resolution_event',$w)&&!array_key_exists('resolution_at',$w))return;
    if(!preg_match('/^[a-f0-9]{32}$/D',$w['resolution_event']??'')||!is_int($w['resolution_at']??null)||$w['resolution_at']<1||$w['status']!=='resolved')support_failure(503,'storage_invalid');
}
// Independent authority prevents an older chat snapshot from accepting a second
// vote. Restore this authority and the deletion ledger from their newest state.
function support_satisfaction_store(callable $operation,bool $write=false,bool $initialize=false): mixed {
    if($initialize&&PHP_SAPI!=='cli')support_failure(403,'forbidden');
    $dir=support_private_dir(support_runtime_dir().'/satisfaction');$path=$dir.'/authority.json';$marker=$dir.'/initialized';$lp=$dir.'/authority.lock';
    foreach([$path,$marker,$lp]as $f)if(is_link($f)||(file_exists($f)&&!is_file($f)))support_failure(503,'satisfaction_invalid');
    $lock=@fopen($lp,'c+b');if(!$lock||!flock($lock,LOCK_EX)){if($lock)fclose($lock);support_failure(503,'satisfaction_unavailable');}
    try{
        if(!$initialize&&(!is_file($path)||!is_file($marker)))support_failure(503,'satisfaction_not_initialized');
        if($initialize&&!is_file($path)&&file_exists($marker))support_failure(503,'satisfaction_authority_missing');
        $s=['version'=>1,'ratings'=>[]];if(is_file($path)){if(filesize($path)>1048576)support_failure(503,'satisfaction_capacity');$s=json_decode((string)file_get_contents($path),true,12,JSON_THROW_ON_ERROR);}
        if(!is_array($s)||($s['version']??null)!==1||array_diff(array_keys($s),['version','ratings','daily','dedup'])||!is_array($s['ratings']??null)||count($s['ratings'])>2048)support_failure(503,'satisfaction_invalid');
        foreach($s['ratings']as $key=>$r){if(!preg_match('/^[a-f0-9]{64}$/D',(string)$key)||!is_array($r)||array_diff(array_keys($r),['conversation','event','owner','value','at','expires','reason','comment','operator'])||!support_valid_id($r['conversation']??null)||!preg_match('/^[a-f0-9]{32}$/D',$r['event']??'')||!preg_match('/^[a-f0-9]{64}$/D',$r['owner']??'')||!in_array($r['value']??null,['positive','negative'],true)||!is_int($r['at']??null)||!is_int($r['expires']??null)||$r['expires']<$r['at']||$r['expires']>$r['at']+604800||$key!==hash('sha256',$r['conversation'].':'.$r['event']))support_failure(503,'satisfaction_invalid');if(isset($r['comment'])&&(!is_string($r['comment'])||strlen($r['comment'])>600||!mb_check_encoding($r['comment'],'UTF-8'))||isset($r['reason'])&&!in_array($r['reason'],['','unsolved','slow','unhelpful','other'],true)||isset($r['operator'])&&!preg_match('/^[a-f0-9]{32}$/D',$r['operator']))support_failure(503,'satisfaction_invalid');if($write&&$r['expires']<=time())unset($s['ratings'][$key]);}
        if(isset($s['daily'])&&(!is_array($s['daily'])||count($s['daily'])>92))support_failure(503,'satisfaction_invalid');
        foreach($s['daily']??[]as $day=>$daily){if(!preg_match('/^\d{4}-\d\d-\d\d$/D',(string)$day)||!is_array($daily)||array_diff(array_keys($daily),['positive','negative','operators'])||!is_int($daily['positive']??null)||$daily['positive']<0||!is_int($daily['negative']??null)||$daily['negative']<0||!is_array($daily['operators']??null)||count($daily['operators'])>100)support_failure(503,'satisfaction_invalid');foreach($daily['operators']as $actor=>$counts)if(!preg_match('/^[a-f0-9]{32}$/D',(string)$actor)||!is_array($counts)||array_diff(array_keys($counts),['positive','negative'])||array_filter($counts,fn($n)=>!is_int($n)||$n<0))support_failure(503,'satisfaction_invalid');}
        $s['dedup']??=[];if(!is_array($s['dedup']))support_failure(503,'satisfaction_invalid');foreach($s['dedup']as $k=>$v)if(!preg_match('/^[a-f0-9]{64}$/D',(string)$k)||!is_array($v)||array_diff(array_keys($v),['value','expires'])||!in_array($v['value']??null,['positive','negative'],true)||!is_int($v['expires']??null))support_failure(503,'satisfaction_invalid');foreach($s['dedup']as $k=>$v)if($v['expires']<=time())unset($s['dedup'][$k]);if(count($s['dedup'])>4096)support_failure(503,'satisfaction_capacity');
        $s['daily']??=[];foreach($s['daily'] as $day=>&$d){if($day<date('Y-m-d',time()-90*86400))unset($s['daily'][$day]);elseif($day<date('Y-m-d',time()-30*86400))$d['operators']=[];}unset($d);
        $result=$operation($s);if($write){$bytes=json_encode($s,JSON_THROW_ON_ERROR);if(count($s['ratings'])>2048||strlen($bytes)>1048576)support_failure(503,'satisfaction_capacity');support_atomic_replace($path,$bytes);}
        if($initialize&&!is_file($marker))support_atomic_replace($marker,'1');return $result;
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
function support_satisfaction_event(array $c): ?string {
    $w=$c['workflow']??[];return support_satisfaction_enabled()&&$c['messages']&&($w['status']??'')==='resolved'&&($w['resolution_at']??0)>time()-604800&&($w['resolution_at']??PHP_INT_MAX)<=time()&&is_string($w['resolution_event']??null)?$w['resolution_event']:null;
}
function support_satisfaction_offer(array $c): ?array {
    $event=support_satisfaction_event($c);if(!$event)return null;
    try{return support_satisfaction_store(function(array &$s)use($c,$event){$r=$s['ratings'][hash('sha256',$c['user_id'].':'.$event)]??null;return ['event'=>$event,'rating'=>$r['value']??($s['dedup'][support_satisfaction_dedup($c['user_id'],$event)]['value']??null)];});}catch(Throwable $e){return null;}
}
function support_satisfaction_dedup(string $id,string $event): string {return hash_hmac('sha256','csat-event:'.$id.':'.$event,support_security_key());}
function support_satisfaction_vote(array $context,string $id,string $event,string $value,string $reason='',string $comment=''): array {
    if(!support_satisfaction_enabled())support_failure(404,'satisfaction_disabled');
    if(!preg_match('/^[a-f0-9]{32}$/D',$event)||!in_array($value,['positive','negative'],true))support_failure(400,'invalid_rating');
    if(!in_array($reason,['','unsolved','slow','unhelpful','other'],true)||strlen($comment)>600||!mb_check_encoding($comment,'UTF-8')||$value==='positive'&&($reason!==''||$comment!==''))support_failure(400,'invalid_rating');
    return support_store(function(array &$chat,bool &$changed)use($context,$id,$event,$value,$reason,$comment){$c=support_conversation($chat,$id,$context,false);if(support_conversation_retired($c)||!$c['messages'])support_failure(409,'resolution_unavailable');$key=hash('sha256',$id.':'.$event);$owner=hash_hmac('sha256','satisfaction:'.$c['owner_id'],support_security_key());
        return support_satisfaction_store(function(array &$s)use($c,$key,$owner,$event,$value,$reason,$comment){$old=$s['ratings'][$key]??null;$dedup=support_satisfaction_dedup($c['user_id'],$event);if($old&&$old['expires']<=time())support_failure(409,'resolution_unavailable');if($old){if(!hash_equals($old['owner'],$owner)||$old['value']!==$value)support_failure(409,'rating_already_recorded');return ['status'=>'success','replayed'=>true,'rating'=>$old['value']];}
            if(isset($s['dedup'][$dedup])){if($s['dedup'][$dedup]['value']!==$value)support_failure(409,'rating_already_recorded');return ['status'=>'success','replayed'=>true,'rating'=>$value];}
            if(support_satisfaction_event($c)!==$event)support_failure(409,'resolution_unavailable');if(count($s['ratings'])>=2048)support_failure(429,'satisfaction_capacity');
            if(count($s['dedup'])>=4096)support_failure(429,'satisfaction_capacity');$s['dedup'][$dedup]=['value'=>$value,'expires'=>time()+90*86400];
            $s['ratings'][$key]=['conversation'=>$c['user_id'],'event'=>$event,'owner'=>$owner,'value'=>$value,'at'=>time(),'expires'=>$c['workflow']['resolution_at']+604800,'reason'=>$reason,'comment'=>$comment,'operator'=>$c['workflow']['closed_by']??null];
            $day=(new DateTimeImmutable('now',new DateTimeZone('Asia/Tehran')))->format('Y-m-d');$s['daily'][$day]??=['positive'=>0,'negative'=>0,'operators'=>[]];$s['daily'][$day][$value]++;$actor=$c['workflow']['closed_by']??null;if(is_string($actor)&&preg_match('/^[a-f0-9]{32}$/D',$actor)){$s['daily'][$day]['operators'][$actor][$value]=($s['daily'][$day]['operators'][$actor][$value]??0)+1;}return ['status'=>'success','replayed'=>false,'rating'=>$value];
        },true);
    });
}
function support_satisfaction_summary(): array {return support_satisfaction_store(function(array &$s){$counts=['positive'=>0,'negative'=>0];foreach($s['ratings']as $r)if($r['expires']>time())$counts[$r['value']]++;return $counts+['window_seconds'=>604800,'limit'=>2048];});}
