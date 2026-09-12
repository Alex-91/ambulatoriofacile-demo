param([Parameter(Mandatory)][string]$LabRoot)
$ErrorActionPreference = 'Stop'
$repoRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$labPath = (Resolve-Path -LiteralPath $LabRoot).Path
$allowed = Join-Path $repoRoot 'rest/writable/fse-app-labs'
if ([IO.Path]::GetDirectoryName($labPath) -ne [IO.Path]::GetFullPath($allowed) -or [IO.Path]::GetFileName($labPath) -notmatch '^[a-f0-9]{32}$') { throw 'Invalid synthetic lab directory.' }
$labConfig = Get-Content -LiteralPath (Join-Path $labPath 'lab.json') -Raw | ConvertFrom-Json
if ($labConfig.mode -ne 'FSE_SYNTHETIC_APP_LAB' -or $labConfig.web_port -ne 8088) { throw 'Missing lab marker.' }
$uploadTemp = Join-Path $labPath 'writable/upload-temp'
New-Item -ItemType Directory -Path $uploadTemp -Force | Out-Null
$env:FSE_LAB_ROOT = $labPath
$env:XDEBUG_MODE = 'off'
Push-Location $repoRoot
try {
    & php -d max_execution_time=120 -d upload_max_filesize=16M -d post_max_size=20M -d "upload_tmp_dir=$uploadTemp" -d "sys_temp_dir=$uploadTemp" -S 127.0.0.1:8088 ops/fse-validation/app-lab-router.php
} finally { Pop-Location }
