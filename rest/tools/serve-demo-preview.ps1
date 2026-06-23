param(
    [int]$Port = 8085
)

$ErrorActionPreference = 'Stop'

$restRoot = Split-Path -Parent $PSScriptRoot
$repoRoot = Split-Path -Parent $restRoot
$envFile = Join-Path $restRoot '.env.demo'
$logDirectory = Join-Path $restRoot 'writable\demo_setup'
$metaLogPath = Join-Path $logDirectory "serve_demo_preview_${Port}.log"
$stdoutLogPath = Join-Path $logDirectory "serve_demo_preview_${Port}.stdout.log"
$stderrLogPath = Join-Path $logDirectory "serve_demo_preview_${Port}.stderr.log"
$pidPath = Join-Path $logDirectory "serve_demo_preview_${Port}.pid"
$previewUrl = "http://127.0.0.1:${Port}/"
$router = Join-Path $repoRoot 'router.php'

if (-not (Test-Path -LiteralPath $envFile)) {
    throw "File demo env non trovato: $envFile"
}

if (-not (Test-Path -LiteralPath $router)) {
    throw "Router root non trovato: $router"
}

New-Item -ItemType Directory -Force -Path $logDirectory | Out-Null

Get-Content -LiteralPath $envFile | ForEach-Object {
    if ($_ -match '^\s*([^#=]+?)\s*=\s*(.*)\s*$') {
        $key = $matches[1].Trim()
        $value = $matches[2].Trim()

        if ($value.Length -ge 2 -and (($value.StartsWith('"') -and $value.EndsWith('"')) -or ($value.StartsWith("'") -and $value.EndsWith("'")))) {
            $value = $value.Substring(1, $value.Length - 2)
        }

        if ($key -ne '') {
            Set-Item -Path ("Env:" + $key) -Value $value
        }
    }
}

Set-Item -Path 'Env:CI_ENVIRONMENT' -Value 'development'
Set-Item -Path 'Env:app.baseURL' -Value $previewUrl
Set-Item -Path 'Env:APP_CANONICAL_URL' -Value $previewUrl
Set-Item -Path 'Env:APP_RUNTIME_ENV_FILE' -Value $envFile
Set-Item -Path 'Env:DEMO_PUBLIC_ROLE_SWITCH_ENABLED' -Value '1'
Set-Item -Path 'Env:demo.publicRoleSwitchEnabled' -Value '1'

Set-Location $repoRoot

$phpCandidates = @(
    (Get-Command php -ErrorAction SilentlyContinue | Select-Object -ExpandProperty Source -ErrorAction SilentlyContinue),
    'C:\wamp64\bin\php\php8.3.6\php.exe',
    'C:\xampp_82\php\php.exe'
) | Where-Object { $_ -and (Test-Path -LiteralPath $_) } | Select-Object -Unique

if (-not $phpCandidates) {
    throw 'php.exe non trovato per la preview demo.'
}

$phpExe = @($phpCandidates)[0]
$existingPid = 0
if (Test-Path -LiteralPath $pidPath) {
    $existingPidValue = Get-Content -LiteralPath $pidPath -ErrorAction SilentlyContinue | Select-Object -First 1
    if ($existingPidValue) {
        $existingPid = [int]$existingPidValue
    }
}

if ($existingPid -gt 0) {
    $running = Get-Process -Id $existingPid -ErrorAction SilentlyContinue
    if ($running) {
        "[$(Get-Date -Format s)] Demo preview gia attiva su $previewUrl con pid $existingPid" | Out-File -FilePath $metaLogPath -Encoding utf8 -Append
        exit 0
    }
}

$listenerPid = 0
foreach ($line in (netstat -ano)) {
    if ($line -match "127\\.0\\.0\\.1:$Port" -and $line -match 'LISTENING\s+(\d+)$') {
        $listenerPid = [int]$Matches[1]
        break
    }
}

if ($listenerPid -gt 0 -and $listenerPid -ne $existingPid) {
    throw "La porta $Port e gia occupata dal pid $listenerPid. Ferma prima l altra preview oppure usa un altra porta."
}

[System.Environment]::SetEnvironmentVariable('PATH', $null, 'Process')
$env:Path = ((Split-Path -Parent $phpExe) + ';C:\WINDOWS\System32;C:\WINDOWS')

if (Test-Path -LiteralPath $stdoutLogPath) {
    Remove-Item -LiteralPath $stdoutLogPath -Force
}

if (Test-Path -LiteralPath $stderrLogPath) {
    Remove-Item -LiteralPath $stderrLogPath -Force
}

$process = Start-Process -FilePath $phpExe `
    -ArgumentList @('-d', 'xdebug.mode=off', '-S', "127.0.0.1:${Port}", 'router.php') `
    -WorkingDirectory $repoRoot `
    -WindowStyle Hidden `
    -RedirectStandardOutput $stdoutLogPath `
    -RedirectStandardError $stderrLogPath `
    -PassThru

Start-Sleep -Seconds 2

if (-not (Get-Process -Id $process.Id -ErrorAction SilentlyContinue)) {
    Remove-Item -LiteralPath $pidPath -Force -ErrorAction SilentlyContinue
    throw "La preview demo su $previewUrl si e chiusa subito. Controlla $stderrLogPath"
}

"[$(Get-Date -Format s)] Demo preview avviata su $previewUrl con $phpExe (pid $($process.Id))" | Out-File -FilePath $metaLogPath -Encoding utf8 -Append
$process.Id | Out-File -FilePath $pidPath -Encoding ascii
