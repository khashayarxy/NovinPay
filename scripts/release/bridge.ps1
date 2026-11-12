param(
 [Parameter(Mandatory)][string]$ManifestPath,
 [Parameter(Mandatory)][string]$AccessHelper,
 [string]$UploadHelper,
 [Parameter(Mandatory)][string]$PrivateRoot,
 [Parameter(Mandatory)][string]$StatusPath,
 [ValidateRange(1,24)][int]$Hours=24,
 [switch]$QuotaOnly
)
$ErrorActionPreference='Stop'
$m=Get-Content -LiteralPath $ManifestPath -Raw|ConvertFrom-Json
$pin=(Get-FileHash -LiteralPath $ManifestPath).Hash.ToLowerInvariant()
$package='/home/novinpay/novinpay-release-pipeline-v1'
$legacyScope=!$QuotaOnly -and $m.stateDirectory -ceq "$package/pipeline-pr12" -and $m.backup.directory -ceq '/home/novinpay/novinpay-private-backups/maintenance-pr12-v2'
$pr13Scope=$QuotaOnly -and $m.stateDirectory -ceq "$package/pipeline-pr13-r2" -and $m.backup.directory -ceq '/home/novinpay/novinpay-private-backups/maintenance-pr13-v1' -and $m.backup.restoreDatabase -ceq 'novinpay_restore13r1' -and $m.baseline -ceq '1f39bb476fd31880600564e58599c4488622c4c0'
$pr13Activation=$QuotaOnly -and $m.mode -ceq 'pr13-migration-activation-v1' -and $m.stateDirectory -ceq "$package/pipeline-pr13-activation-r4" -and $m.backup.directory -ceq '/home/novinpay/novinpay-private-backups/maintenance-pr13-v1' -and $m.backup.restoreDatabase -ceq 'novinpay_restore13r1' -and $m.baseline -ceq '1f39bb476fd31880600564e58599c4488622c4c0' -and $m.candidate -ceq 'ded4e16302770f28f7bfe8e567e9a2de2314f99d'
$recoveryScope=$QuotaOnly -and $m.format -ceq 'novinpay-recovery-v1' -and $m.stateDirectory -ceq '/home/novinpay/novinpay-direct-pr13' -and $m.backup.directory -cmatch '^/home/novinpay/novinpay-private-backups/recovery-[a-z0-9-]{1,60}$' -and $m.backup.restoreDatabase -cmatch '^novinpay_restore[a-z0-9]+$' -and $m.baseline -cmatch '^[a-f0-9]{40}$' -and $m.target -cmatch '^[a-f0-9]{40}$'
if(!$legacyScope -and !$pr13Scope -and !$pr13Activation -and !$recoveryScope){throw 'Bridge manifest scope mismatch'}
if($recoveryScope){$package=$m.stateDirectory}
$deadline=[DateTimeOffset]::UtcNow.AddHours($Hours)
function Status([string]$state){[IO.File]::WriteAllText($StatusPath,(@{result=$state;pid=$PID;atUtc=[DateTimeOffset]::UtcNow.ToString('o');manifestSha256=$pin}|ConvertTo-Json),[Text.UTF8Encoding]::new($false))}
function UploadNewPrivate([string]$name,[byte[]]$bytes,[string]$directory) {
 if($directory -cne '/home/novinpay/novinpay-direct-pr13' -or $name -cnotmatch '^quota-[a-f0-9]{32}-(reply|ready)\.json$' -or $bytes.Length -lt 1 -or $bytes.Length -gt 65536){throw 'Quota upload outside scope'}
 if(@(Get-Entries $directory|Where-Object file -ceq $name).Count){throw 'Existing quota evidence preserved'}
 $hash=[Convert]::ToHexString([Security.Cryptography.SHA256]::HashData($bytes)).ToLowerInvariant()
 $form=[Net.Http.MultipartFormDataContent]::new();$form.Add([Net.Http.StringContent]::new($directory),'dir');$part=[Net.Http.ByteArrayContent]::new($bytes);$part.Headers.ContentType=[Net.Http.Headers.MediaTypeHeaderValue]::new('application/octet-stream');$form.Add($part,'file-1',$name);$part.Headers.ContentDisposition.Name='"file-1"';$part.Headers.ContentDisposition.FileName='"'+$name+'"';$part.Headers.ContentDisposition.FileNameStar=$null
 $req=[Net.Http.HttpRequestMessage]::new([Net.Http.HttpMethod]::Post,('https://leda.asemanhost.com:2083/execute/Fileman/upload_files?dir='+[uri]::EscapeDataString($directory)+'&overwrite=0&permissions=0600'));[void]$req.Headers.TryAddWithoutValidation('Authorization','cpanel novinpay:'+[Runtime.InteropServices.Marshal]::PtrToStringBSTR($tokenPointer));$req.Content=$form
 try {$res=$httpClient.SendAsync($req).GetAwaiter().GetResult();try {if(!$res.IsSuccessStatusCode){throw 'Quota proof upload HTTP failure'};$r=$res.Content.ReadAsStringAsync().GetAwaiter().GetResult()|ConvertFrom-Json;$r=if($r.result){$r.result}else{$r};if($r.status -ne 1 -or $r.data.succeeded -ne 1 -or $r.data.failed -ne 0){throw 'Ambiguous quota upload; reconcile before retry'}}finally{$res.Dispose()}}finally{$req.Dispose()}
 $meta=Invoke-Cpanel 'Fileman/get_file_information' @{path=$directory+'/'+$name;show_hidden=1;include_permissions=1}
 if($meta.type -ne 'file' -or [long]$meta.size -ne $bytes.Length -or $meta.nicemode.TrimStart('0') -cne '600' -or (Get-TextHash (Read-HostText ($directory+'/'+$name))) -cne $hash){throw 'Quota proof readback failed'}
 return $hash
}

