param(
    [string]$SnapshotName = '.demo-freeze-8085',
    [switch]$Overwrite
)

$ErrorActionPreference = 'Stop'

$repoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$snapshotRoot = Join-Path $repoRoot $SnapshotName
$demoEnvFile = Join-Path $repoRoot 'rest\.env.demo'
$snapshotMetaDir = Join-Path $snapshotRoot 'rest\writable\demo_setup'
$snapshotMetaFile = Join-Path $snapshotMetaDir 'demo_freeze_8085.json'

if (-not (Test-Path -LiteralPath $demoEnvFile)) {
    throw "File demo env non trovato: $demoEnvFile"
}

if (Test-Path -LiteralPath $snapshotRoot) {
    if (-not $Overwrite) {
        throw "Snapshot gia presente in $snapshotRoot. Usa -Overwrite solo se vuoi rigenerarla."
    }

    $resolvedSnapshot = (Resolve-Path -LiteralPath $snapshotRoot).Path
    if (-not $resolvedSnapshot.StartsWith($repoRoot, [System.StringComparison]::OrdinalIgnoreCase)) {
        throw "Percorso snapshot non valido: $resolvedSnapshot"
    }

    Remove-Item -LiteralPath $resolvedSnapshot -Recurse -Force
}

$excludedDirectories = @(
    '.git',
    '.agents',
    '.codex',
    '.ops-secrets',
    '.main-release-worktree',
    $SnapshotName,
    'dist',
    'node_modules',
    'outputs',
    'repo_friend_main'
)

$excludedFilePatterns = @(
    '*.log',
    'temp_*.cookies'
)

New-Item -ItemType Directory -Force -Path $snapshotRoot | Out-Null

foreach ($item in Get-ChildItem -LiteralPath $repoRoot -Force) {
    if ($excludedDirectories -contains $item.Name) {
        continue
    }

    $skipFile = $false
    foreach ($pattern in $excludedFilePatterns) {
        if (-not $item.PSIsContainer -and $item.Name -like $pattern) {
            $skipFile = $true
            break
        }
    }

    if ($skipFile) {
        continue
    }

    $destination = Join-Path $snapshotRoot $item.Name
    Copy-Item -LiteralPath $item.FullName -Destination $destination -Recurse -Force
}

New-Item -ItemType Directory -Force -Path $snapshotMetaDir | Out-Null

$metadata = [ordered]@{
    created_at = (Get-Date).ToString('s')
    snapshot_root = $snapshotRoot
    source_root = $repoRoot
    preview_url = 'http://127.0.0.1:8085/'
    runtime_env_file = 'rest/.env.demo'
    database = 'ambulatoriofacile_demo'
    note = 'Snapshot demo congelata per presentazione commerciale su porta 8085.'
}

$metadata | ConvertTo-Json -Depth 4 | Set-Content -LiteralPath $snapshotMetaFile -Encoding UTF8

Write-Output "Snapshot demo creata in: $snapshotRoot"
Write-Output "Per avviarla: powershell -ExecutionPolicy Bypass -File rest/tools/start-frozen-demo-8085.ps1"
