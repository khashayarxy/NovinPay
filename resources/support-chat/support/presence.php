<?php
declare(strict_types=1);
require_once __DIR__.'/operators.php';

// Ephemeral availability, independent of chat history and passive polling.
function support_presence_store(callable $operation,bool $write=false): mixed {
    $dir=support_runtime_dir();$path=$dir.'/operator-presence.json';$lp=$dir.'/operator-presence.lock';
    foreach([$path,$lp] as $file)if(is_link($file)||(file_exists($file)&&!is_file($file)))support_failure(503,'presence_unavailable');
    $lock=@fopen($lp,'c+b');if(!$lock||!flock($lock,$write?LOCK_EX:LOCK_SH))support_failure(503,'presence_unavailable');
    try {
        $rows=[];if(is_file($path)){if(filesize($path)>131072)support_failure(503,'presence_unavailable');$rows=json_decode((string)file_get_contents($path),true,16,JSON_THROW_ON_ERROR);}
        if(!is_array($rows)||count($rows)>200)support_failure(503,'presence_unavailable');
        foreach($rows as $key=>$r)if(!preg_match('/^[a-f0-9]{32}$/D',(string)$key)||!is_array($r)||!is_int($r['at']??null)||!is_int($r['expires']??null)||!is_string($r['version']??null)||!is_string($r['operator']??null)||!preg_match('/^[a-zA-Z0-9,-]{16,128}$/D',$r['session']??''))support_failure(503,'presence_unavailable');
        if($write)foreach($rows as $key=>$r)if($r['expires']<=time()||$r['at']<=time()-600)unset($rows[$key]);
        $result=$operation($rows);if($write){if(count($rows)>200)support_failure(503,'presence_capacity');support_atomic_replace($path,json_encode($rows,JSON_THROW_ON_ERROR));}return $result;
    } finally {flock($lock,LOCK_UN);fclose($lock);}
}
function support_presence_record(array $context,string $session): void {
    $now=time();support_presence_store(function(array &$rows)use($context,$session,$now){
        $key=$context['auth_epoch'];if(($rows[$key]['evidence']??null)==='interaction-v1'&&$now-($rows[$key]['at']??0)<60)return;
        $rows[$key]=['evidence'=>'interaction-v1','at'=>$now,'expires'=>min($now+600,$context['issued_at']+8*3600),'operator'=>$context['operator_id']??'legacy','version'=>$context['credential_version'],'session'=>$session];
    },true);
}
// Clock injection is for deterministic PHP tests; HTTP never accepts a timestamp.
function support_presence_online(?int $now=null): bool {
    $now??=time();
    try {
        $accounts=support_multi_operator()?support_ops_store(fn(array &$s)=>$s['accounts']):[];
        return support_presence_store(function(array &$rows)use($now,$accounts){foreach($rows as $r){
            if(($r['evidence']??null)!=='interaction-v1'||$r['at']>$now||$now-$r['at']>=600||$r['expires']<=$now||!is_file(support_runtime_dir().'/sessions-operator/sess_'.$r['session']))continue;
            if(support_multi_operator()){$a=$accounts[$r['operator']]??null;if(!$a||!$a['active']||!hash_equals(support_ops_version($a),$r['version']))continue;}
            elseif($r['operator']!=='legacy'||!hash_equals(hash('sha256',ADMIN_USERNAME."\0".ADMIN_PASSWORD_HASH),$r['version']))continue;
            return true;
        }return false;});
    } catch(Throwable $e){return false;}
}
