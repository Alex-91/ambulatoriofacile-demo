[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [ValidateSet('StageAndDryRun', 'DryRunStaged', 'Apply', 'Verify', 'Cleanup')]
    [string]$Action,

    [Parameter(Mandatory = $true)]
    [ValidatePattern('^afm-[a-z0-9-]{6,40}$')]
    [string]$RunId,

    [string]$Confirm = '',

    [int]$ExpectedAppointments = -1,

    [string]$ConfigPath = 'ops/release-config.local.json',

    [Parameter(Mandatory = $true)]
    [ValidatePattern('^[^@\s]+@[^@\s]+\.[^@\s]+$')]
    [string]$MasterEmail,

    [Parameter(Mandatory = $true)]
    [string]$PlanPath
)

$ErrorActionPreference = 'Stop'

function Resolve-RepoPath {
    param([Parameter(Mandatory = $true)][string]$Path)

    if ([System.IO.Path]::IsPathRooted($Path)) {
        return [System.IO.Path]::GetFullPath($Path)
    }

    $repoRoot = Split-Path -Parent $PSScriptRoot
    return [System.IO.Path]::GetFullPath((Join-Path $repoRoot $Path))
}

function Convert-ToFlatArray {
    param([object]$Value)

    $items = @()
    if ($null -eq $Value) {
        return $items
    }
    foreach ($candidate in @($Value)) {
        foreach ($item in @($candidate)) {
            $items += $item
        }
    }

    return $items
}

function Invoke-CoolifyApi {
    param(
        [Parameter(Mandatory = $true)]
        [ValidateSet('Get', 'Post', 'Delete')]
        [string]$Method,
        [Parameter(Mandatory = $true)]
        [string]$Path,
        [object]$Body = $null
    )

    $parameters = @{
        Method = $Method
        Uri = $script:CoolifyBaseUrl + $Path
        Headers = @{
            Authorization = "Bearer $script:CoolifyToken"
            Accept = 'application/json'
        }
    }
    if ($null -ne $Body) {
        $parameters.ContentType = 'application/json'
        $parameters.Body = $Body | ConvertTo-Json -Depth 10 -Compress
    }

    return Invoke-RestMethod @parameters
}

function Remove-TaskQuietly {
    param([Parameter(Mandatory = $true)][string]$TaskUuid)

    try {
        Invoke-CoolifyApi `
            -Method Delete `
            -Path "/api/v1/applications/$script:ApplicationUuid/scheduled-tasks/$TaskUuid" | Out-Null
    }
    catch {
    }
}

function Remove-TasksByPrefix {
    $tasks = Convert-ToFlatArray -Value (Invoke-CoolifyApi `
        -Method Get `
        -Path "/api/v1/applications/$script:ApplicationUuid/scheduled-tasks")
    foreach ($task in $tasks) {
        if (([string]$task.name).StartsWith($script:TaskPrefix, [StringComparison]::Ordinal)) {
            Remove-TaskQuietly -TaskUuid ([string]$task.uuid)
        }
    }
}

