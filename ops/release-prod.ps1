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

function Test-GitRemoteAligned {
    param([string]$ExpectedBranch)

    $remoteRef = "refs/remotes/origin/$ExpectedBranch"
    & git show-ref --verify --quiet $remoteRef
    if ($LASTEXITCODE -ne 0) {
        throw "Riferimento remoto 'origin/$ExpectedBranch' non disponibile. Esegui prima un fetch/push di '$ExpectedBranch'."
    }

    $comparisonRange = "{0}...origin/{0}" -f $ExpectedBranch
    $counts = git rev-list --left-right --count $comparisonRange
    if ($LASTEXITCODE -ne 0) {
        throw "Impossibile confrontare '$ExpectedBranch' con 'origin/$ExpectedBranch'."
    }

    $parts = $counts.Trim() -split "\s+"
    if ($parts.Count -lt 2) {
        throw "Output inatteso dal confronto Git tra '$ExpectedBranch' e 'origin/$ExpectedBranch'."
    }

    $ahead = [int]$parts[0]
    $behind = [int]$parts[1]

    if ($ahead -gt 0 -and $behind -gt 0) {
        throw "Il branch '$ExpectedBranch' e' divergente rispetto a 'origin/$ExpectedBranch'. Riallinea e pusha '$ExpectedBranch' prima del rilascio."
    }

    if ($ahead -gt 0) {
        throw "Il branch '$ExpectedBranch' contiene commit locali non ancora pushati su 'origin/$ExpectedBranch'. Esegui push di '$ExpectedBranch' prima del rilascio."
    }

    if ($behind -gt 0) {
        throw "Il branch '$ExpectedBranch' non e' allineato con 'origin/$ExpectedBranch'. Aggiorna '$ExpectedBranch' prima del rilascio."
    }
}

function Invoke-HealthRequest {
    param([string]$Uri)

    $curl = Get-Command "curl.exe" -ErrorAction SilentlyContinue
    if ($null -ne $curl) {
        $output = & $curl.Source -sS -L -o NUL -w "%{http_code} %{url_effective}" $Uri
        if ($LASTEXITCODE -ne 0) {
            throw "curl.exe ha restituito exit code $LASTEXITCODE durante l'health check."
        }

        $parts = $output.Trim() -split "\s+", 2
        if ($parts.Count -lt 2) {
            throw "Output inatteso da curl.exe durante l'health check: $output"
        }

        return [pscustomobject]@{
            StatusCode = [int]$parts[0]
            ResolvedUrl = [string]$parts[1]
        }
    }

    $response = Invoke-WebRequest -Uri $Uri -Method Get -MaximumRedirection 10 -TimeoutSec 30
    return [pscustomobject]@{
        StatusCode = [int]$response.StatusCode
        ResolvedUrl = [string]$response.BaseResponse.ResponseUri.AbsoluteUri
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
    $lastError = ""
    $lastStatusCode = ""
    $lastResolvedUrl = ""

    for ($i = 1; $i -le $attempts; $i++) {
        try {
            $response = Invoke-HealthRequest -Uri $HealthUrl
            $lastStatusCode = [string]$response.StatusCode
            $lastResolvedUrl = [string]$response.ResolvedUrl

            if ($response.StatusCode -ge 200 -and $response.StatusCode -lt 400) {
                Write-Host "[$TargetName] health check ok ($lastStatusCode) $lastResolvedUrl"
                return
            }

            $lastError = "status code $lastStatusCode"
        }
        catch {
            $lastError = $_.Exception.Message
        }

        if ($i -eq $attempts) {
            $details = if ($lastResolvedUrl) {
                " Ultimo URL risolto: $lastResolvedUrl."
            }
            else {
                ""
            }

            throw "[$TargetName] health check fallito su $HealthUrl. Ultimo esito: $lastError.$details"
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
    Test-GitRemoteAligned -ExpectedBranch ([string]$config.defaultBranch)
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
