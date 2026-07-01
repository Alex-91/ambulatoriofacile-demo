[CmdletBinding()]
param(
    [string]$ConfigPath = "ops/release-config.local.json",
    [switch]$DryRun
)

$scriptPath = Join-Path $PSScriptRoot "switch-local-db-mode.ps1"
& $scriptPath -Mode local -ConfigPath $ConfigPath -DryRun:$DryRun
if ($LASTEXITCODE -ne 0) {
    exit $LASTEXITCODE
}
