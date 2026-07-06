param(
    [int]$Port = 8086
)

$ErrorActionPreference = 'Stop'

$restRoot = Split-Path -Parent $PSScriptRoot
$logDirectory = Join-Path $restRoot 'writable\ts_preview'
$metaLogPath = Join-Path $logDirectory "ts_test_preview_${Port}.log"
$pidPath = Join-Path $logDirectory "ts_test_preview_${Port}.pid"

if (-not (Test-Path -LiteralPath $pidPath)) {
    Write-Output "Nessun pid file trovato per la preview TS su porta $Port."
    exit 0
}

$pidValue = Get-Content -LiteralPath $pidPath -ErrorAction SilentlyContinue | Select-Object -First 1
$previewPid = 0

if ($pidValue) {
    $previewPid = [int]$pidValue
}

if ($previewPid -le 0) {
    Remove-Item -LiteralPath $pidPath -Force -ErrorAction SilentlyContinue
    Write-Output 'Pid non valido. File ripulito.'
    exit 0
}

$running = Get-Process -Id $previewPid -ErrorAction SilentlyContinue

if ($running) {
    Stop-Process -Id $previewPid -Force
    "[$(Get-Date -Format s)] TS test preview fermata su porta $Port (pid $previewPid)" | Out-File -FilePath $metaLogPath -Encoding utf8 -Append
}

Remove-Item -LiteralPath $pidPath -Force -ErrorAction SilentlyContinue
Write-Output "Preview TS fermata sulla porta $Port."
