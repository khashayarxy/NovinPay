param(
 [Parameter(Mandatory)][string]$ManifestPath,
 [Parameter(Mandatory)][string]$AccessHelper,
 [Parameter(Mandatory)][string]$UploadHelper,
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
$pr13Activation=$QuotaOnly -and $m.mode -ceq 'pr13-migration-activation-v1' -and $m.stateDirectory -ceq "$package/pipeline-pr13-activation-r1" -and $m.backup.directory -ceq '/home/novinpay/novinpay-private-backups/maintenance-pr13-v1' -and $m.backup.restoreDatabase -ceq 'novinpay_restore13r1' -and $m.baseline -ceq '1f39bb476fd31880600564e58599c4488622c4c0' -and $m.candidate -ceq 'ded4e16302770f28f7bfe8e567e9a2de2314f99d'
if(!$legacyScope -and !$pr13Scope -and !$pr13Activation){throw 'Bridge manifest scope mismatch'}
$deadline=[DateTimeOffset]::UtcNow.AddHours($Hours)
function Status([string]$state){[IO.File]::WriteAllText($StatusPath,(@{result=$state;pid=$PID;atUtc=[DateTimeOffset]::UtcNow.ToString('o');manifestSha256=$pin}|ConvertTo-Json),[Text.UTF8Encoding]::new($false))}
function OpenAccess {
 . $AccessHelper
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
$opened=$false;$done=@{};$check='open_authenticated_access'
try {
 . OpenAccess; $opened=$true
 Status 'READY'
 while([DateTimeOffset]::UtcNow -lt $deadline){
  $check='read_quota_requests';$entries=@(Get-Entries $package)
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
   $check='authenticated_quota_request';$req=[Net.Http.HttpRequestMessage]::new([Net.Http.HttpMethod]::Post,'https://leda.asemanhost.com:2083/execute/Quota/get_quota_info')
   [void]$req.Headers.TryAddWithoutValidation('Authorization','cpanel novinpay:'+[Runtime.InteropServices.Marshal]::PtrToStringBSTR($tokenPointer))
   $req.Content=[Net.Http.FormUrlEncodedContent]::new([Collections.Generic.Dictionary[string,string]]::new())
   try{
    $res=$httpClient.SendAsync($req).GetAwaiter().GetResult()
    try{
     if(!$res.IsSuccessStatusCode -or !$res.Headers.Date){throw 'Authenticated quota unavailable'}
     $server=$res.Headers.Date.ToUnixTimeSeconds()
     if([Math]::Abs($server-[DateTimeOffset]::UtcNow.ToUnixTimeSeconds()) -gt 5){throw 'Quota clock mismatch'}
     $j=$res.Content.ReadAsStringAsync().GetAwaiter().GetResult()|ConvertFrom-Json;$v=if($j.result){$j.result}else{$j}
     if($v.status -ne 1 -or [string]$v.data.under_quota_overall -ne '1'){throw 'Quota API rejected'}
     $free=[long][Math]::Floor([double]$v.data.megabytes_remain*1048576)
     $used=[long][Math]::Floor([double]$v.data.megabytes_used*1048576)
     $limit=[long][Math]::Floor([double]$v.data.megabyte_limit*1048576)
     if($q.requiredFreeBytes -lt 805306368 -or $free -lt [long]$q.requiredFreeBytes+2147483648 -or $used -lt 0 -or $free+$used -gt $limit+1048576){throw 'Quota reserve insufficient'}
     $proof=@{account='novinpay';apiHost='leda.asemanhost.com:2083';method='Quota/get_quota_info';httpsCertificateValidated=$true;underQuota=$true;sourceCommit=$m.baseline;nonce=$q.nonce;phase=$q.phase;manifestSha256=$pin;observedAtUnix=$server;freeBytes=$free;usedBytes=$used;limitBytes=$limit}
    }finally{$res.Dispose()}
   }finally{$req.Dispose()}
   $bytes=[Text.Encoding]::UTF8.GetBytes(($proof|ConvertTo-Json -Compress))
   if(@($entries|Where-Object file -ceq ($base+'-reply.json')).Count){$done[$base]=$true;continue}
   $check='quota_reply_upload';$hash=UploadNewPrivate ($base+'-reply.json') $bytes $package
   $null=UploadNewPrivate ($base+'-ready.json') ([Text.Encoding]::UTF8.GetBytes((@{sha256=$hash}|ConvertTo-Json -Compress))) $package
   $done[$base]=$true;Status ('QUOTA_'+$q.phase.ToUpperInvariant())
  }
  if($QuotaOnly){
   if($pr13Activation -and @($entries|Where-Object {$_.file -ceq 'pipeline-pr13-activation-r1' -and $_.type -ceq 'dir'}).Count){
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
 [IO.File]::WriteAllText($StatusPath,(@{result='STOP';check=$check;category=$_.Exception.GetType().Name;manifestSha256=$pin;productionModified=$false;retrySafe=$false;nextAction='Inspect the named bridge operation and server receipts; do not repeat backup or activation blindly.'}|ConvertTo-Json),[Text.UTF8Encoding]::new($false));exit 1
}finally{if($opened){Close-CpanelAccess}}
