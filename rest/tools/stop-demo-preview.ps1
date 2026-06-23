param(
    [int]$Port = 8085
)

$ErrorActionPreference = 'Stop'

$restRoot = Split-Path -Parent $PSScriptRoot
$logDirectory = Join-Path $restRoot 'writable\demo_setup'
$metaLogPath = Join-Path $logDirectory "serve_demo_preview_${Port}.log"
$pidPath = Join-Path $logDirectory "serve_demo_preview_${Port}.pid"

if (-not (Test-Path -LiteralPath $pidPath)) {
    Write-Output "Nessun pid file trovato per la preview demo su porta $Port."
    exit 0
}

$pidValue = Get-Content -LiteralPath $pidPath -ErrorAction SilentlyContinue | Select-Object -First 1
$demoPid = 0

if ($pidValue) {
    $demoPid = [int]$pidValue
}

if ($demoPid -le 0) {
    Remove-Item -LiteralPath $pidPath -Force -ErrorAction SilentlyContinue
    Write-Output "Pid non valido. File ripulito."
    exit 0
}

$running = Get-Process -Id $demoPid -ErrorAction SilentlyContinue

if ($running) {
    Stop-Process -Id $demoPid -Force
    "[$(Get-Date -Format s)] Demo preview fermata su porta $Port (pid $demoPid)" | Out-File -FilePath $metaLogPath -Encoding utf8 -Append
}

Remove-Item -LiteralPath $pidPath -Force -ErrorAction SilentlyContinue
Write-Output "Preview demo fermata sulla porta $Port."
