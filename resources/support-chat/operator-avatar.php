<?php
declare(strict_types=1);
require_once __DIR__.'/support-loader.php';
require_once support_core_path('operator-profile.php');
try{
    if(!support_multi_operator())support_failure(404,'operator_unavailable');
    if(($_SERVER['REQUEST_METHOD']??'')==='POST'){
        if(($_SERVER['CONTENT_LENGTH']??0)>3145728)support_failure(400,'invalid_input');
        $op=support_operator();support_csrf($op);support_interaction();support_json(support_profile_avatar($op,$_POST,$_FILES['image']??null));
    }
    if(($_SERVER['REQUEST_METHOD']??'')!=='GET')support_failure(405,'method_not_allowed');
    $id=support_profile_id($_GET['id']??null);$op=($_GET['scope']??'')==='operator'?support_operator():null;
    $meta=support_ops_store(function(array &$s)use($id,$op){foreach($s['accounts'] as $a){$p=$a['public_profile']??null;
        if(($p['avatar']['id']??null)===$id&&($op?($op['operator_id']===$a['id']||$op['operator_role']==='administrator'):($a['active']&&$p['enabled'])))return $p['avatar'];
    }return null;});
    if(!$meta)support_failure(404,'image_unavailable');$path=support_profile_directory().'/'.$id.'.webp';
    if(is_link($path)||!is_file($path)||filesize($path)!==$meta['bytes']||!hash_equals($meta['sha256'],hash_file('sha256',$path)))support_failure(404,'image_unavailable');
    header('Content-Type: image/webp');header('Content-Length: '.$meta['bytes']);header('Cache-Control: no-store, private');header('X-Content-Type-Options: nosniff');header('Cross-Origin-Resource-Policy: same-origin');header('Referrer-Policy: no-referrer');readfile($path);throw new \App\Support\Chat\LegacyResponse(null, http_response_code() ?: 200);
}catch(Throwable $e){support_error_response($e);}
