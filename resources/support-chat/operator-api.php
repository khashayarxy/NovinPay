<?php
declare(strict_types=1);
require_once __DIR__.'/support-loader.php';
require_once support_core_path('messages.php');require_once support_core_path('operators.php');require_once support_core_path('operator-profile.php');
try{
    if(!support_multi_operator())support_failure(404,'operator_unavailable');
    if(($_SERVER['CONTENT_LENGTH']??0)>16384)support_failure(400,'invalid_input');
    $method=$_SERVER['REQUEST_METHOD']??'';if(!in_array($method,['GET','POST'],true))support_failure(405,'method_not_allowed');
    $action=support_string($_POST['action']??$_GET['action']??'bootstrap',32);
    if($action==='bootstrap'&&$method==='GET'){$base=support_session('operator',true);$op=support_operator(false);support_json(['status'=>'success','token'=>$base['csrf_token'],'authenticated'=>$op!==null,'operator'=>$op?['id'=>$op['operator_id'],'name'=>$op['operator_name'],'role'=>$op['operator_role'],'sender_groups'=>support_profile_groups($op),'sender_group'=>substr(hash_hmac('sha256','sender-group:'.support_principal($op,'operator'),support_security_key()),0,32)]:null,'config'=>support_widget_config()]);}
    if($action==='login'&&$method==='POST'){
        $base=support_session('operator',true);support_csrf($base);$a=support_ops_authenticate(support_string($_POST['username']??null,80),support_ops_password($_POST['password']??null));
        support_change_operator(function()use($a){if(!session_regenerate_id(true))support_failure(503,'session_unavailable');$now=time();$_SESSION=['role'=>'operator','authenticated'=>true,'operator_id'=>$a['id'],'credential_version'=>support_ops_version($a),'csrf_token'=>bin2hex(random_bytes(32)),'auth_epoch'=>bin2hex(random_bytes(16)),'issued_at'=>$now,'last_seen'=>$now,'interactive_at'=>$now];});support_json(['status'=>'success']);
    }
    $op=support_operator();if($method==='POST'){support_csrf($op);support_interaction();support_throttle('operator-action:'.support_principal($op,'operator'),90);}
    if(str_starts_with($action,'push_')||str_starts_with($action,'notification_')){
        require_once support_core_path('operator-notifications.php');
        if($action==='notification_settings'&&$method==='GET')support_json(support_ops_store(function(array &$s)use($op){$p=$s['preferences'][$op['operator_id']]??['sms'=>false,'quiet'=>false];$p['quiet_start']=$p['quiet_start']??support_option_int('SUPPORT_OPERATOR_QUIET_START',22,0,23);$p['quiet_end']=$p['quiet_end']??support_option_int('SUPPORT_OPERATOR_QUIET_END',8,0,23);$p['timezone']=getenv('SUPPORT_TIMEZONE')?:'Asia/Tehran';$p['quiet_now']=support_alert_quiet($p);$p['push_available']=getenv('SUPPORT_OPERATOR_PUSH_REAL_ENABLED')==='1'&&getenv('SUPPORT_OPERATOR_PUSH_ENABLED')==='1';return ['status'=>'success','preferences'=>$p];}));
        if($action==='notification_preferences'&&$method==='POST')support_json(support_ops_store(function(array &$s)use($op){support_ops_recheck($s,$op);$p=$s['preferences'][$op['operator_id']]??['sms'=>false,'quiet'=>false];if(isset($_POST['field'])){$field=$_POST['field'];$value=$_POST['value']??null;if(in_array($field,['sms','quiet'],true)){if(!in_array($value,['0','1'],true))support_failure(400,'invalid_input');$p[$field]=$value==='1';}elseif(in_array($field,['quiet_start','quiet_end'],true)){if(!is_string($value)||!preg_match('/^(?:[0-9]|1[0-9]|2[0-3])$/D',$value))support_failure(400,'invalid_input');$p[$field]=(int)$value;}else support_failure(400,'invalid_input');}else{foreach(['sms','quiet']as $field)if(array_key_exists($field,$_POST))$p[$field]=$_POST[$field]==='1';}$s['preferences'][$op['operator_id']]=$p;return ['status'=>'success'];},true));
        if($action==='push_key'&&$method==='GET'){if(getenv('SUPPORT_OPERATOR_PUSH_ENABLED')!=='1')support_failure(404,'push_disabled');support_json(['status'=>'success','key'=>support_push_keys()['public']]);}
        if($action==='push_subscribe'&&$method==='POST')support_json(support_push_subscribe($op,support_string($_POST['subscription']??null,4096)));
        if($action==='push_unsubscribe'&&$method==='POST'){$endpoint=support_string($_POST['endpoint']??null,2048);$id=substr(hash_hmac('sha256','push-subscription'.$endpoint,support_security_key()),0,32);support_json(support_ops_store(function(array &$s)use($id,$op){support_ops_recheck($s,$op);if(isset($s['subscriptions'][$id])&&$s['subscriptions'][$id]['operator']!==$op['operator_id'])support_failure(403,'forbidden');unset($s['subscriptions'][$id]);return ['status'=>'success'];},true));}
        if($action==='notification_hint'&&$method==='GET'){
            support_throttle('operator-notification-hint:'.support_principal($op,'operator'),120);
            $prefs=support_ops_store(fn(array &$s)=>$s['preferences'][$op['operator_id']]??[]);
            if(support_alert_quiet($prefs))support_json(['status'=>'success','conversation'=>null]);
            support_json(support_store(function(array &$s,bool &$changed)use($op){
                $tests=support_development_conversations();$sequence=0;$conversation=null;$preview=[];
                foreach($s['conversations'] as $id=>$c){
                    if(isset($tests[$id])||support_team_unread($c,$op)===0)continue;
                    $messages=$c['messages']??[];
                    for($i=count($messages)-1;$i>=0;$i--){
                        $m=$messages[$i];
                        if($m['type']==='user'&&($m['read']??false)!==true&&$m['id']>support_team_answered_through($c)){
                            if($m['id']>$sequence){
                                $sequence=$m['id'];$conversation=$id;
                                $preview=($_GET['preview']??'')==='1'?support_alert_preview($m,(string)($c['user_name']??'مهمان')):[];
                            }
                            break;
                        }
                    }
                }
                return ['status'=>'success','conversation'=>$conversation,'sequence'=>$sequence,'store_epoch'=>$s['store_epoch'],'attention'=>support_attention_context($op,'operator'),...$preview];
            }));
        }
    }
    if($action==='readiness'&&$method==='GET'){require_once support_core_path('readiness.php');support_ops_admin($op);support_throttle('readiness:'.support_principal($op,'operator'),12);support_json(support_notification_readiness($op));}
    if($action==='public_profiles'&&$method==='GET'){require_once support_core_path('branding.php');support_json(support_brand_profiles($op));}
    if($action==='public_profile_save'&&$method==='POST'){require_once support_core_path('branding.php');support_json(support_brand_profile($op,$_POST));}
    if($action==='profile'&&$method==='GET')support_json(support_ops_store(function(array &$s)use($op){support_ops_recheck($s,$op);return ['status'=>'success','profile'=>support_profile_public($s['accounts'][$op['operator_id']])];}));
    if($action==='operator_accounts'&&$method==='GET'){support_ops_admin($op);support_json(support_ops_store(function(array &$s)use($op){support_ops_recheck($s,$op);return ['status'=>'success','accounts'=>array_values(array_map('support_profile_public',support_ops_visible_accounts($s)))];}));}
    if(in_array($action,['profile_save','profile_password','account_update','account_password'],true)&&$method==='POST')support_json(support_profile_action($op,$action,$_POST));
    if(in_array($action,['analytics','analytics_csv','operations_settings','operations_save'],true)){
        require_once support_core_path('analytics.php');support_ops_admin($op);support_throttle('reports:'.support_principal($op,'operator'),20);
        if($action==='operations_save'&&$method==='POST')support_json(support_operations_save($op,$_POST));
        if($action==='operations_settings'&&$method==='GET')support_json(['status'=>'success','settings'=>support_operations_settings()]);
        if(in_array($action,['analytics','analytics_csv'],true)&&$method==='GET'){
            $report=support_report($op,$_GET);
            if($action==='analytics_csv'){support_response_headers();header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="support-aggregate.csv"');echo support_report_csv($report);throw new \App\Support\Chat\LegacyResponse(null, http_response_code() ?: 200);}
            support_json($report);
        }support_failure(405,'method_not_allowed');
    }
    if($action==='health'&&$method==='GET'){require_once support_core_path('health.php');support_ops_admin($op);support_throttle('health:'.support_principal($op,'operator'),12);support_json(support_health_snapshot($op));}
    if($action==='dashboard_stats'&&$method==='GET'){
        support_throttle('operator-dashboard:'.support_principal($op,'operator'),60);
        $tests=support_development_conversations();$includeTests=($_GET['include_tests']??'')==='1';
        $r=support_store(function(array &$s,bool &$changed)use($tests,$includeTests,$op){
            $open=0;$unanswered=0;$unreadTotal=0;$urgent=0;$mine=0;$todayNew=0;$todayMessages=0;
            $todayStart=strtotime('today midnight');
            foreach($s['conversations'] as $id=>$c){
                if(support_conversation_retired($c))continue;
                if(isset($tests[$id])&&!$includeTests)continue;
                $w=support_workflow($c);$isResolved=($w['status']??'new')==='resolved';$needs=support_needs_reply($c,$w);
                $unread=(int)support_team_unread($c,$op);$unreadTotal+=$unread;
                if(!$isResolved)$open++;if($needs)$unanswered++;if(($w['priority']??'normal')==='urgent')$urgent++;
                if(($w['assigned']??'')===$op['operator_id']&&!$isResolved)$mine++;
                $createdAt=strtotime((string)($c['created_at']??''));if($createdAt>=$todayStart)$todayNew++;
                foreach($c['messages']??[] as $m){$mTime=strtotime((string)($m['time']??''));if($mTime>=$todayStart)$todayMessages++;}
            }
            $stats=['open_count'=>$open,'unanswered_count'=>$unanswered,'unread_count'=>$unreadTotal,'urgent_count'=>$urgent,'mine_count'=>$mine,'today_new'=>$todayNew,'today_messages'=>$todayMessages];
            if($op['operator_role']==='administrator'){
                require_once support_core_path('analytics.php');
                try{
                    $rep=support_report($op,['range'=>'today']);
                    $stats['median_first_response']=$rep['stats']['first']['median']??null;
                    $stats['avg_first_response']=$rep['stats']['first']['average']??null;
                    $stats['csat_percent']=$rep['csat']['percent']??null;
                    $stats['csat_positive']=$rep['csat']['positive']??0;
                    $stats['csat_negative']=$rep['csat']['negative']??0;
                }catch(Throwable $e){}
            }
            return ['status'=>'success','stats'=>$stats];
        });
        support_json($r);
    }
    if($action==='contacts'&&$method==='GET'){
        support_throttle('operator-contacts:'.support_principal($op,'operator'),60);
        $query=mb_strtolower(support_string($_GET['q']??'',200,true));
        $offset=support_cursor($_GET['offset']??null);
        $limit=min(100,max(1,(int)($_GET['limit']??50)));
        $tests=support_development_conversations();$includeTests=($_GET['include_tests']??'')==='1';
        $r=support_store(function(array &$s,bool &$changed)use($query,$offset,$limit,$tests,$includeTests,$op){
            $contacts=[];
            foreach($s['conversations'] as $id=>$c){
                if(support_conversation_retired($c))continue;
                if(isset($tests[$id])&&!$includeTests)continue;
                $w=support_workflow($c);$name=(string)($c['user_name']??'');$email=(string)($c['user_email']??'');$phone=(string)($c['user_phone']??'');
                $haystack=$name.' '.$email.' '.$phone.' '.$id;
                if($query!==''&&!str_contains(mb_strtolower($haystack),$query))continue;
                $msgCount=count($c['messages']??[]);$lastMsg=html_entity_decode((string)($c['last_message']??''),ENT_QUOTES|ENT_HTML5,'UTF-8');
                $contacts[]=['id'=>$id,'name'=>$name,'email'=>$email,'phone'=>$phone,'created_at'=>$c['created_at']??'','last_time'=>$c['last_time']??$c['created_at']??'','last_message'=>$lastMsg,'message_count'=>$msgCount,'customer_message_count'=>count(array_filter($c['messages'],fn($m)=>$m['type']==='user')),'operator_message_count'=>count(array_filter($c['messages'],fn($m)=>$m['type']==='admin')),'automatic_message_count'=>count(array_filter($c['messages'],'support_faq_auto_message')),'status'=>$w['status']??'new','priority'=>$w['priority']??'normal','unread'=>(int)support_team_unread($c,$op)];
            }
            usort($contacts,fn($a,$b)=>strcmp((string)$b['last_time'],(string)$a['last_time'])?:strcmp($a['id'],$b['id']));
            $total=count($contacts);
            return ['status'=>'success','contacts'=>array_slice($contacts,$offset,$limit),'total'=>$total,'has_more'=>$total>$offset+$limit,'next_offset'=>$offset+$limit];
        });
        support_json($r);
    }
    if($action==='logout'&&$method==='POST'){support_destroy_session('operator');support_json(['status'=>'success']);}
    if($action==='inbox'&&$method==='GET'){
        support_throttle('operator-inbox:'.support_principal($op,'operator'),120);$query=mb_strtolower(support_string($_GET['q']??'',200,true));$filter=$_GET['filter']??'';$offset=support_cursor($_GET['offset']??null);
        $tab=$_GET['tab']??'all';if(!in_array($tab,['all','unread','needs_reply'],true))support_failure(400,'invalid_input');
        $view=$_GET['view']??'inbox';if(!in_array($view,['inbox','mine'],true)||!in_array($filter,['','new','awaiting_reply','resolved','unread','urgent','snoozed','follow_up'],true))support_failure(400,'invalid_input');
        $operationSettings=support_operations_settings();$tests=support_development_conversations();$includeTests=($_GET['include_tests']??'')==='1';
        $r=support_store(function(array &$s,bool &$changed)use($query,$filter,$offset,$tests,$includeTests,$view,$op,$tab,$operationSettings){$rows=[];$total=0;$latest=0;
            foreach($s['conversations'] as $id=>$c){if(support_conversation_retired($c))continue;if(isset($tests[$id])&&!$includeTests)continue;$total+=support_team_unread($c,$op);$start=support_episode_start($c);foreach($c['messages'] as $m)if((int)$m['id']>$start&&$m['type']==='user'&&($m['read']??false)!==true&&$m['id']>support_team_answered_through($c))$latest=max($latest,$m['id']);}
            foreach($s['conversations'] as $id=>$c){if(support_conversation_retired($c))continue;if(isset($tests[$id])&&!$includeTests)continue;$w=support_workflow($c);if($view==='mine'&&$w['assigned']!==$op['operator_id'])continue;$needs=support_needs_reply($c,$w);if($tab==='unread'&&support_team_unread($c,$op)===0||$tab==='needs_reply'&&!$needs)continue;$future=$w['snoozed_until']>time();$due=support_followup_due($w);if($future&&$filter!=='snoozed')continue;if(in_array($filter,['new','awaiting_reply','resolved'],true)&&$w['status']!==$filter||$filter==='snoozed'&&!$future||$filter==='follow_up'&&!$due||$filter==='urgent'&&$w['priority']!=='urgent'||$filter==='unread'&&support_team_unread($c,$op)===0)continue;$haystack=$c['user_name'].' '.$c['user_email'].' '.implode(' ',$w['labels']);
                if($query!==''&&!str_contains(mb_strtolower($haystack),$query)&&!array_filter($c['messages'],fn($m)=>str_contains(mb_strtolower(html_entity_decode($m['edited_text']??$m['text'],ENT_QUOTES|ENT_HTML5,'UTF-8')),$query)))continue;
                $rows[]=['id'=>$id,'development_test'=>isset($tests[$id]),'name'=>$c['user_name'],'last_origin'=>support_faq_auto_message($c['messages'][array_key_last($c['messages'])]??[])?'faq_auto':null,'last'=>html_entity_decode($c['last_message']??'',ENT_QUOTES|ENT_HTML5,'UTF-8'),'time'=>$c['last_time']??$c['created_at'],'unread'=>support_team_unread($c,$op),'waiting'=>support_waiting($c),'delay_threshold'=>$operationSettings['threshold'],'needs_reply'=>$needs,'status'=>$w['status'],'assigned'=>$w['assigned'],'labels'=>$w['labels'],'priority'=>$w['priority'],'snoozed_until'=>$w['snoozed_until'],'follow_up_due'=>$due];
            }usort($rows,fn($a,$b)=>(int)($b['priority']==='urgent')<=>(int)($a['priority']==='urgent')?:((int)$b['follow_up_due']<=>(int)$a['follow_up_due'])?:strcmp($b['time'],$a['time'])?:strcmp($a['id'],$b['id']));return ['status'=>'success','conversations'=>array_slice($rows,$offset,50),'has_more'=>count($rows)>$offset+50,'next_offset'=>$offset+50,'unread_total'=>$total,'unread_sequence'=>$latest,'store_epoch'=>$s['store_epoch']];
        });support_json($r);
    }
    if($action==='detail'&&$method==='GET'){$id=support_string($_GET['user_id']??null,100);support_json(support_store(function(array &$s,bool &$changed)use($id){$c=support_conversation($s,$id,null,true);if(support_conversation_retired($c))support_failure(404,'conversation_unavailable');return ['status'=>'success','workflow'=>support_workflow($c),'source_page'=>$c['source_page']??null];}));}
    if($action==='macro_preview'&&$method==='POST'){require_once support_core_path('productivity.php');support_json(support_macro_preview($op,support_string($_POST['user_id']??null,100),support_string($_POST['reply_id']??null,32)));}
    if($action==='macro_apply'&&$method==='POST'){require_once support_core_path('productivity.php');support_json(support_macro_apply($op,support_string($_POST['plan']??null,2048)));}
    if($action==='workflow'&&$method==='POST')support_json(support_workflow_change($op,support_string($_POST['user_id']??null,100),$_POST));
    if($action==='accounts'&&$method==='GET')support_json(support_ops_store(fn(array &$s)=>['status'=>'success','accounts'=>array_map(fn($a)=>$op['operator_role']==='administrator'?support_ops_public($a):array_intersect_key($a,array_flip(['id','name','active'])),support_ops_visible_accounts($s))]));
    if($action==='account_add'&&$method==='POST'){
        support_ops_admin($op);support_throttle('account-create:'.$op['operator_id'],20,3600);$a=support_ops_account(support_string($_POST['username']??null,80),support_profile_name($_POST['name']??null),support_ops_password($_POST['password']??null),support_string($_POST['role']??null,20));
        support_json(support_ops_store(function(array &$s)use($a,$op){support_ops_recheck($s,$op);foreach($s['accounts'] as $old)if(strtolower($old['username'])===strtolower($a['username']))support_failure(409,'operator_exists');$s['accounts'][$a['id']]=$a;support_ops_audit($s,$op['operator_id'],'account_added');return ['status'=>'success','account'=>support_ops_public($a)];},true));
    }
    if($action==='account_delete'&&$method==='POST')support_json(support_ops_delete($op,support_profile_id($_POST['id']??null)));
    if($action==='account_revoke'&&$method==='POST'){
        support_ops_admin($op);$id=support_string($_POST['id']??null,32);support_json(support_ops_store(function(array &$s)use($id,$op){support_ops_recheck($s,$op);if(!isset($s['accounts'][$id]))support_failure(404,'operator_unavailable');$a=&$s['accounts'][$id];
            if($a['role']==='administrator'&&count(array_filter($s['accounts'],fn($x)=>$x['active']&&$x['role']==='administrator'))<2)support_failure(409,'last_administrator');
            if(isset($a['deleted_at']))support_failure(404,'operator_unavailable');support_profile_invalidate($s,$a);$a['active']=false;support_ops_audit($s,$op['operator_id'],'account_revoked');return ['status'=>'success'];},true));
    }
    if($action==='replies'&&$method==='GET')support_json(support_ops_store(fn(array &$s)=>['status'=>'success','replies'=>array_values($s['replies'])]));
    if($action==='reply_save'&&$method==='POST'){
        $id=support_string($_POST['id']??bin2hex(random_bytes(16)),32);if(!preg_match('/^[a-f0-9]{32}$/D',$id))support_failure(400,'invalid_input');$title=support_string($_POST['title']??null,100);$text=support_string($_POST['text']??null,2000);
        require_once support_core_path('productivity.php');$actions=support_macro_actions($_POST['actions']??[]);if(isset($actions['status'])&&$actions['status']!=='resolved')support_failure(400,'status_is_automatic');support_json(support_ops_store(function(array &$s)use($id,$title,$text,$op,$actions){support_ops_recheck($s,$op);$old=$s['replies'][$id]??null;if($old&&$old['author']!==$op['operator_id']&&$op['operator_role']!=='administrator')support_failure(403,'operator_permission_denied');$next=['id'=>$id,'author'=>$old['author']??$op['operator_id'],'title'=>$title,'text'=>$text,'actions'=>$actions];if($old===$next)return ['status'=>'success','replayed'=>true];$s['replies'][$id]=$next;support_ops_audit($s,$op['operator_id'],'reply_saved');return ['status'=>'success'];},true));
    }
    support_failure(400,'invalid_request');
}catch(Throwable $e){support_error_response($e);}
