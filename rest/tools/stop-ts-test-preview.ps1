param(
    [int]$Port = 8086
)

$ErrorActionPreference = 'Stop'

$restRoot = Split-Path -Parent $PSScriptRoot
$logDirectory = Join-Path $restRoot 'writable\ts_preview'
$metaLogPath = Join-Path $logDirectory "ts_test_preview_${Port}.log"
$pidPath = Join-Path $logDirectory "ts_test_preview_${Port}.pid"

function Get-PreviewListenerPids {
    param(
        [int]$TargetPort
    )

    $pids = @()
    foreach ($line in (netstat -ano)) {
        if ($line -match "127\\.0\\.0\\.1:$TargetPort" -and $line -match 'LISTENING\s+(\d+)$') {
            $pids += [int]$Matches[1]
        }
    }

    return @($pids | Select-Object -Unique)
}

function Stop-PreviewProcess {
    param(
        [int]$TargetPid
    )

    if ($TargetPid -le 0) {
        return $false
    }

    $running = Get-Process -Id $TargetPid -ErrorAction SilentlyContinue
    if (-not $running) {
        return $false
    }

    Stop-Process -Id $TargetPid -Force
    "[$(Get-Date -Format s)] TS test preview fermata su porta $Port (pid $TargetPid)" | Out-File -FilePath $metaLogPath -Encoding utf8 -Append
    return $true
}

$listenerPids = Get-PreviewListenerPids -TargetPort $Port

function Stop-PreviewProcesses {
    param(
        [int[]]$TargetPids
    )

    $stoppedAny = $false

    foreach ($targetPid in @($TargetPids | Where-Object { $_ -gt 0 } | Select-Object -Unique)) {
        if (Stop-PreviewProcess -TargetPid $targetPid) {
            $stoppedAny = $true
        }
    }

    return $stoppedAny
}

if (-not (Test-Path -LiteralPath $pidPath)) {
    if (Stop-PreviewProcesses -TargetPids $listenerPids) {
        Write-Output "Preview TS fermata sulla porta $Port."
        exit 0
    }

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
    if (Stop-PreviewProcesses -TargetPids $listenerPids) {
        Write-Output "Preview TS fermata sulla porta $Port."
        exit 0
    }

    Write-Output 'Pid non valido. File ripulito.'
    exit 0
}

$targetPids = @($previewPid) + @($listenerPids | Where-Object { $_ -ne $previewPid })
[void](Stop-PreviewProcesses -TargetPids $targetPids)

Remove-Item -LiteralPath $pidPath -Force -ErrorAction SilentlyContinue
Write-Output "Preview TS fermata sulla porta $Port."
