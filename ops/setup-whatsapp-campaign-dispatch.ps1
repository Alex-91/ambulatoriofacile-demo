[CmdletBinding()]
param(
    [ValidateSet("demo", "login", "both")]
    [string]$Target = "both",
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
    if ($null -ne $Value) { foreach ($item in $Value) { $items += $item } }
    return $items
}

$path = Resolve-ConfigPath $ConfigPath
if (-not (Test-Path -LiteralPath $path)) { throw "Config non trovato: $path" }
$config = Get-Content -Raw -LiteralPath $path | ConvertFrom-Json
if ([string]::IsNullOrWhiteSpace([string]$config.coolifyBaseUrl) -or [string]::IsNullOrWhiteSpace([string]$config.coolifyToken)) {
    throw "Configurazione Coolify incompleta."
}
$headers = @{ Authorization = "Bearer $($config.coolifyToken)"; Accept = "application/json" }
$baseUrl = ([string]$config.coolifyBaseUrl).TrimEnd('/')
$targetNames = if ($Target -eq 'both') { @('demo', 'login') } else { @($Target) }

foreach ($targetName in $targetNames) {
    $appUuid = [string]$config.targets.$targetName.appUuid
    if ([string]::IsNullOrWhiteSpace($appUuid)) { throw "appUuid mancante per $targetName" }
    $tasksUri = "$baseUrl/api/v1/applications/$appUuid/scheduled-tasks"
    $tasks = if ($DryRun) { @() } else { To-Array (Invoke-RestMethod -Method Get -Uri $tasksUri -Headers $headers) }
    $payload = @{
        name = 'whatsapp-campaign-dispatch'
        command = 'php /var/www/html/rest/spark whatsapp-campaigns:run --no-header'
        frequency = '* * * * *'
        timeout = 90
        enabled = $true
    }
    $existing = $tasks | Where-Object { $_.name -eq $payload.name } | Select-Object -First 1
    if ($DryRun) {
        Write-Host "[$targetName] dry-run: task $($payload.name) ogni minuto"
    } elseif ($existing) {
        Invoke-RestMethod -Method Patch -Uri "$tasksUri/$($existing.uuid)" -Headers $headers -ContentType 'application/json' -Body ($payload | ConvertTo-Json -Compress) | Out-Null
        Write-Host "[$targetName] task aggiornata"
    } else {
        Invoke-RestMethod -Method Post -Uri $tasksUri -Headers $headers -ContentType 'application/json' -Body ($payload | ConvertTo-Json -Compress) | Out-Null
        Write-Host "[$targetName] task creata"
    }
}
