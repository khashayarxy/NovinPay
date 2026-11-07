<?php
declare(strict_types=1);
// Private, additive metadata in the existing conversation transaction. Never an authority.
function support_operator_read_key(array $operator): string {
    return $operator['operator_id']??substr(hash('sha256','legacy:'.ADMIN_USERNAME),0,32);
}
function support_read_contains(array $state,int $id): bool {
    if($id<=($state['through']??0))return true;
    $ranges=$state['ranges']??[];$lo=0;$hi=count($ranges)-1;while($lo<=$hi){$mid=intdiv($lo+$hi,2);[$a,$b]=$ranges[$mid];if($id<$a)$hi=$mid-1;elseif($id>$b)$lo=$mid+1;else return true;}
    return false;
}
function support_read_compact(array $conversation,array $state,array $extra=[]): array {
    $through=(int)($state['through']??0);$ranges=[];$contiguous=true;$extra=array_fill_keys($extra,true);
    $messages=$conversation['messages'];usort($messages,fn($a,$b)=>$a['id']<=>$b['id']);
    foreach($messages as $message){if($message['type']!=='user')continue;$id=$message['id'];
        $read=isset($extra[$id])||support_read_contains($state,$id);
        if($contiguous&&$read){$through=max($through,$id);continue;}
        $contiguous=false;if(!$read||$id<=$through)continue;
        $last=count($ranges)-1;if($last>=0&&$ranges[$last][1]+1===$id)$ranges[$last][1]=$id;else $ranges[]=[$id,$id];
    }
    if(count($ranges)>4096)support_failure(503,'read_state_capacity');
    return ['through'=>$through,'ranges'=>$ranges];
}
function support_operator_reads_init(array &$conversation): void {
    if(isset($conversation['operator_reads']))return;
    // Preserve the old shared-read snapshot once. Future reads cannot alter this baseline.
    $ids=array_column(array_filter($conversation['messages'],fn($m)=>$m['type']==='user'&&($m['read']??false)),'id');
    $conversation['operator_reads']=['baseline'=>support_read_compact($conversation,['through'=>0,'ranges'=>[]],$ids),'accounts'=>[]];
}
function support_operator_message_read(array $conversation,array $message,?array $operator=null): bool {
    if($message['type']!=='user')return true;
    if($operator===null||!isset($conversation['operator_reads']))return ($message['read']??false)===true;
    $reads=$conversation['operator_reads'];$state=$reads['accounts'][support_operator_read_key($operator)]??$reads['baseline'];
    return support_read_contains($state,$message['id']);
}
function support_operator_unread(array $conversation,?array $operator=null): int {
    return count(array_filter($conversation['messages'],fn($m)=>$m['type']==='user'&&!support_operator_message_read($conversation,$m,$operator)));
}
function support_operator_read_visible(array &$conversation,array $operator,array $ids): array {
    support_operator_reads_init($conversation);$key=support_operator_read_key($operator);$reads=&$conversation['operator_reads'];
    if(!isset($reads['accounts'][$key])&&count($reads['accounts'])>=1024)support_failure(503,'read_state_capacity');
    $accepted=array_values(array_column(array_filter($conversation['messages'],fn($m)=>$m['type']==='user'&&in_array($m['id'],$ids,true)),'id'));
    $reads['accounts'][$key]=support_read_compact($conversation,$reads['accounts'][$key]??$reads['baseline'],$accepted);
    return $accepted;
}
function support_operator_reads_validate(array $conversation,int $head): void {
    if(!isset($conversation['operator_reads']))return;$r=$conversation['operator_reads'];
    if(!is_array($r)||array_diff(array_keys($r),['baseline','accounts'])||!isset($r['baseline'],$r['accounts'])||!is_array($r['accounts'])||count($r['accounts'])>1024)support_failure(503,'storage_invalid');
    foreach($r['accounts'] as $key=>$state)if(!is_string($key)||!preg_match('/^[a-f0-9]{32}$/D',$key))support_failure(503,'storage_invalid');
    foreach(array_merge([$r['baseline']],array_values($r['accounts'])) as $state){
        if(!is_array($state)||array_diff(array_keys($state),['through','ranges'])||!is_int($state['through']??null)||$state['through']<0||$state['through']>$head||!is_array($state['ranges']??null)||!array_is_list($state['ranges'])||count($state['ranges'])>4096)support_failure(503,'storage_invalid');
        $last=$state['through'];foreach($state['ranges'] as $range){if(!is_array($range)||count($range)!==2||!array_is_list($range)||!is_int($range[0])||!is_int($range[1])||$range[0]<=$last||$range[1]<$range[0]||$range[1]>$head)support_failure(503,'storage_invalid');$last=$range[1];}
    }
}

// Shared attention is derived independently from the preserved account histories.
function support_team_answered_through(array $c): int {
 $through=0;foreach($c['messages'] as $m)if($m['type']==='admin'&&(!function_exists('support_faq_auto_message')||!support_faq_auto_message($m)))$through=max($through,$m['team_reply_through']??$m['id']);return $through;
}
function support_team_unread(array $c,?array $ignored=null): int {
 $through=support_team_answered_through($c);return count(array_filter($c['messages'],fn($m)=>$m['type']==='user'&&$m['id']>$through&&($m['read']??false)!==true));
}
