[CmdletBinding()]
param(
    [Parameter(Mandatory)][ValidateSet('demo','login')][string]$Target,
    [ValidateRange(0,2147483647)][int]$TenantId = 0,
    [switch]$AllEnabled,
    [Parameter(Mandatory)][string]$PatientUrl,
    [string]$ConfigPath = (Join-Path $PSScriptRoot 'release-config.local.json'),
    [switch]$DryRun
)
$ErrorActionPreference = 'Stop'
if (($AllEnabled -and $TenantId -gt 0) -or (-not $AllEnabled -and $TenantId -le 0)) { throw 'Specificare TenantId oppure AllEnabled.' }
$uri = $null
if (-not [Uri]::TryCreate($PatientUrl,[UriKind]::Absolute,[ref]$uri) -or $uri.Scheme -ne 'https' -or $PatientUrl -match "['\s]" -or $uri.Query -or $uri.Fragment -or $uri.UserInfo) { throw 'Serve un URL HTTPS interno senza credenziali, query o frammento.' }
$scope = if ($AllEnabled) { '--all-enabled' } else { "--tenant $TenantId" }
$payload = @{
    name = $(if ($AllEnabled) { 'patient-booking-all-enabled' } else { "patient-booking-$TenantId" })
    command = "php /var/www/html/rest/spark prenota:scadenze $scope --patient-url '$PatientUrl' --no-header"
    frequency = '*/5 * * * *'
    timeout = 240
    enabled = $true
}
if ($payload.command.Length -gt 250) { throw 'Comando oltre il limite Coolify di 250 caratteri.' }
if ($DryRun) { $payload | ConvertTo-Json; return }
$config = Get-Content -Raw -LiteralPath $ConfigPath | ConvertFrom-Json
$appUuid = [string]$config.targets.$Target.appUuid
if (-not $appUuid) { throw 'Target Coolify non configurato.' }
$headers = @{ Authorization = "Bearer $($config.coolifyToken)"; Accept = 'application/json' }
$tasksUri = "$(([string]$config.coolifyBaseUrl).TrimEnd('/'))/api/v1/applications/$appUuid/scheduled-tasks"
$tasks = @(Invoke-RestMethod -Method Get -Uri $tasksUri -Headers $headers | ForEach-Object { $_ })
$matches = @($tasks | Where-Object { $_.name -eq $payload.name })
if ($matches.Count -gt 1) { throw 'Più task omonime: correggere i duplicati prima di procedere.' }
if ($AllEnabled -and @($tasks | Where-Object { $_.enabled -and $_.name -match '^patient-booking-\d+$' }).Count -gt 0) { throw 'Disabilitare le vecchie task per spazio prima di configurare AllEnabled.' }
$existing = $matches | Select-Object -First 1
if ($existing) { Invoke-RestMethod -Method Patch -Uri "$tasksUri/$($existing.uuid)" -Headers $headers -ContentType 'application/json' -Body ($payload | ConvertTo-Json -Compress) | Out-Null }
else { Invoke-RestMethod -Method Post -Uri $tasksUri -Headers $headers -ContentType 'application/json' -Body ($payload | ConvertTo-Json -Compress) | Out-Null }
Write-Host "[$Target] manutenzione prenotazioni ($scope) configurata ogni 5 minuti."
