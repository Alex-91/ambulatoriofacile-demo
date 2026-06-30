[CmdletBinding()]
param(
    [ValidateSet("demo", "login", "both")]
    [string]$Target = "both",
    [string]$ConfigPath = "",
    [switch]$SkipGitChecks,
    [switch]$SkipHealthCheck,
    [switch]$DryRun,
    [switch]$Force
)

$ErrorActionPreference = "Stop"

function Resolve-ConfigPath {
    param([string]$ConfiguredPath)

    if ($ConfiguredPath) {
        return $ConfiguredPath
    }

    return Join-Path $PSScriptRoot "release-config.local.json"
}

function Load-ReleaseConfig {
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

function Require-Field {
    param(
        [string]$Label,
        [object]$Value
    )

    if ([string]::IsNullOrWhiteSpace([string]$Value)) {
        throw "Campo obbligatorio mancante: $Label"
    }
}

function Test-GitClean {
    param([string]$ExpectedBranch)

    $status = git status --porcelain
    if ($LASTEXITCODE -ne 0) {
        throw "Impossibile leggere lo stato Git."
    }

    if ($status) {
        throw "Working tree non pulito. Completa o archivia le modifiche prima del rilascio."
    }

    $branch = git branch --show-current
    if ($LASTEXITCODE -ne 0) {
        throw "Impossibile leggere il branch Git corrente."
    }

    if ($branch.Trim() -ne $ExpectedBranch) {
        throw "Branch corrente '$($branch.Trim())' diverso da '$ExpectedBranch'."
    }
}

function Invoke-HttpRequestWithFallback {
    param(
        [string]$Uri,
        [hashtable]$Headers = @{},
        [string]$PrimaryMethod = "Post",
        [string]$FallbackMethod = "Get"
    )

    try {
        return Invoke-RestMethod -Method $PrimaryMethod -Uri $Uri -Headers $Headers -TimeoutSec 60
    }
    catch {
        if (-not $FallbackMethod) {
            throw
        }

        return Invoke-RestMethod -Method $FallbackMethod -Uri $Uri -Headers $Headers -TimeoutSec 60
    }
}

function Start-CoolifyDeploy {
    param(
        [string]$TargetName,
        [pscustomobject]$TargetConfig,
        [pscustomobject]$Config,
        [bool]$ForceDeploy,
        [bool]$WhatIf
    )

    $mode = [string]$TargetConfig.deployMode
    if (-not $mode) {
        throw "deployMode mancante per target '$TargetName'."
    }

    $forceValue = if ($ForceDeploy) { "true" } else { "false" }
    $headers = @{}
    $uri = ""

    switch ($mode) {
        "webhook" {
            Require-Field "targets.$TargetName.deployWebhookUrl" $TargetConfig.deployWebhookUrl
            $uri = [string]$TargetConfig.deployWebhookUrl
        }
        "api" {
            Require-Field "coolifyBaseUrl" $Config.coolifyBaseUrl
            Require-Field "coolifyToken" $Config.coolifyToken
            Require-Field "targets.$TargetName.appUuid" $TargetConfig.appUuid
            $baseUrl = ([string]$Config.coolifyBaseUrl).TrimEnd("/")
            $uri = "$baseUrl/api/v1/deploy?uuid=$($TargetConfig.appUuid)&force=$forceValue"
            $headers["Authorization"] = "Bearer $($Config.coolifyToken)"
            $headers["Accept"] = "application/json"
        }
        default {
            throw "deployMode '$mode' non supportato per target '$TargetName'."
        }
    }

    Write-Host "[$TargetName] trigger deploy via $mode"

    if ($WhatIf) {
        Write-Host "[$TargetName] dry-run: nessuna chiamata remota eseguita"
        return
    }

    [void](Invoke-HttpRequestWithFallback -Uri $uri -Headers $headers)
}

function Wait-HealthCheck {
    param(
        [string]$TargetName,
        [string]$HealthUrl,
        [bool]$WhatIf
    )

    Require-Field "healthUrl per $TargetName" $HealthUrl

    if ($WhatIf) {
        Write-Host "[$TargetName] dry-run: salto health check su $HealthUrl"
        return
    }

    $attempts = 12
    $delaySeconds = 10

    for ($i = 1; $i -le $attempts; $i++) {
        try {
            $response = Invoke-WebRequest -Uri $HealthUrl -Method Get -MaximumRedirection 5 -TimeoutSec 30
            if ($response.StatusCode -ge 200 -and $response.StatusCode -lt 400) {
                Write-Host "[$TargetName] health check ok ($($response.StatusCode))"
                return
            }
        }
        catch {
            if ($i -eq $attempts) {
                throw "[$TargetName] health check fallito su $HealthUrl"
            }
        }

        Start-Sleep -Seconds $delaySeconds
    }
}

$resolvedConfigPath = Resolve-ConfigPath -ConfiguredPath $ConfigPath
$config = Load-ReleaseConfig -Path $resolvedConfigPath

Require-Field "defaultBranch" $config.defaultBranch
Require-Field "targets.demo" $config.targets.demo
Require-Field "targets.login" $config.targets.login

if (-not $SkipGitChecks) {
    Test-GitClean -ExpectedBranch ([string]$config.defaultBranch)
}

$targetsToDeploy = switch ($Target) {
    "both" { @("demo", "login") }
    default { @($Target) }
}

foreach ($targetName in $targetsToDeploy) {
    $targetConfig = $config.targets.$targetName
    Start-CoolifyDeploy -TargetName $targetName -TargetConfig $targetConfig -Config $config -ForceDeploy $Force.IsPresent -WhatIf $DryRun.IsPresent

    if (-not $SkipHealthCheck) {
        Wait-HealthCheck -TargetName $targetName -HealthUrl ([string]$targetConfig.healthUrl) -WhatIf $DryRun.IsPresent
    }
}

Write-Host ""
Write-Host "Rilascio completato per: $($targetsToDeploy -join ', ')"
