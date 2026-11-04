<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../../../vendor/autoload.php';
$application = require __DIR__.'/../../../bootstrap/app.php';
$application->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!app()->environment('staging', 'testing') || (app()->environment('staging') && \Illuminate\Support\Facades\DB::connection()->getDatabaseName() !== 'novinpay_stg38')) { fwrite(STDERR, "Isolated staging/testing required.\n"); exit(1); }
$bridge = app(\App\Support\Chat\ChatBridge::class);
$bridge->begin();
require_once __DIR__.'/../support/messages.php';
require_once __DIR__.'/../support/operators.php';
require_once __DIR__.'/../support/attachments.php';

try {
    // Fail-closed: Must be explicitly in development/test environment
    if(!support_development_ui()){
        support_failure(403,'development_environment_required');
    }

    $apply=false;
    $limit=500;
    $customRegistry=null;
    $singleConversation=null;

    foreach(array_slice($argv,1) as $arg){
        if($arg==='--apply')$apply=true;
        elseif($arg==='--dry-run')$apply=false;
        elseif(str_starts_with($arg,'--limit=')){
            $val=substr($arg,8);
            if(!preg_match('/^[0-9]{1,4}$/D',$val)||(int)$val<1||(int)$val>1000)support_failure(400,'invalid_arguments');
            $limit=(int)$val;
        }elseif(str_starts_with($arg,'--registry=')){
            $customRegistry=substr($arg,11);
        }elseif(str_starts_with($arg,'--conversation=')){
            $singleConversation=substr($arg,15);
            if(!support_valid_id($singleConversation))support_failure(400,'invalid_arguments');
        }else{
            support_failure(400,'invalid_arguments');
        }
    }

    // Determine the authoritative test registry file
    $registryPath=$customRegistry?:(getenv('SUPPORT_DEVELOPMENT_CONVERSATIONS_FILE')?:'');
    if($registryPath===''||!is_file($registryPath)){
        support_failure(503,'development_registry_unavailable');
    }

    $realRegistry=realpath($registryPath);
    if(!$realRegistry||is_link($registryPath)||filesize($realRegistry)>262144){
        support_failure(503,'development_registry_invalid');
    }

    // Verify registry belongs to this installation and directory is private
    $parent=support_private_dir(dirname($realRegistry));
    if(dirname($realRegistry)!==$parent){
        support_failure(503,'development_registry_invalid');
    }

    $regData=json_decode(file_get_contents($realRegistry),true,8,JSON_THROW_ON_ERROR);
    if(($regData['installation']??null)!==support_installation_id()||!is_array($regData['conversations']??null)||!array_is_list($regData['conversations'])){
        support_failure(503,'development_registry_invalid');
    }

    // Explicit test-owned conversation IDs
    $registeredList=$regData['conversations'];
    if($singleConversation!==null){
        if(!in_array($singleConversation,$registeredList,true)){
            support_failure(404,'conversation_not_registered_as_test');
        }
        $targetSet=[$singleConversation=>true];
    }else{
        $targetSet=array_fill_keys($registeredList,true);
    }

    $chatDir=support_private_dir(CHAT_DATA_DIR,false);
    $storePath=$chatDir.'/chat_conversations.json';
    if(!is_file($storePath))support_failure(404,'chat_store_not_found');

    $attachmentPruneNeeded=false;
    $report=support_store(function(array &$s,bool &$changed)use($apply,$limit,$storePath,$realRegistry,$regData,$registeredList,$targetSet,&$attachmentPruneNeeded){
        $toRemove=[];
        foreach($s['conversations'] as $id=>$c){
            if(isset($targetSet[$id])){
                // Safety guard: Never remove a conversation with real history (>=5 messages or human admin replies) unless explicitly marked development_test
                $hasAdmin=boolval(array_filter($c['messages']??[],fn($m)=>$m['type']==='admin'&&!($m['auto_reply']??false)));
                if((count($c['messages']??[])>=5||$hasAdmin)&&($c['development_test']??false)!==true){
                    continue;
                }
                $toRemove[]=$id;
                if(count($toRemove)>=$limit)break;
            }
        }

        $backupPath=null;

        if($apply&&count($toRemove)>0){
            $backupPath=$storePath.'.bak-'.date('YmdHis');
            if(!copy($storePath,$backupPath)){
                support_failure(503,'backup_failed');
            }

            foreach($toRemove as $id){
                unset($s['conversations'][$id]);
                if(isset($s['presence']['users'][$id])){
                    unset($s['presence']['users'][$id]);
                }
            }
            $changed=true;

            // Update the registry file atomically to reflect removed records
            $remainingRegistered=array_values(array_diff($registeredList,$toRemove));
            $regData['conversations']=$remainingRegistered;
            $newRegJson=json_encode($regData,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE);
            support_atomic_replace($realRegistry,$newRegJson);

            // Defer attachment prune outside this support_store transaction to avoid nested file lock deadlock
            $attachmentPruneNeeded=true;
        }

        return [
            'status'=>'success',
            'dry_run'=>!$apply,
            'registry_file'=>$realRegistry,
            'backup_created'=>$backupPath,
            'test_conversations_registered'=>count($registeredList),
            'test_conversations_found_in_store'=>count($toRemove),
            'test_conversations_removed'=>$apply?count($toRemove):0,
            'removed_ids'=>$toRemove,
            'active_conversations_remaining'=>$apply?count($s['conversations']):(count($s['conversations'])-count($toRemove)),
            'attachment_prune'=>null
        ];
    },$apply);

    if($attachmentPruneNeeded){
        $report['attachment_prune']=support_attachment_prune(true);
    }

    echo json_encode($report,JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";
} catch(Throwable $e) {
    echo json_encode([
        'status'=>'failed',
        'code'=>$e instanceof SupportFailure?$e->reason:'cleanup_failed',
        'message'=>'Private cleanup failed; preserve evidence.'
    ],JSON_THROW_ON_ERROR)."\n";
    exit(1);
}
