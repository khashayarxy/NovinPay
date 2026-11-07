<?php
declare(strict_types=1);
require_once __DIR__.'/operator-security.php';

interface SupportSmsProvider {public function send(string $mobile,string $code): string;}

/** In-memory deterministic fake. Never selected by credentials or on production HTTP. */
final class SupportFakeSms implements SupportSmsProvider {
    public function __construct(private string $outcome='accepted') {}
    public function send(string $mobile,string $code): string {
        if(!support_sms_test_mode()||!in_array($this->outcome,['accepted','rejected','timeout','quota','credentials','template','limited','malformed'],true))support_failure(503,'sms_disabled');
        return $this->outcome;
    }
}
function support_sms_test_mode(): bool {
    return getenv('SUPPORT_SMS_TEST_MODE')==='1' && (PHP_SAPI==='cli'||(PHP_SAPI==='cli-server'&&getenv('SUPPORT_LOCAL_HTTP')==='1'&&in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true)));
}
function support_sms_ready(): bool {
    if(getenv('SUPPORT_SMS_PROVIDER')==='fake')return support_sms_test_mode();
    return getenv('SUPPORT_SMS_PROVIDER')==='smsir' && getenv('SUPPORT_SMS_REAL_DELIVERY_ENABLED')==='1'
        && is_string(getenv('SMSIR_API_KEY')) && preg_match('/^[A-Za-z0-9_-]{16,256}$/D',getenv('SMSIR_API_KEY'))===1
        && preg_match('/^[1-9][0-9]{0,8}$/D',getenv('SMSIR_TEMPLATE_ID')?:'')===1
        && preg_match('/^[A-Za-z][A-Za-z0-9_]{0,24}$/D',getenv('SMSIR_CODE_PARAMETER')?:'')===1
        && function_exists('curl_exec') && function_exists('openssl_encrypt');
}
/** Official contract verified 2026-10-27: https://sms.ir/rest-api/#content-2 */
final class SupportSmsIr implements SupportSmsProvider {
    public static function payload(string $mobile,string $code,int $template,string $parameter): array {
        return ['mobile'=>$mobile,'templateId'=>$template,'parameters'=>[['name'=>$parameter,'value'=>$code]]];
    }
    public static function result(int $http,string $body): string {
        if($http===429)return 'limited';if($http===401)return 'credentials';
        try{$r=json_decode($body,true,12,JSON_THROW_ON_ERROR);}catch(Throwable $e){return 'malformed';}
        if(!is_array($r)||!is_int($r['status']??null))return 'malformed';
        if($http===200&&$r['status']===1)return is_int($r['data']['messageId']??null)&&$r['data']['messageId']>0?'accepted':'malformed';
        return match($r['status']){10,11,12,13,14=>'credentials',20=>'limited',102=>'quota',113,114,116,117,119=>'template',default=>'rejected'};
    }
    public function send(string $mobile,string $code): string {
        // No HTTP route calls this method. Even direct calls require BOTH flags.
        if(PHP_SAPI!=='cli'||getenv('SUPPORT_PHONE_RECOVERY_ENABLED')!=='1'||!support_sms_ready()||getenv('SUPPORT_SMS_PROVIDER')!=='smsir')support_failure(503,'sms_disabled');
        $curl=curl_init('https://api.sms.ir/v1/send/verify');if(!$curl)return 'rejected';
        $response='';
        try{
            curl_setopt_array($curl,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode(self::payload($mobile,$code,(int)getenv('SMSIR_TEMPLATE_ID'),(string)getenv('SMSIR_CODE_PARAMETER')),JSON_THROW_ON_ERROR),
                CURLOPT_HTTPHEADER=>['X-API-KEY: '.getenv('SMSIR_API_KEY'),'Content-Type: application/json','Accept: application/json'],
                CURLOPT_CONNECTTIMEOUT=>3,CURLOPT_TIMEOUT=>8,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk)use(&$response):int{if(strlen($response)+strlen($chunk)>16384)return 0;$response.=$chunk;return strlen($chunk);}]);
            if(curl_exec($curl)===false)return 'timeout'; // Ambiguous: NEVER retry this dispatch.
            return self::result((int)curl_getinfo($curl,CURLINFO_RESPONSE_CODE),$response);
        }finally{curl_close($curl);}
    }
}
