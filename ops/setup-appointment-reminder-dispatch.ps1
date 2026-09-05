[CmdletBinding()]
param(
    [string]$ConfigPath = "",
    [switch]$DryRun
)

$ErrorActionPreference = "Stop"

function Resolve-ConfigPath([string]$Path) {
    if ($Path) { return $Path }
    return Join-Path $PSScriptRoot "release-config.local.json"
}

function To-Array($Value) {
    $items = @()
    if ($null -eq $Value) { return $items }
    foreach ($item in $Value) { $items += $item }
    return $items
}

$path = Resolve-ConfigPath $ConfigPath
if (-not (Test-Path -LiteralPath $path)) { throw "Config non trovato: $path" }
$config = Get-Content -Raw -LiteralPath $path | ConvertFrom-Json
if ([string]::IsNullOrWhiteSpace([string]$config.coolifyBaseUrl) -or [string]::IsNullOrWhiteSpace([string]$config.coolifyToken)) {
    throw "Configurazione Coolify incompleta."
}

$appUuid = [string]$config.targets.login.appUuid
if ([string]::IsNullOrWhiteSpace($appUuid)) { throw "appUuid mancante per login" }

$headers = @{ Authorization = "Bearer $($config.coolifyToken)"; Accept = "application/json" }
$baseUrl = ([string]$config.coolifyBaseUrl).TrimEnd('/')
$tasksUri = "$baseUrl/api/v1/applications/$appUuid/scheduled-tasks"
$tasks = if ($DryRun) { @() } else { To-Array (Invoke-RestMethod -Method Get -Uri $tasksUri -Headers $headers) }
$payload = @{
    name = "appointment-reminder-dispatch"
    command = "php /var/www/html/rest/spark appointment-reminders:run --no-header"
    frequency = "*/5 * * * *"
    timeout = 82800
    enabled = $true
}
$existing = $tasks | Where-Object { $_.name -eq $payload.name } | Select-Object -First 1

if ($DryRun) {
    Write-Host "[login] dry-run: task $($payload.name) ogni 5 minuti; il comando si attiva dalle 08:00 Europe/Rome e una sola volta al giorno"
} elseif ($existing) {
    Invoke-RestMethod -Method Patch -Uri "$tasksUri/$($existing.uuid)" -Headers $headers -ContentType "application/json" -Body ($payload | ConvertTo-Json -Compress) | Out-Null
    Write-Host "[login] task reminder aggiornata"
} else {
    Invoke-RestMethod -Method Post -Uri $tasksUri -Headers $headers -ContentType "application/json" -Body ($payload | ConvertTo-Json -Compress) | Out-Null
    Write-Host "[login] task reminder creata"
}
