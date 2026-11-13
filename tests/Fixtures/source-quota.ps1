param([switch]$Application)
$ErrorActionPreference='Stop'
$scratch=Join-Path ([IO.Path]::GetTempPath()) ('np-source-quota-'+[guid]::NewGuid().ToString('N'))
[IO.Directory]::CreateDirectory($scratch)|Out-Null
try {
 $manifest=@{format='novinpay-source-release-v1';releaseName='fixture';stateDirectory='/home/novinpay/novinpay-direct-pr13';baseline=('a'*40);target=('b'*40);backup=@{directory='/home/novinpay/novinpay-private-backups/recovery-fixture';restoreDatabase='novinpay_restoreauth1'}}
 if($Application){$manifest.format='novinpay-application-release-v1'}
 $manifestPath=Join-Path $scratch 'manifest.json'
 [IO.File]::WriteAllText($manifestPath,($manifest|ConvertTo-Json))
 $helper=Join-Path $scratch 'access.ps1'
 [IO.File]::WriteAllText($helper,@'
$fixtureManifest=Get-Content $ManifestPath -Raw|ConvertFrom-Json
$fixturePin=(Get-FileHash $ManifestPath).Hash.ToLowerInvariant()
$fixtureNonce='c'*32
$fixtureRequest=@{format=1;nonce=$fixtureNonce;phase='fence';sourceCommit=$fixtureManifest.baseline;targetCommit=$fixtureManifest.target;manifestSha256=$fixturePin;createdAtUnix=[DateTimeOffset]::UtcNow.ToUnixTimeSeconds();requiredFreeBytes=805306368}|ConvertTo-Json -Compress
function Get-Entries([string]$directory){
 if($directory -cne $fixtureManifest.stateDirectory){throw 'Quota coordination directory differs'}
 @{file=('quota-'+$fixtureNonce+'-request.json');type='file';nicemode='0600';size=[Text.Encoding]::UTF8.GetByteCount($fixtureRequest)}
}
function Read-HostText([string]$path){
 if($path -cne ($fixtureManifest.stateDirectory+'/quota-'+$fixtureNonce+'-request.json')){throw 'Unexpected fixture read'}
 $fixtureRequest
}
function Get-AuthenticatedQuota {
 @{ServerUnix=[DateTimeOffset]::UtcNow.ToUnixTimeSeconds();Value=@{megabytes_remain=4096;megabytes_used=1024;megabyte_limit=5120}}
}
function UploadNewPrivate([string]$name,[byte[]]$bytes,[string]$directory){
 if($directory -cne $fixtureManifest.stateDirectory){throw 'Reply directory differs'}
 if($name -cnotmatch ('^quota-'+$fixtureNonce+'-(reply|ready)\.json$')){throw 'Unbound quota reply'}
 [IO.File]::AppendAllText((Join-Path $PSScriptRoot 'uploads.txt'),$name+"`n")
 [Convert]::ToHexString([Security.Cryptography.SHA256]::HashData($bytes)).ToLowerInvariant()
}
function Close-CpanelAccess {}
'@)
 $status=Join-Path $scratch 'status.json'
 $bridge=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../../scripts/release/bridge.ps1'))
 & (Get-Command pwsh).Source -NoProfile -NonInteractive -File $bridge -ManifestPath $manifestPath -AccessHelper $helper -PrivateRoot $scratch -StatusPath $status -Hours 1 -QuotaOnly
 if($LASTEXITCODE -ne 0){throw 'Source quota companion failed'}
 $result=Get-Content $status -Raw|ConvertFrom-Json
 if($result.result -cne 'ACTIVATION_QUOTA_PASS'){throw 'Source quota was not acknowledged'}
 $uploads=@(Get-Content (Join-Path $scratch 'uploads.txt'))
 if($uploads.Count -ne 2 -or !$uploads[0].EndsWith('-reply.json') -or !$uploads[1].EndsWith('-ready.json')){throw 'Quota publication ordering failed'}
 'SOURCE_QUOTA_WORKFLOW_PASS'
} finally {
 foreach($name in @('manifest.json','access.ps1','status.json','uploads.txt')){
  $path=Join-Path $scratch $name
  if([IO.File]::Exists($path)){[IO.File]::Delete($path)}
 }
 [IO.Directory]::Delete($scratch)
}
