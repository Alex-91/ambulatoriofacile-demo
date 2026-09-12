# Resolve security updates in a fresh private copy. Does not install, activate, deploy or replace project dependencies.
[CmdletBinding()]
param()
$ErrorActionPreference = 'Stop'
$repo = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$directory = Join-Path $repo ('rest/writable/fse-dependency-candidates/' + [Guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $directory | Out-Null
$encoding = [Text.UTF8Encoding]::new($false)
$hashes = @{}
foreach ($name in @('composer.json','composer.lock')) {
    $source = Join-Path $repo $name
    $hashes[$name] = (Get-FileHash -LiteralPath $source).Hash
    Copy-Item -LiteralPath $source -Destination (Join-Path $directory $name)
}
$before = Get-Content -LiteralPath (Join-Path $repo 'composer.lock') -Raw | ConvertFrom-Json
$raw = & composer --no-interaction --no-plugins --no-scripts --working-dir $directory update dompdf/dompdf guzzlehttp/guzzle guzzlehttp/psr7 web-auth/webauthn-lib web-token/jwt-library --with-all-dependencies --minimal-changes --no-install --no-progress 2>&1
$code = $LASTEXITCODE
[IO.File]::WriteAllText((Join-Path $directory 'resolve.log'), ($raw -join "`n"), $encoding)
$summary = [ordered]@{generated_at=[DateTime]::UtcNow.ToString('o');status='resolution_failed';installed=$false;production_changed=$false;exit_code=$code}
if ($code -eq 0) {
    $after = Get-Content -LiteralPath (Join-Path $directory 'composer.lock') -Raw | ConvertFrom-Json
    $changes = @()
    foreach ($package in @($after.packages)+@($after.'packages-dev')) {
        if (!$package) { continue }
        $old = @($before.packages)+@($before.'packages-dev') | Where-Object name -eq $package.name | Select-Object -First 1
        if ($old.version -ne $package.version) { $changes += [ordered]@{name=$package.name;before=$old.version;candidate=$package.version} }
    }
    $summary.changes=$changes
    $audit = & composer --no-interaction --no-plugins --no-scripts --working-dir $directory audit --locked --format=json 2> (Join-Path $directory 'audit.stderr.txt')
    $summary.audit_exit_code=$LASTEXITCODE
    [IO.File]::WriteAllText((Join-Path $directory 'audit.json'), ($audit -join "`n"), $encoding)
    $parsed = ($audit -join "`n") | ConvertFrom-Json
    $summary.status = if ($summary.audit_exit_code -eq 0 -and $null -ne $parsed.advisories) { 'resolved_audit_clean_NOT_INSTALLED_OR_TESTED' } else { 'resolved_audit_requires_review' }
}
$summary.source_unchanged = $true
foreach ($name in $hashes.Keys) {
    if ((Get-FileHash -LiteralPath (Join-Path $repo $name)).Hash -ne $hashes[$name]) { $summary.source_unchanged=$false }
}
if (!$summary.source_unchanged) { $summary.status='incomplete_source_changed' }
[IO.File]::WriteAllText((Join-Path $directory 'summary.json'), ($summary | ConvertTo-Json -Depth 8), $encoding)
[pscustomobject]@{Directory=$directory;Summary=$summary}
