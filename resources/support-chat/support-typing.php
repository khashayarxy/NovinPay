<?php
declare(strict_types=1);
require_once __DIR__.'/support-loader.php';
require_once support_core_path('messages.php');require_once support_core_path('recovery.php');require_once support_core_path('phone.php');
try{
    if(getenv('SUPPORT_TYPING_ENABLED')!=='1')support_failure(404,'typing_disabled');
    $method=$_SERVER['REQUEST_METHOD']??'';if(!in_array($method,['GET','POST'],true))support_failure(405,'method_not_allowed');
    $role=$_POST['scope']??$_GET['scope']??'customer';if(!in_array($role,['customer','operator'],true))support_failure(400,'invalid_input');$c=$role==='operator'?support_operator():support_customer_context();
    $id=support_string($_POST['user_id']??$_GET['user_id']??null,100);if($method==='POST')support_csrf($c);
    support_store(function(array &$s,bool &$changed)use($id,$c,$role){support_conversation($s,$id,$role==='customer'?$c:null,$role==='operator');});
    require_once support_core_path('typing.php');
    support_throttle('typing:'.$method.':'.support_principal($c,$role),30);
    if($method==='POST'&&$role==='operator'&&($_POST['typing']??'')==='1')support_interaction();
    $conversation=support_store(fn(array &$s,bool &$changed)=>support_conversation($s,$id,$role==='customer'?$c:null,$role==='operator'));
    $accounts=support_multi_operator()?support_ops_store(fn(array &$s)=>$s['accounts']):[];
    $binding=$method==='POST'?support_typing_binding($c,$role):null;
    $result=support_typing_store(function(array &$rows)use($method,$c,$id,$role,$binding,$conversation,$accounts){
        if($method==='POST'){$key=hash_hmac('sha256',$id.support_principal($c,$role).$c['auth_epoch'],support_security_key());if(count($rows)>=128&&!isset($rows[$key]))support_failure(429,'typing_capacity');if(($_POST['typing']??'')==='1')$rows[$key]=['conversation'=>$id,'role'=>$role,'until'=>time()+7,'binding'=>$binding];else unset($rows[$key]);}
        $active=array_filter($rows,fn($r)=>$r['conversation']===$id&&$r['role']!==$role&&support_typing_live($r,$conversation,$accounts));return ['status'=>'success','typing'=>(bool)$active,'expires_in'=>$active?max(array_column($active,'until'))-time():0];
    },$method==='POST');support_json($result);
}catch(Throwable $e){support_error_response($e);}
