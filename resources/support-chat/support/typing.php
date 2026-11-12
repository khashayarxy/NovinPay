<?php
declare(strict_types=1);
require_once __DIR__.'/operators.php';require_once __DIR__.'/recovery.php';require_once __DIR__.'/phone.php';
const SUPPORT_TYPING_TTL=30;
function support_typing_store(callable $operation,bool $write=false): mixed {
    $dir=support_runtime_dir();$path=$dir.'/typing.json';$lp=$dir.'/typing.lock';foreach([$path,$lp] as $f)if(is_link($f)||(file_exists($f)&&!is_file($f)))support_failure(503,'typing_unavailable');
    $lock=@fopen($lp,'c+b');if(!$lock||!flock($lock,$write?LOCK_EX:LOCK_SH))support_failure(503,'typing_unavailable');
    try{$rows=[];if(is_file($path)){if(filesize($path)>131072)support_failure(503,'typing_unavailable');$rows=json_decode((string)file_get_contents($path),true,16,JSON_THROW_ON_ERROR);}if(!is_array($rows)||count($rows)>128)support_failure(503,'typing_unavailable');
        foreach($rows as $key=>$row){if(!is_array($row)||!is_int($row['until']??null)||!support_valid_id($row['conversation']??null)||!in_array($row['role']??null,['customer','operator'],true))support_failure(503,'typing_unavailable');if($row['until']<=time()||!isset($row['binding']))unset($rows[$key]);}
        $r=$operation($rows);if($write)support_atomic_replace($path,json_encode($rows,JSON_THROW_ON_ERROR));return $r;
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
function support_typing_binding(array $context,string $role): string {
    require_once __DIR__.'/code-recovery.php';
    return support_code_seal(['session'=>session_id(),'context'=>array_intersect_key($context,array_flip(['owner_id','issued_at','auth_epoch','_recovered','_phone','operator_id','credential_version','interactive_at','last_seen']))],'typing-'.$role);
}
function support_typing_live(array $row,array $conversation,array $accounts,?int $now=null): bool {
    $now??=time();if($row['until']<=$now||$row['until']>$now+SUPPORT_TYPING_TTL)return false;
    if(($row['episode']??null)!==($conversation['episode']??1)||($row['reopen_count']??null)!==($conversation['workflow']['reopen_count']??0))return false;
    try{require_once __DIR__.'/code-recovery.php';$b=support_code_open($row['binding'],'typing-'.$row['role']);$session=$b['session']??'';if(!preg_match('/^[a-zA-Z0-9,-]{16,128}$/D',$session)||!is_file(support_runtime_dir().'/sessions-'.$row['role'].'/sess_'.$session))return false;$c=$b['context'];
        if($row['role']==='customer')return $now<($c['last_seen']??0)+7*86400&&$now<$c['issued_at']+30*86400&&support_owned($conversation,$c);
        if($now>=$c['issued_at']+8*3600||$now>=($c['interactive_at']??0)+1800)return false;
        if(support_multi_operator()){$a=$accounts[$c['operator_id']??'']??null;return $a&&$a['active']&&hash_equals(support_ops_version($a),$c['credential_version']);}
        return hash_equals(hash('sha256',ADMIN_USERNAME."\0".ADMIN_PASSWORD_HASH),$c['credential_version']);
    }catch(Throwable $e){return false;}
}
