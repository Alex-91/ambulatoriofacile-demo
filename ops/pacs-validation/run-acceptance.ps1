param([string]$Php = 'php', [string]$Python = 'python')
$ErrorActionPreference = 'Stop'
$acceptanceRepo = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$acceptanceDir = Join-Path $acceptanceRepo ('rest/writable/acceptance-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '-' + [guid]::NewGuid().ToString('N').Substring(0,8))
New-Item -ItemType Directory -Path $acceptanceDir | Out-Null
$acceptanceResults = @()
Push-Location $acceptanceRepo
try {
    $acceptanceSuites = @(
        @{ name='pacs-access-setup'; args=@('--exclude-group','pacs_lab','--fail-on-skipped') },
        @{ name='clinical-record'; args=@('rest/tests/unit/ClinicalRecordTest.php') },
        @{ name='session-access'; args=@('rest/tests/session/SessionAuthHelperTest.php','rest/tests/session/LegacyLoginHandoffServiceTest.php') }
    )
    foreach ($acceptanceSuite in $acceptanceSuites) {
        $acceptanceJunit = Join-Path $acceptanceDir ($acceptanceSuite.name + '.xml')
        $acceptanceArgs = @('-d','xdebug.mode=off','rest/vendor/phpunit/phpunit/phpunit','-c','ops/pacs-validation/phpunit.xml','--do-not-cache-result','--log-junit',$acceptanceJunit) + $acceptanceSuite.args
        & $Php @acceptanceArgs
        $acceptanceExit = $LASTEXITCODE
        $acceptanceResults += @{name=$acceptanceSuite.name;passed=($acceptanceExit -eq 0);exit_code=$acceptanceExit}
        if ($acceptanceExit -ne 0) { throw ('Verifica fallita: ' + $acceptanceSuite.name) }
    }
    Push-Location (Join-Path $acceptanceRepo 'ops/fse-validation')
    try {
        & $Python -m unittest test_clinical_signature.ClinicalSignatures -q
        $acceptanceExit = $LASTEXITCODE
        $acceptanceResults += @{name='clinical-signatures-synthetic';passed=($acceptanceExit -eq 0);exit_code=$acceptanceExit}
        if ($acceptanceExit -ne 0) { throw 'Verifica firme sintetiche fallita.' }
    } finally { Pop-Location }
} finally {
    $acceptanceReport = @{checked_at=(Get-Date).ToUniversalTime().ToString('o');scope='Suite isolata con SQLite, storage temporaneo e firme sintetiche. Nessun database cliente.';external_validation='not_assessed';results=$acceptanceResults;complete=($acceptanceResults.Count -eq 4 -and @($acceptanceResults | Where-Object { !$_.passed }).Count -eq 0)}
    $acceptanceReport | ConvertTo-Json -Depth 5 | Set-Content -LiteralPath (Join-Path $acceptanceDir 'result.json') -Encoding UTF8
    Write-Output ('Esiti: ' + $acceptanceDir)
    Pop-Location
}
