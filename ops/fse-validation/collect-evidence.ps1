param([string]$Python = '', [string]$Settings = '')
$ErrorActionPreference = 'Stop'
$repoRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
if (!$Python) { $Python = Join-Path $repoRoot 'rest/writable/fse-validation-venv/Scripts/python.exe' }
if (!$Settings) { $Settings = Join-Path $repoRoot 'rest/writable/fse-validator-settings.json' }
if (!(Test-Path -LiteralPath $Python) -or !(Test-Path -LiteralPath $Settings)) { throw 'Configure the isolated runtime first.' }
function Get-FseSourceHashes {
    $hashes = [ordered]@{}
    $sources = @()
    foreach ($folder in @('rest/app/Services','rest/app/Models','rest/app/Filters','rest/tests/unit','rest/app/Database/Migrations','rest/app/Controllers/Admin')) {
        $sources += Get-ChildItem -LiteralPath (Join-Path $repoRoot $folder) -File | Where-Object { $_.Name -match 'Fse' }
    }
    foreach ($folder in @('rest/app/Views/admin/fse','ops/fse-validation')) {
        $sources += Get-ChildItem -LiteralPath (Join-Path $repoRoot $folder) -File
    }
    foreach ($path in @('rest/app/Config/Routes.php','rest/app/Config/Fse2.php','rest/app/Config/Filters.php','rest/app/Config/Format.php','rest/app/Controllers/Admin/FseAdminBaseController.php','rest/app/Controllers/Admin/FseDocumentsController.php','rest/app/Controllers/Admin/FseDashboardController.php','rest/tests/_support/fse_synthetic.php')) {
        $sources += Get-Item -LiteralPath (Join-Path $repoRoot $path)
    }
    foreach ($path in @('.dockerignore','Dockerfile','rest/app/Libraries/FilteredMigrationRunner.php','rest/app/Controllers/Tenant/FseSettingsController.php',
        'rest/app/Views/tenant/fse_settings.php','rest/app/Views/tenant/fse_onboarding.php',
        'rest/app/Config/App.php','rest/app/Libraries/TrustedProxyPolicy.php','rest/tests/unit/AppForwardedHeadersTest.php')) {
        $sources += Get-Item -LiteralPath (Join-Path $repoRoot $path)
    }
    foreach ($source in ($sources | Sort-Object FullName -Unique)) {
        $relative = $source.FullName.Substring($repoRoot.Length + 1).Replace('\','/')
        $hashes[$relative] = (Get-FileHash -LiteralPath $source.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
    }
    return $hashes
}
$runId = (Get-Date -Format 'yyyyMMdd-HHmmss') + '-' + [Guid]::NewGuid().ToString('N').Substring(0, 8)
$reportDir = Join-Path $repoRoot ('rest/writable/fse-validation-reports/' + $runId)
New-Item -ItemType Directory -Path $reportDir -Force | Out-Null
$previous = @{}
foreach ($key in @('XDEBUG_MODE','FSE2_VALIDATOR_PYTHON','FSE2_VALIDATOR_SETTINGS','FSE2_RUN_ARTIFACT_INTEGRATION','FSE2_PYTHON_TEST_REPORT','FSE2_LINUX_TEST_REPORT')) {
    $previous[$key] = [Environment]::GetEnvironmentVariable($key, 'Process')
}
$summary = [ordered]@{mode='OFFLINE_SYNTHETIC_ONLY'; official_accreditation_evidence=$false; generated_at=[DateTime]::UtcNow.ToString('o'); status='incomplete'; external_gateway_tests='NOT_EXECUTED'; source_scope='FSE files listed, not a full environment attestation'}
try {
    $env:XDEBUG_MODE = 'off'
    $env:FSE2_VALIDATOR_PYTHON = [IO.Path]::GetFullPath($Python)
    $env:FSE2_VALIDATOR_SETTINGS = [IO.Path]::GetFullPath($Settings)
    $env:FSE2_RUN_ARTIFACT_INTEGRATION = '1'
    $env:FSE2_PYTHON_TEST_REPORT = Join-Path $reportDir 'python-tests.json'
    $env:FSE2_LINUX_TEST_REPORT = Join-Path $reportDir 'linux-package-tests.json'
    $summary.git_head = (& git -C $repoRoot rev-parse HEAD).Trim()
    $summary.working_tree_dirty = [bool](& git -C $repoRoot status --porcelain)
    $summary.source_sha256 = Get-FseSourceHashes
    & $Python (Join-Path $PSScriptRoot 'test_linux_bundle.py') -v
    $packageExit = $LASTEXITCODE
    & $Python (Join-Path $PSScriptRoot 'test_validator.py') -v
    $pythonExit = $LASTEXITCODE
    $phpReport = Join-Path $reportDir 'php-junit.xml'
    Push-Location (Join-Path $repoRoot 'rest')
    try {
        php vendor/bin/phpunit -c ../ops/fse-validation/phpunit.xml --filter Fse --no-coverage --do-not-cache-result --log-junit $phpReport
        $phpExit = $LASTEXITCODE
    } finally { Pop-Location }
    $summary.python = Get-Content -LiteralPath $env:FSE2_PYTHON_TEST_REPORT -Raw | ConvertFrom-Json
    $summary.linux_package = Get-Content -LiteralPath $env:FSE2_LINUX_TEST_REPORT -Raw | ConvertFrom-Json
    [xml]$junit = Get-Content -LiteralPath $phpReport -Raw
    $suite = $junit.testsuites.testsuite | Select-Object -First 1
    $summary.php = [ordered]@{tests=[int]$suite.tests; assertions=[int]$suite.assertions; failures=[int]$suite.failures; errors=[int]$suite.errors; skipped=[int]$suite.skipped}
    if ($packageExit -eq 0 -and $summary.linux_package.passed -and $pythonExit -eq 0 -and $phpExit -eq 0 -and $summary.python.passed -and $summary.php.skipped -eq 0) { $summary.status = 'passed_offline' }
    else { $summary.status = 'failed_or_incomplete' }
    $summary.source_unchanged_during_run = (ConvertTo-Json $summary.source_sha256 -Compress) -ceq (ConvertTo-Json (Get-FseSourceHashes) -Compress)
    if (!$summary.source_unchanged_during_run) { $summary.status = 'incomplete_source_changed_during_run' }
} finally {
    $summary | ConvertTo-Json -Depth 8 | Set-Content -LiteralPath (Join-Path $reportDir 'summary.json') -Encoding utf8
    foreach ($key in $previous.Keys) { [Environment]::SetEnvironmentVariable($key, $previous[$key], 'Process') }
    Write-Output ('Offline evidence: ' + $reportDir)
}
if ($summary.status -ne 'passed_offline') { throw 'Evidence records failed/incomplete tests. It must not be presented as a passed collaudo.' }
