<?php
declare(strict_types=1);
require_once __DIR__.'/operators.php';
function support_productivity_defaults(array $workflow): array {return $workflow+['priority'=>'normal','snoozed_until'=>0,'snoozed_at'=>0];}
function support_productivity_validate(array $conversation): void {
    if(isset($conversation['workflow'])){$w=$conversation['workflow'];if(!is_array($w)||isset($w['priority'])&&!in_array($w['priority'],['normal','urgent'],true)||isset($w['snoozed_until'])&&(!is_int($w['snoozed_until'])||$w['snoozed_until']<0||$w['snoozed_until']>0&&(!is_int($w['snoozed_at']??null)||$w['snoozed_until']<$w['snoozed_at']+60||$w['snoozed_until']>$w['snoozed_at']+30*86400)))support_failure(503,'storage_invalid');}
    if(isset($conversation['macro_receipts'])){if(!is_array($conversation['macro_receipts'])||count($conversation['macro_receipts'])>128)support_failure(503,'storage_invalid');foreach($conversation['macro_receipts'] as $key=>$r)if(!preg_match('/^[a-f0-9]{32}$/D',(string)$key)||!is_array($r)||array_diff(array_keys($r),['expires','digest'])||!is_int($r['expires']??null)||!preg_match('/^[a-f0-9]{64}$/D',$r['digest']??''))support_failure(503,'storage_invalid');}
}
function support_followup_due(array $w,?int $now=null): bool {return ($w['snoozed_until']??0)>0&&$w['snoozed_until']<=($now??time())&&$w['status']!=='resolved';}
function support_snooze_value(mixed $value): int {
    if($value===''||$value==='0')return 0;
    if(!is_string($value)||!preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/D',$value))support_failure(400,'invalid_snooze');$t=strtotime($value);
    if($t===false||gmdate('Y-m-d\TH:i:s\Z',$t)!==$value||$t<time()+60||$t>time()+30*86400)support_failure(400,'invalid_snooze');return $t;
}
function support_workflow_event(array &$w,string $actor,string $event): void {if(count($w['events']??[])>=1000)$w['events']=array_slice($w['events'],-500);$w['events'][]=['at'=>time(),'actor'=>$actor,'event'=>$event];}
function support_macro_actions(mixed $value): array {
    if(is_string($value)){try{$value=json_decode($value,true,8,JSON_THROW_ON_ERROR);}catch(Throwable $e){support_failure(400,'invalid_macro');}}
    if(!is_array($value)||array_diff(array_keys($value),['labels','priority','status']))support_failure(400,'invalid_macro');
    if(isset($value['labels'])){if(!is_array($value['labels'])||!array_is_list($value['labels'])||count($value['labels'])>20)support_failure(400,'invalid_macro');$value['labels']=array_values(array_unique(array_map(fn($v)=>support_string($v,60),$value['labels'])));}
    if(isset($value['priority'])&&!in_array($value['priority'],['normal','urgent'],true)||isset($value['status'])&&!in_array($value['status'],['new','awaiting_reply','resolved'],true))support_failure(400,'invalid_macro');return $value;
}
function support_macro_revision(array $reply): string {return hash('sha256',json_encode([$reply['id'],$reply['title'],$reply['text'],support_macro_actions($reply['actions']??[])],JSON_THROW_ON_ERROR));}
function support_macro_preview(array $context,string $conversation,string $replyId): array {
    return support_store(function(array &$s,bool &$changed)use($context,$conversation,$replyId){support_conversation($s,$conversation,null,true);return support_ops_store(function(array &$ops)use($context,$conversation,$replyId){support_ops_recheck($ops,$context);$reply=$ops['replies'][$replyId]??null;if(!$reply)support_failure(404,'reply_unavailable');
        $plan=['conversation'=>$conversation,'reply'=>$replyId,'revision'=>support_macro_revision($reply),'actor'=>support_principal($context,'operator'),'epoch'=>$context['auth_epoch'],'expires'=>time()+300,'nonce'=>bin2hex(random_bytes(16))];$payload=rtrim(strtr(base64_encode(json_encode($plan,JSON_THROW_ON_ERROR)),'+/','-_'),'=');$token=$payload.'.'.hash_hmac('sha256','macro-v1:'.$payload,support_security_key());
        return ['status'=>'success','plan'=>$token,'reply'=>['title'=>$reply['title'],'text'=>$reply['text'],'actions'=>support_macro_actions($reply['actions']??[])],'expires'=>$plan['expires']];
    });});
}
function support_macro_apply(array $context,string $token): array {
    $parts=explode('.',$token);if(count($parts)!==2||!preg_match('/^[a-zA-Z0-9_-]{20,1500}$/D',$parts[0])||!hash_equals(hash_hmac('sha256','macro-v1:'.$parts[0],support_security_key()),$parts[1]))support_failure(400,'invalid_macro');
    try{$p=json_decode((string)base64_decode(strtr($parts[0],'-_','+/'),true),true,8,JSON_THROW_ON_ERROR);}catch(Throwable $e){support_failure(400,'invalid_macro');}
    if(!is_array($p)||!support_valid_id($p['conversation']??null)||!is_int($p['expires']??null)||!preg_match('/^[a-f0-9]{32}$/D',$p['nonce']??'')||($p['actor']??'')!==support_principal($context,'operator')||($p['epoch']??'')!==$context['auth_epoch'])support_failure(403,'macro_scope_invalid');
    if($p['expires']<time()||$p['expires']>time()+300)support_failure(409,'macro_expired');
    return support_store(function(array &$s,bool &$changed)use($context,$p,$token){$id=$p['conversation'];support_conversation($s,$id,null,true);return support_ops_store(function(array &$ops)use(&$s,&$changed,$context,$p,$token,$id){
        support_ops_recheck($ops,$context);$reply=$ops['replies'][$p['reply']]??null;if(!$reply||!hash_equals(support_macro_revision($reply),$p['revision']))support_failure(409,'macro_changed');$receipts=$s['conversations'][$id]['macro_receipts']??[];
        foreach($receipts as $key=>$r)if($r['expires']<time())unset($receipts[$key]);$digest=hash('sha256',$token);
        if(isset($receipts[$p['nonce']])){if(!hash_equals($receipts[$p['nonce']]['digest'],$digest))support_failure(409,'macro_conflict');return ['status'=>'success','replayed'=>true,'draft'=>$reply['text'],'workflow'=>support_workflow($s['conversations'][$id])];}
        if(count($receipts)>=128)support_failure(429,'macro_capacity');$w=support_workflow($s['conversations'][$id]);$actions=support_macro_actions($reply['actions']??[]);if(isset($actions['status'])&&$actions['status']!=='resolved')support_failure(400,'status_is_automatic');
        if(isset($actions['labels'])){$w['labels']=array_values(array_unique(array_merge($w['labels'],$actions['labels'])));if(count($w['labels'])>20)support_failure(400,'invalid_macro');}
        if(isset($actions['priority']))$w['priority']=$actions['priority'];if(isset($actions['status'])){require_once __DIR__.'/satisfaction.php';require_once __DIR__.'/lifecycle.php';if($actions['status']==='resolved')support_resolve_conversation($s['conversations'][$id],$w,$context['operator_id'],$changed);else support_lifecycle_transition($w,$actions['status'],$context['operator_id']);}
        support_workflow_event($w,$context['operator_id'],'macro_applied');$s['conversations'][$id]['workflow']=$w;$receipts[$p['nonce']]=['expires'=>$p['expires'],'digest'=>$digest];$s['conversations'][$id]['macro_receipts']=$receipts;$changed=true;
        return ['status'=>'success','replayed'=>false,'draft'=>$reply['text'],'workflow'=>$w];
    });},true);
}
