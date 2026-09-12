# Resume only the dedicated local Linux lab. Never reboot, unregister, delete or select another distro.
[CmdletBinding()]
param([switch]$InstallDistribution)
$ErrorActionPreference='Stop'
$repo=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$name='AmbulatorioFacile-FSE'
$location=Join-Path $repo 'rest/writable/fse-wsl-distribution'
$wsl=Join-Path $env:SystemRoot 'System32/wsl.exe'
if (Test-Path -LiteralPath 'HKLM:/SOFTWARE/Microsoft/Windows/CurrentVersion/Component Based Servicing/RebootPending') {
    Write-Output 'RESTART_REQUIRED: save your work and restart Windows manually. Nothing installed or started.'
    exit 3
}
$config=Join-Path $env:USERPROFILE '.wslconfig'
if (!(Test-Path -LiteralPath $config) -or (Get-FileHash -LiteralPath $config).Hash -ne (Get-FileHash -LiteralPath (Join-Path $PSScriptRoot 'wslconfig.fse.example')).Hash) {
    throw 'WSL resource limits changed or missing: review them before continuing.'
}
$os=Get-CimInstance Win32_OperatingSystem
$disk=Get-PSDrive -Name ([IO.Path]::GetPathRoot($location).Substring(0,1))
if ($os.FreePhysicalMemory -lt 4MB -or $disk.Free -lt 25GB) { throw 'At least 4 GiB free RAM and 25 GiB free disk required for this lab.' }
$listing=(& $wsl --list --quiet 2>&1 | Out-String).Replace([string][char]0,'')
if ($LASTEXITCODE -ne 0) { throw 'WSL cannot enumerate distributions. No installation attempted.' }
$distributions=@($listing -split '[\r\n]+' | ForEach-Object { $_.Trim() } | Where-Object { $_ })
if ($distributions -contains $name) {
    Write-Output 'DEDICATED_DISTRO_PRESENT: inspect its OS and Docker state before any build. Nothing changed.'
    return
}
if (!$InstallDistribution) {
    Write-Output 'READY_FOR_DEDICATED_DISTRO_INSTALL: rerun with -InstallDistribution. Nothing changed.'
    return
}
if (Test-Path -LiteralPath $location) { throw 'Unregistered/partial distribution path exists: never overwrite or remove it automatically.' }
# No default distribution, shell login, Docker daemon, public ports or automatic reboot.
& $wsl --install -d Debian --name $name --no-launch --location $location
if ($LASTEXITCODE -ne 0) { throw 'Dedicated distribution install failed; preserve partial state for diagnosis.' }
$after=(& $wsl --list --quiet 2>&1 | Out-String).Replace([string][char]0,'')
if ($LASTEXITCODE -ne 0 -or $name -notin @($after -split '[\r\n]+' | ForEach-Object { $_.Trim() })) {
    throw 'Command completed but dedicated distribution is not confirmed. No Linux test has passed.'
}
Write-Output 'DEDICATED_DISTRO_REGISTERED_NOT_TESTED: no Docker build or FSE test has run.'
