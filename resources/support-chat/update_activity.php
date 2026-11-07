<?php
declare(strict_types=1);
require_once __DIR__.'/support-loader.php';
require_once support_core_path('operator-security.php');
require_once support_core_path('recovery.php');
try {
    support_request_method('POST');
    $scope=$_POST['scope']??'customer';
    if(!is_string($scope) || !in_array($scope,['customer','operator'],true)) support_failure(400,'invalid_input');
    $context=$scope==='operator'?support_operator():support_customer_context();
    support_csrf($context);
    // Legacy clients poll this endpoint; a passive compatibility request is not activity.
    if($scope==='operator'){support_throttle('operator-presence-poll:'.support_principal($context,'operator'),2,45);support_json(['status'=>'success']);}
    $supplied=array_key_exists('user_id',$_POST)?support_string($_POST['user_id'],100):null;
    $result=support_store(function(array &$state,bool &$changed) use($scope,$context,$supplied) {
        $presence=$state['presence']??['operator_last_seen'=>0,'users'=>[]];
        if(!is_array($presence) || !is_int($presence['operator_last_seen']??null) || !is_array($presence['users']??null)) support_failure(503,'storage_invalid');
        if($scope==='operator') $presence['operator_last_seen']=time();
        else {
            $id=$supplied;
            if($id===null) foreach($state['conversations'] as $key=>$c) if(support_owned($c,$context)) {$id=$key;break;}
            if($id===null) support_failure(404,'conversation_unavailable');
            support_conversation($state,$id,$context,false);$presence['users'][$id]=time();
        }
        foreach($presence['users'] as $id=>$last) {
            if(!support_valid_id($id) || !is_int($last)) support_failure(503,'storage_invalid');
            if(time()-$last>3600) unset($presence['users'][$id]);
        }
        support_throttle('presence:'.support_principal($context,$scope),1,45);
        $state['presence']=$presence;$changed=true;return ['status'=>'success'];
    },true);
    support_json($result);
} catch(Throwable $e) { support_error_response($e); }
