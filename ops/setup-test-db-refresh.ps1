[CmdletBinding()]
param(
    [string]$ConfigPath = "",
    [switch]$DryRun,
    [switch]$RunOnceCheck
)

$ErrorActionPreference = "Stop"

function Resolve-ConfigPath {
    param([string]$ConfiguredPath)

    if ($ConfiguredPath) {
        return $ConfiguredPath
    }

    return Join-Path $PSScriptRoot "release-config.local.json"
}

function Load-Config {
    param([string]$Path)

    if (-not (Test-Path $Path)) {
        throw "Config non trovato: $Path"
    }

    try {
        return Get-Content $Path -Raw | ConvertFrom-Json
    }
    catch {
        throw "Config JSON non valido in $Path. $($_.Exception.Message)"
    }
}

function Save-Config {
    param(
        [string]$Path,
        [object]$Config
    )

    $json = $Config | ConvertTo-Json -Depth 20
    Set-Content -LiteralPath $Path -Value $json -Encoding utf8
}

function Write-Step {
    param([string]$Message)

    Write-Host "[db-refresh] $Message"
}

function Ensure-ObjectProperty {
    param(
        [object]$Target,
        [string]$PropertyName,
        [object]$DefaultValue
    )

    $property = $Target.PSObject.Properties[$PropertyName]
    if (-not $property) {
        $Target | Add-Member -NotePropertyName $PropertyName -NotePropertyValue $DefaultValue
        return $Target.PSObject.Properties[$PropertyName].Value
    }

    if ($null -eq $property.Value) {
        $Target.$PropertyName = $DefaultValue
    }

    return $Target.PSObject.Properties[$PropertyName].Value
}

function Ensure-RefreshProperty {
    param(
        [object]$RefreshConfig,
        [string]$PropertyName,
        [object]$DefaultValue
    )

    $value = Ensure-ObjectProperty -Target $RefreshConfig -PropertyName $PropertyName -DefaultValue $DefaultValue
    return $value
}

function Set-ObjectProperty {
    param(
        [object]$Target,
        [string]$PropertyName,
        [object]$Value
    )

    $existing = $Target.PSObject.Properties[$PropertyName]
    if ($existing) {
        $Target.$PropertyName = $Value
        return
    }

    $Target | Add-Member -NotePropertyName $PropertyName -NotePropertyValue $Value
}

function Convert-ToFlatArray {
    param([object]$Value)

    $items = @()
    if ($null -eq $Value) {
        return $items
    }

    foreach ($item in $Value) {
        $items += $item
    }

    return $items
}

function Require-Field {
    param(
        [string]$Label,
        [object]$Value
    )

    if ([string]::IsNullOrWhiteSpace([string]$Value)) {
        throw "Campo obbligatorio mancante: $Label"
    }
}

function Get-ApiErrorMessage {
    param([System.Management.Automation.ErrorRecord]$ErrorRecord)

    if ($ErrorRecord.ErrorDetails -and $ErrorRecord.ErrorDetails.Message) {
        return $ErrorRecord.ErrorDetails.Message
    }

    if ($ErrorRecord.Exception -and $ErrorRecord.Exception.Response) {
        try {
            $stream = $ErrorRecord.Exception.Response.GetResponseStream()
            if ($stream) {
                $reader = New-Object System.IO.StreamReader($stream)
                $message = $reader.ReadToEnd()
                $reader.Close()
                if ($message) {
                    return $message
                }
            }
        }
        catch {
        }
    }

    return $ErrorRecord.Exception.Message
}