function New-TemporaryTask {
    param(
        [Parameter(Mandatory = $true)][string]$Suffix,
        [Parameter(Mandatory = $true)][string]$Command,
        [int]$TimeoutSeconds = 300
    )

    if ($Command.Length -gt 250) {
        throw "Comando remoto troppo lungo ($($Command.Length) caratteri)."
    }

    $sinceUtc = (Get-Date).ToUniversalTime().AddSeconds(-5)
    $created = Invoke-CoolifyApi `
        -Method Post `
        -Path "/api/v1/applications/$script:ApplicationUuid/scheduled-tasks" `
        -Body @{
            name = "$script:TaskPrefix-$Suffix"
            command = $Command
            frequency = '* * * * *'
            timeout = $TimeoutSeconds
            enabled = $true
        }

    return [pscustomobject]@{
        uuid = [string]$created.uuid
        suffix = $Suffix
        since_utc = $sinceUtc
    }
}

function Wait-TemporaryTask {
    param(
        [Parameter(Mandatory = $true)][object]$Task,
        [int]$TimeoutSeconds = 240,
        [switch]$KeepTask
    )

    $deadline = (Get-Date).ToUniversalTime().AddSeconds($TimeoutSeconds)
    try {
        while ((Get-Date).ToUniversalTime() -lt $deadline) {
            $executions = Convert-ToFlatArray -Value (Invoke-CoolifyApi `
                -Method Get `
                -Path "/api/v1/applications/$script:ApplicationUuid/scheduled-tasks/$($Task.uuid)/executions")
            $recent = $executions | Where-Object {
                $_.created_at -and ([datetime]$_.created_at).ToUniversalTime() -ge $Task.since_utc
            } | Sort-Object created_at -Descending | Select-Object -First 1

            if ($recent) {
                $status = ([string]$recent.status).ToLowerInvariant()
                if ($status -in @('finished', 'completed', 'success', 'succeeded')) {
                    return $recent
                }
                if ($status -in @('failed', 'error', 'cancelled', 'canceled')) {
                    throw "Task $($Task.suffix) fallita: $([string]$recent.message)"
                }
            }

            Start-Sleep -Seconds 5
        }

        throw "Timeout in attesa della task $($Task.suffix)."
    }
    finally {
        if (-not $KeepTask.IsPresent) {
            Remove-TaskQuietly -TaskUuid ([string]$Task.uuid)
        }
    }
}

function Convert-ToGzipBase64 {
    param([Parameter(Mandatory = $true)][string]$Text)

    $inputBytes = [Text.Encoding]::UTF8.GetBytes($Text)
    $output = New-Object IO.MemoryStream
    $gzip = New-Object IO.Compression.GZipStream($output, [IO.Compression.CompressionMode]::Compress, $true)
    try {
        $gzip.Write($inputBytes, 0, $inputBytes.Length)
    }
    finally {
        $gzip.Dispose()
    }
    try {
        return [Convert]::ToBase64String($output.ToArray())
    }
    finally {
        $output.Dispose()
    }
}

function Get-MinifiedPhp {
    param([Parameter(Mandatory = $true)][string]$Path)

    $output = & php -d xdebug.mode=off -d xdebug.log= -w $Path 2>&1
    if ($LASTEXITCODE -ne 0) {
        throw ($output -join [Environment]::NewLine)
    }

    return ($output -join [Environment]::NewLine)
}

function New-CompactPlanJson {
    param([Parameter(Mandatory = $true)][string]$Path)

    $plan = Get-Content -LiteralPath $Path -Raw | ConvertFrom-Json
    $groups = foreach ($group in @($plan.groups)) {
        [ordered]@{
            target_id = [int]$group.target_id
            source_ids = @($group.source_ids | ForEach-Object { [int]$_ })
        }
    }

    return [ordered]@{
        plan_version = [int]$plan.plan_version
        master_email = [string]$plan.master_email
        tenant_database = [string]$plan.tenant_database
        expected_live_patients_total = [int]$plan.expected_live_patients_total
        expected_duplicate_groups = [int]$plan.expected_duplicate_groups
        expected_duplicate_records = [int]$plan.expected_duplicate_records
        expected_source_records = [int]$plan.expected_source_records
        groups = @($groups)
    } | ConvertTo-Json -Depth 8 -Compress
}

function Send-CompressedPayload {
    param(
        [Parameter(Mandatory = $true)][string]$Label,
        [Parameter(Mandatory = $true)][string]$Base64,
        [Parameter(Mandatory = $true)][string]$Destination
    )

    $chunkSize = 168
    $tasks = @()
    for ($offset = 0; $offset -lt $Base64.Length; $offset += $chunkSize) {
        $length = [Math]::Min($chunkSize, $Base64.Length - $offset)
        $chunk = $Base64.Substring($offset, $length)
        $index = [int]($offset / $chunkSize)
        $part = $index.ToString('D4')
        $command = "mkdir -p $script:RemotePath;printf %s '$chunk'>$script:RemotePath/$Label-$part"
        $tasks += New-TemporaryTask -Suffix "$Label-$part" -Command $command -TimeoutSeconds 60
    }

    Write-Output "UPLOAD_CREATED label=$Label chunks=$($tasks.Count)"
    foreach ($task in $tasks) {
        Wait-TemporaryTask -Task $task -TimeoutSeconds 300 | Out-Null
    }

    $assemble = New-TemporaryTask `
        -Suffix "$Label-assemble" `
        -Command "cat $script:RemotePath/$Label-*|base64 -d|gzip -d>$script:RemotePath/$Destination" `
        -TimeoutSeconds 60
    Wait-TemporaryTask -Task $assemble -TimeoutSeconds 180 | Out-Null
    Write-Output "UPLOAD_READY label=$Label"
}

function Invoke-RemoteCommand {
    param(
        [Parameter(Mandatory = $true)][string]$Suffix,
        [Parameter(Mandatory = $true)][string]$Command,
        [int]$TimeoutSeconds = 300
    )

    $task = New-TemporaryTask -Suffix $Suffix -Command $Command -TimeoutSeconds $TimeoutSeconds
    $execution = Wait-TemporaryTask -Task $task -TimeoutSeconds ($TimeoutSeconds + 180)
    return [string]$execution.message
}

