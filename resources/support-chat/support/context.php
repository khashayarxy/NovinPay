<?php
declare(strict_types=1);
// Public metadata only: titles come from a private per-installation catalog,
// never document.title, query strings, form fields or referrers.
function support_source_path(mixed $path): bool {
    return is_string($path)&&strlen($path)<=512&&preg_match('~^/(?!/)[a-zA-Z0-9_./-]*$~D',$path)===1
        &&!str_contains($path,'..')&&!preg_match('~(?:^|/)(?:admin[^/]*|operator[^/]*|api(?:\.php)?|pay[^/]*|checkout[^/]*|bot|account[^/]*|login[^/]*|logout[^/]*|support[^/]*)(?:/|$)~i',$path);
}
function support_source_catalog(): array {
    if(getenv('SUPPORT_SOURCE_CONTEXT_ENABLED')!=='1')return [];
    $raw=getenv('SUPPORT_SOURCE_PAGES');if($raw===false||$raw==='')return ['/'=>'صفحهٔ اصلی','/contact.html'=>'تماس با ما'];
    if(strlen($raw)>8192)support_failure(503,'source_configuration_invalid');
    try{$catalog=json_decode($raw,true,4,JSON_THROW_ON_ERROR);}catch(Throwable $e){support_failure(503,'source_configuration_invalid');}
    if(!is_array($catalog)||count($catalog)>32)support_failure(503,'source_configuration_invalid');
    foreach($catalog as $path=>$title)if(!support_source_path($path)||!is_string($title)||trim($title)===''||strlen($title)>160||!mb_check_encoding($title,'UTF-8')||preg_match('/[<>\x00-\x1f\x7f]/',$title))support_failure(503,'source_configuration_invalid');
    return $catalog;
}
function support_source_allowed(): array {try{return array_keys(support_source_catalog());}catch(Throwable $e){return [];}}
function support_source_capture(array &$conversation,mixed $path): void {
    if(isset($conversation['source_page'])||count($conversation['messages'])!==0||!support_source_path($path))return;
    try{$catalog=support_source_catalog();}catch(Throwable $e){return;} // Optional metadata cannot break message ACK.
    if(isset($catalog[$path]))$conversation['source_page']=['path'=>$path,'title'=>$catalog[$path]];
}
function support_source_validate(array $conversation): void {
    if(!isset($conversation['source_page']))return;$v=$conversation['source_page'];
    if(!is_array($v)||array_diff(array_keys($v),['path','title'])||!support_source_path($v['path']??null)||!is_string($v['title']??null)||strlen($v['title'])>160||!mb_check_encoding($v['title'],'UTF-8')||preg_match('/[<>\x00-\x1f\x7f]/',$v['title']))support_failure(503,'storage_invalid');
}