function Invoke-CoolifyApi {
    param(
        [ValidateSet("Get", "Post", "Patch", "Delete")]
        [string]$Method,
        [string]$Path,
        [object]$Body = $null,
        [switch]$AllowEmptyResponse
    )

    $uri = "$script:CoolifyBaseUrl$Path"
    $headers = @{
        Authorization = "Bearer $script:CoolifyToken"
        Accept        = "application/json"
    }

    if ($DryRun.IsPresent -and $Method -ne "Get") {
        Write-Step "dry-run $Method $Path"
        if ($PSBoundParameters.ContainsKey("Body") -and $null -ne $Body) {
            return $Body
        }

        return $null
    }

    try {
        if ($PSBoundParameters.ContainsKey("Body") -and $null -ne $Body) {
            $json = $Body | ConvertTo-Json -Depth 20 -Compress
            return Invoke-RestMethod -Uri $uri -Method $Method -Headers $headers -ContentType "application/json" -Body $json
        }

        return Invoke-RestMethod -Uri $uri -Method $Method -Headers $headers
    }
    catch {
        $message = Get-ApiErrorMessage -ErrorRecord $_
        if ($AllowEmptyResponse.IsPresent -and [string]::IsNullOrWhiteSpace($message)) {
            return $null
        }

        throw "Chiamata API fallita ($Method $Path): $message"
    }
}

function Find-SingleResourceByName {
    param(
        [array]$Items,
        [string]$Name,
        [string]$Label
    )

    $matches = @($Items | Where-Object { $_.name -eq $Name })
    if ($matches.Count -eq 1) {
        return $matches[0]
    }

    if ($matches.Count -eq 0) {
        throw "$Label non trovato con nome '$Name'."
    }

    throw "Trovati piu' elementi per $Label con nome '$Name'. Specifica l'UUID in dbRefresh."
}

function Resolve-Project {
    param(
        [object]$RefreshConfig,
        [array]$Projects
    )

    if ($RefreshConfig.projectUuid) {
        $match = @($Projects | Where-Object { $_.uuid -eq $RefreshConfig.projectUuid })
        if ($match.Count -eq 1) {
            return $match[0]
        }

        throw "Project UUID non trovato: $($RefreshConfig.projectUuid)"
    }

    return Find-SingleResourceByName -Items $Projects -Name ([string]$RefreshConfig.projectName) -Label "Progetto"
}

function Resolve-Environment {
    param(
        [object]$RefreshConfig,
        [array]$Environments
    )

    if ($RefreshConfig.environmentUuid) {
        $match = @($Environments | Where-Object { $_.uuid -eq $RefreshConfig.environmentUuid })
        if ($match.Count -eq 1) {
            return $match[0]
        }

        throw "Environment UUID non trovato: $($RefreshConfig.environmentUuid)"
    }

    return Find-SingleResourceByName -Items $Environments -Name ([string]$RefreshConfig.environmentName) -Label "Environment"
}

function Resolve-Server {
    param(
        [object]$RefreshConfig,
        [array]$Servers
    )

    if ($RefreshConfig.serverUuid) {
        $match = @($Servers | Where-Object { $_.uuid -eq $RefreshConfig.serverUuid })
        if ($match.Count -eq 1) {
            return $match[0]
        }

        throw "Server UUID non trovato: $($RefreshConfig.serverUuid)"
    }

    return Find-SingleResourceByName -Items $Servers -Name ([string]$RefreshConfig.serverName) -Label "Server"
}

function Resolve-Database {
    param(
        [object]$RefreshConfig,
        [array]$Databases,
        [string]$UuidPropertyName,
        [string]$FallbackName,
        [string]$Label
    )

    $uuidProperty = $RefreshConfig.PSObject.Properties[$UuidPropertyName]
    $preferredUuid = if ($uuidProperty) { [string]$uuidProperty.Value } else { "" }
    if ($preferredUuid) {
        $match = @($Databases | Where-Object { $_.uuid -eq $preferredUuid })
        if ($match.Count -eq 1) {
            return $match[0]
        }

        throw "$Label non trovato per UUID $preferredUuid"
    }

    $matches = @($Databases | Where-Object { $_.name -eq $FallbackName })
    if ($matches.Count -eq 1) {
        return $matches[0]
    }

    $healthyMatches = @($matches | Where-Object { $_.status -eq "running:healthy" })
    if ($healthyMatches.Count -eq 1) {
        return $healthyMatches[0]
    }

    if ($matches.Count -eq 0) {
        throw "$Label non trovato con nome '$FallbackName'"
    }

    $available = $matches | ForEach-Object { "$($_.uuid) [$($_.status)]" }
    throw "Trovati piu' database per $Label con nome '$FallbackName': $($available -join ', '). Imposta dbRefresh.$UuidPropertyName nel config locale."
}

