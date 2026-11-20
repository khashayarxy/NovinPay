param(
 [Parameter(Mandatory)][string]$ManifestPath,
 [Parameter(Mandatory)][string]$AccessHelper,
 [string]$UploadHelper,
 [Parameter(Mandatory)][string]$PrivateRoot,
 [Parameter(Mandatory)][string]$StatusPath,
 [ValidateRange(1,24)][int]$Hours=24,
 [switch]$QuotaOnly,
 [switch]$PublishScheduledProof
)
$ErrorActionPreference='Stop'
$m=Get-Content -LiteralPath $ManifestPath -Raw|ConvertFrom-Json
$pin=(Get-FileHash -LiteralPath $ManifestPath).Hash.ToLowerInvariant()
$package='/home/novinpay/novinpay-release-pipeline-v1'
$legacyScope=!$QuotaOnly -and $m.stateDirectory -ceq "$package/pipeline-pr12" -and $m.backup.directory -ceq '/home/novinpay/novinpay-private-backups/maintenance-pr12-v2'
$pr13Scope=$QuotaOnly -and $m.stateDirectory -ceq "$package/pipeline-pr13-r2" -and $m.backup.directory -ceq '/home/novinpay/novinpay-private-backups/maintenance-pr13-v1' -and $m.backup.restoreDatabase -ceq 'novinpay_restore13r1' -and $m.baseline -ceq '1f39bb476fd31880600564e58599c4488622c4c0'
$pr13Activation=$QuotaOnly -and $m.mode -ceq 'pr13-migration-activation-v1' -and $m.stateDirectory -ceq "$package/pipeline-pr13-activation-r4" -and $m.backup.directory -ceq '/home/novinpay/novinpay-private-backups/maintenance-pr13-v1' -and $m.backup.restoreDatabase -ceq 'novinpay_restore13r1' -and $m.baseline -ceq '1f39bb476fd31880600564e58599c4488622c4c0' -and $m.candidate -ceq 'ded4e16302770f28f7bfe8e567e9a2de2314f99d'
$modernScope=$QuotaOnly -and $m.format -cin @('novinpay-recovery-v1','novinpay-source-release-v1','novinpay-application-release-v1','novinpay-runtime-release-v1') -and $m.stateDirectory -ceq '/home/novinpay/novinpay-direct-pr13' -and $m.backup.directory -cmatch '^/home/novinpay/novinpay-private-backups/recovery-[a-z0-9-]{1,60}$' -and $m.backup.restoreDatabase -cmatch '^novinpay_restore[a-z0-9]+$' -and $m.baseline -cmatch '^[a-f0-9]{40}$' -and $m.target -cmatch '^[a-f0-9]{40}$'
$recoveryScope=$modernScope -and $m.format -ceq 'novinpay-recovery-v1'
$sourceScope=$modernScope -and $m.format -cin @('novinpay-source-release-v1','novinpay-application-release-v1','novinpay-runtime-release-v1') -and $m.releaseName -cmatch '^[a-z0-9-]{1,64}$'
if(!$legacyScope -and !$pr13Scope -and !$pr13Activation -and !$recoveryScope -and !$sourceScope){throw 'Bridge manifest scope mismatch'}
if($PublishScheduledProof -and !$recoveryScope){throw 'Scheduled proof publication requires recovery scope'}
if($modernScope){$package=$m.stateDirectory}
if($null -ne $m.quotaProtocol -and (($m.quotaProtocol -isnot [long] -and $m.quotaProtocol -isnot [int]) -or $m.quotaProtocol -notin @(1,2))){
 throw 'quota_protocol_version'
}
$quotaV2=$m.quotaProtocol -ceq 2
if($quotaV2 -and !$modernScope){throw 'Quota protocol 2 requires a modern private manifest'}
. (Join-Path $PSScriptRoot 'quota-bridge.ps1')
$quotaDirectory=if($quotaV2){"$package/quota-v2-$pin"}else{$package}
$deadline=[DateTimeOffset]::UtcNow.AddHours($Hours)
function Write-BridgeStatus($state){
 $tmp=$StatusPath+'.'+[guid]::NewGuid().ToString('N')+'.tmp'
 [IO.File]::WriteAllText($tmp,($state|ConvertTo-Json),[Text.UTF8Encoding]::new($false))
 [IO.File]::Move($tmp,$StatusPath,$true)
}
function Status([string]$state){Write-BridgeStatus @{result=$state;pid=$PID;atUtc=[DateTimeOffset]::UtcNow.ToString('o');manifestSha256=$pin;quotaProtocol=if($quotaV2){2}else{1}}}
function UploadNewPrivate([string]$name,[byte[]]$bytes,[string]$directory) {
 $response=$quotaV2 -and $directory -ceq $quotaDirectory -and $name -cmatch '^quota-[a-f0-9]{32}-response\.json$'
 $legacy=$directory -ceq '/home/novinpay/novinpay-direct-pr13' -and $name -cmatch '^quota-[a-f0-9]{32}-(reply|ready)\.json$'
 $scheduled=$PublishScheduledProof -and $directory -ceq $package -and $name -ceq "scheduled-$pin-offhost.json"
 if((!$response -and !$legacy -and !$scheduled) -or $bytes.Length -lt 1 -or $bytes.Length -gt 65536){throw 'Quota upload outside scope'}
 if(@(Get-Entries $directory|Where-Object file -ceq $name).Count){throw 'Existing quota evidence preserved'}
 $hash=[Convert]::ToHexString([Security.Cryptography.SHA256]::HashData($bytes)).ToLowerInvariant()
 $form=[Net.Http.MultipartFormDataContent]::new();$form.Add([Net.Http.StringContent]::new($directory),'dir');$part=[Net.Http.ByteArrayContent]::new($bytes);$part.Headers.ContentType=[Net.Http.Headers.MediaTypeHeaderValue]::new('application/octet-stream');$form.Add($part,'file-1',$name);$part.Headers.ContentDisposition.Name='"file-1"';$part.Headers.ContentDisposition.FileName='"'+$name+'"';$part.Headers.ContentDisposition.FileNameStar=$null
 $req=[Net.Http.HttpRequestMessage]::new([Net.Http.HttpMethod]::Post,('https://leda.asemanhost.com:2083/execute/Fileman/upload_files?dir='+[uri]::EscapeDataString($directory)+'&overwrite=0&permissions=0600'));[void]$req.Headers.TryAddWithoutValidation('Authorization','cpanel novinpay:'+[Runtime.InteropServices.Marshal]::PtrToStringBSTR($tokenPointer));$req.Content=$form
 try {$res=$httpClient.SendAsync($req).GetAwaiter().GetResult();try {if(!$res.IsSuccessStatusCode){throw ('cPanel HTTP failure '+[int]$res.StatusCode)};$r=$res.Content.ReadAsStringAsync().GetAwaiter().GetResult()|ConvertFrom-Json;$r=if($r.result){$r.result}else{$r};if($r.status -ne 1 -or $r.data.succeeded -ne 1 -or $r.data.failed -ne 0){throw 'quota_upload_ambiguous'}}finally{$res.Dispose()}}finally{$req.Dispose()}
 $meta=Invoke-Cpanel 'Fileman/get_file_information' @{path=$directory+'/'+$name;show_hidden=1;include_permissions=1}
 if($meta.type -ne 'file' -or [long]$meta.size -ne $bytes.Length -or $meta.nicemode.TrimStart('0') -cne '600' -or (Get-TextHash (Read-HostText ($directory+'/'+$name))) -cne $hash){throw 'Quota proof readback failed'}
 return $hash
}

