<?php
declare(strict_types=1);
require_once __DIR__.'/support-loader.php';
require_once support_core_path('knowledge.php');require_once support_core_path('operator-security.php');
try{$method=$_SERVER['REQUEST_METHOD']??'';if(!in_array($method,['GET','POST'],true)||($_SERVER['CONTENT_LENGTH']??0)>1024)support_failure(405,'method_not_allowed');if($method==='POST'&&($_POST['action']??'')!=='search'||$method==='GET'&&($_GET['action']??'home')==='search')support_failure(405,'method_not_allowed');support_throttle('knowledge-public:'.($_SERVER['REMOTE_ADDR']??''),90);support_json(support_content_public(support_string(($method==='POST'?$_POST['action']:($_GET['action']??'home')),16),$method==='POST'?$_POST:$_GET));}catch(Throwable $e){support_error_response($e);}