function Resolve-ExistingApp {
    param(
        [object]$RefreshConfig,
        [array]$Applications,
        [int]$EnvironmentId,
        [string]$AppName
    )

    if ($RefreshConfig.appUuid) {
        $match = @($Applications | Where-Object { $_.uuid -eq $RefreshConfig.appUuid })
        if ($match.Count -eq 1) {
            return $match[0]
        }
    }

    $matches = @(
        $Applications |
            Where-Object {
                $_.name -eq $AppName -and $_.environment_id -eq $EnvironmentId
            }
    )

    if ($matches.Count -eq 1) {
        return $matches[0]
    }

    return $null
}

function New-ToolboxDockerfile {
    return @'
FROM mysql:8

RUN cat <<'EOF' >/usr/local/bin/db-refresh && chmod +x /usr/local/bin/db-refresh
#!/bin/bash
set -euo pipefail

mode="${1:-guarded}"
marker_dir=/tmp/ambulatorio-db-refresh
today=""
marker=""

if [ "$mode" = "guarded" ]; then
  target=$(printf "%02d:%02d" "${SYNC_LOCAL_HOUR:-2}" "${SYNC_LOCAL_MINUTE:-15}")
  now=$(TZ="${SYNC_TZ:-Europe/Rome}" date +%H:%M)
  if [ "$now" != "$target" ]; then
    echo "[skip] now=$now target=$target tz=${SYNC_TZ:-Europe/Rome}"
    exit 0
  fi

  mkdir -p "$marker_dir"
  today=$(TZ="${SYNC_TZ:-Europe/Rome}" date +%F)
  marker="$marker_dir/last-success"
  if [ -f "$marker" ] && [ "$(cat "$marker")" = "$today" ]; then
    echo "[skip] already refreshed on $today"
    exit 0
  fi
elif [ "$mode" != "once" ]; then
  echo "usage: db-refresh [guarded|once]" >&2
  exit 64
fi

echo "[sync] start $(TZ="${SYNC_TZ:-Europe/Rome}" date "+%Y-%m-%dT%H:%M:%S%z")"
mysqldump --single-transaction --quick --routines --events --triggers --no-tablespaces --set-gtid-purged=OFF -h "$PROD_DB_HOST" -P "${PROD_DB_PORT:-3306}" -u "$PROD_DB_USER" -p"$PROD_DB_PASSWORD" "$PROD_DB_NAME" \
| sed -E 's/DEFINER=`[^`]+`@`[^`]+`//g' \
| mysql -h "$TEST_DB_HOST" -P "${TEST_DB_PORT:-3306}" -u "$TEST_DB_USER" -p"$TEST_DB_PASSWORD" "$TEST_DB_NAME"
echo "[sync] done $(TZ="${SYNC_TZ:-Europe/Rome}" date "+%Y-%m-%dT%H:%M:%S%z")"

if [ "$mode" = "guarded" ]; then
  echo "$today" > "$marker"
fi
EOF

CMD ["tail", "-f", "/dev/null"]
'@
}

function Convert-ToBase64Utf8 {
    param([string]$Value)

    $bytes = [System.Text.Encoding]::UTF8.GetBytes($Value)
    return [Convert]::ToBase64String($bytes)
}

function New-RefreshCoreCommand {
    return '/usr/local/bin/db-refresh guarded'
}

function New-GuardedRefreshCommand {
    return New-RefreshCoreCommand
}

function New-ImmediateRefreshCommand {
    return '/usr/local/bin/db-refresh once'
}

