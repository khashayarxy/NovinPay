$ErrorActionPreference='Stop'
. "$PSScriptRoot/../../scripts/release/quota-bridge.ps1"
function Assert($value,[string]$message){if(!$value){throw $message}}
function Wait-QuotaBackoff([int]$Attempt){} # Exercise real exchanges without production backoff delays.
function Fail([scriptblock]$Run,[string]$Message){
 try{& $Run;throw 'assert_expected_failure'}
 catch{Assert ($_.Exception.Message -ceq $Message) "Unexpected failure: $($_.Exception.Message)"}
}

$listener=[Net.Sockets.TcpListener]::new([Net.IPAddress]::Loopback,0)
$listener.Start()
$port=$listener.LocalEndpoint.Port
$server=Start-ThreadJob -ArgumentList $listener -ScriptBlock {
 param($listener)
 foreach($code in @(503,503,200,401)){
  $socket=$listener.AcceptTcpClient()
  try{
   $stream=$socket.GetStream()
   $reader=[IO.StreamReader]::new($stream)
   do{$line=$reader.ReadLine()}while($line)
   $bytes=[Text.Encoding]::ASCII.GetBytes("HTTP/1.1 $code Test`r`nContent-Length: 2`r`nConnection: close`r`n`r`n{}")
   $stream.Write($bytes,0,$bytes.Length)
  }finally{$socket.Dispose()}
 }
}
$client=[Net.Http.HttpClient]::new()
$client.Timeout=[TimeSpan]::FromSeconds(3)
try{
 $http={
  $r=$client.GetAsync("http://127.0.0.1:$port/").GetAwaiter().GetResult()
  try{if(!$r.IsSuccessStatusCode){throw ('cPanel HTTP failure '+[int]$r.StatusCode)};return $r.Content.ReadAsStringAsync().GetAwaiter().GetResult()}finally{$r.Dispose()}
 }
 Assert ((Invoke-QuotaRead $http) -ceq '{}') '503 retries did not recover on a real HTTP response'
 Fail {Invoke-QuotaRead $http} 'cPanel HTTP failure 401'
}finally{
 $client.Dispose()
 $listener.Stop()
 Remove-Job -Job $server -Force
}
$script:transportAttempts=0
Assert ((Invoke-QuotaRead {
 $script:transportAttempts++
 if($script:transportAttempts -eq 1){throw [OperationCanceledException]::new()}
 'recovered'
}) -ceq 'recovered') 'Canceled transport did not recover'
$script:boundedAttempts=0
Fail {Invoke-QuotaRead {$script:boundedAttempts++;throw 'cPanel HTTP failure 503'}} 'cPanel HTTP failure 503'
Assert ($script:boundedAttempts -eq 5) 'A sustained outage exceeded the retry limit'

$clock=[DateTimeOffset]::UtcNow.ToUnixTimeSeconds()
$pin='a'*64;$source='b'*40;$target='c'*40;$nonce='d'*32
$manifest=@{baseline=$source;target=$target}
$q=[ordered]@{format=2;manifestSha256=$pin;sourceCommit=$source;targetCommit=$target;phase='fence';nonce=$nonce;createdAtUnix=$clock;expiresAtUnix=($clock+180);requiredFreeBytes=805306368}
$raw=$q|ConvertTo-Json -Compress
$request=Get-QuotaV2Request $raw "quota-$nonce" $manifest $pin $clock
$requestPin=Get-QuotaDigest ([Text.Encoding]::UTF8.GetBytes($raw))
$observation=@{ServerUnix=$clock;Value=@{megabytes_remain=3200;megabytes_used=1900;megabyte_limit=5120;inode_limit=0;inodes_remain=0;under_inode_limit='1'}}
[byte[]]$bytes=Get-QuotaV2Envelope $request $requestPin $observation $true
Assert-QuotaOutboxBinding $bytes $request $requestPin $clock
Fail {Assert-QuotaOutboxBinding $bytes $request $requestPin ($clock+121)} 'quota_outbox_expired_or_unbound'
Fail {Assert-QuotaOutboxBinding $bytes $request ('e'*64) $clock} 'quota_outbox_expired_or_unbound'
Fail {Get-QuotaV2Request $raw ('quota-'+('f'*32)) $manifest $pin $clock} 'quota_request_identity'
Fail {Get-QuotaV2Envelope $request $requestPin @{ServerUnix=$clock;Value=@{megabytes_remain=2000;megabytes_used=3100;megabyte_limit=5120}} $false} 'quota_account_headroom'

