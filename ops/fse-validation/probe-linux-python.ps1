# Cross-resolution only, from Windows. A successful result is NOT a Linux installation/test.
[CmdletBinding()]
param()
$ErrorActionPreference='Stop'
$repo=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$run=Join-Path $repo ('rest/writable/fse-linux-wheels/'+[Guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $run | Out-Null
$python=Join-Path $repo 'rest/writable/fse-validation-venv/Scripts/python.exe'
$requirements=Join-Path $PSScriptRoot 'requirements.lock.txt'
$hash=(Get-FileHash -LiteralPath $requirements -Algorithm SHA256).Hash
$argsList=@('-m','pip','--isolated','install','--dry-run','--ignore-installed','--only-binary=:all:',
    '--index-url','https://pypi.org/simple','--python-version','3.11','--implementation','cp',
    '--abi','cp311','--abi','abi3','--abi','none','--disable-pip-version-check','--no-input',
    '--timeout','20','--retries','1','--cache-dir',(Join-Path $run 'cache'),
    '--report',(Join-Path $run 'resolution.json'),'-r',$requirements)
# Debian bookworm/glibc 2.36 can use older manylinux floors, not only 2.17/2.28.
foreach ($minor in 36..5) { $argsList+=@('--platform',('manylinux_2_'+$minor+'_x86_64')) }
foreach ($tag in @('manylinux2014_x86_64','manylinux2010_x86_64','manylinux1_x86_64')) { $argsList+=@('--platform',$tag) }
& $python @argsList *> (Join-Path $run 'resolution.log')
$code=$LASTEXITCODE
$unchanged=(Get-FileHash -LiteralPath $requirements -Algorithm SHA256).Hash -eq $hash
$summary=[ordered]@{mode='WINDOWS_CROSS_RESOLUTION_ONLY';status='FAILED_OR_INCOMPLETE';
    target='CPython 3.11 linux/amd64 glibc 2.36';environment_markers='HOST_DEPENDENT_NOT_LINUX_ATTESTED';
    linux_execution='NOT_EXECUTED';packages_installed=$false;requirements_sha256=$hash.ToLowerInvariant();
    requirements_unchanged=$unchanged;pip_exit_code=$code;finished_at=[DateTime]::UtcNow.ToString('o')}
if ($code -eq 0 -and $unchanged -and (Test-Path -LiteralPath (Join-Path $run 'resolution.json'))) {
    $summary.status='CROSS_RESOLUTION_PASSED_NOT_LINUX_TESTED'
}
$summary | ConvertTo-Json -Depth 4 | Set-Content -LiteralPath (Join-Path $run 'summary.json') -Encoding UTF8
Write-Output ('Linux Python preflight: '+(Join-Path $run 'summary.json'))
Get-Content -LiteralPath (Join-Path $run 'resolution.log') -Tail 4
if ($summary.status -eq 'FAILED_OR_INCOMPLETE') { exit 1 }
