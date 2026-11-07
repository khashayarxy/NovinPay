<?php
declare(strict_types=1);
require_once __DIR__.'/operators.php';require_once __DIR__.'/code-recovery.php';require_once __DIR__.'/sms.php';
function support_alert_test(): bool {return PHP_SAPI==='cli'&&getenv('SUPPORT_OPERATOR_NOTIFICATION_TEST_MODE')==='1';}
function support_alert_quiet(array $p): bool {
    if(($p['quiet']??false)===true)return true;
    $start=$p['quiet_start']??support_option_int('SUPPORT_OPERATOR_QUIET_START',22,0,23);$end=$p['quiet_end']??support_option_int('SUPPORT_OPERATOR_QUIET_END',8,0,23);
    $hour=(int)(new DateTimeImmutable('now',new DateTimeZone(getenv('SUPPORT_TIMEZONE')?:'Asia/Tehran')))->format('G');
    return $start===$end?false:($start<$end?($hour>=$start&&$hour<$end):($hour>=$start||$hour<$end));
}
// Fresh authorized hint only. Never persisted to cursors, caches, or audit events.
function support_alert_preview(array $message,string $sender): array {
    $clean=static fn(string $v,int $limit): string=>mb_substr(trim((string)preg_replace('/[\x00-\x1f\x7f\x{202a}-\x{202e}\x{2066}-\x{2069}]/u','',strip_tags(html_entity_decode($v,ENT_QUOTES|ENT_HTML5,'UTF-8')))),0,$limit,'UTF-8');
    return ['preview'=>$clean((string)($message['text']??''),180)?:'تصویر پیوست','sender'=>$clean($sender,80)?:'پشتیبانی'];
}
function support_push_endpoint(string $endpoint): array {
    if(strlen($endpoint)>2048||preg_match('/[\x00-\x20\x7f]/',$endpoint))support_failure(400,'invalid_subscription');$u=parse_url($endpoint);
    if(!$u||($u['scheme']??'')!=='https'||isset($u['user'],$u['pass'])||isset($u['user'])||isset($u['pass'])||isset($u['fragment'])||(isset($u['port'])&&$u['port']!==443)||!in_array($u['host']??'',['fcm.googleapis.com','updates.push.services.mozilla.com','web.push.apple.com'],true)||!str_starts_with($u['path']??'','/'))support_failure(400,'invalid_subscription');return $u;
}
function support_alert_dir(): string {return support_private_dir(support_runtime_dir().'/operator-notifications');}
function support_alert_store(callable $op,bool $write=false,bool $initialize=false): mixed {
    if($initialize&&PHP_SAPI!=='cli')support_failure(403,'forbidden');$dir=support_alert_dir();$path=$dir.'/authority.json';$marker=$dir.'/initialized';$lp=$dir.'/authority.lock';
    foreach([$path,$marker,$lp] as $f)if(is_link($f)||(file_exists($f)&&!is_file($f)))support_failure(503,'alert_state_invalid');
    $lock=@fopen($lp,'c+b');if(!$lock||!flock($lock,LOCK_EX))support_failure(503,'alert_unavailable');
    try{
        if(!$initialize&&(!is_file($path)||!is_file($marker)))support_failure(503,'alert_not_initialized');if($initialize&&!is_file($path)&&is_file($marker))support_failure(503,'alert_authority_missing');
        $s=['version'=>1,'jobs'=>[],'budgets'=>[]];if(is_file($path)){if(filesize($path)>2097152)support_failure(503,'alert_capacity');$s=json_decode((string)file_get_contents($path),true,32,JSON_THROW_ON_ERROR);}
        if(!is_array($s)||($s['version']??null)!==1||!is_array($s['jobs']??null)||!is_array($s['budgets']??null)||array_diff(array_keys($s),['version','jobs','budgets'])||count($s['jobs'])>512||count($s['budgets'])>512)support_failure(503,'alert_state_invalid');
        foreach($s['jobs'] as $id=>$j)if(!preg_match('/^[a-f0-9]{64}$/D',(string)$id)||!is_array($j)||!in_array($j['kind']??null,['push','sms'],true)||!preg_match('/^[a-f0-9]{32}$/D',$j['operator']??'')||!support_valid_id($j['conversation']??null)||!is_int($j['trigger']??null)||!is_int($j['created']??null)||!is_int($j['expires']??null)||!is_int($j['attempts']??null)||$j['attempts']<0||$j['attempts']>3||!is_int($j['next']??null)||!is_int($j['claimed']??null)||!in_array($j['state']??null,['pending','dispatching','accepted','failed','cancelled'],true))support_failure(503,'alert_state_invalid');
        if($write){foreach($s['jobs'] as $id=>$j){if($j['created']<time()-604800)unset($s['jobs'][$id]);elseif($j['state']==='dispatching'&&$j['claimed']<time()-60){$s['jobs'][$id]['state']='failed';$s['jobs'][$id]['result']='interrupted';}elseif($j['expires']<=time()&&$j['state']==='pending')$s['jobs'][$id]['state']='cancelled';}foreach($s['budgets'] as $id=>$b)if(($b['at']??0)<time()-86400)unset($s['budgets'][$id]);}
        $r=$op($s);if($write){$bytes=json_encode($s,JSON_THROW_ON_ERROR);if(count($s['jobs'])>512||count($s['budgets'])>512||strlen($bytes)>2097152)support_failure(503,'alert_capacity');support_atomic_replace($path,$bytes);}if($initialize&&!is_file($marker))support_atomic_replace($marker,'1');return $r;
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
function support_push_keys(bool $initialize=false,string $audience='operator'): array {
    if(!in_array($audience,['operator','customer'],true))support_failure(503,'push_unavailable');
    $dir=$audience==='customer'?support_private_dir(support_runtime_dir().'/customer-notifications'):support_alert_dir();$path=$dir.'/vapid.pem';if(is_link($path))support_failure(503,'push_unavailable');
    if(!is_file($path)){
        if(!$initialize||PHP_SAPI!=='cli'||is_file($dir.'/initialized'))support_failure(503,'push_key_missing');
        $config=__DIR__.'/openssl.cnf';$key=openssl_pkey_new(['config'=>$config,'private_key_type'=>OPENSSL_KEYTYPE_EC,'curve_name'=>'prime256v1']);if(!$key||!openssl_pkey_export($key,$pem,null,['config'=>$config]))support_failure(503,'push_unavailable');support_atomic_replace($path,$pem);
    }
    $key=openssl_pkey_get_private((string)file_get_contents($path));if(!$key)support_failure(503,'push_unavailable');$details=openssl_pkey_get_details($key);
    if(($details['ec']['curve_name']??null)!=='prime256v1')support_failure(503,'push_unavailable');return ['private'=>$key,'public'=>rtrim(strtr(base64_encode("\4".$details['ec']['x'].$details['ec']['y']),'+/','-_'),'=')];
}
function support_push_subscribe(array $context,string $raw): array {
    if(getenv('SUPPORT_OPERATOR_PUSH_ENABLED')!=='1')support_failure(404,'push_disabled');
    $value=json_decode($raw,true,8,JSON_THROW_ON_ERROR);if(!is_array($value)||!is_string($value['endpoint']??null)||!is_array($value['keys']??null))support_failure(400,'invalid_subscription');support_push_endpoint($value['endpoint']);
    foreach(['p256dh'=>65,'auth'=>16] as $key=>$bytes){$v=$value['keys'][$key]??null;if(!is_string($v)||!preg_match('/^[a-zA-Z0-9_-]+={0,2}$/D',$v)||strlen((string)base64_decode(strtr($v,'-_','+/'),true))!==$bytes)support_failure(400,'invalid_subscription');}
    $id=substr(hash_hmac('sha256','push-subscription'.$value['endpoint'],support_security_key()),0,32);$sealed=support_code_seal(['endpoint'=>$value['endpoint']],'push-'.$id);$now=time();
    support_ops_store(function(array &$s)use($context,$id,$sealed,$now){support_ops_recheck($s,$context);$existing=$s['subscriptions'][$id]??null;if($existing&&$existing['operator']!==$context['operator_id'])support_failure(409,'subscription_in_use');
        if(!$existing&&count(array_filter($s['subscriptions'],fn($x)=>$x['operator']===$context['operator_id']))>=5)support_failure(429,'subscription_capacity');
        $s['subscriptions'][$id]=['operator'=>$context['operator_id'],'sealed'=>$sealed,'expires'=>$now+30*86400,'session_expires'=>min($context['issued_at']+8*3600,($context['interactive_at']??time())+1800),'session'=>session_id()];support_ops_audit($s,$context['operator_id'],'push_subscribed');
    },true);
    support_change_operator(function()use($id){$_SESSION['device_subscription']=$id;});return ['status'=>'success'];
}
function support_alert_ready(string $kind): bool {
    $prefix=$kind==='push'?'SUPPORT_OPERATOR_PUSH':'SUPPORT_OPERATOR_SMS';if(getenv($prefix.'_ENABLED')!=='1')return false;
    if(getenv('SUPPORT_OPERATOR_NOTIFICATION_PROVIDER')==='fake')return support_alert_test();
    if(getenv($prefix.'_REAL_ENABLED')!=='1'||!function_exists('curl_exec'))return false;
    if($kind==='push')return is_file(support_alert_dir().'/vapid.pem')&&str_starts_with(getenv('SUPPORT_PUSH_CONTACT')?:'','mailto:');
    return (bool)getenv('SUPPORT_OPERATOR_SMS_RECIPIENTS_FILE')&&preg_match('/^[a-zA-Z0-9_-]{16,256}$/D',getenv('SMSIR_API_KEY')?:'')===1&&preg_match('/^[1-9][0-9]{0,8}$/D',getenv('SUPPORT_OPERATOR_SMS_TEMPLATE_ID')?:'')===1;
}
function support_alert_plan(): array {
    $ops=support_ops_store(function(array &$s){return $s;});
    $pending=support_store(function(array &$s,bool &$changed)use($ops){$r=[];foreach($s['conversations'] as $id=>$c){$w=support_workflow($c);if(support_needs_reply($c,$w)){$unread=[];foreach($ops['accounts'] as $oid=>$account)$unread[$oid]=support_team_unread($c,['operator_id'=>$oid]);$r[]=['conversation'=>$id,'trigger'=>$w['last_customer'],'at'=>$w['customer_at'],'unread'=>$unread];}}return $r;});
    return support_alert_store(function(array &$s)use($pending,$ops){$added=0;$deferred=0;$now=time();
        foreach($s['jobs'] as &$job)if($job['kind']==='push'&&$job['state']==='pending')foreach($pending as $c)if($c['conversation']===$job['conversation']&&($c['trigger']>$job['trigger']||($c['unread'][$job['operator']]??0)===0))$job['state']='cancelled';unset($job);
        foreach($pending as $conversation)foreach($ops['accounts'] as $oid=>$account){if(!$account['active'])continue;$prefs=$ops['preferences'][$oid]??[];if(support_alert_quiet($prefs))continue;
            $targets=[];
            if(($conversation['unread'][$oid]??0)>0&&getenv('SUPPORT_OPERATOR_PUSH_ENABLED')==='1')foreach($ops['subscriptions'] as $sid=>$sub)if($sub['operator']===$oid&&$sub['expires']>$now&&$sub['session_expires']>$now&&(!isset($sub['session'])||is_file(support_runtime_dir().'/sessions-operator/sess_'.$sub['session'])))$targets[]=['push',$sid];
            if(getenv('SUPPORT_OPERATOR_SMS_ENABLED')==='1'&&($prefs['sms']??false)&&$conversation['at']<=time()-support_option_int('SUPPORT_OPERATOR_SMS_DELAY',300,0,86400))$targets[]=['sms',null];
            foreach($targets as [$kind,$sid]){
                $material=$kind==='push'?$conversation['conversation'].$conversation['trigger'].$sid:$oid.gmdate('Y-m-d-H');$key=hash('sha256',$kind.$oid.$material);if(isset($s['jobs'][$key]))continue;
                if(count($s['jobs'])>=512){$deferred++;continue;}
                $s['jobs'][$key]=['kind'=>$kind,'operator'=>$oid,'subscription'=>$sid,'conversation'=>$conversation['conversation'],'trigger'=>$conversation['trigger'],'created'=>$now,'expires'=>$now+86400,'attempts'=>0,'next'=>0,'claimed'=>0,'state'=>'pending','result'=>''];$added++;
            }
        }return ['added'=>$added,'deferred'=>$deferred];
    },true);
}
function support_alert_claim(): ?array {
    // Snapshot lock order: chat -> operators -> notification authority. No network here.
    return support_store(function(array &$chat,bool &$changed){return support_ops_store(function(array &$ops)use($chat){return support_alert_store(function(array &$s)use($chat,$ops){
        foreach($s['jobs'] as $id=>&$j){if($j['state']!=='pending'||$j['next']>time()||!support_alert_ready($j['kind']))continue;$account=$ops['accounts'][$j['operator']]??null;$conversation=$chat['conversations'][$j['conversation']]??null;$prefs=$ops['preferences'][$j['operator']]??[];
            if(!$account||!$account['active']||!$conversation){$j['state']='cancelled';continue;}$w=support_workflow($conversation);
            if($w['status']==='resolved'||support_team_answered_through($conversation)>=$j['trigger']){$j['state']='cancelled';continue;}if(support_alert_quiet($prefs))continue;
            $payload=[];
            if($j['kind']==='push'){if(support_team_unread($conversation,['operator_id'=>$j['operator']])===0||$w['last_customer']!==$j['trigger']){$j['state']='cancelled';continue;}$sub=$ops['subscriptions'][$j['subscription']]??null;if(!$sub||$sub['operator']!==$j['operator']||$sub['expires']<=time()||$sub['session_expires']<=time()||(isset($sub['session'])&&!is_file(support_runtime_dir().'/sessions-operator/sess_'.$sub['session']))){$j['state']='cancelled';continue;}$payload=support_code_open($sub['sealed'],'push-'.$j['subscription']);}
            elseif(!($prefs['sms']??false)){$j['state']='cancelled';continue;}
            $limit=$j['kind']==='sms'?support_option_int('SUPPORT_OPERATOR_SMS_DAILY_BUDGET',10,0,100):100;$per=$j['kind']==='sms'?3:30;
            $bucket=$j['kind'].'-'.gmdate('Y-m-d');$global=$s['budgets'][$bucket]['count']??0;$user=$s['budgets'][$bucket.'-'.$j['operator']]['count']??0;if($global>=$limit||$user>=$per)continue;
            foreach([$bucket,$bucket.'-'.$j['operator']] as $key)$s['budgets'][$key]=['at'=>time(),'count'=>($s['budgets'][$key]['count']??0)+1];
            $j['attempts']++;$j['claimed']=time();$j['state']='dispatching';return ['id'=>$id,'job'=>$j,'payload'=>$payload];
        }return null;
    },true);});});
}
interface SupportOperatorAlertTransport {public function send(string $kind,array $job,array $payload): string;}
final class SupportFakeOperatorAlerts implements SupportOperatorAlertTransport {
    public function __construct(private string $outcome='accepted'){}
    public function send(string $kind,array $job,array $payload): string {if(!support_alert_test()||!in_array($this->outcome,['accepted','rejected','retry','gone','timeout'],true))support_failure(403,'fake_alert_disabled');return $this->outcome;}
}
function support_push_der_signature(string $der): string {
    if(strlen($der)<8||ord($der[0])!==48||ord($der[1])!==strlen($der)-2)support_failure(503,'push_signature_invalid');$pos=2;$out='';
    for($i=0;$i<2;$i++){if(ord($der[$pos++])!==2)support_failure(503,'push_signature_invalid');$length=ord($der[$pos++]);$n=ltrim(substr($der,$pos,$length),"\0");$pos+=$length;if(strlen($n)>32)support_failure(503,'push_signature_invalid');$out.=str_pad($n,32,"\0",STR_PAD_LEFT);}if($pos!==strlen($der))support_failure(503,'push_signature_invalid');return $out;
}
function support_push_vapid(string $endpoint,string $audience='operator'): array {
    $u=support_push_endpoint($endpoint);$keys=support_push_keys(false,$audience);$b64=fn($s)=>rtrim(strtr(base64_encode($s),'+/','-_'),'=');$contact=getenv('SUPPORT_PUSH_CONTACT')?:'';
    if(!str_starts_with($contact,'mailto:')||!filter_var(substr($contact,7),FILTER_VALIDATE_EMAIL))support_failure(503,'push_configuration_missing');
    $body=$b64(json_encode(['typ'=>'JWT','alg'=>'ES256'],JSON_THROW_ON_ERROR)).'.'.$b64(json_encode(['aud'=>'https://'.$u['host'],'exp'=>time()+300,'sub'=>$contact],JSON_THROW_ON_ERROR));
    if(!openssl_sign($body,$signature,$keys['private'],OPENSSL_ALGO_SHA256))support_failure(503,'push_unavailable');return ['jwt'=>$body.'.'.$b64(support_push_der_signature($signature)),'key'=>$keys['public']];
}
final class SupportPrivateOperatorAlerts implements SupportOperatorAlertTransport {
    public function __construct(private string $audience='operator'){}
    public function send(string $kind,array $job,array $payload): string {
        if(PHP_SAPI!=='cli'||!($this->audience==='customer'?($kind==='push'&&support_customer_push_ready()):support_alert_ready($kind)))return 'rejected';
        if($kind==='push'){
            $endpoint=$payload['endpoint']??'';$u=support_push_endpoint($endpoint);$addresses=gethostbynamel($u['host']);if(!$addresses)return 'rejected';foreach($addresses as $ip)if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))return 'rejected';
            $vapid=support_push_vapid($endpoint,$this->audience);$url=$endpoint;$body='';$headers=['Authorization: vapid t='.$vapid['jwt'].',k='.$vapid['key'],'TTL: 60','Urgency: normal','Topic: support-awaiting','Content-Length: 0'];$resolve=[$u['host'].':443:'.$addresses[0]];
        }else{
            $file=getenv('SUPPORT_OPERATOR_SMS_RECIPIENTS_FILE')?:'';$real=realpath($file);$web=realpath(getenv('SUPPORT_PUBLIC_ROOT')?:__DIR__.'/..');$doc=realpath($_SERVER['DOCUMENT_ROOT']??'');if(!$real||is_link($file)||!is_file($real)||filesize($real)>16384||str_starts_with(str_replace('\\','/',$real),str_replace('\\','/',$web).'/')||($doc&&str_starts_with(str_replace('\\','/',$real),str_replace('\\','/',$doc).'/')))return 'rejected';
            $recipients=json_decode((string)file_get_contents($real),true,8,JSON_THROW_ON_ERROR);$mobile=$recipients[$job['operator']]??null;if(!is_string($mobile)||!preg_match('/^09[0-9]{9}$/D',$mobile))return 'rejected';
            $url='https://api.sms.ir/v1/send/verify';$body=json_encode(SupportSmsIr::payload($mobile,'پیام تازهٔ پشتیبانی',(int)getenv('SUPPORT_OPERATOR_SMS_TEMPLATE_ID'),getenv('SUPPORT_OPERATOR_SMS_PARAMETER')?:'ALERT'),JSON_THROW_ON_ERROR);$headers=['X-API-KEY: '.getenv('SMSIR_API_KEY'),'Content-Type: application/json'];$resolve=[];
        }
        $curl=curl_init($url);if(!$curl)return 'rejected';$response='';
        try{curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>$headers,CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>5,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_RESOLVE=>$resolve,CURLOPT_WRITEFUNCTION=>static function($h,string $chunk)use(&$response){if(strlen($response)+strlen($chunk)>16384)return 0;$response.=$chunk;return strlen($chunk);}]);
            if(curl_exec($curl)===false)return 'timeout';$http=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
            if($kind==='sms')return SupportSmsIr::result($http,$response)==='accepted'?'accepted':'rejected';
            return in_array($http,[201,202],true)?'accepted':(in_array($http,[404,410],true)?'gone':(in_array($http,[429,503],true)?'retry':'rejected'));
        }finally{curl_close($curl);}
    }
}
function support_alert_run(SupportOperatorAlertTransport $transport): array {
    if(PHP_SAPI!=='cli')support_failure(403,'forbidden');$lp=support_alert_dir().'/worker.lock';if(is_link($lp))support_failure(503,'alert_unavailable');$lock=@fopen($lp,'c+b');if(!$lock||!flock($lock,LOCK_EX|LOCK_NB))support_failure(409,'alert_worker_busy');
    try{$report=support_alert_plan()+['processed'=>0,'accepted'=>0,'failed'=>0];$end=microtime(true)+30;
        while($report['processed']<10&&microtime(true)<$end-5){$claim=support_alert_claim();if(!$claim)break;$j=$claim['job'];try{$outcome=$transport->send($j['kind'],$j,$claim['payload']);}catch(Throwable $e){$outcome='timeout';}
            if(!in_array($outcome,['accepted','rejected','retry','gone','timeout'],true))$outcome='rejected';
            support_alert_store(function(array &$s)use($claim,$outcome){if(!isset($s['jobs'][$claim['id']]))return;$j=&$s['jobs'][$claim['id']];if($j['state']!=='dispatching')return;$j['result']=$outcome;
                if($outcome==='retry'&&$j['kind']==='push'&&$j['attempts']<3){$j['state']='pending';$j['next']=time()+60*2**$j['attempts'];}else $j['state']=$outcome==='accepted'?'accepted':'failed';
            },true);
            if($outcome==='gone'&&$j['subscription'])support_ops_store(function(array &$s)use($j){unset($s['subscriptions'][$j['subscription']]);},true);
            $report['processed']++;$report[$outcome==='accepted'?'accepted':'failed']++;
        }return $report;
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
