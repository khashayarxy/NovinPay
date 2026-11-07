<?php
declare(strict_types=1);
// Private history shares the original conversation transaction and retention boundary.
function support_edit_author(array $m,array $actor,string $role): bool {
    if(($m['protocol_version']??null)!==2||!support_valid_uuid($m['client_message_id']??'')||!preg_match('/^[a-f0-9]{64}$/D',$m['payload_digest']??'')||!is_string($m['accepted_at']??null)||!preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/D',$m['accepted_at'])||!empty($m['attachments'])||isset($m['faq_question_id']))return false;
    if($role==='customer')return $m['type']==='user'&&hash_equals('customer:'.($actor['owner_id']??''),$m['sender_principal']??'');
    return $m['type']==='admin'&&isset($actor['operator_id'])&&($m['operator_id']??null)===$actor['operator_id']&&preg_match('/^operator:[a-f0-9]{64}$/D',$m['sender_principal']??'');
}
function support_edit_public(array $m,array $actor,string $role): array {
    $at=strtotime($m['accepted_at']??'');$eligible=support_edit_author($m,$actor,$role)&&$at!==false&&$at<=time()&&time()<=$at+900&&($m['revision']??0)<20;
    return ['revision'=>$m['revision']??0,'edited_at'=>$m['edited_at']??null,'can_edit'=>$eligible,'edit_until'=>$eligible?gmdate('Y-m-d\TH:i:s\Z',$at+900):null];
}
function support_edit_message(array $actor,string $role,string $id,int $mid,int $revision,string $request,string $raw): array {
    if(!support_valid_uuid($request)||$revision<0||$revision>20)support_failure(400,'invalid_input');
    $text=support_text($raw);$principal=$role==='operator'?'operator:'.($actor['operator_id']??''):support_principal($actor,$role);
    support_throttle('message-edit:'.$principal,30,60);
    return support_store(function(array &$s,bool &$changed)use($actor,$role,$id,$mid,$revision,$request,$text,$principal){
        support_conversation($s,$id,$role==='customer'?$actor:null,$role==='operator');$c=&$s['conversations'][$id];$position=array_search($mid,array_column($c['messages'],'id'),true);
        if($position===false)support_failure(404,'message_unavailable');$m=&$c['messages'][$position];
        if(!support_edit_author($m,$actor,$role))support_failure(403,'message_edit_forbidden');
        foreach($c['message_edits']??[] as $e)if($e['request_id']===$request){if($e['message_id']!==$mid||$e['actor']!==$principal||$e['previous_revision']!==$revision||$e['updated_text']!==$text)support_failure(409,'edit_idempotency_conflict');return support_edit_result($s,$c,$m,$actor,$role,true);}
        $at=strtotime($m['accepted_at']);if($at===false||$at>time()||time()>$at+900)support_failure(409,'message_edit_expired');
        if(($m['revision']??0)!==$revision)support_failure(409,'message_edit_conflict');
        if(($m['edited_text']??$m['text'])===$text)return support_edit_result($s,$c,$m,$actor,$role,true);
        $history=$c['message_edits']??[];if($revision>=20||count($history)>=1000||strlen(json_encode($history,JSON_THROW_ON_ERROR))>2097152-20000)support_failure(409,'message_edit_capacity');
        $now=gmdate('Y-m-d\TH:i:s\Z');$seq=($c['edit_head']??0)+1;
        $history[]=['sequence'=>$seq,'message_id'=>$mid,'request_id'=>$request,'original_text'=>$m['text'],'previous_text'=>$m['edited_text']??$m['text'],'updated_text'=>$text,'actor'=>$principal,'accepted_at'=>$m['accepted_at'],'edited_at'=>$now,'previous_revision'=>$revision,'revision'=>$revision+1];
        $m['edited_text']=$text;$m['revision']=$revision+1;$m['edited_at']=$now;$m['edit_sequence']=$seq;$c['edit_head']=$seq;$c['message_edits']=$history;
        if($position===array_key_last($c['messages']))$c['last_message']=$text;
        $changed=true;return support_edit_result($s,$c,$m,$actor,$role,false);
    },true);
}
function support_edit_result(array $s,array $c,array $m,array $actor,string $role,bool $replayed): array {
    return ['status'=>'success','conversation_id'=>$c['user_id'],'store_epoch'=>$s['store_epoch'],'edit_head'=>$c['edit_head']??0,'message'=>support_wire_message($m)+support_edit_public($m,$actor,$role),'replayed'=>$replayed];
}
function support_edit_page(array $c,array $query,array $actor,string $role): array {
    $head=$c['edit_head']??0;$base=['edit_head'=>$head,'edit_next'=>$head,'edit_has_more'=>false,'updates'=>[],'reference_updates'=>[]];
    if(!isset($query['edit_after'],$query['known_from'],$query['known_through']))return $base;
    $after=support_cursor($query['edit_after']);$from=support_cursor($query['known_from']);$through=support_cursor($query['known_through']);if($after>$head||$from>$through)support_failure(409,'cursor_reset_required');
    $events=array_values(array_filter($c['message_edits']??[],fn($e)=>$e['sequence']>$after));$batch=array_slice($events,0,50);$targets=array_fill_keys(array_column($batch,'message_id'),true);$live=array_column($c['messages'],null,'id');
    foreach($live as $m){if($m['id']<$from||$m['id']>$through)continue;if(isset($targets[$m['id']])){$out=support_wire_message($m)+support_edit_public($m,$actor,$role);if($role==='operator'&&$m['type']==='user'){$out['read']=support_operator_message_read($c,$m,$actor);$out['read_receipt']=$out['read'];}$base['updates'][]=$out;}if(isset($targets[$m['reply_to']??0]))$base['reference_updates'][]=['id'=>$m['id'],'reference'=>support_reply_preview($m,$live)];}
    $base['edit_next']=count($events)>50?end($batch)['sequence']:$head;$base['edit_has_more']=count($events)>50;return $base;
}
function support_edit_validate(array $c): void {
    $history=$c['message_edits']??[];if(!is_array($history)||!array_is_list($history)||count($history)>1000||!is_int($c['edit_head']??0)||($c['edit_head']??0)<0||strlen(json_encode($history,JSON_THROW_ON_ERROR))>2097152)support_failure(503,'storage_invalid');
    $live=array_column($c['messages'],null,'id');$last=0;$requests=[];$revisions=[];$previous=[];$latest=[];
    foreach($history as $e){if(!is_array($e)||!is_int($e['sequence']??null)||$e['sequence']<=$last||$e['sequence']>($c['edit_head']??0)||!isset($live[$e['message_id']??0])||!support_valid_uuid($e['request_id']??'')||isset($requests[$e['request_id']])||!is_int($e['previous_revision']??null)||($e['revision']??0)!==$e['previous_revision']+1||$e['revision']>20||($revisions[$e['message_id']]??0)!==$e['previous_revision'])support_failure(503,'storage_invalid');
        foreach(['original_text','previous_text','updated_text','actor','accepted_at','edited_at'] as $key)if(!is_string($e[$key]??null)||!mb_check_encoding($e[$key],'UTF-8')||strlen($e[$key])>2000)support_failure(503,'storage_invalid');
        $message=$live[$e['message_id']];$actor=$message['type']==='user'?($message['sender_principal']??''):'operator:'.($message['operator_id']??'');$accepted=strtotime($e['accepted_at']);$edited=strtotime($e['edited_at']);
        if($e['actor']!==$actor||$e['original_text']!==$message['text']||$e['previous_text']!==($previous[$e['message_id']]??$message['text'])||$e['accepted_at']!==($message['accepted_at']??null)||$accepted===false||$edited===false||gmdate('Y-m-d\TH:i:s\Z',$edited)!==$e['edited_at']||$edited<$accepted||$edited>$accepted+900)support_failure(503,'storage_invalid');
        $previous[$e['message_id']]=$e['updated_text'];$latest[$e['message_id']]=$e;$last=$e['sequence'];$requests[$e['request_id']]=true;$revisions[$e['message_id']]=$e['revision'];
    }
    foreach($live as $m)if(isset($m['revision'])||isset($m['edited_at'])||isset($m['edited_text'])){if(!is_int($m['revision']??null)||$m['revision']<1||$m['revision']>20||($revisions[$m['id']]??0)!==$m['revision']||!is_string($m['edited_text']??null)||!is_string($m['edited_at']??null)||($latest[$m['id']]['updated_text']??null)!==$m['edited_text']||($latest[$m['id']]['edited_at']??null)!==$m['edited_at']||($latest[$m['id']]['sequence']??null)!==($m['edit_sequence']??null))support_failure(503,'storage_invalid');}
}
