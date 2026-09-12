param([Parameter(Mandatory)][string]$LabRoot)
$ErrorActionPreference='Stop'
$repoRoot=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$labPath=(Resolve-Path -LiteralPath $LabRoot).Path
if ([IO.Path]::GetDirectoryName($labPath) -ne [IO.Path]::GetFullPath((Join-Path $repoRoot 'rest/writable/fse-app-labs')) -or [IO.Path]::GetFileName($labPath) -notmatch '^[a-f0-9]{32}$') { throw 'Invalid synthetic lab directory.' }
$labConfig=Get-Content -LiteralPath (Join-Path $labPath 'lab.json') -Raw | ConvertFrom-Json
if ($labConfig.mode -ne 'FSE_SYNTHETIC_APP_LAB' -or $labConfig.port -ne 33079) { throw 'Missing lab marker.' }
& (Join-Path $labConfig.mysql_base 'bin/mysqld.exe') --no-defaults ('--basedir='+$labConfig.mysql_base) ('--datadir='+(Join-Path $labPath 'mysql')) --port=33079 --bind-address=127.0.0.1 --mysqlx=0 --skip-log-bin --innodb-buffer-pool-size=64M --max-connections=15 --console
