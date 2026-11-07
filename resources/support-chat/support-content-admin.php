<?php
declare(strict_types=1);
require_once __DIR__.'/support-loader.php';
require_once support_core_path('operators.php');require_once support_core_path('knowledge.php');
try{if(($_SERVER['CONTENT_LENGTH']??0)>65536)support_failure(400,'invalid_input');$method=$_SERVER['REQUEST_METHOD']??'';if(!in_array($method,['GET','POST'],true))support_failure(405,'method_not_allowed');$context=support_operator();support_ops_admin($context);if($method==='POST'){support_csrf($context);support_interaction();support_throttle('knowledge-admin:'.support_principal($context,'operator'),60);}support_json(support_content_admin($context,$method==='GET'?'inspect':support_string($_POST['action']??null,32),$_POST));}catch(Throwable $e){support_error_response($e);}
