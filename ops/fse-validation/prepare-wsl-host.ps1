# Windows host prerequisite only. No reboot, distribution, Docker, firewall or application changes.
[CmdletBinding()]
param([switch]$EnableVirtualMachinePlatform)
$ErrorActionPreference='Stop'
$repo=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$run=Join-Path $repo ('rest/writable/fse-wsl-host/'+[Guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $run | Out-Null
$report=[ordered]@{mode='FSE_LOCAL_WSL_HOST_PREREQUISITE';started_at=[DateTime]::UtcNow.ToString('o');status='incomplete';automatic_restart=$false;production_changes=$false;feature_change_requested=[bool]$EnableVirtualMachinePlatform}
try {
    $admin=([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
    if (!$admin) { throw 'Windows administrator confirmation required.' }
    $os=Get-CimInstance Win32_OperatingSystem
    if ([int]$os.BuildNumber -lt 19041 -or $os.OSArchitecture -notmatch '64') { throw 'Supported 64-bit Windows required.' }
    $feature=Get-WindowsOptionalFeature -Online -FeatureName VirtualMachinePlatform
    $report.feature_before=[string]$feature.State
    if ($EnableVirtualMachinePlatform -and $feature.State -eq 'Disabled') {
        $result=Enable-WindowsOptionalFeature -Online -FeatureName VirtualMachinePlatform -All -NoRestart
        $report.restart_needed=[bool]$result.RestartNeeded
    }
    $after=Get-WindowsOptionalFeature -Online -FeatureName VirtualMachinePlatform
    $report.feature_after=[string]$after.State
    $report.windows_reboot_pending=Test-Path -LiteralPath 'HKLM:/SOFTWARE/Microsoft/Windows/CurrentVersion/Component Based Servicing/RebootPending'
    $report.status=if ($report.feature_after -in @('Enabled','EnablePending')) { 'PREREQUISITE_PRESENT_LINUX_NOT_TESTED' } else { 'PREREQUISITE_NOT_ENABLED' }
} catch {
    $report.status='FAILED_OR_INCOMPLETE'
    # Avoid copying arbitrary command diagnostics into the user-facing report.
    $report.error_type=$_.Exception.GetType().FullName
} finally {
    $report.finished_at=[DateTime]::UtcNow.ToString('o')
    $report | ConvertTo-Json -Depth 4 | Set-Content -LiteralPath (Join-Path $run 'result.json') -Encoding UTF8
    Write-Output ('WSL prerequisite report: '+(Join-Path $run 'result.json'))
}
if ($report.status -eq 'FAILED_OR_INCOMPLETE') { exit 1 }
