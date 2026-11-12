$ErrorActionPreference='Stop'
$tokens=$null;$errors=$null
$source=Join-Path $PSScriptRoot '../../scripts/release/bridge.ps1'
$ast=[Management.Automation.Language.Parser]::ParseFile($source,[ref]$tokens,[ref]$errors)
if($errors.Count){throw 'Shared quota companion syntax failed'}
$function=$ast.Find({param($n)$n -is [Management.Automation.Language.FunctionDefinitionAst] -and $n.Name -eq 'Get-AuthenticatedQuota'},$true)
if(!$function){throw 'Shared quota function missing'}
. ([scriptblock]::Create($function.Extent.Text))
$reader=$ast.Find({param($n)$n -is [Management.Automation.Language.FunctionDefinitionAst] -and $n.Name -eq 'Read-QuotaEntries'},$true)
. ([scriptblock]::Create($reader.Extent.Text))
$open=$ast.Find({param($n)$n -is [Management.Automation.Language.FunctionDefinitionAst] -and $n.Name -eq 'OpenAccess'},$true)
$upload=$ast.Find({param($n)$n -is [Management.Automation.Language.FunctionDefinitionAst] -and $n.Name -eq 'UploadNewPrivate'},$true)
if(!$upload){throw 'Shared private uploader missing'}
. ([scriptblock]::Create($open.Extent.Text))
. ([scriptblock]::Create($upload.Extent.Text))
$AccessHelper=Join-Path ([IO.Path]::GetTempPath()) ('np-access-fixture-'+[guid]::NewGuid().ToString('N')+'.ps1')
try {
 [IO.File]::WriteAllText($AccessHelper, '$accessOpened=$true')
 $modernScope=$true;$UploadHelper='/nonexistent-legacy-helper.ps1'
 . OpenAccess
 if(!$accessOpened){throw 'Existing secure-access workflow was not loaded'}
 $rejected=$false
 try{UploadNewPrivate 'outside.json' ([byte[]]@(1)) '/outside'|Out-Null}catch{$rejected=$true}
 if(!$rejected){throw 'Uploader scope was weakened'}
}finally{[IO.File]::Delete($AccessHelper)}
Add-Type -TypeDefinition @'
using System;
using System.Net;
using System.Net.Http;
using System.Threading;
using System.Threading.Tasks;
public class RecoveryQuotaClient {
    public int Attempts;
    public int[] Codes;
    public bool AllCallsCancellable = true;
    public Task<HttpResponseMessage> SendAsync(HttpRequestMessage request, CancellationToken cancel) {
        AllCallsCancellable &= cancel.CanBeCanceled;
        int code = Codes[Math.Min(Attempts++, Codes.Length - 1)];
        if (code == -1) return Task.FromException<HttpResponseMessage>(new HttpRequestException("synthetic transport failure"));
        var response = new HttpResponseMessage((HttpStatusCode)code);
        response.Headers.Date = DateTimeOffset.UtcNow;
        response.Content = new StringContent("{\"status\":1,\"data\":{\"under_quota_overall\":\"1\",\"megabytes_remain\":4096,\"megabytes_used\":1024,\"megabyte_limit\":5120}}");
        return Task.FromResult(response);
    }
}
'@
$script:sleeps=0
function Start-Sleep {param([int]$Milliseconds) $script:sleeps++}
$tokenPointer=[Runtime.InteropServices.Marshal]::StringToBSTR('synthetic-nonsecret-token')
try {
    $httpClient=[RecoveryQuotaClient]::new();$httpClient.Codes=@(503,200)
    $proof=Get-AuthenticatedQuota
    if($httpClient.Attempts -ne 2 -or $script:sleeps -ne 1 -or !$httpClient.AllCallsCancellable -or $proof.Value.megabytes_remain -ne 4096){throw 'Bounded transient retry failed'}
    $httpClient=[RecoveryQuotaClient]::new();$httpClient.Codes=@(403)
    $caught=$false
    try{Get-AuthenticatedQuota|Out-Null}catch{$caught=$true}
    if(!$caught -or $httpClient.Attempts -ne 1){throw 'Authorization rejection was retried'}
    $httpClient=[RecoveryQuotaClient]::new();$httpClient.Codes=@(-1)
    $caught=$false
    try{Get-AuthenticatedQuota|Out-Null}catch{$caught=$true}
    if(!$caught -or $httpClient.Attempts -ne 3){throw 'Transport retry bound failed'}
    $script:reads=0
    function Get-Entries {param($directory) $script:reads++;if($script:reads -eq 1){throw 'cPanel HTTP failure 503'};@{file='fixture'}}
    $entries=@(Read-QuotaEntries '/synthetic')
    if($script:reads -ne 2 -or $entries.Count -ne 1){throw 'Read-only inventory retry failed'}
    $script:reads=0
    function Get-Entries {param($directory) $script:reads++;throw 'cPanel HTTP failure 403'}
    $caught=$false
    try{Read-QuotaEntries '/synthetic'|Out-Null}catch{$caught=$true}
    if(!$caught -or $script:reads -ne 1){throw 'Inventory authorization error was retried'}
    'QUOTA_COMPANION_REGRESSION_PASS'
}finally{[Runtime.InteropServices.Marshal]::ZeroFreeBSTR($tokenPointer)}
