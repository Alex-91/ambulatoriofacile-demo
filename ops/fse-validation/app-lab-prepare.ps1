$ErrorActionPreference = 'Stop'
$repoRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$mysqlBase = 'C:/wamp64/bin/mysql/mysql8.3.0'
if (!(Test-Path -LiteralPath (Join-Path $mysqlBase 'bin/mysqld.exe'))) { throw 'Isolated MySQL runtime not found; no live server is used as fallback.' }
if (Get-NetTCPConnection -State Listen -ErrorAction Stop | Where-Object { $_.LocalPort -in @(33079,8088) }) { throw 'A lab port is already occupied.' }
$labRoot = Join-Path $repoRoot ('rest/writable/fse-app-labs/' + [Guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path (Join-Path $labRoot 'mysql') | Out-Null
foreach ($folder in @('writable/cache','writable/logs','writable/session','writable/tenants','signing','recovery')) { New-Item -ItemType Directory -Path (Join-Path $labRoot $folder) -Force | Out-Null }
$randomBytes = New-Object byte[] 32
[Security.Cryptography.RandomNumberGenerator]::Fill($randomBytes)
$password = [Convert]::ToHexString($randomBytes).ToLowerInvariant()
$config = [ordered]@{mode='FSE_SYNTHETIC_APP_LAB'; port=33079; web_port=8088; mysql_base=$mysqlBase; password=$password; secret_key=[Guid]::NewGuid().ToString('N'); login_password='FseLabOnly_2026!'}
[IO.File]::WriteAllText((Join-Path $labRoot 'lab.json'), ($config | ConvertTo-Json), (New-Object Text.UTF8Encoding($false)))
& (Join-Path $mysqlBase 'bin/mysqld.exe') --no-defaults --initialize-insecure ('--basedir='+$mysqlBase) ('--datadir='+(Join-Path $labRoot 'mysql')) --console
if ($LASTEXITCODE -ne 0) { throw 'Isolated MySQL initialization failed; existing instances untouched.' }
Write-Output ('FSE lab prepared: ' + $labRoot)
Write-Output 'No Windows service installed. Start only this datadir on port 33079, then run app-lab-seed.php.'