$resolvedConfigPath = Resolve-RepoPath -Path $ConfigPath
$resolvedPlanPath = Resolve-RepoPath -Path $PlanPath
$auditPath = Resolve-RepoPath -Path 'ops/audit-patient-duplicates.php'
$mergePath = Resolve-RepoPath -Path 'ops/merge-patient-duplicates-batch.php'
$config = Get-Content -LiteralPath $resolvedConfigPath -Raw | ConvertFrom-Json

$script:CoolifyBaseUrl = ([string]$config.coolifyBaseUrl).TrimEnd('/')
$script:CoolifyToken = [string]$config.coolifyToken
$script:ApplicationUuid = [string]$config.targets.login.appUuid
$script:TaskPrefix = "codex-$RunId"
$script:RemotePath = "/tmp/$RunId"

if ([string]::IsNullOrWhiteSpace($script:CoolifyBaseUrl) `
    -or [string]::IsNullOrWhiteSpace($script:CoolifyToken) `
    -or [string]::IsNullOrWhiteSpace($script:ApplicationUuid)) {
    throw 'Configurazione Coolify incompleta.'
}

Remove-TasksByPrefix

switch ($Action) {
    'StageAndDryRun' {
        & php -d xdebug.mode=off -d xdebug.log= -l $auditPath | Out-Null
        if ($LASTEXITCODE -ne 0) { throw 'Lint audit PHP fallito.' }
        & php -d xdebug.mode=off -d xdebug.log= -l $mergePath | Out-Null
        if ($LASTEXITCODE -ne 0) { throw 'Lint merge PHP fallito.' }

        Send-CompressedPayload `
            -Label 'a' `
            -Base64 (Convert-ToGzipBase64 -Text (Get-MinifiedPhp -Path $auditPath)) `
            -Destination 'audit-patient-duplicates.php'
        Send-CompressedPayload `
            -Label 'm' `
            -Base64 (Convert-ToGzipBase64 -Text (Get-MinifiedPhp -Path $mergePath)) `
            -Destination 'merge-patient-duplicates-batch.php'
        Send-CompressedPayload `
            -Label 'p' `
            -Base64 (Convert-ToGzipBase64 -Text (New-CompactPlanJson -Path $resolvedPlanPath)) `
            -Destination 'p.json'

        $lintMessage = Invoke-RemoteCommand `
            -Suffix 'lint' `
            -Command "cd $script:RemotePath;php -l audit-patient-duplicates.php;php -l merge-patient-duplicates-batch.php;test -s p.json" `
            -TimeoutSeconds 60
        Write-Output "REMOTE_LINT_BEGIN`n$lintMessage`nREMOTE_LINT_END"

        $dryRunMessage = Invoke-RemoteCommand `
            -Suffix 'dry-run' `
            -Command "cd $script:RemotePath;php merge-patient-duplicates-batch.php --master-email=$MasterEmail --plan=p.json" `
            -TimeoutSeconds 300
        Write-Output "DRY_RUN_BEGIN`n$dryRunMessage`nDRY_RUN_END"
    }
    'DryRunStaged' {
        $message = Invoke-RemoteCommand `
            -Suffix 'dry-run-staged' `
            -Command "cd $script:RemotePath;cp a.php audit-patient-duplicates.php;cp m.php merge-patient-duplicates-batch.php;php merge-patient-duplicates-batch.php --master-email=$MasterEmail --plan=p.json" `
            -TimeoutSeconds 300
        Write-Output "DRY_RUN_BEGIN`n$message`nDRY_RUN_END"
    }
    'Apply' {
        if ($Confirm -notmatch '^[A-F0-9]{16}$') {
            throw 'Token Confirm mancante o non valido.'
        }
        $message = Invoke-RemoteCommand `
            -Suffix 'apply' `
            -Command "cd $script:RemotePath;php merge-patient-duplicates-batch.php --master-email=$MasterEmail --plan=p.json --apply --confirm=$Confirm" `
            -TimeoutSeconds 600
        Write-Output "APPLY_BEGIN`n$message`nAPPLY_END"
    }
    'Verify' {
        if ($ExpectedAppointments -lt 0) {
            throw 'ExpectedAppointments deve essere indicato per la verifica finale.'
        }
        $message = Invoke-RemoteCommand `
            -Suffix 'verify' `
            -Command "cd $script:RemotePath;php merge-patient-duplicates-batch.php --master-email=$MasterEmail --plan=p.json --verify-completed --expected-appointments=$ExpectedAppointments" `
            -TimeoutSeconds 300
        Write-Output "VERIFY_BEGIN`n$message`nVERIFY_END"
    }
    'Cleanup' {
        $message = Invoke-RemoteCommand `
            -Suffix 'cleanup' `
            -Command "test -d $script:RemotePath;rm -rf $script:RemotePath" `
            -TimeoutSeconds 60
        Write-Output "CLEANUP_BEGIN`n$message`nCLEANUP_END"
    }
}

Remove-TasksByPrefix
