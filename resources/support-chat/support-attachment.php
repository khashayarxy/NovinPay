<?php
declare(strict_types=1);
require_once __DIR__.'/support-loader.php';
require_once support_core_path('messages.php');
require_once support_core_path('recovery.php');
require_once support_core_path('phone.php');
require_once support_core_path('attachments.php');
try{
    $role=$_POST['scope']??$_GET['scope']??'customer';if(!in_array($role,['customer','operator'],true))support_failure(400,'invalid_input');
    $context=$role==='operator'?support_operator():support_customer_context();
    if(($_SERVER['REQUEST_METHOD']??'')==='POST'){
        support_csrf($context);$id=support_string($_POST['user_id']??null,100);
        if(($_SERVER['CONTENT_LENGTH']??0)>6*1048576)support_failure(400,'invalid_attachment');
        support_json(support_attachment_upload($context,$role,$id,$_FILES['image']??[],support_string($_POST['upload_id']??null,36)));
    }
    support_request_method('GET');$id=support_string($_GET['id']??null,32);
    support_throttle('image-download:'.$context['auth_epoch'],90);support_throttle('image-download-ip:'.support_ip_key(),240);
    $image=support_store(function(array &$s,bool &$changed)use($id,$context,$role){
        if(!support_attachment_live($s,$id))support_failure(404,'attachment_unavailable');$r=$s['uploads'][$id];support_conversation($s,$r['conversation'],$role==='customer'?$context:null,$role==='operator');
        $file=support_attachment_path($id);if(is_link($file)||!is_file($file)||filesize($file)!==$r['bytes'])support_failure(404,'attachment_unavailable');
        // Read at most 5 MiB under the shared lock, then release it before any
        // slow HTTP output. Retention cannot race authorization/opening.
        $bytes=file_get_contents($file);if(!is_string($bytes)||hash('sha256',$bytes)!==$r['sha256'])support_failure(404,'attachment_unavailable');return $bytes;
    });
    support_response_headers();header('Content-Type: image/webp');header('Content-Security-Policy: default-src \'none\'; sandbox');header('Cross-Origin-Resource-Policy: same-origin');header('Content-Disposition: inline; filename="image.webp"');header('Content-Length: '.strlen($image));echo $image;
}catch(Throwable $e){support_error_response($e);}
