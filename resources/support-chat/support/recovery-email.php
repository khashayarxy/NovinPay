<?php
declare(strict_types=1);
require_once __DIR__.'/code-recovery.php';

interface SupportRecoveryEmailProvider {public function send(string $kind,array $payload): string;}
final class SupportFakeRecoveryEmail implements SupportRecoveryEmailProvider {
    public function __construct(private string $outcome='accepted'){}
    public function send(string $kind,array $payload): string {
        if(!support_code_test_mode()||!in_array($this->outcome,['accepted','rejected','timeout','disabled'],true))support_failure(403,'email_disabled');return $this->outcome;
    }
}
/** Private, opt-in PHP-mail transport. Never called on a customer HTTP request. */
final class SupportPrivateRecoveryEmail implements SupportRecoveryEmailProvider {
    public function send(string $kind,array $payload): string {
        if(PHP_SAPI!=='cli'||!support_code_email_ready()||getenv('SUPPORT_RECOVERY_EMAIL_PROVIDER')!=='php-mail')return 'disabled';
        $dir=support_private_dir(support_runtime_dir().'/code-recovery/results');$old=glob($dir.'/mail-*.tmp');if($old===false||count($old)>128)return 'rejected';
        foreach($old as $file){if(is_link($file)||!is_file($file))return 'rejected';if(filemtime($file)<time()-600)@unlink($file);}
        $resultPath=$dir.'/mail-'.bin2hex(random_bytes(16)).'.tmp';$file=@fopen($resultPath,'x+b');if(!$file)return 'rejected';
        if(!@chmod($resultPath,0600)){fclose($file);@unlink($resultPath);return 'rejected';}
        $args=[PHP_BINARY];if($ini=php_ini_loaded_file())array_push($args,'-c',$ini);
        array_push($args,'-d','disable_functions='.ini_get('disable_functions'),'-d','auto_prepend_file="'.str_replace('"','\\"',(string)ini_get('auto_prepend_file')).'"','-d','auto_append_file=""','-d','display_errors=0','-d','log_errors=0',__DIR__.'/../tools/support-recovery-email.php','--mail-child');
        $pipes=[];$process=@proc_open($args,[0=>['pipe','r'],1=>$file,2=>['file',PHP_OS_FAMILY==='Windows'?'NUL':'/dev/null','a']],$pipes,null,null,['bypass_shell'=>true]);
        if(!is_resource($process)){fclose($file);@unlink($resultPath);return 'rejected';}
        $result='rejected';
        try{
            $bytes=json_encode(['kind'=>$kind,'payload'=>$payload],JSON_THROW_ON_ERROR);if(strlen($bytes)>2000||@fwrite($pipes[0],$bytes)!==strlen($bytes))return 'rejected';
            fclose($pipes[0]);unset($pipes[0]);$deadline=microtime(true)+5;
            while(true){$status=proc_get_status($process);if(fstat($file)['size']>64)return 'rejected';
                if(!$status['running']){rewind($file);$result=trim(stream_get_contents($file,65))==='ACCEPTED'?'accepted':'rejected';break;}
                if(microtime(true)>=$deadline){$result='timeout';break;}usleep(20000);
            }
        }finally{$status=proc_get_status($process);if($status['running']){@proc_terminate($process);usleep(20000);if(proc_get_status($process)['running'])@proc_terminate($process,9);}
            foreach($pipes as $pipe)if(is_resource($pipe))fclose($pipe);proc_close($process);fclose($file);@unlink($resultPath);
        }return $result;
    }
}
function support_code_mail_child(): never {
    if(PHP_SAPI!=='cli'||!support_code_email_ready()||getenv('SUPPORT_RECOVERY_EMAIL_PROVIDER')!=='php-mail')exit(64);
    try{
        $raw=stream_get_contents(STDIN,2001);if(strlen($raw)>2000)exit(64);$p=json_decode($raw,true,8,JSON_THROW_ON_ERROR);$kind=$p['kind']??null;$data=$p['payload']??null;
        if(!is_array($data)||!in_array($kind,['verify','backup'],true))exit(64);$to=support_code_email(support_string($data['email']??null,254));$from=support_code_email((string)getenv('SUPPORT_RECOVERY_EMAIL_FROM'));
        if($kind==='verify'){
            if(!is_string($data['code']??null)||!preg_match('/^[0-9]{6}$/D',$data['code']))exit(64);
            $subject='تأیید ایمیل پشتیبانی '.support_site_name();$body="کد تأیید ایمیل شما: ".$data['code']."\nاین کد پنج دقیقه اعتبار دارد و برای ورود به گفتگو نیست.";
        }else{
            if(!is_string($data['token']??null)||!preg_match('/^ZL1\.[a-f0-9]{32}\.[A-Za-z0-9_-]{43}$/D',$data['token']))exit(64);
            $subject='نسخه خصوصی کد بازیابی '.support_site_name();$body="کد بازیابی گفتگوی شما:\n".$data['token']."\nاین کد دسترسی به گفتگو می‌دهد؛ آن را خصوصی نگه دارید. این نسخه پیام‌های حذف‌شده را بازنمی‌گرداند.";
        }
        $ok=@mail($to,'=?UTF-8?B?'.base64_encode($subject).'?=',$body,'From: '.$from."\r\nContent-Type: text/plain; charset=UTF-8");echo $ok?'ACCEPTED':'REJECTED';exit($ok?0:1);
    }catch(Throwable $ignored){echo 'REJECTED';exit(1);}
}
function support_code_email_claim(): ?array {
    return support_code_store(function(array &$s){foreach($s['jobs'] as $id=>&$j){
        if($j['state']!=='pending')continue;$r=$s['records'][$j['conversation']]??null;$c=$s['challenges'][$j['challenge']]??null;
        $valid=$r&&$r['epoch']===$j['epoch']&&$r['state']!=='revoked';
        if($j['kind']==='backup')$valid=$valid&&$r['state']==='active';
        else $valid=$valid&&$c&&$c['state']==='pending'&&$c['expires']>time()&&($r['state']==='active'||$r['expires']>time());
        if(!$valid){$j['state']='cancelled';$j['payload']='';continue;}
        $j['state']='dispatching';return ['id'=>$id,'kind'=>$j['kind'],'payload'=>$j['payload']];
    }return null;},true);
}
function support_code_email_finish(string $id,string $outcome): void {
    support_code_store(function(array &$s)use($id,$outcome){if(!isset($s['jobs'][$id]))return;$j=&$s['jobs'][$id];if($j['state']!=='dispatching')return;
        $j['state']=$outcome==='accepted'?'accepted':'failed';$j['result']=$outcome;$j['payload']='';
        if($j['kind']==='verify'){
            if(isset($s['challenges'][$j['challenge']])){$c=&$s['challenges'][$j['challenge']];if($c['state']==='pending'){
                if($outcome==='accepted'&&$c['expires']>time())$c['state']='accepted';else{$c['state']='cancelled';$c['sealed']=$c['verifier']='';}
            }}support_code_event($s,$outcome==='accepted'?'email_accepted':'email_failed');
        }else support_code_event($s,$outcome==='accepted'?'backup_accepted':'backup_failed');
    },true);
}
function support_code_email_dispatch(SupportRecoveryEmailProvider $provider,int $limit=10): array {
    if(PHP_SAPI!=='cli'||!support_code_email_ready()||$limit<1||$limit>10)support_failure(403,'email_disabled');
    $path=support_runtime_dir().'/code-recovery/email-worker.lock';if(is_link($path))support_failure(503,'recovery_unavailable');$lock=@fopen($path,'c+b');
    if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){if($lock)fclose($lock);support_failure(409,'email_worker_busy');}
    try{$report=['processed'=>0,'accepted'=>0,'failed'=>0];$deadline=microtime(true)+30;
        while($report['processed']<$limit&&microtime(true)<$deadline-5){$job=support_code_email_claim();if(!$job)break;$payload=support_code_open($job['payload'],$job['id']);
            try{$outcome=$provider->send($job['kind'],$payload);}catch(Throwable $e){$outcome='timeout';}unset($payload);
            if(!in_array($outcome,['accepted','rejected','timeout','disabled'],true))$outcome='rejected';support_code_email_finish($job['id'],$outcome);
            $report['processed']++;$report[$outcome==='accepted'?'accepted':'failed']++;
        }return $report;
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
