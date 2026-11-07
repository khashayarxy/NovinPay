<?php
declare(strict_types=1);
require_once __DIR__.'/messages.php';
// Bounded, sanitized execution evidence. Missing evidence is never a heartbeat.
function support_health_journal(?array $event=null): ?array {
    $dir=support_runtime_dir();$path=$dir.'/health-evidence.json';$lp=$dir.'/health-evidence.lock';
    foreach([$path,$lp]as $f)if(is_link($f)||(file_exists($f)&&!is_file($f)))support_failure(503,'health_invalid');
    if($event===null&&!is_file($path))return null;
    $lock=@fopen($lp,'c+b');if(!$lock||!flock($lock,$event===null?LOCK_SH:LOCK_EX|LOCK_NB)){if($lock)fclose($lock);support_failure(503,'health_unavailable');}
    try{
        $s=['version'=>1,'events'=>[]];if(is_file($path)){if(filesize($path)>16384)support_failure(503,'health_invalid');$s=json_decode((string)file_get_contents($path),true,8,JSON_THROW_ON_ERROR);}
        if(!is_array($s)||($s['version']??null)!==1||array_diff(array_keys($s),['version','events'])||!is_array($s['events']??null)||count($s['events'])>16)support_failure(503,'health_invalid');
        $kinds=['gmail_worker','operator_worker','customer_worker','retention_apply','attachment_prune','operator_prune','satisfaction_prune','http_error'];
        $valid=function($kind,$r)use($kinds){return in_array($kind,$kinds,true)&&is_array($r)&&!array_diff(array_keys($r),['at','outcome','mode','count'])&&is_int($r['at']??null)&&$r['at']>0&&in_array($r['outcome']??null,['success','failure'],true)&&in_array($r['mode']??null,['disabled','fake','live','local'],true)&&is_int($r['count']??null)&&$r['count']>=0&&$r['count']<=1000000000;};
        foreach($s['events']as $kind=>$r)if(!$valid($kind,$r))support_failure(503,'health_invalid');
        if($event!==null){$kind=$event['kind']??'';unset($event['kind']);if(!$valid($kind,$event))support_failure(503,'health_invalid');if($kind==='http_error'&&($s['events'][$kind]['at']??0)>time()-60)return $s;$s['events'][$kind]=$event;support_atomic_replace($path,json_encode($s,JSON_THROW_ON_ERROR));}return $s;
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
function support_health_record(string $kind,string $outcome='success',string $mode='local',int $count=0): void {
    if(PHP_SAPI!=='cli'&&$kind!=='http_error')return;
    try{support_health_journal(['kind'=>$kind,'at'=>time(),'outcome'=>$outcome,'mode'=>$mode,'count'=>min(1000000000,max(0,$count))]);}catch(Throwable $ignored){} // Evidence must not change ACK/worker result.
}
function support_health_metric(string $id,string $label,callable $read): array {
    try{return ['id'=>$id,'label'=>$label]+$read();}catch(Throwable $e){$reason=$e instanceof SupportFailure?$e->reason:'';$missing=str_ends_with($reason,'not_initialized');return ['id'=>$id,'label'=>$label,'state'=>$missing?'Unknown':'Error','note'=>$missing?'authority هنوز مقداردهی نشده است.':'شواهد خواندنی یا معتبر نیست؛ بررسی خصوصی سرور لازم است.'];}
}
function support_health_snapshot(array $context): array {
    support_ops_admin($context);support_ops_store(function(array &$s)use($context){support_ops_recheck($s,$context);});
    $metrics=[];$capacity=function(int $used,int $limit): string {return $used>=$limit?'Error':($used>=$limit*.8?'Warning':'Healthy');};
    $metrics[]=support_health_metric('messages','ظرفیت پیام‌ها',fn()=>support_store(function(array &$s,bool &$changed)use($capacity){$path=CHAT_DATA_DIR.'/chat_conversations.json';clearstatcache(true,$path);$bytes=is_file($path)?filesize($path):0;return ['state'=>$capacity($bytes,33554432),'bytes'=>$bytes,'limit'=>33554432,'note'=>'خواندن و اعتبارسنجی store و deletion ledger؛ بدون تضمین سلامت دیسک یا پشتیبان.'];}));
    $metrics[]=support_health_metric('attachments','فضای پیوست‌ها',fn()=>support_store(function(array &$s,bool &$changed)use($capacity){$bytes=array_sum(array_column($s['uploads']??[],'bytes'));$limit=support_option_int('SUPPORT_ATTACHMENT_GLOBAL_BYTES',268435456,1048576,1073741824);return ['state'=>$capacity($bytes,$limit),'bytes'=>$bytes,'limit'=>$limit,'files'=>count($s['uploads']??[]),'note'=>'مصرف ثبت‌شده در metadata؛ صحت تک‌تک فایل‌های باینری اینجا اثبات نمی‌شود.'];}));
    $metrics[]=support_health_metric('gmail_jobs','صف ایمیل',function(){require_once __DIR__.'/notifications.php';$r=support_notification_inspect();$pending=array_sum(array_intersect_key($r['states'],array_flip(['pending','deferred','retry','inflight'])));return ['state'=>$r['states']['failed']?'Warning':($pending?'Warning':'Healthy'),'pending'=>$pending,'failed'=>$r['states']['failed'],'enabled'=>support_notification_enabled(),'note'=>'شمارش intentهای پایدار؛ این عدد اثبات ارسال ایمیل نیست.'];});
    $metrics[]=support_health_metric('operator_jobs','صف Push و SMS اپراتور',function(){require_once __DIR__.'/operator-notifications.php';return support_alert_store(function(array &$s){$pending=count(array_filter($s['jobs'],fn($j)=>in_array($j['state'],['pending','dispatching'],true)));$failed=count(array_filter($s['jobs'],fn($j)=>$j['state']==='failed'));return ['state'=>$pending||$failed?'Warning':'Healthy','pending'=>$pending,'failed'=>$failed,'note'=>'فقط authority صف؛ تحویل دستگاه یا پیامک را تأیید نمی‌کند.'];});});
    $metrics[]=support_health_metric('customer_jobs','صف Push مشتری',function(){require_once __DIR__.'/customer-notifications.php';return support_customer_push_store(function(array &$s){$pending=count(array_filter($s['jobs'],fn($j)=>$j['state']==='dispatching'));$failed=count(array_filter($s['jobs'],fn($j)=>$j['state']==='failed'));return ['state'=>$pending||$failed?'Warning':'Healthy','pending'=>$pending,'failed'=>$failed,'note'=>'فقط claim/result ثبت‌شده؛ تحویل به دستگاه نامعلوم است.'];});});
    $metrics[]=support_health_metric('workers','آخرین اجرای worker',function(){$journal=support_health_journal();$rows=array_intersect_key($journal['events']??[],array_flip(['gmail_worker','operator_worker','customer_worker']));$verified=count($rows)===3&&!array_filter($rows,fn($r)=>$r['at']<time()-900||$r['at']>time()+30||$r['outcome']!=='success'||$r['mode']!=='live');return ['state'=>$verified?'Healthy':($rows?'Warning':'Unknown'),'executions'=>$rows,'note'=>'fake/disabled یا نبود اجرای تازه، سلامت ارسال واقعی را اثبات نمی‌کند؛ heartbeat دائمی اضافه نشده است.'];});
    $metrics[]=support_health_metric('retention','نگهداری و pruning',function(){require_once __DIR__.'/retention.php';support_retention_ledger();$j=support_health_journal();$rows=array_intersect_key($j['events']??[],array_flip(['retention_apply','attachment_prune','operator_prune','satisfaction_prune']));return ['state'=>$rows?'Warning':'Unknown','enabled'=>support_retention_enabled(),'executions'=>$rows,'note'=>'ledger معتبر است؛ زمان اجرای تأییدشدهٔ CLI نشان داده می‌شود. وجود ledger اثبات cron یا اجرای همهٔ pruningها نیست.'];});
    $metrics[]=support_health_metric('errors','آخرین خطای عملیاتی ثبت‌شده',function(){$j=support_health_journal();$r=$j['events']['http_error']??null;return ['state'=>$r?($r['at']>time()-86400?'Warning':'Unknown'):'Unknown','last'=>$r,'note'=>'ثبت محدود خطاهای 5xx این برنامه؛ نبود رکورد به معنی نبود خطا نیست. متن خطا ذخیره نمی‌شود.'];});
    $metrics[]=['id'=>'backup','label'=>'تازگی پشتیبان','state'=>'Unknown','note'=>'مدرک مستقلِ خواندن، hash و restore پشتیبان در این نصب ثبت نشده است.'];
    $metrics[]=support_health_metric('source','زمینهٔ صفحهٔ عمومی',function(){$c=support_source_catalog();return ['state'=>getenv('SUPPORT_SOURCE_CONTEXT_ENABLED')==='1'?'Healthy':'Warning','allowed_pages'=>count($c),'note'=>'فقط مسیرهای allowlist و عنوان تنظیم‌شده؛ اطلاعات هویت نیست.'];});
    $metrics[]=support_health_metric('satisfaction','رضایت پاسخ‌گویی',function(){if(!support_satisfaction_enabled())return ['state'=>'Warning','enabled'=>false,'note'=>'رضایت‌سنجی خاموش است.'];return ['state'=>'Healthy','enabled'=>true,'counts'=>support_satisfaction_summary(),'note'=>'رأی‌های معتبرِ پنجرهٔ هفت‌روزه؛ فقط گزارش تجمیعی مدیر.'];});
    return ['status'=>'success','checked_at'=>time(),'metrics'=>$metrics];
}
