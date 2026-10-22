[CmdletBinding()]
param(
    [Parameter(Mandatory)][ValidateSet('demo','login')][string]$Target,
    [Parameter(Mandatory)][ValidateRange(1,2147483647)][int]$TenantId,
    [Parameter(Mandatory)][string]$PatientUrl,
    [string]$ConfigPath = (Join-Path $PSScriptRoot 'release-config.local.json'),
    [switch]$DryRun
)
$ErrorActionPreference = 'Stop'
$uri = $null
if (-not [Uri]::TryCreate($PatientUrl,[UriKind]::Absolute,[ref]$uri) -or $uri.Scheme -ne 'https' -or $PatientUrl -match "['\s]" -or $uri.Query -or $uri.Fragment) { throw 'Serve un URL HTTPS interno senza query o frammento.' }
$payload = @{
    name = "patient-booking-$TenantId"
    command = "php /var/www/html/rest/spark prenota:scadenze --tenant $TenantId --patient-url '$PatientUrl' --no-header"
    frequency = '*/5 * * * *'
    timeout = 240
    enabled = $true
}
if ($DryRun) { $payload | ConvertTo-Json; return }
$config = Get-Content -Raw -LiteralPath $ConfigPath | ConvertFrom-Json
$appUuid = [string]$config.targets.$Target.appUuid
if (-not $appUuid) { throw 'Target Coolify non configurato.' }
$headers = @{ Authorization = "Bearer $($config.coolifyToken)"; Accept = 'application/json' }
$tasksUri = "$(([string]$config.coolifyBaseUrl).TrimEnd('/'))/api/v1/applications/$appUuid/scheduled-tasks"
$tasks = @(Invoke-RestMethod -Method Get -Uri $tasksUri -Headers $headers)
$existing = $tasks | Where-Object { $_.name -eq $payload.name } | Select-Object -First 1
if ($existing) { Invoke-RestMethod -Method Patch -Uri "$tasksUri/$($existing.uuid)" -Headers $headers -ContentType 'application/json' -Body ($payload | ConvertTo-Json -Compress) | Out-Null }
else { Invoke-RestMethod -Method Post -Uri $tasksUri -Headers $headers -ContentType 'application/json' -Body ($payload | ConvertTo-Json -Compress) | Out-Null }
Write-Host "[$Target] manutenzione prenotazioni dello spazio $TenantId configurata ogni 5 minuti."
