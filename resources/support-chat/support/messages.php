<?php
declare(strict_types=1);
require_once __DIR__.'/operator-security.php';
require_once __DIR__.'/notifications.php';
require_once __DIR__.'/attachments.php';
require_once __DIR__.'/replies.php';
require_once __DIR__.'/context.php';
require_once __DIR__.'/message-edits.php';
require_once __DIR__.'/satisfaction.php';
require_once __DIR__.'/lifecycle.php';
require_once __DIR__.'/operations.php';
function support_anonymous_id(array $context): string {
    if(isset($context['_phone'])||isset($context['_recovered']))support_failure(403,'original_session_required');
    return 'user_'.substr(hash_hmac('sha256','anonymous-v1'."\0".$context['owner_id'],support_security_key()),0,32).'_'.$context['issued_at'];
}
function support_draft_conversation(string $id,array $context): array {
    if(!hash_equals(support_anonymous_id($context),$id))support_failure(404,'conversation_unavailable');
    $time=date('Y-m-d H:i:s');return ['user_id'=>$id,'owner_id'=>$context['owner_id'],'ownership'=>'claimed','user_name'=>'مهمان','user_email'=>'','created_at'=>$time,'last_message'=>'','last_time'=>$time,'unread_count'=>0,'messages'=>[]];
}
function support_prepare_customer_draft(array &$state,string $id,array $context): void {
    if(!isset($state['conversations'][$id])||!support_conversation_retired($state['conversations'][$id]))return;
    // A retention tombstone with this session's draft ID is ended; reuse the
    // same authorized draft ID for a clean episode. The ledger still prevents
    // any old message below its watermark from reappearing.
    if(!hash_equals(support_anonymous_id($context),$id))support_failure(404,'conversation_unavailable');
    $old=$state['conversations'][$id];$draft=support_draft_conversation($id,$context);
    if(isset($old['retained_receipts']))$draft['retained_receipts']=$old['retained_receipts'];
    $state['conversations'][$id]=$draft;
}
function support_protocol(): void {
    if(($_POST['protocol_version']??null)!=='2') support_failure(426,'protocol_upgrade_required');
}
function support_message_ack(array $message,string $conversation,bool $replayed): array {
    return ['status'=>'accepted','protocol_version'=>2,'conversation_id'=>$conversation,'client_message_id'=>$message['client_message_id'],
        'message_id'=>$message['id'],'sequence'=>$message['id'],'accepted_at'=>$message['accepted_at'],'replayed'=>$replayed];
}
// Extend the existing send limiter: accepted messages/retained ACKs are the counter
// authority. The existing bounded security rates store holds violations only.
function support_customer_send_wait(array $state,array $conversation,string $text,array $images,int $now): int {
    $minute=[];$burst=[];$imageTimes=[];$ownerTimes=[];
    foreach($state['conversations'] as $c)if(($c['owner_id']??null)===$conversation['owner_id'])foreach(array_merge($c['messages'],$c['retained_receipts']??[]) as $m){
        if(($m['type']??'user')!=='user'||!str_starts_with($m['sender_principal']??'','customer:'))continue;
        $at=strtotime($m['accepted_at']??'');if($at===false||$at>$now||$now-$at>=3600)continue;$ownerTimes[]=$at;
        if($c['user_id']!==$conversation['user_id'])continue;
        if($now-$at<60){$minute[]=$at;if(!empty($m['attachments']))$imageTimes[]=$at;}
        if($now-$at<5)$burst[]=$at;
    }
    $wait=0;
    foreach([[$minute,30,60],[$burst,6,5],[$ownerTimes,120,3600],[$images?$imageTimes:[],3,60]] as [$times,$limit,$window]){
        sort($times);if(count($times)>=$limit)$wait=max($wait,$times[count($times)-$limit]+$window-$now);
    }
    // Three identical consecutive customer sends are tolerated; the fourth within
    // a minute waits. A reply from an operator breaks the consecutive sequence.
    if($text!==''){$same=[];$start=support_episode_start($conversation);foreach(array_reverse($conversation['messages']) as $m){if((int)$m['id']<=$start)break;$at=strtotime($m['accepted_at']??'');if($m['type']!=='user'||$m['text']!==$text||$at===false||$now-$at>=60)break;$same[]=$at;if(count($same)===3){$wait=max($wait,$at+60-$now);break;}}}
    return max(0,$wait);
}
function support_customer_cooldown(array $context,?int $violationWait=null): void {
    $key=hash_hmac('sha256','send-violations:'.$context['owner_id'],support_security_key());
    support_security_store(function(array &$s)use($key,$violationWait){$now=time();$times=$s['rates'][$key]??[];
        $duration=count($times)<2?0:min(60,15*(2**min(2,count($times)-2)));$wait=$times?max(0,end($times)+$duration-$now):0;
        if($violationWait!==null){if(!isset($s['rates'][$key])&&count($s['rates'])>=1024)support_failure(503,'security_capacity');$times[]=$now;$s['rates'][$key]=array_slice($times,-4);$n=count($times);$wait=max($wait,$violationWait,$n<2?0:min(60,15*(2**min(2,$n-2))));}
        if($wait>0){header('Retry-After: '.$wait);support_failure(429,'customer_send_limited');}
    });
}
function support_accept_message(array $context,string $role,string $id,string $uuid,string $rawText,array $attachments=[],int $replyTo=0,mixed $sourcePath=null,mixed $faqArticle=null,?int $teamThrough=null): array {
    if($teamThrough!==null&&($role!=='operator'||$teamThrough<0))support_failure(400,'invalid_input');
    if($faqArticle!==null&&($role!=='customer'||!is_string($faqArticle)||!preg_match('/^[a-f0-9]{32}$/D',$faqArticle)||$attachments||$replyTo))support_failure(400,'invalid_faq');
    if(!support_valid_uuid($uuid)) support_failure(400,'invalid_message_id');
    $attachments=support_attachment_ids($attachments);$raw=support_string($rawText,2000,(bool)$attachments);$text=$raw===''?'':support_text($raw);$sender=support_principal($context,$role);
    $digest=hash('sha256',"protocol-v2\0".$role."\0".$id."\0".$raw.($attachments?"\0images\0".implode(',',$attachments):'').($replyTo?"\0reply\0".$replyTo:'').($faqArticle!==null?"\0faq-v1\0".$faqArticle:'').($teamThrough!==null?"\0team-v1\0".$teamThrough:''));
    return support_store(function(array &$state,bool &$changed) use($context,$role,$id,$uuid,$text,$sender,$digest,$attachments,$replyTo,$sourcePath,$faqArticle,$raw,$teamThrough) {
        if($role==='customer'&&!isset($state['conversations'][$id])){
            foreach($state['conversations'] as $existing)if(support_owned($existing,$context)&&!support_conversation_retired($existing))support_failure(409,'conversation_already_exists');
            $state['conversations'][$id]=support_draft_conversation($id,$context);
        }
        $c=support_conversation($state,$id,$role==='customer'?$context:null,$role==='operator');
        foreach(array_merge($c['messages'],$c['retained_receipts']??[]) as $message) if(($message['client_message_id']??null)===$uuid && ($message['sender_principal']??null)===$sender) {
            if(!hash_equals($message['payload_digest'],$digest)) support_failure(409,'idempotency_conflict');
            return support_message_ack($message,$id,true)+($role==='operator'?['reply_ownership'=>support_reply_ownership($c,$context)]:[])+support_attention_snapshot($state['conversations'][$id],$state['store_epoch'],$role==='operator'?$context:null);
        }
        if($teamThrough!==null&&$teamThrough>max([0,...array_column($c['messages'],'id')]))support_failure(409,'invalid_reply_context');
        $faq=null;if($faqArticle!==null){require_once __DIR__.'/knowledge.php';$faq=support_content_public('article',['id'=>$faqArticle])['article'];$available=support_content_public('faq',[])['questions'];if(!in_array($faqArticle,array_column($available,'id'),true)||$raw!==$faq['title'])support_failure(409,'faq_changed');$digestFAQ=hash('sha256',$faq['title']."\0".$faq['body']);foreach(support_episode_messages($c) as $prior)if(($prior['faq_id']??null)===$faqArticle&&($prior['faq_digest']??null)===$digestFAQ)support_failure(409,'faq_already_answered');support_throttle('faq-answer:'.$context['owner_id'],30,300);if(count(array_filter($c['messages'],fn($m)=>($m['faq_at']??0)>time()-300))>=15){header('Retry-After: 300');support_failure(429,'faq_limited');}}
        if($role==='customer'){
            support_prepare_customer_draft($state,$id,$context);
            $c=$state['conversations'][$id];
        }
        if(support_conversation_retired($c))support_failure(409,'conversation_retired');
        if($role==='customer'&&support_workflow($c)['status']==='resolved')support_failure(409,'conversation_resolved');
        if($role==='operator'&&support_workflow($c)['status']==='resolved')support_failure(409,'conversation_resolved');
        if($role==='operator')support_reply_require($c,$context);
        // Replay lookup above must succeed even when new submissions are cooling down.
        if($role==='customer'){support_customer_cooldown($context);$wait=support_customer_send_wait($state,$c,$text,$attachments,time());if($wait) support_customer_cooldown($context,$wait);}
        if($role==='customer')support_source_capture($state['conversations'][$id],$sourcePath);
        support_reply_authorize($c,$replyTo);
        support_attachment_bind($state,$context,$role,$id,$attachments);
        $rates=$c['rate_senders']??[];
        foreach($rates as $key=>$times) {$times=array_values(array_filter($times,fn($t)=>time()-$t<30));if($times)$rates[$key]=$times;else unset($rates[$key]);}
        $requests=$rates[$sender]??[];
        if(count($requests)>=15) support_failure(429,'rate_limited');
        $requests[]=time();$rates[$sender]=$requests;$state['conversations'][$id]['rate_senders']=$rates;
        support_add_message($state,$id,$role==='customer'?'user':'admin',$text);
        $message=&$state['conversations'][$id]['messages'][array_key_last($state['conversations'][$id]['messages'])];
        $acceptedAt=time();
        $message+=['protocol_version'=>2,'client_message_id'=>$uuid,'sender_principal'=>$sender,'payload_digest'=>$digest,'accepted_at'=>gmdate('Y-m-d\TH:i:s\Z',$acceptedAt)];
        if($teamThrough!==null)$message['team_reply_protocol']=1;
        if($role==='operator'){$message['team_reply_through']=$teamThrough??max([0,...array_column(array_filter($state['conversations'][$id]['messages'],fn($m)=>$m['type']==='user'&&($m['read']??false)===true),'id')]);}
        if($role==='operator'){$message['customer_read']=false;if(isset($context['operator_id']))$message['operator_id']=$context['operator_id'];}
        if($replyTo)$message['reply_to']=$replyTo;
        if($attachments)$message['attachments']=$attachments;
        if($role==='operator'&&isset($context['operator_id']))$state['conversations'][$id]['reply_ownership']=['operator_id'=>$context['operator_id'],'credential_version'=>$context['credential_version'],'last_reply_at'=>$acceptedAt,'episode'=>$c['episode']??1];
        require_once __DIR__.'/operators.php';support_workflow_message($state,$id,$role,$message['id'],$context['operator_id']??$role);
        if($faqArticle===null&&$role==='customer'&&support_notification_enabled())$message['notification']=support_notification_intent($state);
        $state['conversations'][$id]['unread_count']=support_team_unread($state['conversations'][$id]);
        $ack=support_message_ack($message,$id,false)+($role==='operator'?['reply_ownership'=>support_reply_ownership($state['conversations'][$id],$context)]:[])+support_attention_snapshot($state['conversations'][$id],$state['store_epoch'],$role==='operator'?$context:null);
        if($faq!==null){$questionId=$message['id'];$message['faq_question_id']=$faqArticle;$message['faq_protocol']=1;support_add_message($state,$id,'system',htmlspecialchars($faq['body'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'));$answer=&$state['conversations'][$id]['messages'][array_key_last($state['conversations'][$id]['messages'])];$answer+=['faq_id'=>$faqArticle,'faq_digest'=>hash('sha256',$faq['title']."\0".$faq['body']),'faq_at'=>time(),'question_message_id'=>$questionId,'faq_answer_only'=>true,'origin'=>'faq_auto'];$used=$state['conversations'][$id]['used_faqs']??[];if(!in_array($faqArticle,$used,true))$used[]=$faqArticle;$state['conversations'][$id]['used_faqs']=$used;}
        $changed=true;return $ack;
    },true);
}
function support_wire_message(array $message): array {
    // Internal sender/owner/digest must never be serialized into browser data.
    $wire=['id'=>$message['id'],'sequence'=>$message['id'],'type'=>$message['type'],'text'=>html_entity_decode($message['edited_text']??$message['text'],ENT_QUOTES|ENT_HTML5,'UTF-8'),'time'=>$message['time'],
        'revision'=>$message['revision']??0,'edited_at'=>$message['edited_at']??null,'client_message_id'=>$message['client_message_id']??null,'accepted_at'=>$message['accepted_at']??null,'legacy'=>!isset($message['accepted_at']),'read'=>$message['read']??($message['type']!=='user'),'customer_read'=>$message['customer_read']??true,'attachments'=>$message['attachments']??[],'reply_to'=>$message['reply_to']??null,'read_receipt'=>($message['read_visible']??false)===true,'episode_boundary'=>$message['episode_boundary']??null];
    if(support_faq_auto_message($message))$wire+=['origin'=>'faq_auto','faq_id'=>$message['faq_id'],'question_message_id'=>$message['question_message_id']];
    if($message['type']==='admin'&&isset($message['sender_principal']))$wire['sender_group']=substr(hash_hmac('sha256','sender-group:'.$message['sender_principal'],support_security_key()),0,32);
    return $wire;
}
function support_cursor(mixed $value,int $default=0): int {
    if($value===null) return $default;
    if(!is_string($value) || !preg_match('/^(0|[1-9][0-9]{0,15})$/D',$value)) support_failure(400,'invalid_cursor');
    $number=(int)$value;if($number>9007199254740991) support_failure(400,'invalid_cursor');return $number;
}
function support_message_page(array $state,array $conversation,array $query,bool $customer=false,?array $operator=null,?array $customerActor=null): array {
    $messages=$customer?support_episode_messages($conversation,true):$conversation['messages'];usort($messages,fn($a,$b)=>$a['id']<=>$b['id']);
    $receipts=$customer?[]:($conversation['retained_receipts']??[]);
    $head=max([$customer?0:($conversation['history_system_through']??0),$messages?end($messages)['id']:0,...array_map(fn($m)=>$m['id'],$receipts)]);$after=isset($query['after_sequence'])?support_cursor($query['after_sequence']):null;
    $before=isset($query['before_sequence'])?support_cursor($query['before_sequence']):null;
    if($after!==null && $before!==null) support_failure(400,'invalid_cursor');
    $limit=support_cursor($query['limit']??null,50);if($limit<1 || $limit>100) support_failure(400,'invalid_limit');
    $through=support_cursor($query['through_sequence']??null,$head);
    if(($after!==null && $after>$head) || $through>$head || (isset($query['store_epoch']) && $query['store_epoch']!==$state['store_epoch'])) support_failure(409,'cursor_reset_required');
    $eligible=array_values(array_filter($messages,fn($m)=>$m['id']<=$through && ($after===null || $m['id']>$after) && ($before===null || $m['id']<$before)));
    $around=isset($query['around_sequence'])?support_cursor($query['around_sequence']):null;
    if($around!==null){$position=array_search($around,array_column($eligible,'id'),true);if($position===false)support_failure(404,'message_unavailable');$slice=array_slice($eligible,max(0,$position-intdiv($limit,2)),$limit);}
    else $slice=$after!==null?array_slice($eligible,0,$limit):array_slice($eligible,-$limit);
    require_once __DIR__.'/operator-profile.php';$senders=support_profile_sender_map($operator!==null);$live=array_column($messages,null,'id');$wire=array_map(function($m)use($live,$senders,$conversation,$operator,$customerActor){$out=support_wire_message($m);if($operator!==null&&$m['type']==='user'){$out['read']=support_operator_message_read($conversation,$m,$operator);$out['read_receipt']=$out['read'];}$out['sender']=($operator!==null&&$m['type']==='admin'?($senders['account:'.($m['operator_id']??'')]??null):null)??$senders[$m['sender_principal']??'']??null;$out['reference']=support_reply_preview($m,$live);return $out+support_edit_public($m,$operator??$customerActor??[], $operator!==null?'operator':'customer');},$slice);
    return ($operator!==null?['reply_ownership'=>support_reply_ownership($conversation,$operator)]:[])+['assigned_operator'=>support_profile_assigned($conversation),'public_agents'=>array_values(array_column(array_values($senders),null,'id')),'status'=>'success','protocol_version'=>2,'conversation_id'=>$conversation['user_id'],'user_name'=>$conversation['user_name'],'user_email'=>$conversation['user_email'],
        'store_epoch'=>$state['store_epoch'],'high_watermark'=>$through,'head_sequence'=>$head,'has_more'=>count($eligible)>count($slice),
        'next_after'=>$slice?end($slice)['id']:($after??$head),'before_sequence'=>$slice?$slice[0]['id']:0,'messages'=>$wire,'focus_sequence'=>$around,'has_older'=>$slice&&$slice[0]['id']>($messages[0]['id']??0),'has_newer'=>$slice&&end($slice)['id']<$head,'read_message_ids'=>array_values(array_column(array_filter(array_slice($messages,-500),fn($m)=>$operator!==null&&$m['type']==='user'?support_operator_message_read($conversation,$m,$operator):($m['read_visible']??false)===true),'id')),'history_removed_through'=>max([$conversation['history_system_through']??0,...array_column($conversation['retained_receipts']??[],'id')]),
        ...support_attention_snapshot($conversation,$state['store_epoch'],$operator),
        ...support_edit_page($conversation,$query,$operator??$customerActor??[],$customer?'customer':'operator'),
        'workflow_status'=>$customer?null:support_workflow($conversation)['status'],'lifecycle'=>$customer?support_lifecycle_public($conversation):null,'availability'=>$customer?support_operations_availability():null,'satisfaction'=>$customer?support_satisfaction_offer($conversation):null,'history_removed_at'=>$conversation['history_removed_at']??null,'customer_unread'=>count(array_filter($messages,fn($m)=>$m['type']==='admin'&&($m['customer_read']??true)===false)),'used_faqs'=>$customer?array_values($conversation['used_faqs']??[]):[],'episode'=>(int)($conversation['episode']??1),'episode_started_at'=>$conversation['episode_started_at']??null,
        'needs_reply'=>support_needs_reply($conversation,support_workflow($conversation)),'operator_unread'=>$customer?null:support_team_unread($conversation,$operator),'customer_unread_sequence'=>max([0,...array_column(array_filter($messages,fn($m)=>$m['type']==='admin'&&($m['customer_read']??true)===false),'id')])];
}
