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
    if(getenv('SUPPORT_SMS_PROVIDER')!=='ippanel'||getenv('SUPPORT_SMS_REAL_DELIVERY_ENABLED')!=='1'||!function_exists('app'))return false;
    try{return (bool)config('customer.ippanel.enabled')&&app(\App\Services\IpPanel::class)->configured();}catch(Throwable){return false;}
}
/** Reuse the application's sole OTP adapter; no second credential or HTTP client. */
final class SupportIpPanel implements SupportSmsProvider {
    public function send(string $mobile,string $code): string {
        if(PHP_SAPI!=='cli'||getenv('SUPPORT_PHONE_RECOVERY_ENABLED')!=='1'||!support_sms_ready())support_failure(503,'sms_disabled');
        $outcome=app(\App\Services\IpPanel::class)->sendOutcome($mobile,$code);
        return $outcome==='uncertain'?'timeout':$outcome;
    }
}