function Upsert-ApplicationEnv {
    param(
        [string]$ApplicationUuid,
        [array]$EnvironmentSpecs
    )

    $existingEnvs = @()
    if (-not $DryRun.IsPresent) {
        $existingEnvs = Convert-ToFlatArray -Value (Invoke-CoolifyApi -Method Get -Path "/api/v1/applications/$ApplicationUuid/envs")
    }

    foreach ($spec in $EnvironmentSpecs) {
        $existing = $existingEnvs | Where-Object { $_.key -eq $spec.key } | Select-Object -First 1
        if ($existing) {
            Invoke-CoolifyApi -Method Patch -Path "/api/v1/applications/$ApplicationUuid/envs" -Body $spec | Out-Null
            Write-Step "env aggiornata: $($spec.key)"
        }
        else {
            Invoke-CoolifyApi -Method Post -Path "/api/v1/applications/$ApplicationUuid/envs" -Body $spec | Out-Null
            Write-Step "env creata: $($spec.key)"
        }
    }
}

function Upsert-ScheduledTask {
    param(
        [string]$ApplicationUuid,
        [string]$TaskName,
        [string]$Command,
        [string]$Frequency,
        [int]$TimeoutSeconds,
        [bool]$Enabled
    )

    $tasks = @()
    if (-not $DryRun.IsPresent) {
        $tasks = Convert-ToFlatArray -Value (Invoke-CoolifyApi -Method Get -Path "/api/v1/applications/$ApplicationUuid/scheduled-tasks")
    }

    $existing = $tasks | Where-Object { $_.name -eq $TaskName } | Select-Object -First 1
    $payload = @{
        name      = $TaskName
        command   = $Command
        frequency = $Frequency
        timeout   = $TimeoutSeconds
        enabled   = $Enabled
    }

    if ($existing) {
        Invoke-CoolifyApi -Method Patch -Path "/api/v1/applications/$ApplicationUuid/scheduled-tasks/$($existing.uuid)" -Body $payload | Out-Null
        Write-Step "scheduled task aggiornata: $TaskName"
        return $existing.uuid
    }

    $created = Invoke-CoolifyApi -Method Post -Path "/api/v1/applications/$ApplicationUuid/scheduled-tasks" -Body $payload
    Write-Step "scheduled task creata: $TaskName"
    if ($DryRun.IsPresent) {
        return "dry-run-task-uuid"
    }

    return [string]$created.uuid
}

function Wait-ForApplicationStatus {
    param(
        [string]$ApplicationUuid,
        [string]$ExpectedPrefix = "running",
        [int]$Attempts = 18,
        [int]$DelaySeconds = 5
    )

    if ($DryRun.IsPresent) {
        return $null
    }

    for ($i = 1; $i -le $Attempts; $i++) {
        $application = Invoke-CoolifyApi -Method Get -Path "/api/v1/applications/$ApplicationUuid"
        $status = [string]$application.status
        if ($status.StartsWith($ExpectedPrefix)) {
            return $application
        }

        Start-Sleep -Seconds $DelaySeconds
    }

    throw "L'applicazione $ApplicationUuid non e' arrivata in stato '$ExpectedPrefix'."
}

function Wait-ForTaskExecution {
    param(
        [string]$ApplicationUuid,
        [string]$TaskUuid,
        [datetime]$SinceUtc,
        [int]$TimeoutSeconds = 150
    )

    if ($DryRun.IsPresent) {
        return $null
    }

    $deadline = (Get-Date).ToUniversalTime().AddSeconds($TimeoutSeconds)
    while ((Get-Date).ToUniversalTime() -lt $deadline) {
        $executions = Convert-ToFlatArray -Value (Invoke-CoolifyApi -Method Get -Path "/api/v1/applications/$ApplicationUuid/scheduled-tasks/$TaskUuid/executions")
        $recent = $executions | Where-Object {
            if (-not $_.created_at) {
                return $false
            }

            ([datetime]$_.created_at).ToUniversalTime() -ge $SinceUtc
        } | Sort-Object created_at -Descending | Select-Object -First 1

        if ($recent) {
            return $recent
        }

        Start-Sleep -Seconds 10
    }

    throw "Nessuna esecuzione trovata per la task $TaskUuid entro $TimeoutSeconds secondi."
}