function OpenAccess {
 . $AccessHelper
 if($recoveryScope){return}
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
     $transient=$status -eq 429 -or $status -ge 500
     if(!$transient){
      if(!$res.IsSuccessStatusCode -or !$res.Headers.Date){throw 'Authenticated quota rejected'}
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
  if(!$transient -or $attempt -eq 2){throw 'Quota API temporarily unavailable; no capture started'}
  Start-Sleep -Milliseconds (500 * [Math]::Pow(2,$attempt))
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
$opened=$false;$done=@{};$check='open_authenticated_access'
try {
 . OpenAccess; $opened=$true
 Status 'READY'
 while([DateTimeOffset]::UtcNow -lt $deadline){
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
   $bytes=[Text.Encoding]::UTF8.GetBytes(($proof|ConvertTo-Json -Compress))
   if(@($entries|Where-Object file -ceq ($base+'-reply.json')).Count){$done[$base]=$true;continue}
   $check='quota_reply_upload';$hash=UploadNewPrivate ($base+'-reply.json') $bytes $package
   $null=UploadNewPrivate ($base+'-ready.json') ([Text.Encoding]::UTF8.GetBytes((@{sha256=$hash}|ConvertTo-Json -Compress))) $package
   $done[$base]=$true;Status ('QUOTA_'+$q.phase.ToUpperInvariant())
  }
  if($QuotaOnly){
   if($recoveryScope -and @(Get-Entries '/home/novinpay/novinpay-private-backups'|Where-Object file -ceq ([IO.Path]::GetFileName($m.backup.directory))).Count){
    if(@(Get-Entries $m.backup.directory|Where-Object file -ceq 'receipt.json').Count){
     $receipt=Read-HostText ($m.backup.directory+'/receipt.json')|ConvertFrom-Json
     if($receipt.result -cne 'BACKUP_AND_DISPOSABLE_RESTORE_PASS' -or $receipt.sourceCommit -cne $m.baseline -or $receipt.restoreDatabase -cne $m.backup.restoreDatabase -or $receipt.migrations -ne $m.migrations.Count -or $receipt.triggers -ne $m.expectedTriggers){throw 'Recovery receipt identity mismatch'}
     $check='independent_offhost_verification'
     Close-CpanelAccess;$opened=$false
     if(!(Test-Path -LiteralPath (Join-Path $PrivateRoot 'OFFHOST-PROOF.json'))){
      & (Join-Path $PSScriptRoot 'transfer.ps1') -PrivateRoot $PrivateRoot -ExpectedArchiveSha256 $receipt.archiveSha256 -TransferAuthorized -AccessHelper $AccessHelper -RecoveryManifest $ManifestPath -ExpectedManifestSha256 $pin
     }
     $verified=Get-Content -LiteralPath (Join-Path $PrivateRoot 'OFFHOST-PROOF.json') -Raw|ConvertFrom-Json
     if($verified.archiveSha256 -cne $receipt.archiveSha256 -or $verified.sourceCommit -cne $m.baseline -or $verified.restoreDatabase -cne $m.backup.restoreDatabase -or $verified.migrations -ne $m.migrations.Count -or $verified.triggers -ne $m.expectedTriggers -or !$verified.authenticatedDecryption -or !$verified.isolatedRestoreVerified){throw 'Independent recovery evidence mismatch'}
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
 [IO.File]::WriteAllText($StatusPath,(@{result='STOP';check=$check;category=$_.Exception.GetBaseException().GetType().Name;httpStatus=$httpStatus;manifestSha256=$pin;productionModified=$false;retrySafe=$false;nextAction='Inspect the named bridge operation and server receipts; do not repeat backup or activation blindly.'}|ConvertTo-Json),[Text.UTF8Encoding]::new($false));exit 1
}finally{if($opened){Close-CpanelAccess}}
