param([Parameter(Mandatory)][string]$PrivateRoot,[Parameter(Mandatory)][ValidatePattern('^[a-f0-9]{64}$')][string]$ExpectedArchiveSha256,[string]$RecoveryManifest,[string]$ExpectedManifestSha256)
$ErrorActionPreference='Stop'
$expectedBaseline='33d4a52c2c2862493dec9dcf4a81b18263cc4b74';$expectedRestore='novinpay_restore10r3';$expectedServer='/home/novinpay/novinpay-private-backups/maintenance-pr12-v2'
if($RecoveryManifest){
 if($ExpectedManifestSha256 -cnotmatch '^[a-f0-9]{64}$' -or (Get-FileHash -LiteralPath $RecoveryManifest).Hash.ToLowerInvariant() -cne $ExpectedManifestSha256){throw 'Recovery manifest pin mismatch'}
 $context=Get-Content -LiteralPath $RecoveryManifest -Raw|ConvertFrom-Json
 if($context.baseline -cne '1f39bb476fd31880600564e58599c4488622c4c0' -or $context.backup.restoreDatabase -cne 'novinpay_restore13r1' -or $context.backup.directory -cne '/home/novinpay/novinpay-private-backups/maintenance-pr13-v1'){throw 'Recovery manifest scope mismatch'}
 $expectedBaseline=$context.baseline;$expectedRestore=$context.backup.restoreDatabase;$expectedServer=$context.backup.directory
}
$root=[IO.Path]::GetFullPath($PrivateRoot)
if($root.StartsWith('C:\Users\Khashayar\Documents\NovinPay',[StringComparison]::OrdinalIgnoreCase)){throw 'Recovery storage must be outside the repository'}
$allowed=@([Security.Principal.WindowsIdentity]::GetCurrent().User.Value,'S-1-5-18','S-1-5-32-544')
foreach($path in @($root,(Join-Path $root 'backup.npb'),(Join-Path $root 'backup-key.dpapi'),(Join-Path $root 'server-receipt.json'))){if(@((Get-Acl -LiteralPath $path).Access|Where-Object {$_.AccessControlType -eq 'Allow' -and $_.IdentityReference.Translate([Security.Principal.SecurityIdentifier]).Value -notin $allowed}).Count){throw 'Private ACL failed'}}
$r=Get-Content -LiteralPath (Join-Path $root 'server-receipt.json') -Raw|ConvertFrom-Json
if($r.sourceCommit -cne $expectedBaseline -or $r.sourceDatabase -cne 'novinpay_live1' -or $r.restoreDatabase -cne $expectedRestore -or !$r.schemaAndDefinersMatch -or !$r.allSnapshotRowsMatch -or !$r.environmentPreserved -or !$r.publicHtmlUnchanged -or !$r.serverPlaintextRemoved -or !$r.serverKeyRetained -or !$r.privateMaintenanceStateVerified -or $r.productionUpdated -or $r.migrations -ne 14 -or $r.triggers -ne 5){throw 'Fresh backup/restore receipt incomplete'}
if( $r.archiveSha256 -cne $ExpectedArchiveSha256 -or (Get-FileHash -LiteralPath (Join-Path $root 'backup.npb')).Hash.ToLowerInvariant() -cne $r.archiveSha256){throw 'Archive mismatch'}
$key=$null;$plain=$null;$tar=$null;$gz=$null;$memory=$null
try{
 $secret=ConvertTo-SecureString ([IO.File]::ReadAllText((Join-Path $root 'backup-key.dpapi')));$ptr=[Runtime.InteropServices.Marshal]::SecureStringToBSTR($secret)
 try{$key=[Convert]::FromBase64String([Runtime.InteropServices.Marshal]::PtrToStringBSTR($ptr))}finally{[Runtime.InteropServices.Marshal]::ZeroFreeBSTR($ptr);$secret.Dispose()}
 $all=[IO.File]::ReadAllBytes((Join-Path $root 'backup.npb'));$i=[Array]::IndexOf($all,[byte]10);if($i -lt 1 -or $i -gt 1024){throw 'Envelope header'};$h=[Text.Encoding]::UTF8.GetString($all,0,$i)|ConvertFrom-Json
 if($h.format -cne 'NOVINPAY-BACKUP-AES256GCM-v1'){throw 'Envelope identity'}
 $cipher=[byte[]]::new($all.Length-$i-1);[Array]::Copy($all,$i+1,$cipher,0,$cipher.Length);$plain=[byte[]]::new($cipher.Length);$aes=[Security.Cryptography.AesGcm]::new($key,16)
 try{$aes.Decrypt([Convert]::FromBase64String($h.iv),$cipher,[Convert]::FromBase64String($h.tag),$plain,[Text.Encoding]::ASCII.GetBytes($h.format))}finally{$aes.Dispose()}
 if([Convert]::ToHexString([Security.Cryptography.SHA256]::HashData($plain)).ToLowerInvariant() -cne $r.decryptedTarSha256){throw 'Plaintext digest'}
 $memory=[IO.MemoryStream]::new($plain,$false);$gz=[IO.Compression.GZipStream]::new($memory,[IO.Compression.CompressionMode]::Decompress);$tar=[System.Formats.Tar.TarReader]::new($gz)
 $hashes=@{};$texts=@{};$total=0L;$entries=0;$symlinks=0
 $keep=@('database.sql','database-metadata.json','release-metadata.json','RECOVERY.txt','novinpay/.env')
 while($entry=$tar.GetNextEntry()){
  $entries++;$name=$entry.Name
  if($name.StartsWith('/') -or $name.Contains('\') -or $name.Split('/') -contains '..'){throw 'Unsafe archive member'}
  if($entry.EntryType -eq [System.Formats.Tar.TarEntryType]::SymbolicLink){$symlinks++;if($name -cne 'novinpay/public/storage' -or $entry.LinkName -notin @('/home/novinpay/novinpay/storage/app/public','../storage/app/public')){throw 'Unexpected recovery symlink'};continue}
  if(!$entry.DataStream){continue};$total+=$entry.Length;if($total -gt 268435456 -or $entry.Length -gt 67108864){throw 'Expanded archive bounds'}
  if($hashes.ContainsKey($name)){throw 'Duplicate archive member'}
  if($name -in $keep){$buf=[IO.MemoryStream]::new();try{$entry.DataStream.CopyTo($buf);$bytes=$buf.ToArray();$hashes[$name]=[Convert]::ToHexString([Security.Cryptography.SHA256]::HashData($bytes)).ToLowerInvariant();$texts[$name]=[Text.Encoding]::UTF8.GetString($bytes);[Array]::Clear($bytes,0,$bytes.Length)}finally{$buf.Dispose()}}
  else{$hashes[$name]=[Convert]::ToHexString([Security.Cryptography.SHA256]::HashData($entry.DataStream)).ToLowerInvariant()}
 }
 foreach($name in @('database.sql','database-metadata.json','release-metadata.json','RECOVERY.txt','.stage2-credentials.json','novinpay/.env','novinpay/artisan','novinpay/composer.lock','novinpay/package-lock.json','novinpay/vendor/autoload.php','novinpay/public/build/manifest.json','novinpay/routes/web.php')){if(!$hashes.ContainsKey($name)){throw 'Required recovery member missing'}}
 $meta=$texts['release-metadata.json']|ConvertFrom-Json
 if($meta.sourceCommit -cne $expectedBaseline -or $meta.sourceDatabase -cne 'novinpay_live1'){throw 'Release metadata identity'}
 foreach($prop in $meta.protectedFileHashes.PSObject.Properties){if($hashes['novinpay/'+$prop.Name] -cne $prop.Value){throw 'Protected configuration digest mismatch'}}
 if(!$meta.sourceFileHashes -or !$meta.assetFileHashes -or $meta.sourceBranch -cne 'codex/novinpay-release-contact-corrections'){throw 'Source/asset recovery inventory absent'}
 foreach($prop in $meta.sourceFileHashes.PSObject.Properties){if($hashes['novinpay/'+$prop.Name] -cne $prop.Value){throw 'Source or retained production modification mismatch'}}
 foreach($prop in $meta.assetFileHashes.PSObject.Properties){if($hashes['novinpay/public/build/'+$prop.Name] -cne $prop.Value){throw 'Prebuilt asset mismatch'}}
 if(!$meta.maintenanceStateFileHashes){throw 'Private maintenance inventory missing'}
 foreach($name in @('state.json','audit.jsonl')){if(!$hashes.ContainsKey($name) -or $hashes[$name] -cne $meta.maintenanceStateFileHashes.$name){throw 'Private maintenance history digest mismatch'}}
 $persistent=0;foreach($prop in $meta.persistentFileHashes.PSObject.Properties){if($hashes['novinpay/storage/app/'+$prop.Name] -cne $prop.Value){throw 'Persistent upload digest mismatch'};$persistent++}
 if($persistent -ne [int]$r.persistentFiles){throw 'Persistent inventory mismatch'}
 $sql=$texts['database.sql'];$rowHash=[Security.Cryptography.IncrementalHash]::CreateHash([Security.Cryptography.HashAlgorithmName]::SHA256);$counts=@{}
 try{foreach($line in $sql.Split("`n")){if($line -cmatch '^INSERT INTO `([a-z0-9_]+)` '){$table=$Matches[1];$counts[$table]=[int]$counts[$table]+1;$rowHash.AppendData([Text.Encoding]::UTF8.GetBytes($line+"`n"))}};$digest=[Convert]::ToHexString($rowHash.GetHashAndReset()).ToLowerInvariant()}finally{$rowHash.Dispose()}
 if($digest -cne $r.snapshotDataDigest -or $counts['migrations'] -ne 14){throw 'Database snapshot row digest mismatch'}
 $triggerCount=0;foreach($trigger in @('article_versions_no_DELETE','article_versions_no_UPDATE','financial_document_identity','financial_entries_no_DELETE','financial_entries_no_UPDATE')){if($sql -notmatch ('\bTRIGGER\s+(?:`?[a-z0-9_]+`?\.)?`?'+[regex]::Escape($trigger)+'`?\b')){throw 'Required trigger definition missing'};$triggerCount++}
 if($texts['novinpay/.env'] -notmatch '(?m)^APP_KEY=\S+' -or $texts['novinpay/.env'] -notmatch '(?m)^DB_DATABASE=novinpay_live1\s*$'){throw 'Private recovery environment incomplete'}
 $proof=@{result='MAINTENANCE_V1_INDEPENDENT_OFFHOST_PASS';syntheticFixture=[bool]$r.syntheticFixture;sourceCommit=$expectedBaseline;privateMaintenanceHistoryVerified=$true;sourceDatabase='novinpay_live1';restoreDatabase=$expectedRestore;snapshotUnix=[long]$r.snapshotUnix;archiveSha256=$r.archiveSha256;serverArchivePath=($expectedServer+'/backup.npb');archiveContentsVerified=$true;isolatedRestoreVerified=$true;schemaAndDefinersMatch=$true;allSnapshotRowsMatch=$true;noPlaintextWrittenToWindowsDisk=$true;recoveryKeyAvailable=$true;sha256=$r.archiveSha256;authenticatedDecryption=$true;tarMembers=$entries;expandedBytes=$total;symlinksNotFollowed=$symlinks;requiredApplicationAndConfigurationPresent=$true;allProtectedFileHashesMatch=$true;persistentFilesVerified=$persistent;databaseDumpRowDigestMatches=$true;migrations=14;triggers=$triggerCount;recoveryInstructionsPresent=$true;releaseMetadataMatches=$true;plaintextWrittenToDisk=$false;protectedFileHashes=$meta.protectedFileHashes;productionUpdated=$false}
 $proofPath=Join-Path $root 'OFFHOST-PROOF.json';$out=[IO.FileStream]::new($proofPath,[IO.FileMode]::CreateNew,[IO.FileAccess]::Write,[IO.FileShare]::None);try{$data=[Text.UTF8Encoding]::new($false).GetBytes(($proof|ConvertTo-Json -Depth 6));$out.Write($data,0,$data.Length);$out.Flush($true)}finally{$out.Dispose()};Write-Output 'INDEPENDENT OFF-HOST DECRYPTION, ARCHIVE AND SNAPSHOT PASS; no plaintext written to disk; preserve the DPAPI key and owner profile'
}finally{if($tar){$tar.Dispose()};if($gz){$gz.Dispose()};if($memory){$memory.Dispose()};if($key){[Array]::Clear($key,0,$key.Length)};if($plain){[Array]::Clear($plain,0,$plain.Length)};$texts=$null;$sql=$null}