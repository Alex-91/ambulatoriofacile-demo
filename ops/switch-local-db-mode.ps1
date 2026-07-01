[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidateSet('test', 'local')]
    [string]$Mode,

    [string]$ConfigPath = "ops/release-config.local.json",

    [switch]$DryRun
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

function Resolve-RepoPath {
    param([Parameter(Mandatory = $true)][string]$Path)

    if ([System.IO.Path]::IsPathRooted($Path)) {
        return [System.IO.Path]::GetFullPath($Path)
    }

    $repoRoot = [System.IO.Path]::GetFullPath((Join-Path $PSScriptRoot ".."))
    return [System.IO.Path]::GetFullPath((Join-Path $repoRoot $Path))
}

function Test-ObjectProperty {
    param(
        [Parameter(Mandatory = $true)][AllowNull()][object]$Object,
        [Parameter(Mandatory = $true)][string]$Name
    )

    return $null -ne $Object -and $Object.PSObject.Properties.Match($Name).Count -gt 0
}

function Get-ObjectProperty {
    param(
        [Parameter(Mandatory = $true)][AllowNull()][object]$Object,
        [Parameter(Mandatory = $true)][string]$Name,
        [AllowNull()][object]$Default = $null
    )

    if (Test-ObjectProperty -Object $Object -Name $Name) {
        return $Object.$Name
    }

    return $Default
}

function Get-BooleanSetting {
    param(
        [AllowNull()][object]$Object,
        [Parameter(Mandatory = $true)][string]$Name,
        [bool]$Default
    )

    if (-not (Test-ObjectProperty -Object $Object -Name $Name)) {
        return $Default
    }

    return [bool](Get-ObjectProperty -Object $Object -Name $Name)
}

function Get-StringSetting {
    param(
        [AllowNull()][object]$Object,
        [Parameter(Mandatory = $true)][string]$Name,
        [string]$Default = ""
    )

    $value = Get-ObjectProperty -Object $Object -Name $Name -Default $null
    if ($null -eq $value) {
        return $Default
    }

    $text = [string]$value
    if ([string]::IsNullOrWhiteSpace($text)) {
        return $Default
    }

    return $text.Trim()
}

function Get-IntSetting {
    param(
        [AllowNull()][object]$Object,
        [Parameter(Mandatory = $true)][string]$Name,
        [int]$Default
    )

    $value = Get-ObjectProperty -Object $Object -Name $Name -Default $null
    if ($null -eq $value -or [string]::IsNullOrWhiteSpace([string]$value)) {
        return $Default
    }

    return [int]$value
}

function Ensure-Directory {
    param([Parameter(Mandatory = $true)][string]$Path)

    if ($DryRun) {
        return
    }

    if (-not (Test-Path -LiteralPath $Path)) {
        New-Item -ItemType Directory -Path $Path -Force | Out-Null
    }
}

function Read-JsonFile {
    param([Parameter(Mandatory = $true)][string]$Path)

    if (-not (Test-Path -LiteralPath $Path)) {
        throw "File di configurazione non trovato: $Path"
    }

    return Get-Content -LiteralPath $Path -Raw | ConvertFrom-Json
}

