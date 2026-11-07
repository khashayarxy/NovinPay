<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
const SUPPORT_KNOWLEDGE_LIMIT=2097152;
function support_content_text(mixed $value,int $limit,bool $empty=false): string {
    $v=support_string($value,$limit,$empty);if(preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f\x{202a}-\x{202e}\x{2066}-\x{2069}]/u',$v))support_failure(400,'invalid_content');return $v;
}
function support_content_id(mixed $v): string {if(!is_string($v)||!preg_match('/^[a-f0-9]{32}$/D',$v))support_failure(400,'invalid_content');return $v;}
function support_content_position(mixed $v): int {if(!is_string($v)||!preg_match('/^[0-9]{1,4}$/D',$v))support_failure(400,'invalid_content');return (int)$v;}
function support_content_boolean(mixed $v): bool {if(!in_array($v,['0','1'],true))support_failure(400,'invalid_content');return $v==='1';}
function support_content_hex(mixed $v): string {if(!is_string($v)||!preg_match('/^#[a-fA-F0-9]{6}$/D',$v))support_failure(400,'invalid_brand');return strtolower($v);}
function support_content_contrast(string $hex): float {$v=[];foreach(str_split(substr($hex,1),2)as $p){$n=hexdec($p)/255;$v[]=$n<=.04045?$n/12.92:(($n+.055)/1.055)**2.4;}return 1.05/(.2126*$v[0]+.7152*$v[1]+.0722*$v[2]+.05);}
function support_content_readable(string $hex): string {while(support_content_contrast($hex)<4.5){$v=array_map(fn($p)=>(int)floor(hexdec($p)*.92),str_split(substr($hex,1),2));$hex=sprintf('#%02x%02x%02x',...$v);}return $hex;}
function support_content_defaults(): array {
    $color=getenv('SUPPORT_WIDGET_COLOR')?:'#8124bb';if(!preg_match('/^#[a-fA-F0-9]{6}$/D',$color))$color='#8124bb';
    $color=support_content_readable($color);
    return ['version'=>1,'revision'=>0,'categories'=>[],'articles'=>[],'events'=>[],'uploads'=>[],
        'settings'=>['site_title'=>support_site_name(),'color'=>$color,'accent'=>'#a36aec','home_heading'=>'به پشتیبانی خوش آمدید','home_subtitle'=>'پاسخ‌ها در همین گفتگو در دسترس شماست.','welcome'=>mb_substr(getenv('SUPPORT_WELCOME_MESSAGE')?:'سلام! چطور می‌توانیم کمکتان کنیم؟',0,250),'availability'=>mb_substr(getenv('SUPPORT_AVAILABILITY_TEXT')?:'پاسخ در همین گفتگو',0,120),'customer_theme_default'=>in_array(getenv('SUPPORT_CUSTOMER_THEME_DEFAULT'),['light','dark','system'],true)?getenv('SUPPORT_CUSTOMER_THEME_DEFAULT'):'light','operator_theme_default'=>in_array(getenv('SUPPORT_OPERATOR_THEME_DEFAULT'),['light','dark','system'],true)?getenv('SUPPORT_OPERATOR_THEME_DEFAULT'):'dark','knowledge_visible'=>true,'logo'=>null]];
}
function support_content_link(mixed $link): array {
    if(!is_array($link)||array_diff(array_keys($link),['label','path']))support_failure(400,'invalid_content');$label=support_content_text($link['label']??null,100);$path=support_string($link['path']??null,512);require_once __DIR__.'/context.php';$parts=explode('#',$path);
    if(count($parts)>2||!support_source_path($parts[0])||isset($parts[1])&&!preg_match('/^[a-zA-Z0-9_-]{1,80}$/D',$parts[1]))support_failure(400,'invalid_content_link');return ['label'=>$label,'path'=>$path];
}
function support_content_validate(array $s): void {
    if(($s['version']??null)!==1||array_diff(array_keys($s),['version','revision','categories','articles','events','settings','uploads'])||!is_int($s['revision']??null)||$s['revision']<0||$s['revision']>=2147483647)support_failure(503,'knowledge_invalid');
    foreach(['categories'=>24,'articles'=>120,'events'=>200,'uploads'=>64]as $key=>$limit)if(!is_array($s[$key]??null)||count($s[$key])>$limit)support_failure(503,'knowledge_capacity');
    try{
        foreach($s['categories']as $id=>$c){if(!is_array($c)||array_diff(array_keys($c),['id','title','position','published'])||support_content_id($id)!==($c['id']??null)||!is_int($c['position']??null)||$c['position']<0||$c['position']>9999||!is_bool($c['published']??null))support_failure(503,'knowledge_invalid');support_content_text($c['title']??null,100);}
        foreach($s['articles']as $id=>$a){if(!is_array($a)||array_diff(array_keys($a),['id','category','title','summary','body','links','position','published','featured','suggested'])||support_content_id($id)!==($a['id']??null)||!array_key_exists('category',$a)||$a['category']!==null&&!isset($s['categories'][$a['category']])||!is_int($a['position']??null)||$a['position']<0||$a['position']>9999||!is_array($a['links']??null)||!array_is_list($a['links'])||count($a['links'])>6)support_failure(503,'knowledge_invalid');foreach(['published','featured','suggested']as $f)if(!is_bool($a[$f]??null))support_failure(503,'knowledge_invalid');support_content_text($a['title']??null,160);support_content_text($a['summary']??null,300,true);support_content_text($a['body']??null,12288,!$a['published']);foreach($a['links']as $l)support_content_link($l);}
        $v=$s['settings']??null;if(!is_array($v)||array_diff(array_keys($v),array_keys(support_content_defaults()['settings']))||array_diff(array_keys(support_content_defaults()['settings']),array_keys($v)))support_failure(503,'knowledge_invalid');foreach(['site_title'=>120,'home_heading'=>160,'home_subtitle'=>240,'welcome'=>1000,'availability'=>120]as $key=>$limit)support_content_text($v[$key],$limit);support_content_hex($v['color']);support_content_hex($v['accent']);foreach(['customer_theme_default','operator_theme_default']as $key)if(!in_array($v[$key],['light','dark','system'],true))support_failure(503,'knowledge_invalid');if(!is_bool($v['knowledge_visible'])||$v['logo']!==null&&!support_brand_meta_valid($v['logo']))support_failure(503,'knowledge_invalid');
        foreach($s['uploads']as $key=>$r)if(!support_valid_uuid($key)||!is_array($r)||!support_brand_meta_valid($r['asset']??null)||!is_int($r['expires']??null))support_failure(503,'knowledge_invalid');
        foreach($s['events']as $e)if(!is_array($e)||!is_int($e['at']??null)||!preg_match('/^[a-f0-9]{32}$/D',$e['actor']??'')||!in_array($e['event']??null,['category_saved','article_saved','reordered','presentation_saved','logo_uploaded'],true))support_failure(503,'knowledge_invalid');
    }catch(Throwable $e){support_failure(503,'knowledge_invalid');}
}
function support_content_store(callable $operation,bool $write=false): mixed {
    $directory=support_runtime_dir().'/knowledge';$path=$directory.'/authority.json';$marker=$directory.'/initialized';$lp=$directory.'/authority.lock';
    if(!$write&&!is_dir($directory)){$s=support_content_defaults();$changed=false;return $operation($s,$changed);}
    $directory=support_private_dir($directory);foreach([$path,$marker,$lp]as $f)if(is_link($f)||(file_exists($f)&&!is_file($f)))support_failure(503,'knowledge_invalid');
    $lock=@fopen($lp,'c+b');if(!$lock||!flock($lock,$write?LOCK_EX:LOCK_SH)){if($lock)fclose($lock);support_failure(503,'knowledge_unavailable');}
    try{
        if(is_file($path)!==is_file($marker))support_failure(503,'knowledge_incomplete');$s=support_content_defaults();
        if(is_file($path)){clearstatcache(true,$path);if(filesize($path)>SUPPORT_KNOWLEDGE_LIMIT)support_failure(503,'knowledge_capacity');try{$s=json_decode((string)file_get_contents($path),true,16,JSON_THROW_ON_ERROR);}catch(Throwable $e){support_failure(503,'knowledge_invalid');}if(!is_array($s))support_failure(503,'knowledge_invalid');require_once __DIR__.'/branding.php';support_content_validate($s);}
        $changed=false;$result=$operation($s,$changed);
        if($write&&$changed){$s['revision']++;require_once __DIR__.'/branding.php';support_content_validate($s);$bytes=json_encode($s,JSON_THROW_ON_ERROR);if(strlen($bytes)>SUPPORT_KNOWLEDGE_LIMIT)support_failure(429,'knowledge_capacity');support_atomic_replace($path,$bytes);if(!is_file($marker))support_atomic_replace($marker,'1');if(is_array($result))$result['revision']=$s['revision'];}
        return $result;
    }finally{flock($lock,LOCK_UN);fclose($lock);}
}
function support_content_published(array $s): array {
    if(getenv('SUPPORT_KNOWLEDGE_ENABLED')==='0'||!$s['settings']['knowledge_visible'])return [];$articles=array_filter($s['articles'],fn($a)=>$a['published']&&($a['category']===null||$s['categories'][$a['category']]['published']));usort($articles,fn($a,$b)=>$a['position']<=>$b['position']?:strcmp($a['id'],$b['id']));return $articles;
}
function support_content_public_item(array $a,bool $body=false): array {return array_intersect_key($a,array_flip($body?['id','category','title','summary','body','links']:['id','category','title','summary']));}
function support_content_presentation(): array {
    try{return support_content_store(function(array &$s,bool &$changed){$v=$s['settings'];return ['name'=>$v['site_title'],'color'=>$v['color'],'accent'=>$v['accent'],'home_heading'=>$v['home_heading'],'home_subtitle'=>$v['home_subtitle'],'welcome'=>$v['welcome'],'availability'=>$v['availability'],'customer_theme_default'=>$v['customer_theme_default'],'operator_theme_default'=>$v['operator_theme_default'],'knowledge_enabled'=>count(support_content_published($s))>0,'content_revision'=>$s['revision'],'managed_logo'=>$v['logo']?'support-avatar.php?kind=logo&id='.$v['logo']['id']:null];});}catch(Throwable $e){return ['knowledge_enabled'=>false];}
}
function support_content_normalize(string $v): string {return mb_strtolower((string)preg_replace('/[\s\x{200c}\x{200d}\x{064b}-\x{065f}\x{0670}]+/u','',strtr($v,['ي'=>'ی','ك'=>'ک'])));}
function support_content_public(string $action,array $query): array {
    return support_content_store(function(array &$s,bool &$changed)use($action,$query){$articles=support_content_published($s);
        if($action==='faq')return ['status'=>'success','revision'=>$s['revision'],'questions'=>array_map('support_content_public_item',array_values(array_filter($articles,fn($a)=>$a['suggested'])))];
        if($action==='home'){$categories=array_values(array_filter($s['categories'],fn($c)=>$c['published']&&array_filter($articles,fn($a)=>$a['category']===$c['id'])));usort($categories,fn($a,$b)=>$a['position']<=>$b['position']?:strcmp($a['id'],$b['id']));$suggested=array_values(array_filter($articles,fn($a)=>$a['suggested']));if(!$suggested)$suggested=array_values(array_filter($articles,fn($a)=>$a['featured']));if(!$suggested)$suggested=$articles;return ['status'=>'success','enabled'=>getenv('SUPPORT_KNOWLEDGE_ENABLED')!=='0'&&$s['settings']['knowledge_visible'],'total'=>count($articles),'categories'=>array_map(fn($c)=>array_intersect_key($c,array_flip(['id','title'])),$categories),'suggested'=>array_map('support_content_public_item',array_slice($suggested,0,5))];}
        if($action==='article'){$id=support_content_id($query['id']??null);foreach($articles as $a)if($a['id']===$id)return ['status'=>'success','article'=>support_content_public_item($a,true)];support_failure(404,'article_unavailable');}
        if($action!=='search')support_failure(400,'invalid_input');$q=support_content_normalize(support_content_text($query['q']??'',160,true));$category=support_string($query['category']??'',32,true);if($category!=='')support_content_id($category);$matches=array_values(array_filter($articles,fn($a)=>($category===''||$a['category']===$category)&&($q===''||str_contains(support_content_normalize($a['title'].' '.$a['summary'].' '.$a['body']),$q))));return ['status'=>'success','articles'=>array_map('support_content_public_item',array_slice($matches,0,30)),'total'=>count($matches)];
    });
}
function support_content_revision(array $s,mixed $value): void {if(!is_string($value)||!preg_match('/^[0-9]{1,10}$/D',$value)||$s['revision']!==(int)$value)support_failure(409,'knowledge_changed');}
function support_content_audit(array &$s,array $context,string $event): void {$s['events']=array_values(array_filter($s['events'],fn($e)=>$e['at']>time()-604800));$s['events'][]=['at'=>time(),'actor'=>$context['operator_id'],'event'=>$event];if(count($s['events'])>200)$s['events']=array_slice($s['events'],-200);}
function support_content_admin(array $context,string $action,array $input): array {
    require_once __DIR__.'/operators.php';support_ops_admin($context);
    return support_ops_store(function(array &$ops)use($context,$action,$input){support_ops_recheck($ops,$context);return support_content_store(function(array &$s,bool &$changed)use($context,$action,$input){
        if($action==='inspect')return ['status'=>'success','revision'=>$s['revision'],'categories'=>array_values($s['categories']),'articles'=>array_values($s['articles']),'settings'=>$s['settings']];support_content_revision($s,$input['revision']??null);
        if($action==='category_save'){$id=support_content_id($input['id']??null);if(!isset($s['categories'][$id])&&count($s['categories'])>=24)support_failure(429,'knowledge_capacity');$s['categories'][$id]=['id'=>$id,'title'=>support_content_text($input['title']??null,100),'position'=>support_content_position($input['position']??'0'),'published'=>support_content_boolean($input['published']??null)];$event='category_saved';}
        elseif($action==='article_save'){$id=support_content_id($input['id']??null);if(!isset($s['articles'][$id])&&count($s['articles'])>=120)support_failure(429,'knowledge_capacity');$category=support_string($input['category']??'',32,true);if($category!==''&&!isset($s['categories'][$category]))support_failure(400,'invalid_content');$published=support_content_boolean($input['published']??null);try{$links=json_decode(support_string($input['links']??'[]',5000),true,8,JSON_THROW_ON_ERROR);}catch(Throwable $e){support_failure(400,'invalid_content_link');}if(!is_array($links)||!array_is_list($links)||count($links)>6)support_failure(400,'invalid_content_link');$s['articles'][$id]=['id'=>$id,'category'=>$category===''?null:$category,'title'=>support_content_text($input['title']??null,160),'summary'=>support_content_text($input['summary']??'',300,true),'body'=>support_content_text($input['body']??'',12288,!$published),'links'=>array_map('support_content_link',$links),'position'=>support_content_position($input['position']??'0'),'published'=>$published,'featured'=>support_content_boolean($input['featured']??'0'),'suggested'=>support_content_boolean($input['suggested']??'0')];$event='article_saved';}
        elseif($action==='reorder'){$kind=$input['kind']??'';if(!in_array($kind,['categories','articles'],true))support_failure(400,'invalid_content');try{$ids=json_decode(support_string($input['ids']??null,5000),true,4,JSON_THROW_ON_ERROR);}catch(Throwable $e){support_failure(400,'invalid_content');}if(!is_array($ids)||!array_is_list($ids)||count($ids)!==count($s[$kind])||count(array_unique($ids))!==count($ids))support_failure(400,'invalid_content');foreach($ids as $position=>$id){if(!is_string($id)||!isset($s[$kind][$id]))support_failure(400,'invalid_content');$s[$kind][$id]['position']=$position;}$event='reordered';}
        elseif($action==='presentation_save'){$v=$s['settings'];foreach(['site_title'=>120,'home_heading'=>160,'home_subtitle'=>240,'welcome'=>1000,'availability'=>120]as $key=>$limit)$v[$key]=support_content_text($input[$key]??null,$limit);$v['color']=support_content_hex($input['color']??null);$v['accent']=support_content_hex($input['accent']??null);if(support_content_contrast($v['color'])<4.5)support_failure(400,'brand_contrast');foreach(['customer_theme_default','operator_theme_default']as $key){if(!in_array($input[$key]??null,['light','dark','system'],true))support_failure(400,'invalid_content');$v[$key]=$input[$key];}$v['knowledge_visible']=support_content_boolean($input['knowledge_visible']??null);if(array_key_exists('logo_id',$input))$v['logo']=support_brand_select($s['uploads'],$v['logo'],$input['logo_id']);$s['settings']=$v;$event='presentation_saved';}
        elseif($action==='article_delete'){$id=support_content_id($input['id']??null);if(!isset($s['articles'][$id])||!$s['articles'][$id]['suggested'])support_failure(404,'article_unavailable');unset($s['articles'][$id]);$event='article_saved';}
        else support_failure(400,'invalid_input');$changed=true;support_content_audit($s,$context,$event);return ['status'=>'success'];
    },$action!=='inspect');});
}
// Snapshot published knowledge, then use the existing owned chat transaction.
// The customer question is a real user message; the answer remains a system
// record with no human principal/ACK and never enters human-response metrics.
function support_faq_answer(array $context,string $id,string $article): array {
    require_once __DIR__.'/messages.php';support_content_id($article);
    $answer=support_content_public('article',['id'=>$article])['article'];
    $available=support_content_public('faq',[])['questions'];if(!in_array($article,array_column($available,'id'),true))support_failure(404,'article_unavailable');
    $digest=hash('sha256',$answer['title']."\0".$answer['body']);
    return support_store(function(array &$s,bool &$changed)use($context,$id,$article,$answer,$digest){
        if(!isset($s['conversations'][$id])){if(isset($context['_phone'])||isset($context['_recovered'])||!hash_equals(support_anonymous_id($context),$id))support_failure(404,'conversation_unavailable');support_throttle('anonymous-new:'.support_ip_key(),20);$s['conversations'][$id]=support_draft_conversation($id,$context);}
        support_prepare_customer_draft($s,$id,$context);
        $c=support_conversation($s,$id,$context,false);if(support_conversation_retired($c))support_failure(409,'conversation_retired');
        if(support_workflow($c)['status']==='resolved')support_failure(409,'conversation_resolved');
        $start=support_episode_start($c);
        foreach($c['messages'] as $m)if((int)$m['id']>$start&&($m['faq_id']??null)===$article&&($m['faq_digest']??null)===$digest)return ['status'=>'success','message_id'=>$m['id'],'question_message_id'=>$m['question_message_id']??null,'replayed'=>true];
        support_throttle('faq-answer:'.$context['owner_id'],30,300);
        if(count(array_filter($c['messages'],fn($m)=>($m['faq_at']??0)>time()-300))>=15){header('Retry-After: 300');support_failure(429,'faq_limited');}
        $questionRaw=$answer['title'];$question=support_text($questionRaw);
        support_add_message($s,$id,'user',$question);
        $qm=&$s['conversations'][$id]['messages'][array_key_last($s['conversations'][$id]['messages'])];
        $uuid=support_uuid4();$accepted=gmdate('Y-m-d\TH:i:s\Z');$principal=support_principal($context,'customer');
        $qm+=['protocol_version'=>2,'client_message_id'=>$uuid,'sender_principal'=>$principal,'payload_digest'=>hash('sha256',"protocol-v2\0customer\0{$id}\0{$questionRaw}"),'accepted_at'=>$accepted,'faq_question_id'=>$article];
        require_once __DIR__.'/operators.php';support_workflow_message($s,$id,'customer',$qm['id'],'customer');
        support_add_message($s,$id,'system',htmlspecialchars($answer['body'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'));
        $m=&$s['conversations'][$id]['messages'][array_key_last($s['conversations'][$id]['messages'])];$m+=['faq_id'=>$article,'faq_digest'=>$digest,'faq_at'=>time(),'question_message_id'=>$qm['id'],'faq_answer_only'=>true,'origin'=>'faq_auto'];
        $used=$s['conversations'][$id]['used_faqs']??[];if(!in_array($article,$used,true))$used[]=$article;$s['conversations'][$id]['used_faqs']=array_values(array_unique($used));
        $s['conversations'][$id]['last_activity_at']=time();$changed=true;
        return ['status'=>'success','message_id'=>$m['id'],'question_message_id'=>$qm['id'],'replayed'=>false];
    },true);
}
