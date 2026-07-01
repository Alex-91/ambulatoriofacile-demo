[CmdletBinding()]
param(
    [string]$ConfigPath = "",
    [switch]$DryRun
)

$ErrorActionPreference = "Stop"

$setupScript = Join-Path $PSScriptRoot "setup-test-db-refresh.ps1"
if (-not (Test-Path $setupScript)) {
    throw "Script non trovato: $setupScript"
}

$arguments = @(
    "-ExecutionPolicy", "Bypass",
    "-File", $setupScript,
    "-RunOnceCheck"
)

if ($ConfigPath) {
    $arguments += @("-ConfigPath", $ConfigPath)
}

if ($DryRun.IsPresent) {
    $arguments += "-DryRun"
}

& powershell @arguments
if ($LASTEXITCODE -ne 0) {
    throw "Refresh immediato del DB test fallito."
}
