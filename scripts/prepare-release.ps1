param([Parameter(Mandatory=$true)][string]$OutputDirectory)

# Local artifact preparation only. No installation, application boot, credentials,
# migration, network call or deployment. Use only after build/test/CI review.
$ErrorActionPreference = 'Stop'
$project = [IO.Path]::GetFullPath((Split-Path $PSScriptRoot -Parent))
$output = [IO.Path]::GetFullPath($OutputDirectory)
if ($output -eq $project -or $output.StartsWith($project + [IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)) {
    throw 'Release artifacts must remain outside the repository.'
}
Push-Location $project
try {
    if (git status --porcelain=v1) { throw 'Commit reviewed changes before preparing a release.' }
    $revision = git rev-parse HEAD
    if ($LASTEXITCODE -ne 0 -or $revision -notmatch '^[a-f0-9]{40}$') { throw 'Cannot resolve source revision.' }
    $manifestPath = Join-Path $project 'public/build/manifest.json'
    $manifest = Get-Content -LiteralPath $manifestPath -Raw | ConvertFrom-Json
    $assetNames = @('manifest.json')
    foreach ($entry in $manifest.PSObject.Properties.Value) {
        $assetNames += @($entry.file) + @($entry.css) + @($entry.assets)
    }
    $assetNames = @($assetNames | Where-Object { $_ } | Sort-Object -Unique)
    foreach ($name in $assetNames) {
        if ($name -ne 'manifest.json' -and $name -notmatch '^assets/[a-zA-Z0-9_.-]+$') { throw 'Unsafe Vite asset path.' }
        $item = Get-Item -LiteralPath (Join-Path $project ('public/build/' + $name))
        if ($item.PSIsContainer -or ($item.Attributes -band [IO.FileAttributes]::ReparsePoint)) { throw 'Asset is not a regular file.' }
    }
    New-Item -ItemType Directory -Path $output -Force | Out-Null
    $archivePath = Join-Path $output ('novinpay-' + $revision + '.zip')
    if (Test-Path -LiteralPath $archivePath) { throw 'Refusing to overwrite an existing release artifact.' }
    git archive --format=zip --output=$archivePath $revision
    if ($LASTEXITCODE -ne 0) { throw 'Source archive failed.' }
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $zip = [IO.Compression.ZipFile]::Open($archivePath, [IO.Compression.ZipArchiveMode]::Update)
    try {
        foreach ($entry in $zip.Entries) {
            if ($entry.FullName -match '(^|/)(\.env($|\.(?!example$))|\.git/|[^/]+\.(sqlite|zip)$)' -or $entry.FullName -match '^public/build/') {
                throw 'Unintended sensitive/generated source entry; artifact is not approved for use.'
            }
        }
        foreach ($name in $assetNames) {
            [IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zip, (Join-Path $project ('public/build/' + $name)), ('public/build/' + $name)) | Out-Null
        }
        $entry = $zip.CreateEntry('release-metadata.json')
        $writer = [IO.StreamWriter]::new($entry.Open())
        try { $writer.Write((@{ revision=$revision; vite_manifest_sha256=(Get-FileHash -LiteralPath $manifestPath -Algorithm SHA256).Hash; production_deployment_authorized=$false } | ConvertTo-Json)) }
        finally { $writer.Dispose() }
    } finally { $zip.Dispose() }
    [pscustomobject]@{ path=$archivePath; revision=$revision; sha256=(Get-FileHash -LiteralPath $archivePath -Algorithm SHA256).Hash; build_files=$assetNames.Count; deployment_performed=$false } | ConvertTo-Json
} finally { Pop-Location }
