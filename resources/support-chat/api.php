<?php
declare(strict_types=1);
require_once __DIR__.'/support-loader.php';
require_once support_core_path('messages.php');
require_once support_core_path('recovery.php');
require_once support_core_path('retention.php');
require_once support_core_path('phone.php');
require_once support_core_path('code-recovery.php');
date_default_timezone_set('Asia/Tehran');
try {
    if(($_SERVER['CONTENT_LENGTH']??0)>16384) support_failure(400,'invalid_input');
    $method=$_SERVER['REQUEST_METHOD']??'';
    if($method==='GET'&&isset($_GET['widget_config']))support_json(support_widget_config());
    if(!in_array($method,['GET','POST'],true)) support_failure(405,'method_not_allowed');
    $scope=$_GET['scope']??($_POST['scope']??'customer');
    if(!is_string($scope) || !in_array($scope,['customer','operator'],true)) support_failure(400,'invalid_input');
    if($method==='GET'&&isset($_GET['recovery_availability']))support_json(['enabled'=>support_secret_recovery_enabled(),'phone_enabled'=>support_phone_enabled(),'phone_available'=>support_phone_enabled()&&support_sms_ready(),
        'code_issuance_enabled'=>support_code_enabled()&&support_code_exists(),'email_backup_enabled'=>support_code_email_enabled(),'email_available'=>support_code_email_ready()]);
    if($method==='GET'&&isset($_GET['get_recovery_context'])){
        if(!support_secret_recovery_enabled()&&!support_phone_enabled())support_failure(404,'recovery_unavailable');
        $base=support_session('customer',true);support_json(['status'=>'success','token'=>$base['csrf_token']]);
    }
    if($method==='GET' && isset($_GET['get_csrf'])) {
        $context=$scope==='operator'?support_operator():support_customer_context(true);
        support_json(['token'=>$context['csrf_token'],'context'=>support_public_context($context,$scope)]);
    }
    if($method==='GET' && isset($_GET['get_session'])) {
        $customer=support_customer_context();
        $result=support_store(function(array &$state,bool &$changed) use($customer) {
            foreach(array_reverse($state['conversations'],true) as $id=>$c) if(support_owned($c,$customer)&&!support_conversation_retired($c)) return ['status'=>'success','user_id'=>$id,'user_name'=>$c['user_name'],'user_email'=>$c['user_email'],'owned_count'=>count(array_filter($state['conversations'],fn($owned)=>support_owned($owned,$customer)&&!support_conversation_retired($owned))),'contact_name'=>html_entity_decode($c['user_name'],ENT_QUOTES|ENT_HTML5,'UTF-8'),'user_phone'=>$c['user_phone']??'','contact_prompt_state'=>$c['contact_prompt_state']??null,'retired'=>support_conversation_retired($c),'episode'=>(int)($c['episode']??1),'episode_started_at'=>$c['episode_started_at']??null];
            return ['status'=>'success','user_id'=>null,'draft_user_id'=>support_anonymous_id($customer)];
        });
        support_json($result+['context'=>support_public_context($customer,'customer')]);
    }
    if($method==='POST' && isset($_POST['logout_customer'])) {
        $context=support_session('customer',false);support_csrf($context);support_destroy_session('customer');support_json(['status'=>'success']);
    }
    if($method==='POST' && isset($_POST['secret_action'])) {
        if(!support_secret_recovery_enabled())support_failure(404,'recovery_unavailable');
        $base=support_session('customer',false);support_csrf($base);$action=support_string($_POST['secret_action'],16);
        if($action==='recover') {
            $access=support_recovery_authenticate($_POST['recovery_secret']??null);support_recovery_renew($base,$access);
            support_json(['status'=>'success']);
        }
        $context=support_customer_context();$id=support_string($_POST['user_id']??null,100);
        if($action==='status'){
            $active=support_store(function(array &$s,bool &$changed)use($context,$id){$c=support_conversation($s,$id,$context,false);$r=$c['secret_recovery']??support_code_credential($c);return $r&&!$r['revoked']&&!support_recovery_is_revoked($id);});
            support_json(['status'=>'success','active'=>$active]);
        }
        if($action==='create'||$action==='rotate')support_recovery_create($context,$id);
        if($action==='revoke'){support_customer_transaction($base,function()use($context,$id){support_recovery_revoke($context,$id);if(isset($context['_recovered']))support_recovery_replace_session([]);});support_json(['status'=>'success']);}
        support_failure(400,'invalid_input');
    }
    if($method==='POST'&&isset($_POST['phone_action'])) {
        if(!support_phone_enabled())support_failure(404,'phone_unavailable');
        $base=support_session('customer',false);support_csrf($base);
        support_json(support_phone_action($base,support_string($_POST['phone_action'],20),$_POST));
    }
    if($method==='POST'&&isset($_POST['recovery_code_action'])){
        $base=support_session('customer',false);support_csrf($base);
        support_json(support_code_action($base,support_string($_POST['recovery_code_action'],20),$_POST));
    }
    if($method==='POST' && isset($_POST['start_chat'])) {
        $customer=support_customer_context();support_csrf($customer);
        $name=support_text($_POST['name']??null,200);$email=support_string($_POST['email']??'',254,true);
        if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL)) support_failure(400,'invalid_input');
        // Optional unverified contact input never becomes an ownership index.
        if(($_POST['phone']??'')!=='')support_phone_normalize($_POST['phone']);
        support_throttle('legacy-start-ip:'.support_ip_key(),60);support_throttle('legacy-start-owner:'.$customer['owner_id'],10);
        $result=support_store(function(array &$state,bool &$changed) use($customer,$name,$email) {
            foreach(array_reverse($state['conversations'],true) as $id=>$c) if(support_owned($c,$customer)&&!support_conversation_retired($c)) return ['status'=>'success','user_id'=>$id,'is_new'=>false];
            $id='user_'.bin2hex(random_bytes(16)).'_'.time();$time=date('Y-m-d H:i:s');
            $state['conversations'][$id]=['user_id'=>$id,'owner_id'=>$customer['owner_id'],'ownership'=>'claimed','user_name'=>$name,'user_email'=>$email,'created_at'=>$time,'last_message'=>'','last_time'=>$time,'unread_count'=>0,'messages'=>[]];
            $changed=true;return ['status'=>'success','user_id'=>$id,'is_new'=>true];
        },true);support_json($result+['context'=>support_public_context($customer,'customer')]);
    }
    if($method==='POST' && isset($_POST['operator_activity'])) {
        $context=support_operator();support_csrf($context);support_throttle('operator-heartbeat-account:'.support_principal($context,'operator'),30,60);support_throttle('operator-heartbeat:'.$context['auth_epoch'],2,60);support_interaction();support_json(['status'=>'success']);
    }
    if($method==='POST'&&($_POST['action']??'')==='faq_answer'){
        $customer=support_customer_context();support_csrf($customer);support_throttle('faq-request:'.support_principal($customer,'customer'),120);require_once support_core_path('knowledge.php');
        support_json(support_faq_answer($customer,support_string($_POST['user_id']??null,100),support_string($_POST['article_id']??null,32)));
    }
    if($method==='POST'&&isset($_POST['update_contact'])){
        $customer=support_customer_context();support_csrf($customer);$id=support_string($_POST['user_id']??null,100);
        $prompt=$_POST['contact_prompt']??null;if($prompt!==null&&!in_array($prompt,['saved','skipped'],true))support_failure(400,'invalid_input');support_throttle('contact:'.$customer['owner_id'],20,3600);
        $name=support_string($_POST['name']??'',200,true);$email=support_string($_POST['email']??'',254,true);$phone=support_string($_POST['phone']??'',30,true);
        if($prompt!=='skipped'){require_once support_core_path('contact.php');[$name,$phone]=support_contact_fields($name,$phone);if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))support_failure(400,'invalid_input');}
        support_store(function(array &$s,bool &$changed)use($customer,$id,$name,$email,$phone,$prompt){$conversation=support_conversation($s,$id,$customer,false);if(support_conversation_retired($conversation))support_failure(409,'conversation_retired');if($prompt==='skipped'){$s['conversations'][$id]['last_activity_at']=time();$s['conversations'][$id]['contact_prompt_state']='skipped';$changed=true;return;}$s['conversations'][$id]['last_activity_at']=time();if($prompt!==null)$s['conversations'][$id]['contact_prompt_state']=$prompt;$s['conversations'][$id]['user_name']=$name===''?'مهمان':support_text($name,200);$s['conversations'][$id]['user_email']=$email;$s['conversations'][$id]['user_phone']=$phone===''?'':support_phone_normalize($phone);$changed=true;},true);
        support_json(['status'=>'success']);
    }
    if($method==='POST' && (isset($_POST['send_message']) || (($_POST['action']??null)==='send_reply'))) {
        $role=isset($_POST['send_message'])?'customer':'operator';
        $context=$role==='customer'?support_customer_context():support_operator();support_csrf($context);
        $id=support_string($_POST['user_id']??null,100);
        // Authorization precedes protocol/UUID lookup, including legacy clients.
        $newAnonymous=support_store(function(array &$s,bool &$changed) use($context,$role,$id) {
            if($role==='customer'&&!isset($s['conversations'][$id])&&!isset($context['_phone'])&&!isset($context['_recovered'])&&hash_equals(support_anonymous_id($context),$id))return true;
            support_conversation($s,$id,$role==='customer'?$context:null,$role==='operator');
            return false;
        });
        if($newAnonymous)support_throttle('anonymous-new:'.support_ip_key(),20);
        support_protocol();$uuid=support_string($_POST['client_message_id']??null,36);
        $attachments=support_attachment_ids($_POST['attachments']??null);
        $raw=support_string($_POST[$role==='customer'?'message':'reply']??null,2000,(bool)$attachments);
        support_throttle('send-request:'.support_principal($context,$role),120);if($role==='customer')support_throttle('send-network:'.support_ip_key(),180);
        if($role==='operator') support_interaction();
        $result=support_accept_message($context,$role,$id,$uuid,$raw,$attachments,support_reply_id($_POST['reply_to']??null),$_POST['source_path']??null,$_POST['faq_article']??null,isset($_POST['team_through'])?support_cursor($_POST['team_through']):null);
        // Durable ACK first. Optional transports are handled by the private notification queue.
        support_json($result);
    }
    if($method==='GET' && (isset($_GET['get_conversation']) || isset($_GET['receipts']))) {
        $id=support_string($_GET['user_id']??null,100);$operator=$scope==='operator'?support_operator():null;
        $context=$operator??support_customer_context();$role=$operator?'operator':'customer';
        $sender=support_principal($context,$role);
        if(!$operator&&!isset($context['_phone'])&&!isset($context['_recovered'])&&hash_equals(support_anonymous_id($context),$id)){
            $empty=support_store(function(array &$s,bool &$changed)use($id,$context){if(isset($s['conversations'][$id]))return null;
                if(isset($_GET['receipts']))return ['status'=>'success','protocol_version'=>2,'conversation_id'=>$id,'receipts'=>[]];
                return support_message_page($s,support_draft_conversation($id,$context),$_GET);});
            if($empty!==null){support_throttle('sync:'.$sender,30);support_json($empty);}
        }
        $result=support_retention_pinned_read($id,fn()=>support_store(function(array &$state,bool &$changed) use($id,$context,$operator,$sender) {
            $c=support_conversation($state,$id,$operator?null:$context,$operator!==null);
            if(isset($_GET['receipts'])) {
                $ids=$_GET['client_message_ids']??null;if(!is_array($ids) || count($ids)>50 || array_filter($ids,fn($x)=>!support_valid_uuid($x))) support_failure(400,'invalid_message_id');
                $out=[];foreach(array_merge($c['messages'],$c['retained_receipts']??[]) as $m) if(($m['sender_principal']??null)===$sender && in_array($m['client_message_id']??null,$ids,true)) $out[]=support_message_ack($m,$id,true);
                return ['status'=>'success','protocol_version'=>2,'conversation_id'=>$id,'receipts'=>$out];
            }
            return support_message_page($state,$c,$_GET,$operator===null,$operator,$operator===null?$context:null);
        }));
        support_throttle('sync:'.$sender,$operator!==null?120:30);support_json($result);
    }
    if($method==='POST'&&isset($_POST['lifecycle'])){
        $customer=support_customer_context();support_csrf($customer);
        support_json(support_customer_lifecycle($customer,support_string($_POST['user_id']??null,100),support_string($_POST['lifecycle'],16)));
    }
    if($method==='POST'&&($_POST['action']??'')==='satisfaction'){
        if($scope!=='customer')support_failure(403,'forbidden');$context=support_customer_context();support_csrf($context);support_throttle('rating:'.support_principal($context,'customer'),20);
        support_json(support_satisfaction_vote($context,support_string($_POST['user_id']??null,100),support_string($_POST['event']??null,32),support_string($_POST['value']??null,16),support_string($_POST['reason']??'',32,true),support_string($_POST['comment']??'',600,true)));
    }
    if($method==='POST'&&isset($_POST['edit_message'])) {
        if(array_diff(array_keys($_POST),['edit_message','protocol_version','scope','user_id','message_id','revision','edit_request_id','text','csrf_token']))support_failure(400,'invalid_input');
        support_protocol();$actor=$scope==='operator'?support_operator():support_customer_context();support_csrf($actor);
        support_json(support_edit_message($actor,$scope,support_string($_POST['user_id']??null,100),support_reply_id($_POST['message_id']??null),support_cursor($_POST['revision']??null),support_string($_POST['edit_request_id']??null,36),support_string($_POST['text']??null,2000)));
    }
    if($method==='POST'&&isset($_POST['read_visible'])) {
        $role=$scope;$context=$role==='operator'?support_operator():support_customer_context();support_csrf($context);
        $id=support_string($_POST['user_id']??null,100);$ids=json_decode(support_string($_POST['message_ids']??null,4096),true,4,JSON_THROW_ON_ERROR);
        if(!is_array($ids)||!array_is_list($ids)||count($ids)>100||array_filter($ids,fn($n)=>!is_int($n)||$n<1||$n>9007199254740991))support_failure(400,'invalid_input');
        support_throttle('visible-read:'.support_principal($context,$role),60);
        $result=support_store(function(array &$s,bool &$changed)use($context,$role,$id,$ids){
            support_conversation($s,$id,$role==='customer'?$context:null,$role==='operator');$c=&$s['conversations'][$id];
            $accepted=[];
            if($role==='operator'){
                $beforeReads=$c['operator_reads']??null;$accepted=support_operator_read_visible($c,$context,$ids);if($beforeReads!==$c['operator_reads'])$changed=true;
                // Visibility is explicit: a tail-page read must not consume unseen history.
                foreach($c['messages'] as &$m){
                    if($m['type']==='user'&&in_array($m['id'],$ids,true)){
                        if(($m['read']??false)===false){$m['read']=true;$m['read_visible']=true;$changed=true;}
                    }
                }unset($m);
                $c['unread_count']=support_team_unread($c);
            }else{
                foreach($c['messages'] as &$m)if(in_array($m['id'],$ids,true)){
                    if($m['type']==='admin'){
                        if(($m['customer_read']??true)===false){$m['customer_read']=true;$m['read_visible']=true;$changed=true;}
                        if(($m['read_visible']??false)===true)$accepted[]=$m['id'];
                    }
                }unset($m);
            }
            if($changed)$c['attention_revision']=($c['attention_revision']??0)+1;
            return ['status'=>'success','read_message_ids'=>$accepted,...support_attention_snapshot($c,$s['store_epoch'],$role==='operator'?$context:null)];
        },true);support_json($result);
    }
    if($method==='GET' && isset($_GET['get_users'])) {
        $context=support_operator();support_throttle('list:'.support_principal($context,'operator'),12);
        $offset=support_cursor($_GET['offset']??null);$limit=support_cursor($_GET['limit']??null,50);if($limit<1 || $limit>50) support_failure(400,'invalid_limit');
        $users=support_store(function(array &$s,bool &$changed) use($offset,$limit,$context) {
            $list=[];foreach($s['conversations'] as $id=>$c){if(support_conversation_retired($c))continue;$list[]=['user_id'=>$id,'user_name'=>$c['user_name'],'user_email'=>$c['user_email'],'last_message'=>$c['last_message']??'','last_time'=>$c['last_time']??$c['created_at'],'unread_count'=>support_operator_unread($c,$context),'ownership'=>$c['ownership']];}
            usort($list,fn($a,$b)=>strcmp($b['last_time'],$a['last_time'])?:strcmp($a['user_id'],$b['user_id']));
            return ['status'=>'success','users'=>array_slice($list,$offset,$limit),'next_offset'=>$offset+$limit,'has_more'=>count($list)>$offset+$limit];
        });support_json($users);
    }
    if($method==='POST' && isset($_POST['action'])) {
        $operator=support_operator();support_csrf($operator);$action=support_string($_POST['action'],32);$id=support_string($_POST['user_id']??null,100);
        if($action!=='mark_read') support_failure(400,'invalid_input');
        support_store(function(array &$s,bool &$changed) use($id,$operator) {
            support_conversation($s,$id,null,true);
            support_operator_read_visible($s['conversations'][$id],$operator,array_column($s['conversations'][$id]['messages'],'id'));
            // Internal unread count only; no client-facing delivery/read receipt is claimed.
            foreach($s['conversations'][$id]['messages'] as &$m) if($m['type']==='user') $m['read']=true;
            unset($m);$s['conversations'][$id]['unread_count']=0;$changed=true;
        },true);support_json(['status'=>'success']);
    }
    support_failure(400,'invalid_request');
}catch(Throwable $e){support_error_response($e);}
