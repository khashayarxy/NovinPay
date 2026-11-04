<?php
declare(strict_types=1);
require_once __DIR__.'/satisfaction.php';

// A workflow transition is an event, never a fabricated human message.
function support_lifecycle_transition(array &$w,string $status,string $actor): bool {
    if($w['status']===$status)return false;
    $was=$w['status'];support_resolution_transition($w,$status);
    if($status==='resolved'){$w['closed_by']=$actor;$w['closed_at']=time();$w['snoozed_until']=0;$w['snoozed_at']=0;}
    elseif($was==='resolved'){$w['reopened_pending']=true;$w['reopened_at']=time();$w['reopen_count']=($w['reopen_count']??0)+1;}
    support_workflow_event($w,$actor,$status==='resolved'?'closed':($was==='resolved'?'reopened':'status_changed'));
    return true;
}
function support_resolve_conversation(array &$conversation,array &$workflow,string $actor,bool &$changed): bool {
    $transitioned=support_lifecycle_transition($workflow,'resolved',$actor);
    // Handling status is independent of every operator's read progress.
    if($transitioned){
        $workflow['snoozed_until']=0;$workflow['snoozed_at']=0;
        $changed=true;
        return true;
    }
    return false;
}
function support_customer_lifecycle(array $context,string $id,string $operation): array {
    if(!support_multi_operator())support_failure(404,'operator_unavailable');
    if(!in_array($operation,['reopen','new'],true))support_failure(400,'invalid_input');
    // Recovered sessions remain deliberately scoped to their granted conversation.
    if($operation==='new'&&(isset($context['_recovered'])||isset($context['_phone'])))support_failure(403,'original_session_required');
    support_throttle('lifecycle:'.$context['owner_id'],20,3600);
    $event=support_string($_POST['event']??'',32,true);if($event!==''&&!preg_match('/^[a-f0-9]{32}$/D',$event))support_failure(400,'invalid_input');
    return support_store(function(array &$s,bool &$changed)use($context,$id,$operation,$event){
        $c=support_conversation($s,$id,$context,false);if(support_conversation_retired($c))support_failure(409,'conversation_retired');
        $w=support_workflow($c);
        if($operation==='reopen'){
            $changed=support_lifecycle_transition($w,'awaiting_reply','customer')||$changed;
            $s['conversations'][$id]['workflow']=$w;return ['status'=>'success','user_id'=>$id,'lifecycle'=>support_lifecycle_public($s['conversations'][$id])];
        }
        // The client names the closure event; a repeated click after the
        // episode boundary is idempotent even though workflow is no longer resolved.
        if($operation==='new'&&$event!==''&&($c['episode_event']??null)===$event)
            return ['status'=>'success','user_id'=>$id,'replayed'=>true,'lifecycle'=>support_lifecycle_public($c)];
        if($w['status']!=='resolved')support_failure(409,'resolution_unavailable');
        // A new episode is a boundary inside the same owned conversation. The
        // operator keeps one inbox row and one unified history.
        support_throttle('legacy-start-ip:'.support_ip_key(),60);support_throttle('legacy-start-owner:'.$context['owner_id'],10);
        $time=time();$episode=(int)($c['episode']??1)+1;$resolution=(string)($w['resolution_event']??'');
        if(!preg_match('/^[a-f0-9]{32}$/D',$resolution))support_failure(409,'resolution_unavailable');
        support_add_message($s,$id,'system','گفتگوی جدید');
        $boundary=&$s['conversations'][$id]['messages'][array_key_last($s['conversations'][$id]['messages'])];
        $boundary['episode_boundary']=$episode;$boundary['episode']=$episode;
        $s['conversations'][$id]['episode']=$episode;
        $s['conversations'][$id]['episode_started_at']=$time;
        $s['conversations'][$id]['episode_start_sequence']=$boundary['id'];
        $s['conversations'][$id]['episode_event']=$resolution;
        $s['conversations'][$id]['used_faqs']=[];
        unset($s['conversations'][$id]['contact_prompt_state']);
        $s['conversations'][$id]['last_message']='گفتگوی جدید';$s['conversations'][$id]['last_time']=$boundary['time'];
        $s['conversations'][$id]['unread_count']=0;
        $w['status']='new';$w['last_customer']=0;$w['last_operator']=0;$w['customer_at']=0;$w['reopened_pending']=false;
        $w['snoozed_until']=0;$w['snoozed_at']=0;unset($w['reopened_at'],$w['closed_by'],$w['closed_at'],$w['resolution_event'],$w['resolution_at']);
        support_workflow_event($w,'customer','episode_started');
        $s['conversations'][$id]['workflow']=$w;$changed=true;
        return ['status'=>'success','user_id'=>$id,'replayed'=>false,'episode'=>$episode,'lifecycle'=>support_lifecycle_public($s['conversations'][$id])];
    },true);
}
function support_lifecycle_public(array $c): array {
    $w=support_workflow($c);return ['resolved'=>$w['status']==='resolved','closed_at'=>$w['status']==='resolved'?($w['resolution_at']??null):null,'revision'=>count($w['events']),'retired'=>support_conversation_retired($c),'can_start_new'=>true,'episode'=>(int)($c['episode']??1),'episode_started_at'=>$c['episode_started_at']??null];
}
