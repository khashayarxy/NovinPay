# Version 2 quota envelopes. The caller supplies the existing authenticated cPanel adapter.
function Get-QuotaDigest([byte[]]$Bytes) {
 [Convert]::ToHexString([Security.Cryptography.SHA256]::HashData($Bytes)).ToLowerInvariant()
}

function Test-QuotaTransient($ErrorRecord) {
 $cause=$ErrorRecord.Exception.GetBaseException()
 if($cause -is [Net.Http.HttpRequestException]){
  if($null -eq $cause.StatusCode){return $true}
  return [int]$cause.StatusCode -in @(408,429) -or [int]$cause.StatusCode -ge 500
 }
 return $cause -is [OperationCanceledException] -or $ErrorRecord.Exception.Message -match '^cPanel HTTP failure (408|429|5[0-9]{2})$'
}

function Wait-QuotaBackoff([int]$Attempt) {
 Start-Sleep -Milliseconds ([Math]::Min(4000,250*[Math]::Pow(2,$Attempt)) + (Get-Random -Minimum 0 -Maximum 251))
}

function Invoke-QuotaRead([scriptblock]$Read) {
 for($i=0;$i -lt 5;$i++){
  try { return & $Read }
  catch { if($i -eq 4 -or !(Test-QuotaTransient $_)){throw}; Wait-QuotaBackoff $i }
 }
}

function Find-QuotaPrivate([string]$Directory,[string]$Name,[int]$Limit) {
 $files=@(Invoke-QuotaRead { Get-Entries $Directory } | Where-Object file -ceq $Name)
 if(!$files.Count){return $null}
 if($files.Count -ne 1 -or $files[0].type -cne 'file' -or $files[0].nicemode -cne '0600' -or [long]$files[0].size -gt $Limit){
  throw 'quota_private_file_identity'
 }
 return $files[0]
}

function Read-QuotaPrivate([string]$Directory,[string]$Name,[int]$Limit) {
 $info=Find-QuotaPrivate $Directory $Name $Limit
 if(!$info){return $null}
 $raw=Invoke-QuotaRead { Read-HostText "$Directory/$Name" }
 if([Text.Encoding]::UTF8.GetByteCount($raw) -ne [long]$info.size){throw 'quota_private_file_changed'}
 return $raw
}

function Publish-QuotaEnvelope([string]$Directory,[string]$Name,[byte[]]$Bytes,[long]$ExpiresAt) {
 $pin=Get-QuotaDigest $Bytes
 for($attempt=0;$attempt -lt 4;$attempt++){
  $current=Read-QuotaPrivate $Directory $Name 8192
  if($null -ne $current){
   if((Get-QuotaDigest ([Text.Encoding]::UTF8.GetBytes($current))) -cne $pin){throw 'quota_remote_envelope_conflict'}
   return
  }
  if([DateTimeOffset]::UtcNow.ToUnixTimeSeconds() -ge $ExpiresAt){throw 'quota_request_expired'}
  try {
   if((UploadNewPrivate $Name $Bytes $Directory) -cne $pin){throw 'quota_upload_pin'}
   return
  } catch {
   # A lost upload response may hide a committed file. Always reconcile first.
   if(!(Test-QuotaTransient $_) -and $_.Exception.Message -cnotin @('quota_upload_ambiguous','Existing quota evidence preserved')){throw}
   if($attempt -lt 3){Wait-QuotaBackoff $attempt}
  }
 }
 $current=Read-QuotaPrivate $Directory $Name 8192
 if($null -ne $current -and (Get-QuotaDigest ([Text.Encoding]::UTF8.GetBytes($current))) -ceq $pin){return}
 throw 'quota_publication_unconfirmed'
}

function Get-QuotaV2Request([string]$Raw,[string]$BaseName,$Manifest,[string]$ManifestPin,[long]$Now) {
 try{$q=$Raw|ConvertFrom-Json}catch{throw 'quota_request_json'}
 if($q.format -cne 2 -or $q.manifestSha256 -cne $ManifestPin -or $q.sourceCommit -cne $Manifest.baseline -or
    $q.targetCommit -cne $Manifest.target -or $q.phase -cnotin @('backup','fence') -or
    $BaseName -cnotmatch '^quota-[a-f0-9]{32}$' -or $q.nonce -cne $BaseName.Substring(6) -or
    ($q.createdAtUnix -isnot [long] -and $q.createdAtUnix -isnot [int]) -or
    ($q.expiresAtUnix -isnot [long] -and $q.expiresAtUnix -isnot [int]) -or
    ($q.requiredFreeBytes -isnot [long] -and $q.requiredFreeBytes -isnot [int]) -or
    $q.requiredFreeBytes -lt 805306368 -or $q.createdAtUnix -gt $Now+5 -or $q.expiresAtUnix -ne ($q.createdAtUnix + 180)){
  throw 'quota_request_identity'
 }
 return $q
}

