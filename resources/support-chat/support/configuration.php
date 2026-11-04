<?php
declare(strict_types=1);
ini_set('display_errors','0');ini_set('zend.exception_ignore_args','1');
// A standalone installation never loads the host application's payment configuration.
function support_standalone(): bool { return getenv('SUPPORT_CONFIG_FILE') !== false && getenv('SUPPORT_CONFIG_FILE') !== ''; }
if (support_standalone()) {
    $file=(string)getenv('SUPPORT_CONFIG_FILE');$real=realpath($file);$document=($_SERVER['DOCUMENT_ROOT']??'')!==''?realpath($_SERVER['DOCUMENT_ROOT']):false;
    if(!$real||is_link($file)||!is_file($real)||!is_readable($real)||($document&&str_starts_with(strtolower(str_replace('\\','/',$real)),strtolower(str_replace('\\','/',$document)).'/')))throw new RuntimeException('private_configuration_required');
    for($parent=dirname($real);$parent!==dirname($parent);$parent=dirname($parent))if(is_link($parent))throw new RuntimeException('private_configuration_required');
    foreach(file($real,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES) as $line){
        $line=trim($line);if($line===''||str_starts_with($line,'#')||!str_contains($line,'='))continue;
        [$key,$value]=array_map('trim',explode('=',$line,2));if(!preg_match('/^[A-Z][A-Z0-9_]*$/D',$key)||getenv($key)!==false)continue;
        if(strlen($value)>=2&&(($value[0]==='"'&&str_ends_with($value,'"'))||($value[0]==="'"&&str_ends_with($value,"'"))))$value=substr($value,1,-1);
        putenv($key.'='.$value);
    }
    $web=realpath(getenv('SUPPORT_PUBLIC_ROOT')?:__DIR__.'/..');
    if($web&&str_starts_with(strtolower(str_replace('\\','/',$real)),strtolower(str_replace('\\','/',$web)).'/'))throw new RuntimeException('private_configuration_required');
    if(!preg_match('/^[a-f0-9]{32}$/D',getenv('SUPPORT_INSTALLATION_ID')?:'')||!getenv('CHAT_DATA_DIR')||!getenv('SUPPORT_RUNTIME_DIR'))throw new RuntimeException('installation_configuration_required');
    foreach(['CHAT_DATA_DIR'=>getenv('CHAT_DATA_DIR'),'PAYMENT_DATA_DIR'=>dirname((string)getenv('CHAT_DATA_DIR')).'/unused-payment-boundary','ADMIN_USERNAME'=>'','ADMIN_PASSWORD_HASH'=>''] as $key=>$value)if(!defined($key))define($key,$value);
} else {
    throw new RuntimeException('private_configuration_required');
}

function support_installation_id(): string {return support_standalone()?(string)getenv('SUPPORT_INSTALLATION_ID'):'legacy-zizolearn';}
function support_site_name(): string { $name=getenv('SUPPORT_SITE_NAME')?:(support_standalone()?'وب‌سایت':'نوین‌پی');return mb_substr($name,0,80); }
function support_development_ui(): bool {
    if(!support_standalone()||getenv('SUPPORT_DEVELOPMENT_UI')!=='1')return false;
    if(PHP_SAPI==='cli')return true;
    $host=parse_url('http://'.($_SERVER['HTTP_HOST']??''),PHP_URL_HOST)?:'';
    return in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true)&&(str_ends_with($host,'.test')||(PHP_SAPI==='cli-server'&&getenv('SUPPORT_LOCAL_HTTP')==='1'));
}
function support_customer_v2_enabled(): bool {
    return getenv('SUPPORT_CUSTOMER_V2_ENABLED') === '1' || (support_development_ui() && getenv('SUPPORT_LOCAL_V2_ENABLED') === '1');
}
function support_development_asset_revision(): ?string {
    if(!support_development_ui())return null;
    $public=rtrim(getenv('SUPPORT_PUBLIC_ROOT')?:dirname(__DIR__),'/\\');$hash=hash_init('sha256');
    foreach(['support-widget.js','support-client/launcher.css'] as $file){$path=$public.'/'.$file;if(!is_file($path)||!is_readable($path))return null;hash_update_file($hash,$path);}
    return hash_final($hash);
}
function support_option_int(string $name,int $default,int $minimum,int $maximum): int {
    $raw=getenv($name);if($raw===false||$raw==='')return $default;
    if(!preg_match('/^[0-9]{1,12}$/D',$raw)||(int)$raw<$minimum||(int)$raw>$maximum)throw new RuntimeException('invalid_support_configuration');return (int)$raw;
}
function support_suggested_questions(): array {
    $questions=[];try{$raw=getenv('SUPPORT_SUGGESTED_QUESTIONS');$values=$raw===false||$raw===''?['راهنمای انتخاب دوره','چطور پیام تصویری بفرستم؟']:json_decode($raw,true,4,JSON_THROW_ON_ERROR);if(is_array($values)&&array_is_list($values)&&count($values)<=5)foreach($values as $q)if(is_string($q)&&strlen($q)<=180&&mb_check_encoding($q,'UTF-8')&&!preg_match('/[<>\x00-\x1f]/',$q))$questions[]=$q;}catch(Throwable $ignored){}
    return $questions;
}
function support_widget_config(): array {
    require_once __DIR__.'/context.php';
    $color=getenv('SUPPORT_WIDGET_COLOR')?:'#0866ff';if(!preg_match('/^#[a-fA-F0-9]{6}$/D',$color))$color='#0866ff';
    $logo=getenv('SUPPORT_WIDGET_LOGO')?:'';if($logo!==''&&(!preg_match('~^/(?!/)[a-zA-Z0-9_./-]+$~D',$logo)||str_contains($logo,'..')))$logo='';

    $config=['suggested_questions'=>support_suggested_questions(),'customer_theme_default'=>in_array(getenv('SUPPORT_CUSTOMER_THEME_DEFAULT'),['light','dark','system'],true)?getenv('SUPPORT_CUSTOMER_THEME_DEFAULT'):'light','operator_theme_default'=>in_array(getenv('SUPPORT_OPERATOR_THEME_DEFAULT'),['light','dark','system'],true)?getenv('SUPPORT_OPERATOR_THEME_DEFAULT'):'dark','source_paths'=>support_source_allowed(),'version'=>'0.1.0','installation'=>support_installation_id(),'local_asset_revision'=>support_development_asset_revision(),'customer_v2'=>support_customer_v2_enabled(),'local_v2'=>support_development_ui()&&getenv('SUPPORT_LOCAL_V2_ENABLED')==='1','development'=>support_development_ui(),'name'=>support_site_name(),'color'=>$color,'logo'=>$logo,
        'welcome'=>mb_substr(getenv('SUPPORT_WELCOME_MESSAGE')?:'سلام! چطور می‌توانیم کمکتان کنیم؟',0,250),
        'availability'=>mb_substr(getenv('SUPPORT_AVAILABILITY_TEXT')?:'پاسخ در همین گفتگو',0,120),
        'attachments'=>getenv('SUPPORT_ATTACHMENTS_ENABLED')==='1','typing'=>getenv('SUPPORT_TYPING_ENABLED')==='1','max_images'=>support_option_int('SUPPORT_ATTACHMENT_MAX_IMAGES',3,1,3)];
    require_once __DIR__.'/knowledge.php';require_once __DIR__.'/branding.php';$extra=support_content_presentation();$logo=$extra['managed_logo']??null;unset($extra['managed_logo']);$config=array_replace($config,$extra);if($logo!==null)$config['logo']=$logo;$config['agents']=support_public_agents();return $config;
}
