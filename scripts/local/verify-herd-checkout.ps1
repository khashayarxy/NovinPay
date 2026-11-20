param(
    [Parameter(Mandatory)]
    [ValidatePattern('^[a-f0-9]{40}$')]
    [string] $ExpectedCommit
)

$ErrorActionPreference = 'Stop'
$siteUrl = 'https://novinpaypos.test'
$links = herd links --json
if ($LASTEXITCODE) { throw 'Herd link inventory failed.' }
$site = @($links | ConvertFrom-Json | Where-Object { $_.url -ceq $siteUrl })
if ($site.Count -ne 1 -or $site[0].phpVersion -cne '8.4') { throw 'Expected one HTTPS NovinPay Herd link with PHP 8.4.' }
$checkout = $site[0].path
$actual = git --no-optional-locks -C $checkout rev-parse HEAD
if ($LASTEXITCODE -or $actual -cne $ExpectedCommit) { throw "Herd serves $checkout at $actual. Expected $ExpectedCommit." }
$changes = @(git --no-optional-locks -C $checkout status --porcelain --untracked-files=all)
if ($LASTEXITCODE -or $changes.Count) { throw 'The served checkout contains uncommitted changes. Record this as a development preview.' }

$page = Invoke-WebRequest "$siteUrl/products" -MaximumRedirection 0
$paths = @(
    '/css/store-navigation.css',
    '/js/storefront-premium.js'
)
$built = [regex]::Matches($page.Content, '(?:src|href)="https://novinpaypos\.test(/build/assets/[^"?]+)') |
    ForEach-Object { $_.Groups[1].Value } | Sort-Object -Unique
if (!$built) { throw 'Compiled Vite assets missing from rendered catalog.' }
$client = [Net.Http.HttpClient]::new()
try {
    foreach ($path in $paths + @($built) + @('/build/manifest.json')) {
        $local = Join-Path $checkout ('public' + $path)
        $bytes = $client.GetByteArrayAsync($siteUrl + $path).GetAwaiter().GetResult()
        $servedHash = [Convert]::ToHexString([Security.Cryptography.SHA256]::HashData($bytes))
        if ($servedHash -cne (Get-FileHash -LiteralPath $local).Hash) { throw "Herd asset bytes differ from checkout at $path." }
    }
} finally {
    $client.Dispose()
}
[pscustomobject]@{ Checkout = $checkout; Commit = $actual; PhpVersion = $site[0].phpVersion; Assets = 'Matched' }
