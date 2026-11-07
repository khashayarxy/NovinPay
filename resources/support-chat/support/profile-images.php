<?php
declare(strict_types=1);
require_once __DIR__.'/operators.php';
function support_profile_image_encode(array $file,bool $avatar): array {
    if(($file['error']??null)!==UPLOAD_ERR_OK||!is_string($file['tmp_name']??null)||!is_uploaded_file($file['tmp_name'])||!is_int($file['size']??null)||$file['size']<1||$file['size']>2097152)support_failure(400,'invalid_brand_image');
    $raw=file_get_contents($file['tmp_name']);$info=@getimagesizefromstring($raw);if(!$info||!in_array($info['mime']??'', ['image/jpeg','image/png','image/webp'],true)||$info[0]<1||$info[1]<1||$info[0]>4096||$info[1]>4096||$info[0]*$info[1]>4194304)support_failure(400,'invalid_brand_image');
    $image=@imagecreatefromstring($raw);unset($raw);if(!$image)support_failure(400,'invalid_brand_image');$output=null;$crop=null;
    try{if($avatar){$side=min($info[0],$info[1]);$crop=imagecrop($image,['x'=>intdiv($info[0]-$side,2),'y'=>intdiv($info[1]-$side,2),'width'=>$side,'height'=>$side]);if(!$crop)support_failure(400,'invalid_brand_image');$output=imagescale($crop,192,192,IMG_BICUBIC_FIXED);}
        else{$scale=min(1,384/$info[0],384/$info[1]);$output=imagescale($image,max(1,(int)round($info[0]*$scale)),max(1,(int)round($info[1]*$scale)),IMG_BICUBIC_FIXED);}if(!$output)support_failure(400,'invalid_brand_image');imagepalettetotruecolor($output);imagealphablending($output,false);imagesavealpha($output,true);ob_start();$ok=imagewebp($output,null,82);$bytes=ob_get_clean();if(!$ok||!is_string($bytes)||strlen($bytes)<1||strlen($bytes)>262144)support_failure(400,'invalid_brand_image');return ['binary'=>$bytes,'meta'=>['id'=>bin2hex(random_bytes(16)),'sha256'=>hash('sha256',$bytes),'bytes'=>strlen($bytes),'width'=>imagesx($output),'height'=>imagesy($output)]];
    }finally{if($output)imagedestroy($output);if($crop)imagedestroy($crop);imagedestroy($image);}
}