function Format-EnvValue {
    param([AllowNull()][object]$Value)

    if ($null -eq $Value) {
        return ''
    }

    $text = [string]$Value
    if ($text -eq '') {
        return ''
    }

    if ($text -match '^[A-Za-z0-9._:/@+\-]+$') {
        return $text
    }

    $escaped = $text.Replace('\', '\\').Replace('"', '\"')
    return '"' + $escaped + '"'
}

function Set-EnvValue {
    param(
        [Parameter(Mandatory = $true)][object]$Lines,
        [Parameter(Mandatory = $true)][string]$Key,
        [AllowNull()][object]$Value
    )

    $formatted = Format-EnvValue -Value $Value
    $replacement = "$Key = $formatted"
    $pattern = '^\s*' + [regex]::Escape($Key) + '\s*='

    for ($i = 0; $i -lt $Lines.Count; $i++) {
        if ($Lines[$i] -match $pattern) {
            $Lines[$i] = $replacement
            return
        }
    }

    [void]$Lines.Add($replacement)
}

function Save-EnvFile {
    param(
        [Parameter(Mandatory = $true)][string]$Path,
        [Parameter(Mandatory = $true)][hashtable]$Values
    )

    $lines = New-Object System.Collections.ArrayList
    foreach ($line in (Get-Content -LiteralPath $Path)) {
        [void]$lines.Add([string]$line)
    }

    foreach ($entry in $Values.GetEnumerator()) {
        Set-EnvValue -Lines $lines -Key $entry.Key -Value $entry.Value
    }

    if ($DryRun) {
        Write-Host "[dry-run] Aggiornerei $Path con $($Values.Count) chiavi."
        return
    }

    Set-Content -LiteralPath $Path -Value $lines -Encoding UTF8
}

function Copy-FileIfNeeded {
    param(
        [Parameter(Mandatory = $true)][string]$Source,
        [Parameter(Mandatory = $true)][string]$Destination
    )

    if (Test-Path -LiteralPath $Destination) {
        return
    }

    Ensure-Directory -Path ([System.IO.Path]::GetDirectoryName($Destination))

    if ($DryRun) {
        Write-Host "[dry-run] Creerei snapshot locale: $Destination"
        return
    }

    Copy-Item -LiteralPath $Source -Destination $Destination -Force
}

function Restore-FromSnapshot {
    param(
        [Parameter(Mandatory = $true)][string]$SnapshotPath,
        [Parameter(Mandatory = $true)][string]$EnvPath
    )

    if (-not (Test-Path -LiteralPath $SnapshotPath)) {
        throw "Snapshot locale non trovato: $SnapshotPath"
    }

    if ($DryRun) {
        Write-Host "[dry-run] Ripristinerei $EnvPath da $SnapshotPath"
        return
    }

    Copy-Item -LiteralPath $SnapshotPath -Destination $EnvPath -Force
    Remove-Item -LiteralPath $SnapshotPath -Force
}

function Invoke-CoolifyApi {
    param(
        [Parameter(Mandatory = $true)][ValidateSet('Get', 'Patch')][string]$Method,
        [Parameter(Mandatory = $true)][string]$Path,
        [AllowNull()][object]$Body = $null
    )

    $uri = $script:CoolifyBaseUrl.TrimEnd('/') + $Path
    $headers = @{
        Authorization = "Bearer $script:CoolifyToken"
        Accept        = 'application/json'
    }

    $params = @{
        Method  = $Method
        Uri     = $uri
        Headers = $headers
    }

    if ($Method -ne 'Get') {
        $params.ContentType = 'application/json'
        $params.Body = ($Body | ConvertTo-Json -Depth 20)
    }

    try {
        return Invoke-RestMethod @params
    }
    catch {
        $details = ""
        if ($_.Exception.Response) {
            try {
                $reader = New-Object System.IO.StreamReader($_.Exception.Response.GetResponseStream())
                $details = $reader.ReadToEnd()
            }
            catch {
                $details = ""
            }
        }

        if ($details) {
            throw "Chiamata Coolify API fallita ($Method $Path): $details"
        }

        throw
    }
}

function Get-DatabaseConnectionInfo {
    param([Parameter(Mandatory = $true)][object]$Database)

    if (Test-ObjectProperty -Object $Database -Name 'mysql_database') {
        $user = Get-ObjectProperty -Object $Database -Name 'mysql_user' -Default ''
        $password = Get-ObjectProperty -Object $Database -Name 'mysql_password' -Default ''
        $rootPassword = Get-ObjectProperty -Object $Database -Name 'mysql_root_password' -Default ''

        if ([string]::IsNullOrWhiteSpace([string]$user) -and -not [string]::IsNullOrWhiteSpace([string]$rootPassword)) {
            $user = 'root'
            $password = $rootPassword
        }

        return @{
            Engine   = 'mysql'
            Database = [string](Get-ObjectProperty -Object $Database -Name 'mysql_database' -Default '')
            Username = [string]$user
            Password = [string]$password
        }
    }

    if (Test-ObjectProperty -Object $Database -Name 'mariadb_database') {
        $user = Get-ObjectProperty -Object $Database -Name 'mariadb_user' -Default ''
        $password = Get-ObjectProperty -Object $Database -Name 'mariadb_password' -Default ''
        $rootPassword = Get-ObjectProperty -Object $Database -Name 'mariadb_root_password' -Default ''

        if ([string]::IsNullOrWhiteSpace([string]$user) -and -not [string]::IsNullOrWhiteSpace([string]$rootPassword)) {
            $user = 'root'
            $password = $rootPassword
        }

        return @{
            Engine   = 'mariadb'
            Database = [string](Get-ObjectProperty -Object $Database -Name 'mariadb_database' -Default '')
            Username = [string]$user
            Password = [string]$password
        }
    }

    throw "Il DB test non e' MySQL/MariaDB, quindi questo switch non sa come collegare l'app locale."
}

function Get-LocalTestSettings {
    param([Parameter(Mandatory = $true)][object]$Config)

    $localSection = Get-ObjectProperty -Object $Config -Name 'localTestDb' -Default ([pscustomobject]@{})
    $refreshSection = Get-ObjectProperty -Object $Config -Name 'dbRefresh' -Default ([pscustomobject]@{})

    $envPath = Resolve-RepoPath (Get-StringSetting -Object $localSection -Name 'envPath' -Default 'rest/.env')
    $snapshotPath = Resolve-RepoPath (Get-StringSetting -Object $localSection -Name 'snapshotEnvPath' -Default 'ops/.local/rest.env.before-coolify-test')
    $dbUuid = Get-StringSetting -Object $localSection -Name 'testDatabaseUuid' -Default (Get-StringSetting -Object $refreshSection -Name 'testDatabaseUuid' -Default '')
    $publicHost = Get-StringSetting -Object $localSection -Name 'publicHost' -Default ''

    if ([string]::IsNullOrWhiteSpace($publicHost)) {
        $publicHost = ([Uri]$Config.coolifyBaseUrl).Host
    }

    if ([string]::IsNullOrWhiteSpace($dbUuid)) {
        throw "Manca localTestDb.testDatabaseUuid e non trovo dbRefresh.testDatabaseUuid nel file di configurazione locale."
    }

    return @{
        EnvPath                        = $envPath
        SnapshotPath                   = $snapshotPath
        TestDatabaseUuid               = $dbUuid
        PublicHost                     = $publicHost
        PreferredPublicPort            = Get-IntSetting -Object $localSection -Name 'preferredPublicPort' -Default 23306
        PublicPortTimeout              = Get-IntSetting -Object $localSection -Name 'publicPortTimeout' -Default 3600
        AutoExposeDatabase             = Get-BooleanSetting -Object $localSection -Name 'autoExposeDatabase' -Default $true
        DisableOutboundIntegrations    = Get-BooleanSetting -Object $localSection -Name 'disableOutboundIntegrations' -Default $true
        ForceDevelopmentEnvironment    = Get-BooleanSetting -Object $localSection -Name 'forceDevelopmentEnvironment' -Default $true
    }
}

function Ensure-PublicDatabaseAccess {
    param(
        [Parameter(Mandatory = $true)][hashtable]$Settings,
        [Parameter(Mandatory = $true)][object]$Database
    )

    $currentPort = Get-ObjectProperty -Object $Database -Name 'public_port' -Default $null
    $isPublic = [bool](Get-ObjectProperty -Object $Database -Name 'is_public' -Default $false)

    if ($isPublic -and $currentPort) {
        return $Database
    }

    if (-not $Settings.AutoExposeDatabase) {
        throw "Il DB test non e' pubblico. Imposta localTestDb.autoExposeDatabase=true oppure esponilo da Coolify."
    }

    $portToUse = if ($currentPort) { [int]$currentPort } else { [int]$Settings.PreferredPublicPort }
    $payload = @{
        is_public           = $true
        public_port         = $portToUse
        public_port_timeout = [int]$Settings.PublicPortTimeout
    }

    if ($DryRun) {
        Write-Host "[dry-run] Esporterei il DB test su porta pubblica $portToUse per $($Settings.PublicPortTimeout) secondi."
        $Database | Add-Member -NotePropertyName is_public -NotePropertyValue $true -Force
        $Database | Add-Member -NotePropertyName public_port -NotePropertyValue $portToUse -Force
        return $Database
    }

    Invoke-CoolifyApi -Method Patch -Path "/api/v1/databases/$($Settings.TestDatabaseUuid)" -Body $payload | Out-Null

    for ($attempt = 0; $attempt -lt 10; $attempt++) {
        Start-Sleep -Seconds 3
        $updated = Invoke-CoolifyApi -Method Get -Path "/api/v1/databases/$($Settings.TestDatabaseUuid)"
        $updatedIsPublic = [bool](Get-ObjectProperty -Object $updated -Name 'is_public' -Default $false)
        $updatedPort = Get-ObjectProperty -Object $updated -Name 'public_port' -Default $null

        if ($updatedIsPublic -and $updatedPort) {
            return $updated
        }
    }

    throw "Coolify non ha esposto il DB test entro il tempo di attesa previsto."
}

function Build-TestModeEnvValues {
    param(
        [Parameter(Mandatory = $true)][hashtable]$Settings,
        [Parameter(Mandatory = $true)][object]$Database
    )

    $connection = Get-DatabaseConnectionInfo -Database $Database
    $publicPort = Get-ObjectProperty -Object $Database -Name 'public_port' -Default $null

    if (-not $publicPort) {
        throw "Il DB test non ha una porta pubblica utilizzabile."
    }

    $values = @{
        'database.default.hostname' = $Settings.PublicHost
        'database.default.port'     = [string]$publicPort
        'database.default.database' = $connection.Database
        'database.default.username' = $connection.Username
        'database.default.password' = $connection.Password
    }

    if ($Settings.ForceDevelopmentEnvironment) {
        $values['CI_ENVIRONMENT'] = 'development'
    }

    if ($Settings.DisableOutboundIntegrations) {
        $values['email.protocol']    = 'smtp'
        $values['email.SMTPHost']    = '127.0.0.1'
        $values['email.SMTPPort']    = '25252'
        $values['email.SMTPCrypto']  = ''
        $values['email.SMTPUser']    = 'disabled-local-test'
        $values['email.SMTPPass']    = 'disabled-local-test'
        $values['PUSH_REMOTE_URL']   = ''
        $values['PUSH_REMOTE_API_KEY'] = ''
        $values['SMS_API_TOKEN']     = ''
        $values['SMS_USERNAME']      = ''
        $values['SMS_PASSWORD']      = ''
        $values['SMS_ULTRAMSG_URL']  = ''
        $values['CRON_ACCESS_TOKEN'] = 'disabled-local-test'
    }

    return $values
}

$configFile = Resolve-RepoPath -Path $ConfigPath
$config = Read-JsonFile -Path $configFile
$settings = Get-LocalTestSettings -Config $config

if (-not (Test-Path -LiteralPath $settings.EnvPath)) {
    throw "File env locale non trovato: $($settings.EnvPath)"
}

$script:CoolifyBaseUrl = [string]$config.coolifyBaseUrl
$script:CoolifyToken = [string]$config.coolifyToken

if ([string]::IsNullOrWhiteSpace($script:CoolifyBaseUrl) -or [string]::IsNullOrWhiteSpace($script:CoolifyToken)) {
    throw "Mancano coolifyBaseUrl o coolifyToken nel file di configurazione locale."
}

if ($Mode -eq 'local') {
    Restore-FromSnapshot -SnapshotPath $settings.SnapshotPath -EnvPath $settings.EnvPath
    if ($DryRun) {
        Write-Host "[dry-run] Locale tornerebbe al DB locale originale."
    }
    else {
        Write-Host "Ripristinato il file .env locale da snapshot."
    }
    exit 0
}

Copy-FileIfNeeded -Source $settings.EnvPath -Destination $settings.SnapshotPath

$database = Invoke-CoolifyApi -Method Get -Path "/api/v1/databases/$($settings.TestDatabaseUuid)"
$database = Ensure-PublicDatabaseAccess -Settings $settings -Database $database
$envValues = Build-TestModeEnvValues -Settings $settings -Database $database

Save-EnvFile -Path $settings.EnvPath -Values $envValues

if ($DryRun) {
    Write-Host "[dry-run] Locale pronto per usare il DB test Coolify."
}
else {
    Write-Host "Locale configurato sul DB test Coolify ($($settings.PublicHost):$($envValues['database.default.port']))."
}
