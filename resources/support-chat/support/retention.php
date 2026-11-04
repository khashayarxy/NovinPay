<?php
declare(strict_types=1);
require_once __DIR__.'/storage.php';
require_once __DIR__.'/operators.php';
function support_retention_tombstone(array &$c,int $now): void {
                        // Retain only the minimum ownership/idempotency tombstone.
                        $c=array_intersect_key($c,array_flip(['user_id','owner_id','ownership','created_at','messages','retained_receipts','history_removed_at','history_system_through']));
                        $c['retired_at']=$now;
                        $c+=['user_name'=>'مهمان','user_email'=>'','user_phone'=>'','last_message'=>'','last_time'=>'','unread_count'=>0,'contact_prompt_state'=>'skipped'];
}
function support_retention_enabled(): bool {return getenv('SUPPORT_RETENTION_ENABLED')==='1';}
function support_retention_ledger(): array {
    $path=support_runtime_dir().'/retention-ledger.json';
    if(is_link($path)||(file_exists($path)&&!is_file($path)))support_failure(503,'retention_unavailable');
    if(!file_exists($path))return ['version'=>1,'conversations'=>[]];
    if(filesize($path)>4194304)support_failure(503,'retention_unavailable');
    try{$s=json_decode((string)file_get_contents($path),true,32,JSON_THROW_ON_ERROR);}catch(Throwable $e){support_failure(503,'retention_unavailable');}
    if(!is_array($s)||($s['version']??null)!==1||!is_array($s['conversations']??null)||array_diff(array_keys($s),['version','conversations']))support_failure(503,'retention_unavailable');
    foreach($s['conversations'] as $id=>$row)if(!support_valid_id($id)||!is_array($row)||!is_int($row['through']??null)||$row['through']<1||!is_int($row['erased_at']??null)||$row['erased_at']<0||array_diff(array_keys($row),['through','erased_at']))support_failure(503,'retention_unavailable');
    return $s;
}
function support_retention_guard(array $s): void {
    // Always enforce an existing deletion ledger, including when scheduling is off.
    foreach(support_retention_ledger()['conversations'] as $id=>$row){
        if($s['last_user_message_id']<$row['through'])support_failure(503,'retention_restore_conflict');
        foreach($s['conversations'][$id]['messages']??[] as $m)if($m['id']<=$row['through'])support_failure(503,'retention_restore_conflict');
    }
}
function support_retention_pins(callable $op,bool $write=false): mixed {
    $dir=support_runtime_dir();$path=$dir.'/retention-pins.json';$lockPath=$dir.'/retention-pins.lock';
    if(is_link($lockPath)||(file_exists($lockPath)&&!is_file($lockPath)))support_failure(503,'retention_unavailable');
    $lock=@fopen($lockPath,'c+b');if(!$lock||!flock($lock,LOCK_EX)){if($lock)fclose($lock);support_failure(503,'retention_unavailable');}
    try{
        $pins=[];if(is_link($path)||(file_exists($path)&&!is_file($path)))support_failure(503,'retention_unavailable');
        if(is_file($path)){if(filesize($path)>4194304)support_failure(503,'retention_unavailable');try{$pins=json_decode((string)file_get_contents($path),true,16,JSON_THROW_ON_ERROR);}catch(Throwable $e){support_failure(503,'retention_unavailable');}}
        if(!is_array($pins))support_failure(503,'retention_unavailable');
        foreach($pins as $id=>$stamp)if(!support_valid_id($id)||!is_int($stamp)||$stamp<0)support_failure(503,'retention_unavailable');
        $changed=false;$result=$op($pins,$changed);
        if($write&&$changed)support_atomic_replace($path,json_encode($pins,JSON_THROW_ON_ERROR));return $result;
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
function support_retention_mark(array &$pins,bool &$changed,string $id): void {
    $now=time();foreach($pins as $key=>$stamp)if($now-$stamp>86400){unset($pins[$key]);$changed=true;}
    if($now-($pins[$id]??0)>=60){$pins[$id]=$now;$changed=true;}
}
function support_retention_touch(string $id): void {
    if(!support_retention_enabled())return;
    support_retention_pins(function(array &$pins,bool &$changed)use($id){support_retention_mark($pins,$changed,$id);},true);
}
function support_retention_pinned_read(string $id,callable $read): mixed {
    if(!support_retention_enabled())return $read();
    // Match cleanup's pin -> chat lock order and hold the pin lock across the
    // authorized read and pin persistence, closing the deletion race.
    return support_retention_pins(function(array &$pins,bool &$changed)use($id,$read){$result=$read();support_retention_mark($pins,$changed,$id);return $result;},true);
}
function support_retention_initialize(): array {
    if(PHP_SAPI!=='cli'||!support_retention_enabled())support_failure(403,'retention_disabled');
    $s=['version'=>1,'armed_at'=>time()];support_atomic_replace(support_runtime_dir().'/retention-policy.json',json_encode($s,JSON_THROW_ON_ERROR));return ['initialized'=>true,'warmup_seconds'=>300];
}
function support_retention_cleanup(bool $apply,int $limit=500,?int $now=null): array {
    if(PHP_SAPI!=='cli')support_failure(404,'not_found');
    $now??=time();if($limit<1||$limit>1000)support_failure(400,'invalid_limit');
    if($apply){
        if(!support_retention_enabled())support_failure(403,'retention_disabled');
        $path=support_runtime_dir().'/retention-policy.json';
        if(is_link($path)||!is_file($path)||filesize($path)>4096)support_failure(503,'retention_not_armed');
        try{$policy=json_decode((string)file_get_contents($path),true,8,JSON_THROW_ON_ERROR);}catch(Throwable $e){support_failure(503,'retention_not_armed');}
        if(!is_array($policy)||array_diff(array_keys($policy),['version','armed_at'])||($policy['version']??null)!==1||!is_int($policy['armed_at']??null)||$policy['armed_at']<0||$now-$policy['armed_at']<300)support_failure(503,'retention_warmup');
    }
    require_once __DIR__.'/notifications.php';$notificationLock=support_notification_lock();
    try{return support_retention_pins(function(array &$pins,bool &$pinChanged)use($apply,$limit,$now){
        return support_store(function(array &$s,bool &$changed)use($pins,$apply,$limit,$now){
            $ledger=support_retention_ledger();$cutoff=$now-7*86400;
            if($apply&&isset($s['analytics']))foreach($s['analytics']['days']as $day=>$bucket)if($day<date('Y-m-d',$now-90*86400)||$day<date('Y-m-d',$now-30*86400)&&!empty($bucket['operators'])){$changed=true;break;}
            $report=['dry_run'=>!$apply,'cutoff_utc'=>gmdate('Y-m-d\TH:i:s\Z',$cutoff),'messages'=>0,'tombstones_upgraded'=>0,'conversations'=>0,'active_skipped'=>0,'unanswered_skipped'=>0,'legacy_or_unknown_skipped'=>0,'closed_expired'=>0];
            foreach($s['conversations'] as $id=>&$c){
                if(!$c['messages']&&isset($c['history_removed_at'])&&!isset($c['retired_at'])&&($erased=strtotime($c['history_removed_at']))!==false&&$erased<$cutoff){
                    $report['tombstones_upgraded']++;if($apply){support_retention_tombstone($c,$now);unset($s['presence']['users'][$id]);$changed=true;}
                    if($report['messages']+$report['tombstones_upgraded']>=$limit)break;continue;
                }
                $w=support_workflow($c);$repairThrough=$ledger['conversations'][$id]['through']??0;
                $repair=array_filter($c['messages'],fn($m)=>$m['id']<=$repairThrough);
                if(!$repair){
                    // The approved lifecycle rule is closed-only: a genuine
                    // resolution expires seven days after its authoritative time.
                    if(($w['status']??'')!=='resolved'){$report['active_skipped']++;continue;}
                    $closed=(int)($w['resolution_at']??0);
                    if($closed<=0||$closed>=$cutoff){$report['active_skipped']++;continue;}
                    $actorUnread=false;if(isset($c['operator_reads'])&&support_multi_operator()){$accounts=support_ops_store(fn(array &$ops)=>$ops['accounts']);foreach($accounts as $oid=>$account)if($account['active']&&support_operator_unread($c,['operator_id'=>$oid])>0){$actorUnread=true;break;}}
                    if($actorUnread||($c['unread_count']??0)>0||array_filter($c['messages'],fn($m)=>($m['customer_read']??true)===false)){$report['unanswered_skipped']++;continue;}
                    if($now-($pins[$id]??0)<300||$now-($s['presence']['users'][$id]??0)<300){$report['active_skipped']++;continue;}
                    $report['closed_expired']++;
                }
                // The stored array need not be ordered after a valid restore.
                // Advance the ledger only across the oldest IDs first so a
                // bounded batch cannot strand a retained lower-ID message.
                $ordered=$c['messages'];usort($ordered,fn($a,$b)=>$a['id']<=>$b['id']);
                $selected=[];foreach($ordered as $m){
                    if(count($selected)+$report['messages']+$report['tombstones_upgraded']>=$limit)break;
                    if($repair&&$m['id']>$repairThrough)continue;$selected[$m['id']]=$m;
                }
                if(!$selected)continue;
                $report['messages']+=count($selected);$report['conversations']++;
                if($apply){
                    $through=max(array_keys($selected));$ledger['conversations'][$id]=['through'=>max($repairThrough,$through),'erased_at'=>$now];
                    // Ledger first: a crash or JSON write failure fails closed on reads.
                    // Re-running --apply completes an interrupted deletion without exposing stale text.
                    support_atomic_replace(support_runtime_dir().'/retention-ledger.json',json_encode($ledger,JSON_THROW_ON_ERROR));
                    foreach($selected as $m){
                        if($m['type']==='system'){if(isset($m['faq_at']))$c['history_system_through']=max($c['history_system_through']??0,$m['id']);continue;}
                        $receipt=array_intersect_key($m,array_flip(['id','client_message_id','sender_principal','payload_digest','accepted_at','protocol_version','reply_to']));
                        if(isset($m['notification']))$receipt['notification']=support_notification_retired($m['notification']);
                        if(isset($receipt['client_message_id'],$receipt['sender_principal'],$receipt['payload_digest'],$receipt['accepted_at'],$receipt['protocol_version']))$c['retained_receipts'][]=$receipt;
                    }
                    $c['messages']=array_values(array_filter($c['messages'],fn($m)=>!isset($selected[$m['id']])));
                    if(isset($c['message_edits']))$c['message_edits']=array_values(array_filter($c['message_edits'],fn($e)=>!isset($selected[$e['message_id']])));
                    if(!$c['messages']){
                        support_retention_tombstone($c,$now);
                        unset($s['presence']['users'][$id]);
                    }
                    else{$latest=$c['messages'][0];foreach($c['messages'] as $remaining)if($remaining['id']>$latest['id'])$latest=$remaining;$c['last_message']=$latest['edited_text']??$latest['text'];}
                    $c['history_removed_at']=gmdate('Y-m-d\TH:i:s\Z',$now);$changed=true;
                }
                if($report['messages']+$report['tombstones_upgraded']>=$limit)break;
            }
            unset($c);if($changed)$s['store_epoch']=bin2hex(random_bytes(16));return $report;
        },$apply,true);
    });}finally{flock($notificationLock,LOCK_UN);fclose($notificationLock);}
}
