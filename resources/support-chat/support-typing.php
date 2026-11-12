<?php
declare(strict_types=1);
require_once __DIR__.'/support-loader.php';
require_once support_core_path('messages.php');require_once support_core_path('recovery.php');require_once support_core_path('phone.php');
try{
    if(getenv('SUPPORT_TYPING_ENABLED')!=='1')support_failure(404,'typing_disabled');
    $method=$_SERVER['REQUEST_METHOD']??'';if(!in_array($method,['GET','POST'],true))support_failure(405,'method_not_allowed');
    $role=$_POST['scope']??$_GET['scope']??'customer';if(!in_array($role,['customer','operator'],true))support_failure(400,'invalid_input');$c=$role==='operator'?support_operator():support_customer_context();
    $id=support_string($_POST['user_id']??$_GET['user_id']??null,100);if($method==='POST')support_csrf($c);
    require_once support_core_path('typing.php');
    support_throttle('typing:'.$method.':'.support_principal($c,$role),30);
    $conversation=support_store(fn(array &$s,bool &$changed)=>support_conversation($s,$id,$role==='customer'?$c:null,$role==='operator'));
    $inactive=support_conversation_retired($conversation)||($conversation['workflow']['status']??'')==='resolved';
    $age=$_POST['idle_seconds']??'0';
    if($method==='POST'&&(!is_string($age)||!preg_match('/^\d{1,2}$/D',$age)))support_failure(400,'invalid_input');
    $publish=$method==='POST'&&!$inactive&&($_POST['typing']??'')==='1'&&(int)$age<SUPPORT_TYPING_TTL;
    if($publish&&$role==='operator')support_interaction();
    $accounts=$method==='GET'&&!$inactive&&$role==='customer'&&support_multi_operator()?support_ops_store(fn(array &$s)=>$s['accounts']):[];
    $binding=$publish?support_typing_binding($c,$role):null;
    $result=support_typing_store(function(array &$rows)use($method,$c,$id,$role,$binding,$conversation,$accounts,$inactive,$publish,$age){
        if($method==='POST'){
            $key=hash_hmac('sha256',$id.support_principal($c,$role).$c['auth_epoch'],support_security_key());
            if($publish){
                if(count($rows)>=128&&!isset($rows[$key]))support_failure(429,'typing_capacity');
                $rows[$key]=['conversation'=>$id,'role'=>$role,'until'=>time()+SUPPORT_TYPING_TTL-(int)$age,'binding'=>$binding,'episode'=>$conversation['episode']??1,'reopen_count'=>$conversation['workflow']['reopen_count']??0];
            }else unset($rows[$key]);
            return ['status'=>'success'];
        }
        if($inactive)return ['status'=>'success','typing'=>false,'expires_in'=>0];
        $active=array_filter($rows,fn($r)=>$r['conversation']===$id&&$r['role']!==$role&&support_typing_live($r,$conversation,$accounts));return ['status'=>'success','typing'=>(bool)$active,'expires_in'=>$active?max(array_column($active,'until'))-time():0];
    },$method==='POST');support_json($result);
}catch(Throwable $e){support_error_response($e);}
