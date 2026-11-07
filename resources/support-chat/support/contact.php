<?php
declare(strict_types=1);
require_once __DIR__.'/phone.php';
// Unverified profile fields never participate in identity/recovery lookup.
function support_contact_fields(mixed $rawName,mixed $rawPhone): array {
    $name=support_string($rawName,200,true);$phone=support_string($rawPhone,80,true);
    if($name!==''&&(!preg_match('/^[\p{L}\p{M}\p{Zs}\x{200c}.\x{2019}\x{0027}-]+$/u',$name)||!preg_match('/\p{L}/u',$name)||mb_strlen($name)>80))support_failure(400,'contact_name_invalid');
    $name=trim((string)preg_replace('/\p{Zs}+/u',' ',$name));
    if($phone!=='')$phone=support_phone_normalize($phone);
    if($name===''&&$phone==='')support_failure(400,'contact_empty');
    return [$name,$phone];
}