$resolvedConfigPath = Resolve-ConfigPath -ConfiguredPath $ConfigPath
$config = Load-Config -Path $resolvedConfigPath
$refreshConfig = Ensure-ObjectProperty -Target $config -PropertyName "dbRefresh" -DefaultValue ([pscustomobject]@{})

Require-Field "coolifyBaseUrl" $config.coolifyBaseUrl
Require-Field "coolifyToken" $config.coolifyToken

$script:CoolifyBaseUrl = ([string]$config.coolifyBaseUrl).TrimEnd("/")
$script:CoolifyToken = [string]$config.coolifyToken

[void](Ensure-RefreshProperty -RefreshConfig $refreshConfig -PropertyName "projectName" -DefaultValue "ambulatoriofacile-demo")
[void](Ensure-RefreshProperty -RefreshConfig $refreshConfig -PropertyName "environmentName" -DefaultValue "production")
[void](Ensure-RefreshProperty -RefreshConfig $refreshConfig -PropertyName "serverName" -DefaultValue "localhost")
[void](Ensure-RefreshProperty -RefreshConfig $refreshConfig -PropertyName "prodDatabaseName" -DefaultValue "ambulatoriofacile-db")
[void](Ensure-RefreshProperty -RefreshConfig $refreshConfig -PropertyName "testDatabaseName" -DefaultValue "ambulatoriofacile-test-db")
[void](Ensure-RefreshProperty -RefreshConfig $refreshConfig -PropertyName "appName" -DefaultValue "ambulatoriofacile-db-refresh-job")
[void](Ensure-RefreshProperty -RefreshConfig $refreshConfig -PropertyName "taskName" -DefaultValue "nightly-prod-to-test-refresh")
[void](Ensure-RefreshProperty -RefreshConfig $refreshConfig -PropertyName "syncTimezone" -DefaultValue "Europe/Rome")
[void](Ensure-RefreshProperty -RefreshConfig $refreshConfig -PropertyName "syncHour" -DefaultValue 2)
[void](Ensure-RefreshProperty -RefreshConfig $refreshConfig -PropertyName "syncMinute" -DefaultValue 15)
[void](Ensure-RefreshProperty -RefreshConfig $refreshConfig -PropertyName "pollFrequency" -DefaultValue "* * * * *")
[void](Ensure-RefreshProperty -RefreshConfig $refreshConfig -PropertyName "timeoutSeconds" -DefaultValue 7200)

Write-Step "carico contesto Coolify"
$projects = Convert-ToFlatArray -Value (Invoke-CoolifyApi -Method Get -Path "/api/v1/projects")
$project = Resolve-Project -RefreshConfig $refreshConfig -Projects $projects
$projectUuid = if ($refreshConfig.projectUuid) { [string]$refreshConfig.projectUuid } else { [string]$project.uuid }
$environments = Convert-ToFlatArray -Value (Invoke-CoolifyApi -Method Get -Path "/api/v1/projects/$projectUuid/environments")
$environment = Resolve-Environment -RefreshConfig $refreshConfig -Environments $environments
$servers = Convert-ToFlatArray -Value (Invoke-CoolifyApi -Method Get -Path "/api/v1/servers")
$server = Resolve-Server -RefreshConfig $refreshConfig -Servers $servers
$serverUuid = if ($refreshConfig.serverUuid) { [string]$refreshConfig.serverUuid } else { [string]$server.uuid }
$databases = Convert-ToFlatArray -Value (Invoke-CoolifyApi -Method Get -Path "/api/v1/databases")
$prodDatabase = Resolve-Database -RefreshConfig $refreshConfig -Databases $databases -UuidPropertyName "prodDatabaseUuid" -FallbackName $refreshConfig.prodDatabaseName -Label "DB produzione"
$testDatabase = Resolve-Database -RefreshConfig $refreshConfig -Databases $databases -UuidPropertyName "testDatabaseUuid" -FallbackName $refreshConfig.testDatabaseName -Label "DB test"

