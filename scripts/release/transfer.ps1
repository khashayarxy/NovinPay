param([Parameter(Mandatory)][string]$PrivateRoot,[Parameter(Mandatory)][ValidatePattern('^[a-f0-9]{64}$')][string]$ExpectedArchiveSha256,[switch]$TransferAuthorized,[Parameter(Mandatory)][string]$AccessHelper,[string]$RecoveryManifest,[string]$ExpectedManifestSha256)
$ErrorActionPreference='Stop'
if(!$RecoveryManifest){throw 'Pinned recovery manifest required'}
if($RecoveryManifest){
 if($ExpectedManifestSha256 -cnotmatch '^[a-f0-9]{64}$' -or (Get-FileHash -LiteralPath $RecoveryManifest).Hash.ToLowerInvariant() -cne $ExpectedManifestSha256){throw 'Recovery manifest pin mismatch'}
 $context=Get-Content -LiteralPath $RecoveryManifest -Raw|ConvertFrom-Json
 if($context.format -cne 'novinpay-recovery-v1' -or $context.baseline -cnotmatch '^[a-f0-9]{40}$' -or $context.backup.restoreDatabase -cnotmatch '^novinpay_restore[a-z0-9]+$' -or $context.backup.directory -cnotmatch '^/home/novinpay/novinpay-private-backups/recovery-[a-z0-9-]{1,60}$'){throw 'Recovery manifest scope mismatch'}
 $expectedBaseline=$context.baseline;$expectedRestore=$context.backup.restoreDatabase;$expectedServer=$context.backup.directory
}
if(!$TransferAuthorized){throw 'Explicit authorization required before any hosting connection'}
 $access=$AccessHelper
if(!(Test-Path -LiteralPath $access)){throw 'Existing authenticated cPanel access helper unavailable; do not improvise credentials'}
$allowed=@([Security.Principal.WindowsIdentity]::GetCurrent().User.Value,'S-1-5-18','S-1-5-32-544')
function Private([string]$p){$i=Get-Item -LiteralPath $p -Force;if($i.Attributes -band [IO.FileAttributes]::ReparsePoint){throw 'Recovery reparse point refused'};if(@((Get-Acl -LiteralPath $p).Access|Where-Object{$_.AccessControlType -eq 'Allow' -and $_.IdentityReference.Translate([Security.Principal.SecurityIdentifier]).Value -notin $allowed}).Count){throw 'Recovery ACL mismatch'}}
Private $PrivateRoot
. $access
try{
 $server=$expectedServer
 $r=(Read-HostText ($server+'/receipt.json'))|ConvertFrom-Json
 if($r.sourceCommit -cne $expectedBaseline -or $r.sourceDatabase -cne 'novinpay_live1' -or $r.restoreDatabase -cne $expectedRestore -or !$r.schemaAndDefinersMatch -or !$r.allSnapshotRowsMatch -or $r.productionUpdated -or $r.archiveSha256 -cne $ExpectedArchiveSha256 -or $r.archiveBytes -lt 1000 -or $r.archiveBytes -gt 67109888){throw 'New backup receipt identity mismatch'}
 $archive=Join-Path $PrivateRoot 'backup.npb'
 if(!(Test-Path -LiteralPath $archive)){
  $temporary=Join-Path $PrivateRoot 'backup.npb.download';$stream=[IO.FileStream]::new($temporary,[IO.FileMode]::CreateNew,[IO.FileAccess]::Write,[IO.FileShare]::None)
  try{Private $temporary;$index=0;$written=0L;foreach($part in $r.parts){if($part.file -cne ('encrypted-part-{0:d4}.txt' -f $index) -or $part.sha256 -notmatch '^[a-f0-9]{64}$' -or $part.bytes -lt 1 -or $part.bytes -gt 196608){throw 'Encrypted part metadata mismatch'};$bytes=[Convert]::FromBase64String((Read-HostText ($server+'/'+$part.file)));$written+=$bytes.Length;if($bytes.Length -ne $part.bytes -or [Convert]::ToHexString([Security.Cryptography.SHA256]::HashData($bytes)).ToLowerInvariant() -cne $part.sha256 -or $written -gt $r.archiveBytes){throw 'Encrypted transfer mismatch'};$stream.Write($bytes,0,$bytes.Length);$index++};$stream.Flush($true)}finally{$stream.Dispose()}
  if((Get-Item -LiteralPath $temporary).Length -ne $r.archiveBytes -or (Get-FileHash -LiteralPath $temporary).Hash.ToLowerInvariant() -cne $ExpectedArchiveSha256){throw 'Final encrypted archive mismatch'}
  [IO.File]::Move($temporary,$archive)
 }
 Private $archive
 if((Get-FileHash -LiteralPath $archive).Hash.ToLowerInvariant() -cne $ExpectedArchiveSha256){throw 'Existing encrypted archive mismatch'}
 $receipt=Join-Path $PrivateRoot 'server-receipt.json'
 if(Test-Path -LiteralPath $receipt){Private $receipt;$old=Get-Content -LiteralPath $receipt -Raw|ConvertFrom-Json;if($old.archiveSha256 -cne $r.archiveSha256 -or $old.sourceCommit -cne $r.sourceCommit){throw 'Existing receipt differs; do not overwrite'}}
 else{[IO.File]::WriteAllText($receipt,($r|ConvertTo-Json -Depth 8),[Text.UTF8Encoding]::new($false));Private $receipt}
}finally{Close-CpanelAccess}
& (Join-Path $PSScriptRoot 'verify-offhost.ps1') -PrivateRoot $PrivateRoot -ExpectedArchiveSha256 $ExpectedArchiveSha256 -RecoveryManifest $RecoveryManifest -ExpectedManifestSha256 $ExpectedManifestSha256
