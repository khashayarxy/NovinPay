<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/operator-reads.php';
require_once __DIR__.'/message-edits.php';
function support_conversation_retired(array $conversation): bool {return isset($conversation['retired_at'])||(!$conversation['messages']&&isset($conversation['history_removed_at']));}
function support_episode_start(array $conversation): int {return max(0,(int)($conversation['episode_start_sequence']??0));}
function support_episode_messages(array $conversation,bool $customer=false): array {
    $start=support_episode_start($conversation);
    return array_values(array_filter($conversation['messages'],fn($m)=>(int)$m['id']>$start&&(!$customer||empty($m['episode_boundary']))));
}
function support_valid_id(mixed $id): bool { return is_string($id) && preg_match('/^user_[a-f0-9]{32}_[0-9]{1,20}$/D',$id)===1; }
// FAQ origin is derived from server-owned pairing metadata, never message text.
// This recognizes existing system FAQ records without rewriting historical data.
function support_faq_auto_message(array $m): bool {
    return ($m['type']??null)==='system'&&($m['faq_answer_only']??false)===true
        && is_string($m['faq_id']??null)&&preg_match('/^[a-f0-9]{32}$/D',$m['faq_id'])===1
        && is_string($m['faq_digest']??null)&&preg_match('/^[a-f0-9]{64}$/D',$m['faq_digest'])===1
        && is_int($m['question_message_id']??null)&&$m['question_message_id']>0;
}
function support_validate_store(array &$state): bool {
    require_once __DIR__.'/analytics.php';support_metrics_validate($state);
    $legacy=!isset($state['schema_version']);
    $version=$state['schema_version']??1;
    if ((!$legacy && !in_array($version,[2,3],true)) || !is_array($state['conversations']??null)
        || !is_int($state['last_user_message_id']??null) || $state['last_user_message_id']<0) support_failure(503,'storage_invalid');
    require_once __DIR__.'/replies.php';
    $max=0;$ids=[];
    foreach ($state['conversations'] as $id=>&$conversation) {
        if (!support_valid_id($id) || !is_array($conversation) || ($conversation['user_id']??null)!==$id
            || !is_string($conversation['user_name']??null) || !is_string($conversation['user_email']??null)
            || !is_string($conversation['created_at']??null) || !is_array($conversation['messages']??null)) support_failure(503,'storage_invalid');
        if ($legacy) { $conversation['ownership']='legacy_unclaimed'; $conversation['owner_id']=null; }
        if (!in_array($conversation['ownership']??null,['claimed','legacy_unclaimed'],true)) support_failure(503,'storage_invalid');
        if ($conversation['ownership']==='claimed' && (!is_string($conversation['owner_id']??null) || preg_match('/^[a-f0-9]{64}$/D',$conversation['owner_id'])!==1)) support_failure(503,'storage_invalid');
        if ($conversation['ownership']==='legacy_unclaimed' && ($conversation['owner_id']??null)!==null) support_failure(503,'storage_invalid');
        if(isset($conversation['secret_recovery'])) {
            require_once __DIR__.'/recovery.php';
            if(!is_array($conversation['secret_recovery']))support_failure(503,'storage_invalid');
            support_recovery_validate($conversation['secret_recovery']);
        }
        require_once __DIR__.'/productivity.php';support_productivity_validate($conversation);support_reply_ownership_validate($conversation);
        require_once __DIR__.'/context.php';support_source_validate($conversation);
        require_once __DIR__.'/satisfaction.php';support_satisfaction_validate($conversation);
        if(isset($conversation['last_activity_at'])&&(!is_int($conversation['last_activity_at'])||$conversation['last_activity_at']<0))support_failure(503,'storage_invalid');
        if(isset($conversation['episode'])&&(!is_int($conversation['episode'])||$conversation['episode']<1))support_failure(503,'storage_invalid');
        if(isset($conversation['episode_started_at'])&&(!is_int($conversation['episode_started_at'])||$conversation['episode_started_at']<0))support_failure(503,'storage_invalid');
        if(isset($conversation['episode_start_sequence'])&&(!is_int($conversation['episode_start_sequence'])||$conversation['episode_start_sequence']<0||$conversation['episode_start_sequence']>$state['last_user_message_id']))support_failure(503,'storage_invalid');
        if(isset($conversation['episode_event'])&&!preg_match('/^[a-f0-9]{32}$/D',$conversation['episode_event']))support_failure(503,'storage_invalid');
        if(isset($conversation['used_faqs'])){if(!is_array($conversation['used_faqs'])||!array_is_list($conversation['used_faqs'])||count($conversation['used_faqs'])>120||count(array_unique($conversation['used_faqs']))!==count($conversation['used_faqs']))support_failure(503,'storage_invalid');foreach($conversation['used_faqs'] as $faq)if(!preg_match('/^[a-f0-9]{32}$/D',(string)$faq))support_failure(503,'storage_invalid');}
        if(isset($conversation['attention_revision'])&&(!is_int($conversation['attention_revision'])||$conversation['attention_revision']<0||$conversation['attention_revision']>9007199254740990))support_failure(503,'storage_invalid');
        if(isset($conversation['retired_at'])&&(!is_int($conversation['retired_at'])||$conversation['retired_at']<0||$conversation['messages']))support_failure(503,'storage_invalid');
        if(isset($conversation['contact_prompt_state'])&&!in_array($conversation['contact_prompt_state'],['saved','skipped'],true))support_failure(503,'storage_invalid');
        if(isset($conversation['history_system_through'])&&(!is_int($conversation['history_system_through'])||$conversation['history_system_through']<1||$conversation['history_system_through']>$state['last_user_message_id']))support_failure(503,'storage_invalid');
        support_edit_validate($conversation);support_operator_reads_validate($conversation,$state['last_user_message_id']);
        $keys=[];
        foreach ($conversation['messages'] as $message) {
            if (!is_array($message) || !is_int($message['id']??null) || $message['id']<1 || isset($ids[$message['id']])
                || !in_array($message['type']??null,['user','admin','system'],true) || !is_string($message['text']??null)
                || !is_string($message['time']??null) || (isset($message['read']) && !is_bool($message['read'])) || (array_key_exists('customer_read',$message)&&($message['type']!=='admin'||!is_bool($message['customer_read'])))) support_failure(503,'storage_invalid');
            if(isset($message['operator_id'])&&($message['type']!=='admin'||!is_string($message['operator_id'])||!preg_match('/^[a-f0-9]{32}$/D',$message['operator_id'])))support_failure(503,'storage_invalid');
            support_reply_validate($message);
            if(isset($message['faq_id'])||isset($message['faq_digest'])||isset($message['faq_at'])){if($message['type']!=='system'||!preg_match('/^[a-f0-9]{32}$/D',$message['faq_id']??'')||!preg_match('/^[a-f0-9]{64}$/D',$message['faq_digest']??'')||!is_int($message['faq_at']??null)||$message['faq_at']<1)support_failure(503,'storage_invalid');}
            if(isset($message['episode_boundary'])&&($message['type']!=='system'||!is_int($message['episode_boundary'])||$message['episode_boundary']<2))support_failure(503,'storage_invalid');
            if(isset($message['question_message_id'])&&(!is_int($message['question_message_id'])||$message['question_message_id']<1))support_failure(503,'storage_invalid');
            $ids[$message['id']]=true;$max=max($max,$message['id']);
            $metadata=['client_message_id','sender_principal','payload_digest','accepted_at','protocol_version'];
            $present=count(array_filter($metadata,fn($key)=>array_key_exists($key,$message)));
            if($present!==0 && $present!==count($metadata)) support_failure(503,'storage_invalid');
            if((isset($message['team_reply_through'])||isset($message['team_reply_protocol']))&&($present!==count($metadata)||$message['type']!=='admin'))support_failure(503,'storage_invalid');
            if ($present) {
                if ($version!==3 || !support_valid_uuid($message['client_message_id'])
                    || !is_string($message['sender_principal']??null) || !preg_match('/^(customer|operator):[a-f0-9]{64}$/D',$message['sender_principal'])
                    || !is_string($message['payload_digest']??null) || !preg_match('/^[a-f0-9]{64}$/D',$message['payload_digest'])
                    || ($message['protocol_version']??null)!==2 || !is_string($message['accepted_at']??null)
                    || !preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/D',$message['accepted_at'])) support_failure(503,'storage_invalid');
                $accepted=strtotime($message['accepted_at']);
                $role=$message['type']==='user'?'customer':($message['type']==='admin'?'operator':'invalid');
                $images=$message['attachments']??[];
                require_once __DIR__.'/attachments.php';if(support_attachment_ids($images)!==$images)support_failure(503,'storage_invalid');
                $expected=hash('sha256',"protocol-v2\0".$role."\0".$id."\0".html_entity_decode($message['text'],ENT_QUOTES|ENT_HTML5,'UTF-8').($images?"\0images\0".implode(',',$images):'').(isset($message['reply_to'])?"\0reply\0".$message['reply_to']:''));
                if(isset($message['team_reply_through'])&&($role!=='operator'||!is_int($message['team_reply_through'])||$message['team_reply_through']<0||$message['team_reply_through']>=$message['id']))support_failure(503,'storage_invalid');
                if(isset($message['team_reply_protocol'])){if($message['team_reply_protocol']!==1||!isset($message['team_reply_through']))support_failure(503,'storage_invalid');$expected=hash('sha256',"protocol-v2\0".$role."\0".$id."\0".html_entity_decode($message['text'],ENT_QUOTES|ENT_HTML5,'UTF-8').($images?"\0images\0".implode(',',$images):'').(isset($message['reply_to'])?"\0reply\0".$message['reply_to']:'')."\0team-v1\0".$message['team_reply_through']);}
                if(isset($message['faq_protocol'])){if($message['faq_protocol']!==1||$role!=='customer'||$images||isset($message['reply_to'])||!preg_match('/^[a-f0-9]{32}$/D',$message['faq_question_id']??''))support_failure(503,'storage_invalid');$expected=hash('sha256',"protocol-v2\0".$role."\0".$id."\0".html_entity_decode($message['text'],ENT_QUOTES|ENT_HTML5,'UTF-8')."\0faq-v1\0".$message['faq_question_id']);}
                if($accepted===false || gmdate('Y-m-d\TH:i:s\Z',$accepted)!==$message['accepted_at'] || !str_starts_with($message['sender_principal'],$role.':')
                    || ($role==='customer' && $message['sender_principal']!=='customer:'.($conversation['owner_id']??''))
                    || !hash_equals($expected,$message['payload_digest'])) support_failure(503,'storage_invalid');
                $key=$message['sender_principal'].':'.$message['client_message_id'];
                if (isset($keys[$key])) support_failure(503,'storage_invalid');
                $keys[$key]=true;
            }
            if(array_key_exists('notification',$message)){require_once __DIR__.'/notifications.php';support_notification_validate($message['notification'],$message);}
        }
        if(isset($conversation['retained_receipts'])){
            if(!is_array($conversation['retained_receipts']))support_failure(503,'storage_invalid');
            foreach($conversation['retained_receipts'] as $m){
                if(!is_array($m)||array_diff(array_keys($m),['id','client_message_id','sender_principal','payload_digest','accepted_at','protocol_version','notification','reply_to'])
                    ||!is_int($m['id']??null)||$m['id']<1||isset($ids[$m['id']])||!support_valid_uuid($m['client_message_id']??null)
                    ||!is_string($m['sender_principal']??null)||!preg_match('/^(customer|operator):[a-f0-9]{64}$/D',$m['sender_principal'])
                    ||(str_starts_with($m['sender_principal'],'customer:')&&$m['sender_principal']!=='customer:'.($conversation['owner_id']??''))
                    ||!is_string($m['payload_digest']??null)||!preg_match('/^[a-f0-9]{64}$/D',$m['payload_digest'])||($m['protocol_version']??null)!==2
                    ||!is_string($m['accepted_at']??null)||strtotime($m['accepted_at'])===false||gmdate('Y-m-d\TH:i:s\Z',strtotime($m['accepted_at']))!==$m['accepted_at'])support_failure(503,'storage_invalid');
                support_reply_validate($m);
                $key=$m['sender_principal'].':'.$m['client_message_id'];if(isset($keys[$key]))support_failure(503,'storage_invalid');
                $keys[$key]=true;$ids[$m['id']]=true;$max=max($max,$m['id']);
                if(array_key_exists('notification',$m)){require_once __DIR__.'/notifications.php';support_notification_validate($m['notification'],$m,true);}
            }
        }
        if (isset($conversation['unread_count']) && (!is_int($conversation['unread_count']) || $conversation['unread_count']<0)) support_failure(503,'storage_invalid');
        foreach (['last_message','last_time'] as $field) if (isset($conversation[$field]) && !is_string($conversation[$field])) support_failure(503,'storage_invalid');
        if (isset($conversation['rate_requests']) && (!is_array($conversation['rate_requests']) || array_filter($conversation['rate_requests'],fn($t)=>!is_int($t) || $t<0))) support_failure(503,'storage_invalid');
        if(isset($conversation['rate_senders'])) {
            if(!is_array($conversation['rate_senders']) || count($conversation['rate_senders'])>32) support_failure(503,'storage_invalid');
            foreach($conversation['rate_senders'] as $key=>$times)
                if(!is_string($key) || !preg_match('/^(customer|operator):[a-f0-9]{64}$/D',$key) || !is_array($times) || count($times)>15 || array_filter($times,fn($t)=>!is_int($t) || $t<0)) support_failure(503,'storage_invalid');
        }
    }
    unset($conversation);
    require_once __DIR__.'/attachments.php';support_attachment_validate($state);
    if (isset($state['presence'])) {
        $presence=$state['presence'];
        if (!is_array($presence) || !is_int($presence['operator_last_seen']??null) || $presence['operator_last_seen']<0 || !is_array($presence['users']??null)) support_failure(503,'storage_invalid');
        foreach($presence['users'] as $id=>$last) if (!support_valid_id($id) || !is_int($last) || $last<0) support_failure(503,'storage_invalid');
    }
    if ($state['last_user_message_id']<$max) support_failure(503,'storage_invalid');
    if ($version===3 && (!is_string($state['store_epoch']??null) || !preg_match('/^[a-f0-9]{32}$/D',$state['store_epoch']))) support_failure(503,'storage_invalid');
    $state['schema_version']=3;
    // Stable for read-only adapters; first write persists this epoch. Never randomize on GET.
    if ($version!==3) $state['store_epoch']=substr(hash('sha256','zizolearn-schema2:'.(realpath(CHAT_DATA_DIR)?:CHAT_DATA_DIR)),0,32);
    return $version<3;
}
function support_atomic_replace(string $path, string $bytes): void {
    if (is_link($path) || (file_exists($path) && !is_file($path))) support_failure(503,'storage_unavailable');
    $temporary=$path.'.tmp.'.bin2hex(random_bytes(12));$handle=@fopen($temporary,'x+b');
    if (!$handle) support_failure(503,'storage_unavailable');
    $closed=false;
    try {
        if (!@chmod($temporary,0600)) support_failure(503,'storage_unavailable');
        $offset=0;$length=strlen($bytes);
        while ($offset<$length) { $written=@fwrite($handle,substr($bytes,$offset)); if (!$written) support_failure(503,'storage_unavailable'); $offset+=$written; }
        if (!@fflush($handle) || !function_exists('fsync') || !@fsync($handle)) support_failure(503,'storage_unavailable');
        if (!fclose($handle)) support_failure(503,'storage_unavailable');
        $closed=true;
        if (!@rename($temporary,$path)) support_failure(503,'storage_unavailable');
    } finally {
        if (!$closed && is_resource($handle)) fclose($handle);
        if (is_file($temporary)) @unlink($temporary);
    }
}
function support_store(callable $operation, bool $write=false,bool $retentionRepair=false): mixed {
    static $depth=0;
    if($depth>0)support_failure(503,'storage_recursion_prohibited');
    $depth++;
    try {
        $directory=support_private_dir(CHAT_DATA_DIR,false);$path=$directory.'/chat_conversations.json';
        $lockPath=support_runtime_dir().'/chat-store.lock';
        if (is_link($lockPath)) support_failure(503,'storage_unavailable');
        $lock=@fopen($lockPath,'c+b');
        if (!$lock || !@flock($lock,$write?LOCK_EX:LOCK_SH)) { if ($lock) fclose($lock); support_failure(503,'storage_unavailable'); }
        @chmod($lockPath,0600);
        try {
            if (is_link($path) || (file_exists($path) && !is_file($path))) support_failure(503,'storage_unavailable');
            $raw=null;
            if (is_file($path)) {
                if ((int)filesize($path)>32*1024*1024) support_failure(503,'storage_unavailable');
                $raw=@file_get_contents($path);
                if ($raw===false || trim($raw)==='') support_failure(503,'storage_invalid');
                try { $state=json_decode($raw,true,128,JSON_THROW_ON_ERROR); }
                catch (JsonException $e) { support_failure(503,'storage_invalid'); }
                if (!is_array($state)) support_failure(503,'storage_invalid');
            } else $state=['schema_version'=>3,'store_epoch'=>bin2hex(random_bytes(16)),'conversations'=>[],'last_user_message_id'=>0];
            $backupName=isset($state['schema_version'])?'schema3-original.json':'legacy-pre-ownership.json';
            $legacy=support_validate_store($state);$changed=false;
            require_once __DIR__.'/retention.php';
            if(!$retentionRepair)support_retention_guard($state);
            elseif(PHP_SAPI!=='cli')support_failure(403,'forbidden');
            $before=$write?$state:[];$result=$operation($state,$changed);
            if($write&&$changed){require_once __DIR__.'/analytics.php';support_metrics_commit($before,$state);}
            if ($write && $changed) {
                support_validate_store($state);
                if ($legacy && $raw!==null) {
                    $backup=support_runtime_dir().'/'.$backupName;
                    if (is_link($backup) || (file_exists($backup) && !is_file($backup))) support_failure(503,'storage_unavailable');
                    if (!file_exists($backup)) support_atomic_replace($backup,$raw);
                }
                $encoded=json_encode($state,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
                if (strlen($encoded)>32*1024*1024) support_failure(503,'storage_capacity');
                support_atomic_replace($path,$encoded);
            }
            return $result;
        } finally { flock($lock,LOCK_UN);fclose($lock); }
    } finally { $depth--; }
}
function support_owned(array $conversation, array $customer): bool {
    if (isset($customer['novinpay_owners'])) return ($conversation['ownership'] ?? null) === 'claimed' && in_array($conversation['owner_id'] ?? '', $customer['novinpay_owners'], true);
    if(isset($customer['_phone']))return function_exists('support_phone_owned')&&support_phone_owned($conversation,$customer);
    if(isset($customer['_recovered']))return function_exists('support_recovered_owned')&&support_recovered_owned($conversation,$customer);
    return ($conversation['ownership']??null)==='claimed' && is_string($conversation['owner_id']??null)
        && hash_equals($conversation['owner_id'],$customer['owner_id']);
}
function support_conversation(array $state, string $id, ?array $customer, bool $operator): array {
    if (!support_valid_id($id) || !isset($state['conversations'][$id]) || (!$operator && (!$customer || !support_owned($state['conversations'][$id],$customer)))) support_failure(404,'conversation_unavailable');
    return $state['conversations'][$id];
}
function support_add_message(array &$state,string $id,string $type,string $text): int {
    if ($state['last_user_message_id']>=9007199254740991) support_failure(503,'storage_unavailable');
    $messageId=++$state['last_user_message_id'];$time=date('Y-m-d H:i:s');
    $conversation=&$state['conversations'][$id];
    $conversation['attention_revision']=($conversation['attention_revision']??0)+1;
    $conversation['messages'][]=['id'=>$messageId,'type'=>$type,'text'=>$text,'time'=>$time,'read'=>$type==='admin'];
    $conversation['last_message']=$text;$conversation['last_time']=$time;
    $conversation['unread_count']=$type==='user'?($conversation['unread_count']??0)+1:($conversation['unread_count']??0);
    return $messageId;
}
function support_attention_snapshot(array $conversation,string $epoch,?array $operator=null): array {
    $messages=support_episode_messages($conversation);
    $incoming=array_values(array_filter($messages,fn($m)=>$m['type']==='admin'&&($m['customer_read']??true)===false));
    return ['conversation_id'=>$conversation['user_id'],'store_epoch'=>$epoch,'attention_revision'=>$conversation['attention_revision']??0,
        'customer_unread'=>count($incoming),'first_customer_unread_sequence'=>$incoming?min(array_column($incoming,'id')):0,
        'needs_reply'=>support_needs_reply($conversation,support_workflow($conversation)),'operator_unread'=>support_team_unread($conversation,$operator),
        'operator_read_watermark'=>$operator===null?null:($conversation['operator_reads']['accounts'][support_operator_read_key($operator)]['through']??$conversation['operator_reads']['baseline']['through']??0)];
}
