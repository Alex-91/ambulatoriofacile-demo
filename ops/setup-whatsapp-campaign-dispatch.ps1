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
        # Use the Italian civil time independently of the scheduler/server timezone.
        # Poll every minute; the worker enforces each tenant's UI-configured rate
        # and checks the closing time before each send. Keep command below 255 chars.
        command = 'php -r ''date_default_timezone_set("Europe/Rome");$t=date("Hi");if($t<"0730"||$t>="2230"){echo "Night pause\n";exit;}passthru("timeout 85s php /var/www/html/rest/spark whatsapp-campaigns:run --no-header",$s);exit($s);'''
        frequency = '* * * * *'
        timeout = 90
        enabled = $true
    }
    $existing = $tasks | Where-Object { $_.name -eq $payload.name } | Select-Object -First 1
    if ($DryRun) {
        Write-Host "[$targetName] dry-run: task $($payload.name) ogni minuto, ritmo dai parametri dello spazio, 07:30-22:30 Europe/Rome"
    } elseif ($existing) {
        Invoke-RestMethod -Method Patch -Uri "$tasksUri/$($existing.uuid)" -Headers $headers -ContentType 'application/json' -Body ($payload | ConvertTo-Json -Compress) | Out-Null
        Write-Host "[$targetName] task aggiornata"
    } else {
        Invoke-RestMethod -Method Post -Uri $tasksUri -Headers $headers -ContentType 'application/json' -Body ($payload | ConvertTo-Json -Compress) | Out-Null
        Write-Host "[$targetName] task creata"
    }
}
