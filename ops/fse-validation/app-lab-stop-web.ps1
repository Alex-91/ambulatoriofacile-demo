param([Parameter(Mandatory)][string]$LabRoot)
$ErrorActionPreference='Stop'
$repo=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$lab=(Resolve-Path -LiteralPath $LabRoot).Path
if ([IO.Path]::GetDirectoryName($lab) -ne [IO.Path]::GetFullPath((Join-Path $repo 'rest/writable/fse-app-labs')) -or [IO.Path]::GetFileName($lab) -notmatch '^[a-f0-9]{32}$') { throw 'Invalid synthetic lab directory.' }
$config=Get-Content -LiteralPath (Join-Path $lab 'lab.json') -Raw | ConvertFrom-Json
if ($config.mode -ne 'FSE_SYNTHETIC_APP_LAB' -or $config.web_port -ne 8088) { throw 'Missing synthetic marker.' }
$listeners=@(Get-NetTCPConnection -State Listen -ErrorAction Stop | Where-Object LocalPort -eq 8088)
if (!$listeners) { Write-Output 'Lab web port is already stopped.'; return }
if ($listeners.Count -ne 1 -or $listeners[0].LocalAddress -ne '127.0.0.1') { throw 'Unexpected listener; nothing stopped.' }
$response=Invoke-WebRequest -UseBasicParsing -Uri 'http://127.0.0.1:8088/login' -MaximumRedirection 0 -TimeoutSec 10 -NoProxy
if ($response.Headers['X-FSE-Test-Environment'] -ne 'synthetic-only' -or $response.Headers['X-FSE-Lab-Id'] -ne [IO.Path]::GetFileName($lab)) { throw 'Different HTTP instance; nothing stopped.' }
$process=Get-CimInstance Win32_Process -Filter ('ProcessId='+$listeners[0].OwningProcess)
$phpPath=[IO.Path]::GetFullPath((Get-Command php -CommandType Application | Select-Object -First 1).Source)
if (!$process -or $process.ExecutablePath -ne $phpPath -or !$process.CommandLine.Contains($lab) -or $process.CommandLine -notmatch '127\.0\.0\.1:8088' -or $process.CommandLine -notmatch 'ops[/\\]fse-validation[/\\]app-lab-router\.php') { throw 'Not the expected PHP lab server; nothing stopped.' }
Stop-Process -Id $process.ProcessId -Force -ErrorAction Stop
Write-Output 'Only the identified loopback PHP lab server was stopped. Files and MySQL retained.'