Set-ObjectProperty -Target $refreshConfig -PropertyName "projectUuid" -Value $projectUuid
Set-ObjectProperty -Target $refreshConfig -PropertyName "environmentUuid" -Value ([string]$environment.uuid)
Set-ObjectProperty -Target $refreshConfig -PropertyName "serverUuid" -Value $serverUuid
Set-ObjectProperty -Target $refreshConfig -PropertyName "prodDatabaseUuid" -Value ([string]$prodDatabase.uuid)
Set-ObjectProperty -Target $refreshConfig -PropertyName "testDatabaseUuid" -Value ([string]$testDatabase.uuid)

$applications = Convert-ToFlatArray -Value (Invoke-CoolifyApi -Method Get -Path "/api/v1/applications")
$app = $null
if ($refreshConfig.appUuid) {
    try {
        $app = Invoke-CoolifyApi -Method Get -Path "/api/v1/applications/$([string]$refreshConfig.appUuid)"
    }
    catch {
        $app = $null
    }
}

if (-not $app) {
    $app = Resolve-ExistingApp -RefreshConfig $refreshConfig -Applications $applications -EnvironmentId ([int]$environment.id) -AppName ([string]$refreshConfig.appName)
}

if (-not $app) {
    Write-Step "creo applicazione toolbox per il refresh DB"
    $createBody = @{
        project_uuid              = $projectUuid
        server_uuid               = $serverUuid
        environment_uuid          = [string]$environment.uuid
        build_pack                = "dockerfile"
        name                      = [string]$refreshConfig.appName
        description               = "Toolbox interna per refresh notturno del DB test da produzione"
        dockerfile                = Convert-ToBase64Utf8 -Value (New-ToolboxDockerfile)
        ports_exposes             = "3306"
        health_check_enabled      = $false
        connect_to_docker_network = $true
        autogenerate_domain       = $false
    }

    $created = Invoke-CoolifyApi -Method Post -Path "/api/v1/applications/dockerfile" -Body $createBody
    if ($DryRun.IsPresent) {
        Set-ObjectProperty -Target $refreshConfig -PropertyName "appUuid" -Value "dry-run-app-uuid"
        $app = [pscustomobject]@{
            uuid   = $refreshConfig.appUuid
            status = "dry-run"
        }
    }
    else {
        Set-ObjectProperty -Target $refreshConfig -PropertyName "appUuid" -Value ([string]$created.uuid)
        $app = Invoke-CoolifyApi -Method Get -Path "/api/v1/applications/$($refreshConfig.appUuid)"
    }
}
else {
    Set-ObjectProperty -Target $refreshConfig -PropertyName "appUuid" -Value ([string]$app.uuid)
    Write-Step "riuso applicazione esistente: $($app.uuid)"
}

$desiredEnvs = @(
    @{ key = "SYNC_TZ"; value = [string]$refreshConfig.syncTimezone; is_preview = $false; is_literal = $true; is_multiline = $false; is_shown_once = $false },
    @{ key = "SYNC_LOCAL_HOUR"; value = ([string]$refreshConfig.syncHour); is_preview = $false; is_literal = $true; is_multiline = $false; is_shown_once = $false },
    @{ key = "SYNC_LOCAL_MINUTE"; value = ([string]$refreshConfig.syncMinute); is_preview = $false; is_literal = $true; is_multiline = $false; is_shown_once = $false },
    @{ key = "PROD_DB_HOST"; value = [string]$prodDatabase.uuid; is_preview = $false; is_literal = $true; is_multiline = $false; is_shown_once = $false },
    @{ key = "PROD_DB_PORT"; value = "3306"; is_preview = $false; is_literal = $true; is_multiline = $false; is_shown_once = $false },
    @{ key = "PROD_DB_NAME"; value = [string]$prodDatabase.mysql_database; is_preview = $false; is_literal = $true; is_multiline = $false; is_shown_once = $false },
    @{ key = "PROD_DB_USER"; value = [string]$prodDatabase.mysql_user; is_preview = $false; is_literal = $true; is_multiline = $false; is_shown_once = $false },
    @{ key = "PROD_DB_PASSWORD"; value = [string]$prodDatabase.mysql_password; is_preview = $false; is_literal = $true; is_multiline = $false; is_shown_once = $false },
    @{ key = "TEST_DB_HOST"; value = [string]$testDatabase.uuid; is_preview = $false; is_literal = $true; is_multiline = $false; is_shown_once = $false },
    @{ key = "TEST_DB_PORT"; value = "3306"; is_preview = $false; is_literal = $true; is_multiline = $false; is_shown_once = $false },
    @{ key = "TEST_DB_NAME"; value = [string]$testDatabase.mysql_database; is_preview = $false; is_literal = $true; is_multiline = $false; is_shown_once = $false },
    @{ key = "TEST_DB_USER"; value = [string]$testDatabase.mysql_user; is_preview = $false; is_literal = $true; is_multiline = $false; is_shown_once = $false },
    @{ key = "TEST_DB_PASSWORD"; value = [string]$testDatabase.mysql_password; is_preview = $false; is_literal = $true; is_multiline = $false; is_shown_once = $false }
)

