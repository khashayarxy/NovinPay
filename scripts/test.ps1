param(
    [Parameter(ValueFromRemainingArguments = $true)]
    [string[]] $TestArguments
)

$ErrorActionPreference = 'Stop'
$projectRoot = Split-Path $PSScriptRoot -Parent
$phpCommand = Get-Command php -ErrorAction SilentlyContinue
$candidates = @(
    $phpCommand.Source
    (Join-Path $env:USERPROFILE '.config\herd\bin\php84\php.exe')
    (Join-Path $env:USERPROFILE '.config\herd\bin\php85\php.exe')
)
$phpExecutable = $null

foreach ($candidate in $candidates) {
    if ($candidate -and (Test-Path -LiteralPath $candidate -PathType Leaf)) {
        $version = & $candidate -r 'echo PHP_VERSION_ID;'
        if ($LASTEXITCODE -eq 0 -and "$version" -match '^\d+$' -and [int] $version -ge 80401) {
            $phpExecutable = $candidate
            break
        }
    }
}

if (-not $phpExecutable) {
    throw 'PHP >=8.4.1 is required by composer.lock. Use an existing compatible CLI on PATH or Herd PHP 8.4/8.5; no software was installed.'
}

# Only this process and its children are configured. Never run tests using
# the normal database or a cached production configuration.
$overrides = @{
    PATH = (Split-Path $phpExecutable -Parent) + [IO.Path]::PathSeparator + $env:PATH
    APP_ENV = 'testing'
    APP_CONFIG_CACHE = (Join-Path ([IO.Path]::GetTempPath()) ('novinpay-test-config-' + [guid]::NewGuid() + '.php'))
    DB_CONNECTION = 'sqlite'
    DB_DATABASE = ':memory:'
    # A nonempty SQLite URL also overrides any DB_URL in the normal .env.
    DB_URL = 'sqlite:///:memory:'
    CACHE_STORE = 'array'
    SESSION_DRIVER = 'array'
    QUEUE_CONNECTION = 'sync'
    MAIL_MAILER = 'array'
}
$previous = @{}

Push-Location $projectRoot
try {
    foreach ($key in $overrides.Keys) {
        $previous[$key] = [Environment]::GetEnvironmentVariable($key, 'Process')
        [Environment]::SetEnvironmentVariable($key, $overrides[$key], 'Process')
    }
    & $phpExecutable artisan test @TestArguments
    $testExitCode = $LASTEXITCODE
} finally {
    foreach ($key in $previous.Keys) {
        [Environment]::SetEnvironmentVariable($key, $previous[$key], 'Process')
    }
    Pop-Location
}

exit $testExitCode