function Read-QuotaAcknowledgment([string]$Raw,$Request,[string]$RequestPin) {
 $a=$Raw|ConvertFrom-Json
 if($a.format -cne 2 -or $a.manifestSha256 -cne $Request.manifestSha256 -or $a.phase -cne $Request.phase -or
    $a.nonce -cne $Request.nonce -or $a.requestSha256 -cne $RequestPin -or
    $a.result -cnotin @('accepted','expired','rejected') -or
    ($a.result -ceq 'accepted' -and $a.responseSha256 -cnotmatch '^[a-f0-9]{64}$')){
  throw 'quota_ack_identity'
 }
 return $a
}

function Get-QuotaV2Envelope($Request,[string]$RequestPin,$Observation,[bool]$RequireInodes) {
 $v=$Observation.Value; $now=[long]$Observation.ServerUnix
 $free=[long][Math]::Floor([double]$v.megabytes_remain*1048576)
 $used=[long][Math]::Floor([double]$v.megabytes_used*1048576)
 $limit=[long][Math]::Floor([double]$v.megabyte_limit*1048576)
 if($used -lt 0 -or $limit -le 0 -or $free -gt $limit+1048576-$used -or $free-2147483648 -lt [long]$Request.requiredFreeBytes){throw 'quota_account_headroom'}
 if($now -lt $Request.createdAtUnix-2 -or $now -ge $Request.expiresAtUnix){throw 'quota_observation_outside_request'}
 $p=[ordered]@{format=2;account='novinpay';apiHost='leda.asemanhost.com:2083';method='Quota/get_quota_info';httpsCertificateValidated=$true;underQuota=$true;sourceCommit=$Request.sourceCommit;targetCommit=$Request.targetCommit;nonce=$Request.nonce;phase=$Request.phase;manifestSha256=$Request.manifestSha256;requestSha256=$RequestPin;observedAtUnix=$now;freeBytes=$free;usedBytes=$used;limitBytes=$limit}
 if($RequireInodes){
  if($null -eq $v.inode_limit -or $null -eq $v.inodes_remain -or [string]$v.under_inode_limit -cne '1' -or
     [long]$v.inode_limit -lt 0 -or [long]$v.inodes_remain -lt 0){throw 'quota_inode_evidence'}
  $p.inodeLimit=[long]$v.inode_limit; $p.inodesRemaining=[long]$v.inodes_remain
  $p.underInodeQuota=$true; $p.inodeUnlimited=([long]$v.inode_limit -eq 0)
 }
 $body=[Text.Encoding]::UTF8.GetBytes(($p|ConvertTo-Json -Compress))
 return ,([Text.Encoding]::UTF8.GetBytes(([ordered]@{format=2;body=[Convert]::ToBase64String($body);sha256=Get-QuotaDigest $body}|ConvertTo-Json -Compress)))
}

function Get-QuotaOutbox([string]$Path,[scriptblock]$Prepare) {
 if(!(Test-Path -LiteralPath $Path)){
  [byte[]]$bytes=& $Prepare
  $tmp=$Path+'.'+[guid]::NewGuid().ToString('N')+'.tmp'
  $file=[IO.File]::Open($tmp,[IO.FileMode]::CreateNew,[IO.FileAccess]::Write,[IO.FileShare]::None)
  try{$file.Write($bytes,0,$bytes.Length);$file.Flush($true)}finally{$file.Dispose()}
  [IO.File]::Move($tmp,$Path,$false)
 }
 $item=Get-Item -LiteralPath $Path
 if($item.Attributes.HasFlag([IO.FileAttributes]::ReparsePoint) -or $item.Length -lt 1 -or $item.Length -gt 8192){throw 'quota_outbox_identity'}
 return ,([IO.File]::ReadAllBytes($Path))
}

function Assert-QuotaOutboxBinding([byte[]]$Bytes,$Request,[string]$RequestPin,[long]$Now) {
 $envelope=[Text.Encoding]::UTF8.GetString($Bytes)|ConvertFrom-Json
 $body=[Convert]::FromBase64String($envelope.body)
 if($envelope.format -cne 2 -or (Get-QuotaDigest $body) -cne $envelope.sha256){throw 'quota_outbox_integrity'}
 $proof=[Text.Encoding]::UTF8.GetString($body)|ConvertFrom-Json
 if($proof.format -cne 2 -or $proof.requestSha256 -cne $RequestPin -or $proof.manifestSha256 -cne $Request.manifestSha256 -or
    $proof.nonce -cne $Request.nonce -or $proof.sourceCommit -cne $Request.sourceCommit -or $proof.targetCommit -cne $Request.targetCommit -or
    $proof.phase -cne $Request.phase -or $proof.observedAtUnix -lt $Request.createdAtUnix-2 -or
    $proof.observedAtUnix -gt $Now+5 -or $Now-$proof.observedAtUnix -gt 120){throw 'quota_outbox_expired_or_unbound'}
}
