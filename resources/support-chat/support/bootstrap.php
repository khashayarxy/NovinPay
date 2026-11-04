<?php
declare(strict_types=1);
require_once __DIR__ . '/configuration.php';
ini_set('display_errors','0');

final class SupportFailure extends RuntimeException {
    public function __construct(public readonly int $status, public readonly string $reason) { parent::__construct($reason); }
}
function support_failure(int $status, string $reason): never { throw new SupportFailure($status, $reason); }
function support_valid_uuid(mixed $value): bool {
    return is_string($value) && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',$value)===1;
}
function support_uuid4(): string {
    $b=random_bytes(16);$b[6]=chr((ord($b[6])&0x0f)|0x40);$b[8]=chr((ord($b[8])&0x3f)|0x80);$h=bin2hex($b);
    return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20,12);
}
function support_response_headers(): void {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
}
function support_json(array $data, int $status=200): never {
    http_response_code($status);support_response_headers();
    throw new \App\Support\Chat\LegacyResponse($data, $status);
}
function support_error_response(Throwable $error): never {
    if ($error instanceof \App\Support\Chat\LegacyResponse) throw $error;
    $status=$error instanceof SupportFailure ? $error->status : 503;
    if($status>=500){try{require_once __DIR__.'/health.php';support_health_record('http_error','failure','local',1);}catch(Throwable $ignored){}}
    $code=$error instanceof SupportFailure ? $error->reason : 'service_unavailable';
    // Do not log customer text, cookie IDs, CSRF, credentials or private paths.
    if ($status>=500) error_log('NovinPay support storage/service request failed');
    $messages=[401=>'نشست معتبر نیست یا منقضی شده است. دوباره وارد شوید.',403=>'درخواست مجاز نیست.',
        404=>'گفتگو در دسترس نیست.',400=>'اطلاعات درخواست معتبر نیست.',405=>'روش درخواست مجاز نیست.',
        409=>'اطلاعات پیام یا همگام‌سازی ناسازگار است. متن حفظ شده؛ بررسی لازم است.',
        426=>'نسخه صفحه قدیمی است؛ متن را حفظ و صفحه را تازه کنید.',
        429=>'درخواست‌های زیادی ارسال شده است. کمی بعد تلاش کنید.',503=>'ثبت اطلاعات ممکن نشد. متن را نگه دارید و دوباره تلاش کنید.'];
    if ($status===429 && !array_filter(headers_list(),fn($h)=>str_starts_with(strtolower($h),'retry-after:'))) header('Retry-After: 30');
    $editMessages=['message_edit_expired'=>'مهلت ۱۵ دقیقه‌ای ویرایش این پیام تمام شده است.','message_edit_conflict'=>'پیام در دستگاه دیگری تغییر کرده است؛ متن ویرایش شما حفظ شد.','message_edit_forbidden'=>'فقط پیام متنی خودتان با مشخصات معتبر قابل ویرایش است.','edit_idempotency_conflict'=>'این درخواست با ویرایش دیگری تداخل دارد.','message_edit_capacity'=>'ظرفیت ویرایش این گفتگو تکمیل شده است.'];
    support_json(['status'=>'error','code'=>$code,'message'=>$editMessages[$code]??$messages[$status]??'درخواست انجام نشد.'],$status);
}
function support_private_dir(string $path, bool $owned=true): string {
    if ($path==='' || (!str_starts_with($path,'/') && !preg_match('~^[A-Za-z]:[/\\\\]~',$path))) support_failure(503,'private_storage_unavailable');
    $candidate=$path;
    // Reject existing symbolic-link ancestors; never follow links into a sibling app.
    while ($candidate!==dirname($candidate)) {
        if (is_link($candidate)) support_failure(503,'private_storage_unavailable');
        $candidate=dirname($candidate);
    }
    if (!is_dir($path) && !@mkdir($path,0700,true) && !is_dir($path)) support_failure(503,'private_storage_unavailable');
    $real = realpath($path);
    $pubEnv = getenv('SUPPORT_PUBLIC_ROOT');
    if ($pubEnv !== false && $pubEnv !== '') {
        $web = realpath($pubEnv);
    } elseif (is_file(__DIR__ . '/../index.html')) {
        $web = realpath(__DIR__ . '/..');
    } elseif (is_dir(dirname(__DIR__) . '/public_html')) {
        $web = realpath(dirname(__DIR__) . '/public_html');
    } else {
        $web = realpath(__DIR__ . '/..');
    }
    if ($real===false || $web===false || str_starts_with(strtolower(str_replace('\\','/',$real)).'/',strtolower(str_replace('\\','/',$web)).'/')) support_failure(503,'private_storage_unavailable');
    $documentRoot=($_SERVER['DOCUMENT_ROOT']??'')!==''?realpath($_SERVER['DOCUMENT_ROOT']):false;
    if($documentRoot&&str_starts_with(strtolower(str_replace('\\','/',$real)).'/',strtolower(str_replace('\\','/',$documentRoot)).'/'))support_failure(503,'private_storage_unavailable');
    $payment=realpath(PAYMENT_DATA_DIR);
    if ($payment!==false && str_starts_with(strtolower(str_replace('\\','/',$real)).'/',strtolower(str_replace('\\','/',$payment)).'/')) support_failure(503,'private_storage_unavailable');
    // CHAT_DATA_DIR can be the parent of payment storage: never chmod that parent.
    if (($owned && !@chmod($real,0700)) || !is_readable($real) || !is_writable($real)) support_failure(503,'private_storage_unavailable');
    return $real;
}
function support_runtime_dir(): string {
    static $directory;
    return $directory ??= support_private_dir((getenv('SUPPORT_RUNTIME_DIR') ?: CHAT_DATA_DIR.'/support-security'));
}
function support_string(mixed $value, int $max, bool $empty=false): string {
    if (!is_string($value) || strlen($value)>$max || (!$empty && trim($value)==='') || !mb_check_encoding($value,'UTF-8')) support_failure(400,'invalid_input');
    return trim($value);
}
function support_text(mixed $value, int $max=2000): string {
    $text=htmlspecialchars(support_string($value,$max),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
    if (strlen($text)>$max) support_failure(400,'invalid_input');
    return $text;
}
function support_request_method(string $method): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '')!==$method) { header('Allow: '.$method); support_failure(405,'method_not_allowed'); }
}
function support_session_name(string $role): string {
    if (!in_array($role,['customer','operator'],true)) support_failure(403,'forbidden');
    if(support_standalone())return 'Support'.support_installation_id().ucfirst($role);
    return $role==='customer' ? 'ZizoSupportCustomer' : 'ZizoSupportOperator';
}
function support_close_session(): void {
    if(session_status()===PHP_SESSION_ACTIVE && !session_write_close()) support_failure(503,'session_unavailable');
}
function support_native_session(string $role, bool $initialize): array {
    $contexts = &$GLOBALS['novinpay_chat_contexts'];
    if (!is_array($contexts)) $contexts = [];
    if (isset($contexts[$role])) return $contexts[$role];
    $name=support_session_name($role);
    $cookie=$_COOKIE[$name] ?? null;
    if (!$initialize && $cookie===null) support_failure(401,'session_expired');
    if ($cookie!==null && (!is_string($cookie) || !preg_match('/^[a-zA-Z0-9,-]{16,128}$/D',$cookie))) support_failure(401,'session_expired');
    if (session_status()===PHP_SESSION_ACTIVE) support_close_session();
    session_id('');
    $https=(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off') || (int)($_SERVER['SERVER_PORT']??0)===443;
    $local=PHP_SAPI==='cli-server' && getenv('SUPPORT_LOCAL_HTTP')==='1' && in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true);
    if (!$https && !$local) support_failure(503,'https_required');
    $dir=support_private_dir(support_runtime_dir().'/sessions-'.$role);
    session_name($name);
    if (is_string($cookie)) session_id($cookie);
    session_save_path($dir);
    if (!@session_start(['use_strict_mode'=>1,'use_only_cookies'=>1,'use_cookies'=>1,'cookie_httponly'=>1,
        'cookie_secure'=>$https,'cookie_samesite'=>'Strict','cookie_path'=>'/','cookie_domain'=>'',
        'cookie_lifetime'=>$role==='customer'?7*86400:0,'gc_maxlifetime'=>$role==='customer'?30*86400:8*3600,'cache_limiter'=>'nocache'])) support_failure(503,'session_unavailable');
    $now=time();$idle=$role==='customer'?7*86400:1800;$absolute=$role==='customer'?30*86400:8*3600;
    $valid=isset($_SESSION['role'],$_SESSION['issued_at'],$_SESSION['last_seen'],$_SESSION['csrf_token'])
        && $_SESSION['role']===$role && is_int($_SESSION['issued_at']) && is_int($_SESSION['last_seen'])
        && $now-($role==='operator'?($_SESSION['interactive_at']??$_SESSION['last_seen']):$_SESSION['last_seen'])<=$idle && $now-$_SESSION['issued_at']<=$absolute;
    if (!$valid) {
        $wasAuthenticated=($_SESSION['authenticated']??false)===true;
        $_SESSION=[];
        if (!session_regenerate_id(true)) support_failure(503,'session_unavailable');
        if($role==='operator' && $wasAuthenticated) support_security_audit('session_expired');
        if (!$initialize) { support_close_session(); support_failure(401,'session_expired'); }
        $_SESSION=['role'=>$role,'issued_at'=>$now,'last_seen'=>$now,'csrf_token'=>bin2hex(random_bytes(32))];
        if ($role==='customer') $_SESSION['owner_id']=bin2hex(random_bytes(32));
        else $_SESSION['interactive_at']=$now;
    }
    if ($role==='customer' && (!is_string($_SESSION['owner_id']??null) || !preg_match('/^[a-f0-9]{64}$/D',$_SESSION['owner_id']))) { support_close_session(); support_failure(401,'session_expired'); }
    if(!isset($_SESSION['auth_epoch'])) $_SESSION['auth_epoch']=bin2hex(random_bytes(16));
    if($role==='customer') $_SESSION['last_seen']=$now;
    $context=$_SESSION;
    support_close_session();
    return $contexts[$role]=$context;
}
function support_session(string $role, bool $initialize): array {
    $context = support_native_session($role, $initialize);
    return app(\App\Support\Chat\ChatBridge::class)->session($role, $context);
}
function support_operator(bool $required=true): ?array {
    if (!isset($_COOKIE[support_session_name('operator')]) && !auth()->check()) {
        if ($required) support_failure(401,'session_expired');
        return null;
    }
    try { $context=support_session('operator',false); }
    catch (SupportFailure $e) { if (!$required && $e->status===401) return null; throw $e; }
    require_once __DIR__.'/operators.php';
    if(support_multi_operator()){
        $operator=($context['authenticated']??false)===true?support_ops_session($context):null;
        if(!$operator&&$required)support_failure(401,'session_expired');return $operator;
    }
    if (($context['authenticated']??false)!==true || !is_string($context['credential_version']??null)
        || !hash_equals(hash('sha256',ADMIN_USERNAME."\0".ADMIN_PASSWORD_HASH),$context['credential_version'])) {
        if ($required) support_failure(401,'session_expired');
        return null;
    }
    return $context;
}
function support_csrf(array $context): void {
    $value=$_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? null);
    if (!is_string($value) || strlen($value)!==64 || !is_string($context['csrf_token']??null) || !hash_equals($context['csrf_token'],$value)) support_failure(403,'csrf_invalid');
}
function support_change_operator(callable $change): void {
    // The login controller has already opened and validated this operator session.
    // Keep its newly issued ID instead of reloading an unissued browser cookie.
    if (session_name()!==support_session_name('operator') || session_id()==='') support_failure(503,'session_unavailable');
    if (!session_start()) support_failure(503,'session_unavailable');
    $change();
    support_close_session();
}
function support_destroy_session(string $role): void {
    $destroyContext=support_session($role,false);
    if($role==='operator'&&isset($destroyContext['device_subscription'],$destroyContext['operator_id'])){
        require_once __DIR__.'/operators.php';support_ops_store(function(array &$s)use($destroyContext){$id=$destroyContext['device_subscription'];if(($s['subscriptions'][$id]['operator']??null)===$destroyContext['operator_id'])unset($s['subscriptions'][$id]);},true);
    }
    if($role==='customer'&&is_file(support_runtime_dir().'/customer-notifications/initialized')){
        require_once __DIR__.'/customer-notifications.php';support_customer_push_store(function(array &$s)use($destroyContext){foreach($s['subscriptions'] as $id=>$sub)if($sub['epoch']===$destroyContext['auth_epoch'])unset($s['subscriptions'][$id]);},true);
    }
    if (!session_start()) support_failure(503,'session_unavailable');
    $_SESSION=[];
    $params=session_get_cookie_params();
    setcookie(session_name(),'', ['expires'=>time()-3600,'path'=>'/','secure'=>$params['secure'],'httponly'=>true,'samesite'=>'Strict']);
    if (!session_destroy()) support_failure(503,'session_unavailable');
    if ($role === 'operator') app(\App\Support\Chat\ChatBridge::class)->endOperator();
}
function support_interaction(): void {
    app(\App\Support\Chat\ChatBridge::class)->touchOperator();
    $context=null;
    support_change_operator(function()use(&$context) {
        $now=time();
        if($now-(int)($_SESSION['interactive_at']??0)>=60) $_SESSION['interactive_at']=$now;
        $context=$_SESSION;
    });
    // Availability failure must never prevent a durable message ACK.
    try{require_once __DIR__.'/presence.php';support_presence_record($context,session_id());}catch(Throwable $ignored){}
}
function support_security_audit(string $event): void {
    require_once __DIR__.'/operator-security.php';
    support_audit($event);
}
