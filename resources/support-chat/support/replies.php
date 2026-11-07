<?php
declare(strict_types=1);
// References retain IDs only. Preview text is projected exclusively from live messages.
function support_reply_id(mixed $value): int {
    if($value===null||$value==='')return 0;
    if(is_int($value))$value=(string)$value;
    if(!is_string($value)||!preg_match('/^[1-9][0-9]{0,15}$/D',$value)||(int)$value>9007199254740991)support_failure(400,'invalid_reply_reference');
    return (int)$value;
}
function support_reply_validate(array $message): void {
    if(isset($message['reply_to'])&&(!is_int($message['reply_to'])||$message['reply_to']<1||$message['reply_to']>9007199254740991||$message['reply_to']>=$message['id']))support_failure(503,'storage_invalid');
    if(isset($message['read_visible'])&&!is_bool($message['read_visible']))support_failure(503,'storage_invalid');
}
function support_reply_authorize(array $conversation,int $target): void {
    if(!$target)return;
    foreach($conversation['messages'] as $m)if($m['id']===$target&&in_array($m['type'],['user','admin'],true))return;
    // A retained receipt proves membership without recovering erased content.
    foreach($conversation['retained_receipts']??[] as $m)if($m['id']===$target)return;
    support_failure(409,'reply_unavailable');
}
function support_reply_preview(array $message,array $live): ?array {
    $id=$message['reply_to']??0;if(!$id)return null;
    $target=$live[$id]??null;
    if(!$target)return ['id'=>$id,'available'=>false];
    return ['id'=>$id,'revision'=>$target['revision']??0,'available'=>true,'type'=>$target['type'],'text'=>mb_substr(html_entity_decode($target['edited_text']??$target['text'],ENT_QUOTES|ENT_HTML5,'UTF-8'),0,120),'image'=>(bool)($target['attachments']??[])];
}