$remote=@{}
$script:uploadCount=0
function Get-Entries($Directory){
 foreach($name in $remote.Keys){
  [pscustomobject]@{file=$name;type='file';nicemode='0600';size=$remote[$name].Length}
 }
}
function Read-HostText($Path){[Text.Encoding]::UTF8.GetString($remote[($Path -split '/')[-1]])}
function UploadNewPrivate($Name,$Value,$Directory){
 $script:uploadCount++
 $remote[$Name]=$Value
 throw 'cPanel HTTP failure 503' # committed bytes, response lost
}
$name="quota-$nonce-response.json"
Publish-QuotaEnvelope '/private/mailbox' $name $bytes ($clock+180)
Publish-QuotaEnvelope '/private/mailbox' $name $bytes ($clock+180)
Assert ($script:uploadCount -eq 1) 'A lost upload response caused duplicate writes'
$bad=$bytes.Clone();$bad[0]=32
Fail {Publish-QuotaEnvelope '/private/mailbox' $name $bad ($clock+180)} 'quota_remote_envelope_conflict'
$ack=@{format=2;manifestSha256=$pin;nonce=$nonce;phase='fence';requestSha256=$requestPin;result='accepted';responseSha256=(Get-QuotaDigest $bytes)}
Assert ((Read-QuotaAcknowledgment ($ack|ConvertTo-Json -Compress) $request $requestPin).result -ceq 'accepted') 'Ack was not bound'
Fail {Read-QuotaAcknowledgment ($ack|ConvertTo-Json -Compress) $request ('e'*64)} 'quota_ack_identity'

$tmp=Join-Path ([IO.Path]::GetTempPath()) ('novinpay-quota-test-'+[guid]::NewGuid().ToString('N'))
try{
 [byte[]]$out1=Get-QuotaOutbox $tmp {,$bytes}
 [byte[]]$out2=Get-QuotaOutbox $tmp {throw 'A restarting bridge must use its existing envelope'}
 Assert ((Get-QuotaDigest $out1) -ceq (Get-QuotaDigest $out2)) 'Restart changed the shipped envelope'
 $env:NOVINPAY_FIXTURE_REQUEST=[Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($raw))
 $env:NOVINPAY_FIXTURE_ENVELOPE=$tmp
 $env:NOVINPAY_FIXTURE_RUNTIME=(Resolve-Path "$PSScriptRoot/../../scripts/release/runtime.php").Path
 @'
<?php
require getenv('NOVINPAY_FIXTURE_RUNTIME');
$raw = base64_decode(getenv('NOVINPAY_FIXTURE_REQUEST'), true);
$request = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
$proof = NovinPay\Release\quotaEnvelope(file_get_contents(getenv('NOVINPAY_FIXTURE_ENVELOPE')), $request, hash('sha256', $raw));
NovinPay\Release\quotaGate($proof, time(), $request['nonce'], $request['sourceCommit'], 900000000, $request['requiredFreeBytes']);
'@ | php
 Assert ($LASTEXITCODE -eq 0) 'PHP rejected the exact published Windows envelope'
}finally{if(Test-Path -LiteralPath $tmp){Remove-Item -LiteralPath $tmp}}
'QUOTA_BRIDGE_FAULTS_PASS'
