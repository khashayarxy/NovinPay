<?php
declare(strict_types=1);
require_once __DIR__.'/operators.php';

function support_operations_defaults(): array {return ['revision'=>0,'effective_at'=>null,'timezone'=>'Asia/Tehran','days'=>[0,1,2,3,6],'start'=>'09:00','end'=>'17:00','enabled'=>false,'notice'=>false,'threshold'=>300];}
// Installation settings use the existing operator settings authority.
function support_operations_settings(): array {
    if(!support_multi_operator())return support_operations_defaults();
    return support_ops_store(fn(array &$s)=>($s['preferences']['support_operations']??support_operations_defaults()));
}
function support_operations_save(array $op,array $input): array {
    support_ops_admin($op);$raw=json_decode(support_string($input['settings']??null,2048),true,8,JSON_THROW_ON_ERROR);
    if(!is_array($raw)||array_diff(array_keys($raw),['revision','timezone','days','start','end','enabled','notice','threshold']))support_failure(400,'invalid_input');
    if(!in_array($raw['timezone']??null,DateTimeZone::listIdentifiers(),true)||!is_array($raw['days']??null)||!array_is_list($raw['days'])||!$raw['days']||count($raw['days'])>7||count(array_unique($raw['days']))!==count($raw['days']))support_failure(400,'invalid_input');
    foreach($raw['days']as $d)if(!is_int($d)||$d<0||$d>6)support_failure(400,'invalid_input');
    foreach(['start','end']as $k)if(!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$raw[$k]??''))support_failure(400,'invalid_input');
    if($raw['start']===$raw['end']||!is_bool($raw['enabled']??null)||!is_bool($raw['notice']??null)||!is_int($raw['threshold']??null)||$raw['threshold']<60||$raw['threshold']>86400)support_failure(400,'invalid_input');
    return support_ops_store(function(array &$s)use($op,$raw){support_ops_recheck($s,$op);$old=$s['preferences']['support_operations']??support_operations_defaults();if(($raw['revision']??null)!==$old['revision'])support_failure(409,'settings_changed');
        $next=$raw;$next['revision']=$old['revision']+1;$next['effective_at']=time();$s['preferences']['support_operations']=$next;
        // A bounded history permits honest business-time calculations across changes.
        $history=$s['preferences']['support_hours_history']??[];$history=array_values(array_filter($history,fn($p)=>$p['effective_at']>time()-90*86400));if(count($history)>=100)support_failure(429,'settings_capacity');$history[]=$next;$s['preferences']['support_hours_history']=$history;
        support_ops_audit($s,$op['operator_id'],'working_hours_saved');return ['status'=>'success','settings'=>$next];
    },true);
}
function support_hours_seconds(int $from,int $to,array $p): ?int {
    if(!$p['enabled']||!is_int($p['effective_at'])||$from<$p['effective_at']||$to<$from||$to-$from>90*86400)return null;
    $tz=new DateTimeZone($p['timezone']);$first=(new DateTimeImmutable('@'.$from))->setTimezone($tz)->setTime(0,0)->modify('-1 day');$sum=0;
    for($day=$first;$day->getTimestamp()<$to;$day=$day->modify('+1 day')){
        if(!in_array((int)$day->format('w'),$p['days'],true))continue;
        $start=new DateTimeImmutable($day->format('Y-m-d').' '.$p['start'],$tz);$end=new DateTimeImmutable($day->format('Y-m-d').' '.$p['end'],$tz);if($end<=$start)$end=$end->modify('+1 day');
        $sum+=max(0,min($to,$end->getTimestamp())-max($from,$start->getTimestamp()));
    }return $sum;
}
function support_business_seconds(int $from,int $to,array $history): ?int {
    if(!$history)return null;usort($history,fn($a,$b)=>$a['effective_at']<=>$b['effective_at']);$total=0;$cursor=$from;
    if($to===$from){foreach(array_reverse($history)as $p)if($p['effective_at']<=$from)return support_hours_seconds($from,$to,$p);return null;}
    foreach($history as $i=>$p){$end=min($to,$history[$i+1]['effective_at']??$to);if($end<=$cursor||$p['effective_at']>$cursor)continue;$part=support_hours_seconds($cursor,$end,$p);if($part===null)return null;$total+=$part;$cursor=$end;if($cursor===$to)return $total;}
    return null;
}
function support_operations_availability(): array {
    $p=support_operations_settings();$now=time();$open=$p['enabled']&&support_hours_seconds($now,$now+1,$p)===1;
    return ['outside'=>$p['enabled']&&$p['notice']&&!$open,'text'=>'اکنون خارج از ساعت کاری پشتیبانی هستیم؛ پیام شما در گفتگو ثبت می‌شود.'];
}
function support_waiting(array $c): array {
    $w=support_workflow($c);if($w['status']==='resolved')return ['state'=>'resolved','since'=>null];
    $since=null;$answered=false;$human=false;$start=support_episode_start($c);$teamThrough=support_team_answered_through($c);
    foreach($c['messages']as $m){if((int)$m['id']<=$start)continue;if($m['type']==='user'&&$m['id']>$teamThrough){$since??=strtotime($m['accepted_at']??$m['time']);$answered=false;}elseif($m['type']==='admin'){$human=true;$answered=true;}}
    // Explicit reopen means support owes a follow-up, even without a new message.
    if(($w['reopened_pending']??false)&&$since===null)$since=$w['reopened_at'];
    return ['state'=>$since!==null?'support':'customer','since'=>$since,'unanswered'=>!$human];
}
