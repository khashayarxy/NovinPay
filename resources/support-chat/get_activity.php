<?php
declare(strict_types=1);
require_once __DIR__.'/support-loader.php';
require_once support_core_path('storage.php');
require_once support_core_path('presence.php');
try {
    support_request_method('GET');
    $scope=$_GET['scope']??'customer';
    if(!is_string($scope) || !in_array($scope,['customer','operator'],true)) support_failure(400,'invalid_input');
    $operator=$scope==='operator'?support_operator():null;
    $result=support_store(function(array &$state,bool &$changed) use($operator) {
        $presence=$state['presence']??['operator_last_seen'=>0,'users'=>[]];
        if(!is_array($presence) || !is_int($presence['operator_last_seen']??null) || !is_array($presence['users']??null)) support_failure(503,'storage_invalid');
        $online=support_presence_online();
        $out=['admin_online'=>$online,'admin_last_seen'=>null,'admin_last_seen_text'=>$online?'آنلاین':'آفلاین','users'=>[]];
        if($operator) {
            foreach($presence['users'] as $id=>$last) {
                if(!support_valid_id($id) || !is_int($last)) support_failure(503,'storage_invalid');
                if(isset($state['conversations'][$id])) $out['users'][$id]=['online'=>time()-$last<120,'last_seen'=>$last];
            }
        }
        return $out;
    });
    support_json($result);
} catch(Throwable $e) { support_error_response($e); }