function OpenAccess {
 . $AccessHelper
 if($modernScope){return}
 $tokens=$null;$parseErrors=$null
 $ast=[Management.Automation.Language.Parser]::ParseFile($UploadHelper,[ref]$tokens,[ref]$parseErrors)
 if($parseErrors.Count){throw 'Upload helper syntax'}
 $fn=$ast.Find({param($n)$n -is [Management.Automation.Language.FunctionDefinitionAst] -and $n.Name -eq 'UploadNewPrivate'},$true)
 . ([scriptblock]::Create($fn.Extent.Text))
 # Dot sourcing this function publishes the helpers into the bridge's own scope.
}
function EnsurePrivate([string]$name,[byte[]]$bytes,[string]$directory) {
 $found=@(Get-Entries $directory|Where-Object file -ceq $name)
 if(!$found.Count){return UploadNewPrivate $name $bytes $directory}
 if($found.Count -ne 1 -or $found[0].type -ne 'file' -or $found[0].nicemode -ne '0600' -or [long]$found[0].size -ne $bytes.Length){throw 'Existing proof metadata differs'}
 $expected=[Convert]::ToHexString([Security.Cryptography.SHA256]::HashData($bytes)).ToLowerInvariant()
 if((Get-TextHash (Read-HostText ($directory+'/'+$name))) -cne $expected){throw 'Existing proof bytes differ'}
 return $expected
}
function Get-AuthenticatedQuota {
 for($attempt=0;$attempt -lt 3;$attempt++){
  $failureCode='quota_transport_failure'
  $res=$null
  $cancel=[Threading.CancellationTokenSource]::new()
  $cancel.CancelAfter(15000)
  $req=[Net.Http.HttpRequestMessage]::new([Net.Http.HttpMethod]::Post,'https://leda.asemanhost.com:2083/execute/Quota/get_quota_info')
  [void]$req.Headers.TryAddWithoutValidation('Authorization','cpanel novinpay:'+[Runtime.InteropServices.Marshal]::PtrToStringBSTR($tokenPointer))
  $req.Content=[Net.Http.FormUrlEncodedContent]::new([Collections.Generic.Dictionary[string,string]]::new())
  $transient=$false
  try{
   try{$res=$httpClient.SendAsync($req,$cancel.Token).GetAwaiter().GetResult()}
   catch [Net.Http.HttpRequestException]{$transient=$true}
   catch [Threading.Tasks.TaskCanceledException]{$transient=$true}
   if($res){
    try{
     $status=[int]$res.StatusCode
     $transient=$status -in @(408,429) -or $status -ge 500
     if($transient){$failureCode='cPanel HTTP failure '+$status}
     if(!$transient){
      if(!$res.IsSuccessStatusCode){throw ('cPanel HTTP failure '+[int]$res.StatusCode)}
      if(!$res.Headers.Date){throw 'quota_server_clock_missing'}
      $server=$res.Headers.Date.ToUnixTimeSeconds()
      if([Math]::Abs($server-[DateTimeOffset]::UtcNow.ToUnixTimeSeconds()) -gt 5){throw 'Quota clock mismatch'}
      $j=$res.Content.ReadAsStringAsync($cancel.Token).GetAwaiter().GetResult()|ConvertFrom-Json
      $v=if($j.result){$j.result}else{$j}
      if($v.status -ne 1 -or [string]$v.data.under_quota_overall -ne '1'){throw 'Quota API rejected'}
      return @{Value=$v.data;ServerUnix=$server}
     }
    }finally{$res.Dispose();$res=$null}
   }
  }catch [Threading.Tasks.TaskCanceledException]{$transient=$true}
  finally{$req.Dispose();$cancel.Dispose()}
  if(!$transient -or $attempt -eq 2){throw $failureCode}
  Wait-QuotaBackoff $attempt
 }
}
function Read-QuotaEntries([string]$directory) {
 for($attempt=0;$attempt -lt 3;$attempt++){
  try{return @(Get-Entries $directory)}
  catch{
   $base=$_.Exception.GetBaseException()
   $transport=$base -is [Net.Http.HttpRequestException] -or $base -is [Threading.Tasks.TaskCanceledException]
   $serverTransient=$_.Exception.Message -match '^cPanel HTTP failure (429|5[0-9]{2})$'
   if((!$transport -and !$serverTransient) -or $attempt -eq 2){throw}
   Start-Sleep -Milliseconds (500 * [Math]::Pow(2,$attempt))
  }
 }
}
$runtimeQuotaResponses=0
$opened=$false;$done=@{};$check='open_authenticated_access'
$outboxLock=$null
$published=@{}
$lastGoodPoll=[DateTimeOffset]::UtcNow
$pollDegraded=$false
try {
 . OpenAccess; $opened=$true
 if($quotaV2){
  $httpClient.Timeout=[TimeSpan]::FromSeconds(8)
  $check='quota_mailbox_identity'
  $mailbox=Invoke-QuotaRead { Invoke-Cpanel 'Fileman/get_file_information' @{path=$quotaDirectory;show_hidden=1;include_permissions=1} }
  if($mailbox.type -cne 'dir' -or $mailbox.nicemode -cne '0700'){throw 'quota_mailbox_metadata'}
  $allowed=@([Security.Principal.WindowsIdentity]::GetCurrent().User.Value,'S-1-5-18','S-1-5-32-544')
  if((Get-Item -LiteralPath $PrivateRoot).Attributes.HasFlag([IO.FileAttributes]::ReparsePoint) -or
     @((Get-Acl -LiteralPath $PrivateRoot).Access|Where-Object {$_.AccessControlType -eq 'Allow' -and $_.IdentityReference.Translate([Security.Principal.SecurityIdentifier]).Value -notin $allowed}).Count){throw 'quota_outbox_permissions'}
  $outboxLock=[IO.File]::Open((Join-Path $PrivateRoot "quota-$pin.lock"),[IO.FileMode]::OpenOrCreate,[IO.FileAccess]::ReadWrite,[IO.FileShare]::None)
 }
 Status 'READY'
 while([DateTimeOffset]::UtcNow -lt $deadline){
  if($quotaV2){
   $check='read_quota_mailbox'
   try{
    $entries=@(Invoke-QuotaRead { Get-Entries $quotaDirectory })
   }catch{
    if(!(Test-QuotaTransient $_) -or ([DateTimeOffset]::UtcNow-$lastGoodPoll).TotalSeconds -ge 90){throw}
    if(!$pollDegraded){Status 'QUOTA_MAILBOX_DEGRADED';$pollDegraded=$true}
    Wait-QuotaBackoff 4
    continue
   }
   $lastGoodPoll=[DateTimeOffset]::UtcNow
   if($pollDegraded){Status 'READY';$pollDegraded=$false}
   foreach($entry in $entries | Where-Object file -cmatch '^quota-[a-f0-9]{32}-request\.json$'){
    $base=$entry.file.Substring(0,$entry.file.Length-13)
    if($done.ContainsKey($base)){continue}
    if($published.ContainsKey($base) -and $entries.file -cnotcontains "$base-ack.json"){continue}
    $check='validate_quota_request'
    if($entry.type -cne 'file' -or $entry.nicemode -cne '0600' -or [long]$entry.size -gt 2048){throw 'quota_request_metadata'}
    $raw=Invoke-QuotaRead {Read-HostText "$quotaDirectory/$($entry.file)"}
    if([Text.Encoding]::UTF8.GetByteCount($raw) -ne [long]$entry.size){throw 'quota_request_changed'}
    $requestPin=Get-QuotaDigest ([Text.Encoding]::UTF8.GetBytes($raw))
    $now=[DateTimeOffset]::UtcNow.ToUnixTimeSeconds()
    $q=Get-QuotaV2Request $raw $base $m $pin $now
    $ack=$null
    if($entries.file -ccontains "$base-ack.json"){
     $check='validate_quota_acknowledgment'
     $ack=Read-QuotaAcknowledgment (Read-QuotaPrivate $quotaDirectory "$base-ack.json" 2048) $q $requestPin
    }
    if($ack){
     $done[$base]=$true
     if($ack.result -ceq 'accepted'){
      $responseRaw=Read-QuotaPrivate $quotaDirectory "$base-response.json" 8192
      if($null -eq $responseRaw -or (Get-QuotaDigest ([Text.Encoding]::UTF8.GetBytes($responseRaw))) -cne $ack.responseSha256){throw 'quota_ack_response_identity'}
      if($sourceScope -and $q.phase -ceq 'fence'){
       $runtimeQuotaResponses++
       if($m.format -cne 'novinpay-runtime-release-v1' -or $runtimeQuotaResponses -ge 2){Status 'ACTIVATION_QUOTA_PASS';exit 0}
      }
     }else{Status ('QUOTA_'+$ack.result.ToUpperInvariant())}
     continue
    }
    if($now -ge $q.expiresAtUnix){$done[$base]=$true;continue}
    $check='quota_durable_outbox'
    $outbox=Join-Path $PrivateRoot "$pin-$($q.nonce).response.json"
    [byte[]]$envelope=Get-QuotaOutbox $outbox {Get-QuotaV2Envelope $q $requestPin (Get-AuthenticatedQuota) ($m.format -ceq 'novinpay-runtime-release-v1')}
    Assert-QuotaOutboxBinding $envelope $q $requestPin ([DateTimeOffset]::UtcNow.ToUnixTimeSeconds())
    $check='quota_envelope_publication'
    if($entries.file -cnotcontains "$base-response.json"){Publish-QuotaEnvelope $quotaDirectory "$base-response.json" $envelope $q.expiresAtUnix;Status 'QUOTA_RESPONSE_PUBLISHED'}
    else{
     $published=Read-QuotaPrivate $quotaDirectory "$base-response.json" 8192
     if((Get-QuotaDigest ([Text.Encoding]::UTF8.GetBytes($published))) -cne (Get-QuotaDigest $envelope)){throw 'quota_remote_envelope_conflict'}
    }
    $published[$base]=$true
   }
  }else{
  $check='read_quota_requests';$entries=@(Read-QuotaEntries $package)
  foreach($entry in $entries|Where-Object file -cmatch '^quota-[a-f0-9]{32}-request.json$'){
   $base=$entry.file.Substring(0,$entry.file.Length-13)
   if($done.ContainsKey($base)){continue}
   if($entry.type -ne 'file' -or $entry.nicemode -ne '0600' -or [long]$entry.size -gt 2048){throw 'Quota request metadata'}
   $q=Read-HostText ($package+'/'+$entry.file)|ConvertFrom-Json
   if($q.manifestSha256 -cne $pin){continue}
   $now=[DateTimeOffset]::UtcNow.ToUnixTimeSeconds()
   if($q.sourceCommit -cne $m.baseline -or $q.targetCommit -cne $m.target -or $q.phase -notin @('backup','fence') -or $q.nonce -cne $base.Substring(6)){throw 'Quota request identity'}
   # Old abandoned requests are evidence, not an instruction to issue stale replies.
   if($now-[long]$q.createdAtUnix -gt 180){$done[$base]=$true;continue}
   if([long]$q.createdAtUnix -gt $now+5){throw 'Quota request clock'}
   if(@($entries|Where-Object file -ceq ($base+'-ready.json')).Count){$done[$base]=$true;continue}
   $check='authenticated_quota_request'
   $observed=Get-AuthenticatedQuota
   $v=$observed.Value
   $server=$observed.ServerUnix
   $free=[long][Math]::Floor([double]$v.megabytes_remain*1048576)
   $used=[long][Math]::Floor([double]$v.megabytes_used*1048576)
   $limit=[long][Math]::Floor([double]$v.megabyte_limit*1048576)
   if($q.requiredFreeBytes -lt 805306368 -or $free -lt [long]$q.requiredFreeBytes+2147483648 -or $used -lt 0 -or $free+$used -gt $limit+1048576){throw 'Quota reserve insufficient'}
   $proof=@{account='novinpay';apiHost='leda.asemanhost.com:2083';method='Quota/get_quota_info';httpsCertificateValidated=$true;underQuota=$true;sourceCommit=$m.baseline;nonce=$q.nonce;phase=$q.phase;manifestSha256=$pin;observedAtUnix=$server;freeBytes=$free;usedBytes=$used;limitBytes=$limit}
   if($m.format -ceq 'novinpay-runtime-release-v1'){
    if($null -eq $v.inode_limit -or $null -eq $v.inodes_remain -or [string]$v.under_inode_limit -cne '1'){throw 'Explicit inode quota evidence unavailable'}
    $inodeLimit=[long]$v.inode_limit;$inodeRemaining=[long]$v.inodes_remain
    if($inodeLimit -lt 0 -or $inodeRemaining -lt 0){throw 'Invalid inode quota evidence'}
    $proof.inodeLimit=$inodeLimit;$proof.inodesRemaining=$inodeRemaining;$proof.underInodeQuota=$true;$proof.inodeUnlimited=($inodeLimit -eq 0)
   }
   $bytes=[Text.Encoding]::UTF8.GetBytes(($proof|ConvertTo-Json -Compress))
   if(@($entries|Where-Object file -ceq ($base+'-reply.json')).Count){$done[$base]=$true;continue}
   $check='quota_reply_upload';$hash=UploadNewPrivate ($base+'-reply.json') $bytes $package
   $null=UploadNewPrivate ($base+'-ready.json') ([Text.Encoding]::UTF8.GetBytes((@{sha256=$hash}|ConvertTo-Json -Compress))) $package
   $done[$base]=$true;Status ('QUOTA_'+$q.phase.ToUpperInvariant())
   if($sourceScope -and $q.phase -ceq 'fence'){
    $runtimeQuotaResponses++
    if($m.format -cne 'novinpay-runtime-release-v1' -or $runtimeQuotaResponses -ge 2){Status 'ACTIVATION_QUOTA_PASS';exit 0}
   }
  }
  }
  if($QuotaOnly){
   if($recoveryScope -and @(Invoke-QuotaRead {Get-Entries '/home/novinpay/novinpay-private-backups'}|Where-Object file -ceq ([IO.Path]::GetFileName($m.backup.directory))).Count){
    if(@(Invoke-QuotaRead {Get-Entries $m.backup.directory}|Where-Object file -ceq 'receipt.json').Count){
     $receipt=Invoke-QuotaRead {Read-HostText ($m.backup.directory+'/receipt.json')}|ConvertFrom-Json
     if($receipt.result -cne 'BACKUP_AND_DISPOSABLE_RESTORE_PASS' -or $receipt.sourceCommit -cne $m.baseline -or $receipt.restoreDatabase -cne $m.backup.restoreDatabase -or $receipt.migrations -ne $m.migrations.Count -or $receipt.triggers -ne $m.expectedTriggers){throw 'Recovery receipt identity mismatch'}
     $check='independent_offhost_verification'
     Close-CpanelAccess;$opened=$false
     if(!(Test-Path -LiteralPath (Join-Path $PrivateRoot 'OFFHOST-PROOF.json'))){
      & (Join-Path $PSScriptRoot 'transfer.ps1') -PrivateRoot $PrivateRoot -ExpectedArchiveSha256 $receipt.archiveSha256 -TransferAuthorized -AccessHelper $AccessHelper -RecoveryManifest $ManifestPath -ExpectedManifestSha256 $pin
     }
     $verified=Get-Content -LiteralPath (Join-Path $PrivateRoot 'OFFHOST-PROOF.json') -Raw|ConvertFrom-Json
     if($verified.archiveSha256 -cne $receipt.archiveSha256 -or $verified.sourceCommit -cne $m.baseline -or $verified.restoreDatabase -cne $m.backup.restoreDatabase -or $verified.migrations -ne $m.migrations.Count -or $verified.triggers -ne $m.expectedTriggers -or !$verified.authenticatedDecryption -or !$verified.isolatedRestoreVerified){throw 'Independent recovery evidence mismatch'}
     if($PublishScheduledProof){
      if($verified.result -cne 'INDEPENDENT_OFFHOST_RECOVERY_PASS' -or $verified.syntheticFixture -ne $false -or
         !$verified.archiveContentsVerified -or !$verified.databaseDumpRowDigestMatches -or !$verified.recoveryKeyAvailable -or
         [long]$verified.snapshotUnix -ne [long]$receipt.snapshotUnix -or $verified.serverArchivePath -cne ($m.backup.directory+'/backup.npb')){throw 'Scheduled proof identity mismatch'}
      $check='publish_scheduled_recovery_proof'
      . $AccessHelper
      $opened=$true
      $null=EnsurePrivate "scheduled-$pin-offhost.json" ([IO.File]::ReadAllBytes((Join-Path $PrivateRoot 'OFFHOST-PROOF.json'))) $package
     }
     Status 'DATABASE_RECOVERY_PASS';exit 0
    }
   }
   if($pr13Activation -and @($entries|Where-Object {$_.file -ceq 'pipeline-pr13-activation-r4' -and $_.type -ceq 'dir'}).Count){
    $journal=@(Get-Entries $m.stateDirectory)
    if(@($journal|Where-Object file -ceq 'activation.complete.json').Count){Status 'ACTIVATION_COMPLETE';exit 0}
    if(@($journal|Where-Object file -ceq 'activation.failed.json').Count){Status 'ACTIVATION_STOP_PRESERVED';exit 75}
   }
   if($pr13Scope -and @($entries|Where-Object {$_.file -ceq 'pipeline-pr13-r2' -and $_.type -ceq 'dir'}).Count){
    if(@(Get-Entries $m.stateDirectory|Where-Object file -ceq 'backup.complete.json').Count){Status 'BACKUP_COMPLETE_TRANSFER_PENDING';exit 0}
   }
   Start-Sleep -Seconds 5;continue
  }
  $dirs=@(Get-Entries '/home/novinpay/novinpay-private-backups')
  if(@($dirs|Where-Object file -ceq 'maintenance-pr12-v2').Count){
   $files=@(Get-Entries $m.backup.directory)
   if(@($files|Where-Object file -ceq 'receipt.json').Count){
    $r=Read-HostText ($m.backup.directory+'/receipt.json')|ConvertFrom-Json
    if($r.result -cne 'BACKUP_AND_DISPOSABLE_RESTORE_PASS' -or $r.sourceCommit -cne $m.baseline){throw 'Backup receipt not PASS'}
    $stateEntries=@(Get-Entries $m.stateDirectory)
    if(!@($stateEntries|Where-Object file -ceq 'offhost-ready.json').Count){
     $check='independent_offhost_transfer_and_decryption';Close-CpanelAccess;$opened=$false
     if(!(Test-Path -LiteralPath (Join-Path $PrivateRoot 'OFFHOST-PROOF.json'))){
      & (Join-Path $PSScriptRoot 'transfer.ps1') -PrivateRoot $PrivateRoot -ExpectedArchiveSha256 $r.archiveSha256 -TransferAuthorized -AccessHelper $AccessHelper | Out-Null
     }
     $local=Get-Content -LiteralPath (Join-Path $PrivateRoot 'OFFHOST-PROOF.json') -Raw|ConvertFrom-Json
     if($local.archiveSha256 -cne $r.archiveSha256 -or !$local.authenticatedDecryption -or (Get-FileHash (Join-Path $PrivateRoot 'backup.npb')).Hash.ToLowerInvariant() -cne $r.archiveSha256){throw 'Existing independent proof mismatch'}
     . OpenAccess;$opened=$true
     $bytes=[IO.File]::ReadAllBytes((Join-Path $PrivateRoot 'OFFHOST-PROOF.json'))
     $check='offhost_proof_upload';$hash=EnsurePrivate 'offhost-proof.json' $bytes $m.stateDirectory
     $null=EnsurePrivate 'offhost-ready.json' ([Text.Encoding]::UTF8.GetBytes((@{sha256=$hash;manifestSha256=$pin}|ConvertTo-Json -Compress))) $m.stateDirectory
     Status 'OFFHOST_VERIFIED'
    }
    if(@(Get-Entries $m.stateDirectory|Where-Object file -ceq 'activation.complete.json').Count){Status 'COMPLETE';exit 0}
   }
  }
  Start-Sleep -Seconds 5
 }
 Status 'IDLE_WINDOW_ENDED';exit 75
}catch{
 # Exception strings may contain API bodies: never serialize them.
 $httpStatus=$null
 if($_.Exception.Message -match '^cPanel HTTP failure ([0-9]{3})$'){$httpStatus=[int]$Matches[1]}
 Write-BridgeStatus @{result='STOP';check=$check;category=$_.Exception.GetBaseException().GetType().Name;httpStatus=$httpStatus;manifestSha256=$pin;productionModified=$false;retrySafe=$false;nextAction='Inspect the named bridge operation and server receipts; do not repeat backup or activation blindly.'};exit 1
}finally{if($outboxLock){$outboxLock.Dispose()};if($opened){Close-CpanelAccess}}
