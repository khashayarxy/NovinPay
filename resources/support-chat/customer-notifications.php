<?php
declare(strict_types=1);
require_once __DIR__.'/support-loader.php';
require_once support_core_path('customer-notifications.php');require_once support_core_path('operator-profile.php');
try{
    if(($_SERVER['CONTENT_LENGTH']??0)>8192)support_failure(400,'invalid_input');
    $context=support_customer_context();$action=support_string($_POST['action']??$_GET['action']??'status',20);$method=$_SERVER['REQUEST_METHOD']??'';
    if(!in_array($method,['GET','POST'],true))support_failure(405,'method_not_allowed');
    if($method==='POST'){support_csrf($context);support_throttle('customer-push:'.support_principal($context,'customer'),12);}
    if($action==='status'&&$method==='GET')support_json(['status'=>'success','push_enabled'=>support_customer_push_enabled(),'external_delivery'=>getenv('SUPPORT_CUSTOMER_PUSH_REAL_ENABLED')==='1']);
    if($action==='hint'&&$method==='GET')support_json(support_store(function(array &$s,bool &$changed)use($context){foreach(array_reverse($s['conversations'],true) as $c)if(support_owned($c,$context)&&!support_conversation_retired($c)){$incoming=array_values(array_filter($c['messages'],fn($m)=>$m['type']==='admin'&&($m['customer_read']??true)===false));$latest=null;foreach($incoming as $m)if(!$latest||$m['id']>$latest['id'])$latest=$m;return [...support_attention_snapshot($c,$s['store_epoch']),'status'=>'success','unread'=>count($incoming),'sequence'=>$latest['id']??0,'store_epoch'=>$s['store_epoch'],'attention'=>support_attention_context($context,'customer'),...(($latest&&($_GET['preview']??'')==='1')?support_alert_preview($latest,support_profile_sender_map()[$latest['sender_principal']??'']['name']??'پشتیبانی'):[])];}return ['status'=>'success','unread'=>0];}));
    if(!support_customer_push_enabled())support_failure(404,'push_disabled');
    if($action==='key'&&$method==='GET')support_json(['status'=>'success','key'=>support_push_keys(false,'customer')['public']]);
    if($action==='subscribe'&&$method==='POST')support_json(support_customer_push_subscribe($context,support_string($_POST['user_id']??null,100),support_string($_POST['subscription']??null,4096)));
    if($action==='unsubscribe'&&$method==='POST'){support_customer_push_store(function(array &$s)use($context){foreach($s['subscriptions'] as $id=>$sub)if($sub['epoch']===$context['auth_epoch'])unset($s['subscriptions'][$id]);},true);support_json(['status'=>'success']);}
    support_failure(400,'invalid_request');
}catch(Throwable $e){support_error_response($e);}
