<?php
declare(strict_types=1);
require_once __DIR__.'/support-loader.php';
require_once support_core_path('branding.php');
try{if(($_SERVER['REQUEST_METHOD']??'')!=='POST')support_failure(405,'method_not_allowed');if(($_SERVER['CONTENT_LENGTH']??0)>3145728)support_failure(400,'invalid_input');$context=support_operator();support_ops_admin($context);support_csrf($context);support_interaction();support_throttle('brand-upload:'.support_principal($context,'operator'),10);support_json(support_brand_upload($context,support_string($_POST['kind']??null,8),support_string($_POST['operator_id']??'',32,true),support_string($_POST['upload_id']??null,36),$_POST['revision']??null,$_FILES['image']??[]));}catch(Throwable $e){support_error_response($e);}
