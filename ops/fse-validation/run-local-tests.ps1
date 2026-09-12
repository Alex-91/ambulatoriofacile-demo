param([string]$Python = '', [string]$Settings = '')
$ErrorActionPreference = 'Stop'
$repoRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
if (!$Python) { $Python = Join-Path $repoRoot 'rest/writable/fse-validation-venv/Scripts/python.exe' }
if (!$Settings) { $Settings = Join-Path $repoRoot 'rest/writable/fse-validator-settings.json' }
if (!(Test-Path -LiteralPath $Python) -or !(Test-Path -LiteralPath $Settings)) { throw 'Set up the isolated runtime and local settings first; see README.md.' }
$oldValues = @{}
foreach ($key in @('XDEBUG_MODE', 'FSE2_VALIDATOR_PYTHON', 'FSE2_VALIDATOR_SETTINGS', 'FSE2_RUN_ARTIFACT_INTEGRATION')) {
    $oldValues[$key] = [Environment]::GetEnvironmentVariable($key, 'Process')
}
try {
    $env:XDEBUG_MODE = 'off'
    $env:FSE2_VALIDATOR_PYTHON = [IO.Path]::GetFullPath($Python)
    $env:FSE2_VALIDATOR_SETTINGS = [IO.Path]::GetFullPath($Settings)
    $env:FSE2_RUN_ARTIFACT_INTEGRATION = '1'
    & $Python (Join-Path $PSScriptRoot 'test_validator.py') -v
    if ($LASTEXITCODE -ne 0) { throw 'Artifact validation tests failed.' }
    Push-Location (Join-Path $repoRoot 'rest')
    try {
        php vendor/bin/phpunit -c ../ops/fse-validation/phpunit.xml --filter Fse --no-coverage --do-not-cache-result
        if ($LASTEXITCODE -ne 0) { throw 'PHP FSE tests failed.' }
    } finally { Pop-Location }
} finally {
    foreach ($key in $oldValues.Keys) { [Environment]::SetEnvironmentVariable($key, $oldValues[$key], 'Process') }
}