Write-Step "sincronizzo env dell'app toolbox"
Upsert-ApplicationEnv -ApplicationUuid $refreshConfig.appUuid -EnvironmentSpecs $desiredEnvs

if ($app -and [string]$app.status -like "running:*") {
    Write-Step "riavvio toolbox per applicare le env runtime"
    Invoke-CoolifyApi -Method Post -Path "/api/v1/applications/$($refreshConfig.appUuid)/restart" | Out-Null
}
else {
    Write-Step "avvio toolbox per applicare le env runtime"
    Invoke-CoolifyApi -Method Post -Path "/api/v1/applications/$($refreshConfig.appUuid)/start" | Out-Null
}

$appStatus = Wait-ForApplicationStatus -ApplicationUuid $refreshConfig.appUuid

Write-Step "sincronizzo task notturna"
$taskUuid = Upsert-ScheduledTask `
    -ApplicationUuid $refreshConfig.appUuid `
    -TaskName ([string]$refreshConfig.taskName) `
    -Command (New-GuardedRefreshCommand) `
    -Frequency ([string]$refreshConfig.pollFrequency) `
    -TimeoutSeconds ([int]$refreshConfig.timeoutSeconds) `
    -Enabled $true

Set-ObjectProperty -Target $refreshConfig -PropertyName "taskUuid" -Value ([string]$taskUuid)

if ($RunOnceCheck.IsPresent) {
    Write-Step "avvio verifica immediata con task temporanea"
    $manualTaskName = "$($refreshConfig.taskName)-check-now"
    $manualTaskUuid = Upsert-ScheduledTask `
        -ApplicationUuid $refreshConfig.appUuid `
        -TaskName $manualTaskName `
        -Command (New-ImmediateRefreshCommand) `
        -Frequency "* * * * *" `
        -TimeoutSeconds ([int]$refreshConfig.timeoutSeconds) `
        -Enabled $true

    $execution = Wait-ForTaskExecution -ApplicationUuid $refreshConfig.appUuid -TaskUuid $manualTaskUuid -SinceUtc ((Get-Date).ToUniversalTime())
    Invoke-CoolifyApi -Method Delete -Path "/api/v1/applications/$($refreshConfig.appUuid)/scheduled-tasks/$manualTaskUuid" | Out-Null
    if ($execution) {
        Write-Step "verifica immediata completata con esecuzione $($execution.uuid)"
    }
    else {
        Write-Step "verifica immediata simulata in dry-run"
    }
}

Save-Config -Path $resolvedConfigPath -Config $config

Write-Step "config locale aggiornata"
Write-Host ""
Write-Host "App toolbox: $($refreshConfig.appUuid)"
Write-Host "Task notturna: $($refreshConfig.taskUuid)"
Write-Host "DB prod: $($refreshConfig.prodDatabaseUuid)"
Write-Host "DB test: $($refreshConfig.testDatabaseUuid)"
if ($appStatus) {
    Write-Host "Stato app: $($appStatus.status)"
}
