<?php
declare(strict_types=1);
require_once __DIR__.'/operations.php';

const SUPPORT_METRIC_KEYS=['new','incoming','outgoing','resolved','closures','reopened','first','business_first','resolution','wait','business_wait','positive','negative','hours','operators','handled','insufficient'];
function support_metrics_validate(array $s): void {
    if(!isset($s['analytics']))return;$a=$s['analytics'];
    if(!is_array($a)||array_diff(array_keys($a),['since','days'])||!is_int($a['since']??null)||$a['since']<1||!is_array($a['days']??null)||count($a['days'])>92)support_failure(503,'analytics_invalid');
    foreach($a['days']as $day=>$d){
        if(!preg_match('/^\d{4}-\d\d-\d\d$/D',(string)$day)||!is_array($d)||array_diff(array_keys($d),SUPPORT_METRIC_KEYS)||!is_array($d['operators']??null)||count($d['operators'])>100||!is_array($d['hours']??null)||count($d['hours'])!==24)support_failure(503,'analytics_invalid');
        foreach(['new','incoming','outgoing','resolved','closures','reopened','positive','negative']as $k)if(!is_int($d[$k]??null)||$d[$k]<0)support_failure(503,'analytics_invalid');
        foreach(['first','business_first','resolution','wait','business_wait']as $k){if(!is_array($d[$k]??null)||count($d[$k])>4096)support_failure(503,'analytics_invalid');foreach($d[$k]as $n)if(!is_int($n)||$n<0)support_failure(503,'analytics_invalid');}
        foreach($d['hours']as $n)if(!is_int($n)||$n<0)support_failure(503,'analytics_invalid');
        foreach($d['operators']as $id=>$o){if(!preg_match('/^[a-f0-9]{32}$/D',(string)$id)||!is_array($o)||array_diff(array_keys($o),['insufficient','handled','conversations','outgoing','closures','resolved','first','business_first','resolution','wait','business_wait']))support_failure(503,'analytics_invalid');foreach($o as $key=>$v){if($key==='insufficient'){if(!is_array($v)||array_filter($v,fn($n)=>$n!==true))support_failure(503,'analytics_invalid');}elseif(in_array($key,['handled','outgoing','closures','resolved'],true)){if(!is_int($v)||$v<0)support_failure(503,'analytics_invalid');}else{if(!is_array($v)||count($v)>4096)support_failure(503,'analytics_invalid');foreach($v as $n)if($key==='conversations'?!preg_match('/^[a-f0-9]{64}$/D',(string)$n):(!is_int($n)||$n<0))support_failure(503,'analytics_invalid');}}}
    }
}
function support_metric_bucket(): array {return ['new'=>0,'incoming'=>0,'outgoing'=>0,'resolved'=>0,'closures'=>0,'reopened'=>0,'first'=>[],'business_first'=>[],'resolution'=>[],'wait'=>[],'business_wait'=>[],'positive'=>0,'negative'=>0,'hours'=>array_fill(0,24,0),'operators'=>[]];}
function support_metric_add(array &$d,string $key,?string $operator=null,?int $sample=null): void {
    if($sample!==null){if(count($d[$key]??[])>=512){$d['insufficient'][$key]=true;if($operator!==null)$d['operators'][$operator]['insufficient'][$key]=true;return;}$d[$key][]=$sample;}else $d[$key]=($d[$key]??0)+1;
    if($operator!==null){$d['operators'][$operator]??=[];if($sample!==null){if(count($d['operators'][$operator][$key]??[])>=512){$d['operators'][$operator]['insufficient'][$key]=true;return;}$d['operators'][$operator][$key][]=$sample;}else $d['operators'][$operator][$key]=($d['operators'][$operator][$key]??0)+1;}
}
// Called once immediately before the existing atomic chat write. Counts cannot
// survive without the corresponding messages/transitions, including lost ACKs.
function support_metrics_commit(array $before,array &$s): void {
    if(!support_multi_operator())return;$now=time();$tz=new DateTimeZone('Asia/Tehran');$day=(new DateTimeImmutable('@'.$now))->setTimezone($tz)->format('Y-m-d');
    $metrics=$s['analytics']??['since'=>$now,'days'=>[]];
    foreach($metrics['days']as $key=>&$value){if($key<date('Y-m-d',$now-90*86400))unset($metrics['days'][$key]);elseif($key<date('Y-m-d',$now-30*86400))$value['operators']=[];}unset($value);
    $d=$metrics['days'][$day]??support_metric_bucket();$history=null;
    foreach($s['conversations']as $id=>&$c){$old=$before['conversations'][$id]??null;if($old===$c)continue;
        if($old===null)support_metric_add($d,'new');
        elseif((int)($c['episode']??1)>(int)($old['episode']??1))support_metric_add($d,'new');
        $known=array_fill_keys(array_column($old['messages']??[],'id'),true);
        // Automatic FAQ answers are neither customer traffic nor human performance.
        foreach($c['messages']as $m){if(support_faq_auto_message($m)||isset($known[$m['id']])||!isset($m['accepted_at']))continue;
            $operator=$m['type']==='admin'?($m['operator_id']??null):null;$at=strtotime($m['accepted_at']);
            if($m['type']==='user'){support_metric_add($d,'incoming');$d['hours'][(int)(new DateTimeImmutable('@'.$at))->setTimezone($tz)->format('G')]++;}
            elseif($m['type']==='admin'){
                support_metric_add($d,'outgoing',$operator);
                if($operator){$fingerprint=hash_hmac('sha256','metric-conversation:'.$id,support_security_key());$d['operators'][$operator]['conversations']??=[];if(!in_array($fingerprint,$d['operators'][$operator]['conversations'],true)){if(count($d['operators'][$operator]['conversations'])>=512)$d['operators'][$operator]['insufficient']['conversations']=true;else $d['operators'][$operator]['conversations'][]=$fingerprint;}}
                $first=null;$waiting=null;$priorReply=false;
                foreach($c['messages']as $prior){if($prior['id']>=$m['id'])break;if(!isset($prior['accepted_at'])&&in_array($prior['type'],['user','admin'],true)){$priorReply=true;$waiting=null;continue;}if($prior['type']==='user'){$first??=strtotime($prior['accepted_at']);$waiting??=strtotime($prior['accepted_at']);}elseif($prior['type']==='admin'){$priorReply=true;$waiting=null;}}
                $history??=support_ops_store(fn(array &$o)=>$o['preferences']['support_hours_history']??[]);
                if($waiting!==null){support_metric_add($d,'wait',$operator,max(0,$at-$waiting));$business=support_business_seconds($waiting,$at,$history);if($business!==null)support_metric_add($d,'business_wait',$operator,$business);}
                if(!$priorReply&&$first!==null&&!isset($c['history_removed_at'])){support_metric_add($d,'first',$operator,max(0,$at-$first));$business=support_business_seconds($first,$at,$history);if($business!==null)support_metric_add($d,'business_first',$operator,$business);}
            }
        }
        $w=$c['workflow']??[];$ow=$old['workflow']??[];
        if(($w['status']??'')==='resolved'&&($ow['status']??'')!=='resolved'){
            $actor=preg_match('/^[a-f0-9]{32}$/D',$w['closed_by']??'')?$w['closed_by']:null;support_metric_add($d,'closures',$actor);
            // Each genuine episode closure contributes one resolved cycle.
            $event=(string)($w['resolution_event']??'');
            if($event!==''&&($c['metrics_resolved_event']??null)!==$event){
                $c['metrics_resolved_event']=$event;support_metric_add($d,'resolved',$actor);
                $start=(int)($c['episode_started_at']??0);if($start<=0){$created=strtotime($c['created_at'].' Asia/Tehran');$start=$created!==false?$created:0;}
                if($start>0&&$start>=$metrics['since']&&!isset($c['history_removed_at']))support_metric_add($d,'resolution',$actor,max(0,$now-$start));
            }
        }
        if(($w['reopen_count']??0)>($ow['reopen_count']??0))support_metric_add($d,'reopened');
    }unset($c);$metrics['days'][$day]=$d;$s['analytics']=$metrics;
}
function support_metric_stats(array $values): array {
    if(!$values)return ['count'=>0,'average'=>null,'median'=>null,'p90'=>null];sort($values);$n=count($values);return ['count'=>$n,'average'=>array_sum($values)/$n,'median'=>$n%2?$values[intdiv($n,2)]:($values[$n/2-1]+$values[$n/2])/2,'p90'=>$values[max(0,(int)ceil(.9*$n)-1)]];
}
function support_report_range(array $q): array {
    $tz=new DateTimeZone('Asia/Tehran');$today=new DateTimeImmutable('today',$tz);$range=$q['range']??'7';
    if(!in_array($range,['today','7','30','custom'],true))support_failure(400,'invalid_range');
    if($range==='custom'){$from=$q['from']??'';$to=$q['to']??'';}else{$from=$today->modify('-'.(['today'=>0,'7'=>6,'30'=>29][$range]).' days')->format('Y-m-d');$to=$today->format('Y-m-d');}
    foreach([$from,$to]as $v){if(!is_string($v)||!preg_match('/^\d{4}-\d\d-\d\d$/D',$v))support_failure(400,'invalid_range');$date=DateTimeImmutable::createFromFormat('!Y-m-d',$v,$tz);if(!$date||$date->format('Y-m-d')!==$v)support_failure(400,'invalid_range');}
    if($from<$today->modify('-89 days')->format('Y-m-d')||$to<$from||$to>$today->format('Y-m-d')||strtotime($to)-strtotime($from)>89*86400)support_failure(400,'invalid_range');return [$from,$to];
}
function support_report(array $op,array $q): array {
    support_ops_admin($op);[$from,$to]=support_report_range($q);$settings=support_operations_settings();
    $r=support_store(function(array &$s,bool &$changed)use($from,$to,$settings){
        $all=support_metric_bucket();$trend=[];$operations=['waiting'=>[],'delayed'=>[],'reopened'=>[],'unanswered'=>[],'negative'=>[]];$snapshot=['open'=>0,'needs_reply'=>0,'unread'=>0,'resolved'=>0,'waiting_support'=>0,'waiting_customer'=>0];
        foreach($s['analytics']['days']??[]as $day=>$d){if($day<$from||$day>$to)continue;$trend[]=['day'=>$day,'new'=>$d['new'],'incoming'=>$d['incoming'],'first'=>support_metric_stats(isset($d['insufficient']['first'])?[]:$d['first'])];
            foreach(['new','incoming','outgoing','resolved','closures','reopened']as $key)$all[$key]+=$d[$key]??0;
            foreach($d['insufficient']??[]as $key=>$flag)$all['insufficient'][$key]=true;
            foreach(['first','business_first','resolution','wait','business_wait']as $key)$all[$key]=array_merge($all[$key],$d[$key]);
            foreach($d['hours']as $h=>$count)$all['hours'][$h]+=$count;
            foreach($day>=date('Y-m-d',time()-30*86400)?$d['operators']:[]as $oid=>$od)foreach($od as $key=>$v){if(is_array($v))$all['operators'][$oid][$key]=array_merge($all['operators'][$oid][$key]??[],$v);else $all['operators'][$oid][$key]=($all['operators'][$oid][$key]??0)+$v;}
        }

        foreach($s['conversations']as $id=>$c){if(support_conversation_retired($c))continue;$w=support_workflow($c);$wait=support_waiting($c);$snapshot[$w['status']==='resolved'?'resolved':'open']++;if($wait['state']==='support')$snapshot['waiting_support']++;elseif($wait['state']==='customer')$snapshot['waiting_customer']++;if(support_needs_reply($c,$w))$snapshot['needs_reply']++;if($c['unread_count'])$snapshot['unread']++;
            $row=['id'=>$id,'name'=>'مهمان','waiting_seconds'=>$wait['since']?max(0,time()-$wait['since']):null];if($wait['state']==='support'){$operations['waiting'][]=$row;if($row['waiting_seconds']>=$settings['threshold'])$operations['delayed'][]=$row;}if(($wait['unanswered']??false)&&$w['status']!=='resolved')$operations['unanswered'][]=$row;
            if(($w['reopened_at']??0)>time()-86400)$operations['reopened'][]=$row;
        }
        foreach($operations as &$rows)$rows=array_slice($rows,0,50);unset($rows);
        $stats=[];foreach(['first','business_first','resolution','wait','business_wait']as $key)$stats[$key]=support_metric_stats(isset($all['insufficient'][$key])?[]:$all[$key]);
        return ['status'=>'success','from'=>$from,'to'=>$to,'timezone'=>'Asia/Tehran','coverage_since'=>$s['analytics']['since']??null,'totals'=>array_intersect_key($all,array_flip(['new','incoming','outgoing','resolved','closures','reopened'])),'snapshot'=>$snapshot,'stats'=>$stats,'hours'=>$all['hours'],'trend'=>$trend,'operators'=>$all['operators'],'operations'=>$operations,'threshold'=>$settings['threshold'],'above_threshold'=>isset($all['insufficient']['wait'])?null:count(array_filter($all['wait'],fn($n)=>$n>$settings['threshold']))];
    });
    // CSAT remains in its existing independent deduplication authority.
    $r['csat']=['positive'=>0,'negative'=>0,'responses'=>0,'percent'=>null,'available'=>false,'retained_positive'=>0,'retained_negative'=>0];
    if(support_satisfaction_enabled())$r=support_satisfaction_store(function(array &$s)use($r,$from,$to){$out=$r;$out['csat']['available']=true;foreach($s['daily']??[]as $day=>$d)if($day>=$from&&$day<=$to){foreach(['positive','negative']as $k)$out['csat'][$k]+=$d[$k];foreach($day>=date('Y-m-d',time()-30*86400)?($d['operators']??[]):[]as $id=>$o)foreach($o as $k=>$n)$out['operators'][$id][$k]=($out['operators'][$id][$k]??0)+$n;}
        foreach($out['trend']as &$point){$daily=$s['daily'][$point['day']]??[];$point['positive']=$daily['positive']??0;$point['negative']=$daily['negative']??0;}unset($point);
        foreach($s['ratings']as $vote){$voteDay=(new DateTimeImmutable('@'.$vote['at']))->setTimezone(new DateTimeZone('Asia/Tehran'))->format('Y-m-d');if($vote['expires']>time()&&$voteDay>=$from&&$voteDay<=$to)$out['csat']['retained_'.$vote['value']]++;}
        foreach($s['ratings']as $rating)if($rating['expires']>time()&&$rating['value']==='negative')$out['operations']['negative'][]=array_intersect_key($rating,array_flip(['conversation','reason','comment','at']));$out['operations']['negative']=array_slice($out['operations']['negative'],0,50);return $out;});
    $r['csat']['responses']=$r['csat']['positive']+$r['csat']['negative'];$r['csat']['percent']=$r['csat']['responses']?100*$r['csat']['positive']/$r['csat']['responses']:null;
    $accounts=support_ops_store(fn(array &$s)=>array_map(fn($a)=>$a['name'],$s['accounts']));$ops=[];foreach($r['operators']as $id=>$d){$n=($d['positive']??0)+($d['negative']??0);$ops[]=['name'=>$accounts[$id]??'اپراتور پیشین','handled'=>isset($d['handled'])||isset($d['insufficient']['conversations'])?null:count(array_unique($d['conversations']??[])),'responses'=>$d['outgoing']??0,'first'=>support_metric_stats(isset($d['insufficient']['first'])?[]:($d['first']??[])),'closures'=>$d['closures']??0,'positive'=>$d['positive']??0,'negative'=>$d['negative']??0,'survey_count'=>$n,'csat'=>$n?100*($d['positive']??0)/$n:null];}$r['operators']=$ops;return $r;
}
function support_report_csv(array $report): string {
    $f=fopen('php://temp','w+');fwrite($f,"\xEF\xBB\xBF");$safe=fn($v)=>is_string($v)&&preg_match('/^[\s\x00-\x1f]*[=+@-]/u',$v)?"'".$v:$v;
    fputcsv($f,['metric','value','from','to','timezone'],',','"','');foreach($report['totals']as $key=>$value)fputcsv($f,array_map($safe,[$key,$value,$report['from'],$report['to'],$report['timezone']]),',','"','');foreach(['positive','negative','responses','percent']as $key)fputcsv($f,array_map($safe,['csat_'.$key,$report['csat'][$key],$report['from'],$report['to'],$report['timezone']]),',','"','');foreach($report['stats'] as $metric=>$stats)foreach($stats as $key=>$value)fputcsv($f,array_map($safe,[$metric.'_'.$key,$value,$report['from'],$report['to'],$report['timezone']]),',','"','');rewind($f);return stream_get_contents($f);
}
