param([string]$MySqlBin = 'C:/wamp64/bin/mysql/mysql8.3.0/bin')
$ErrorActionPreference = 'Stop'
$bookingRepo = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
$bookingData = Join-Path $bookingRepo ('tmp/patient-booking-qa-' + [Guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $bookingData | Out-Null
$bookingServer = Join-Path $MySqlBin 'mysqld.exe'
$bookingAdmin = Join-Path $MySqlBin 'mysqladmin.exe'
$bookingBase = [IO.Path]::GetFullPath((Join-Path $MySqlBin '..'))
& $bookingServer --no-defaults --initialize-insecure "--basedir=$bookingBase" "--datadir=$bookingData" --console
if ($LASTEXITCODE -ne 0) { throw 'Initialization failed' }
$bookingProcess = Start-Process -FilePath $bookingServer -ArgumentList @('--no-defaults',"--basedir=$bookingBase","--datadir=$bookingData",'--port=13371','--bind-address=127.0.0.1','--mysqlx=0','--console') -WindowStyle Hidden -PassThru -RedirectStandardOutput (Join-Path $bookingData 'stdout.log') -RedirectStandardError (Join-Path $bookingData 'stderr.log')
try {
    $bookingReady = $false
    for ($i=0; $i -lt 20; $i++) {
        Start-Sleep -Milliseconds 500
        $bookingProcess.Refresh()
        if ($bookingProcess.HasExited) { throw (Get-Content -Raw (Join-Path $bookingData 'stderr.log')) }
        # A refused connection is expected while this isolated server starts.
        $bookingPreviousErrorPreference = $ErrorActionPreference
        try {
            $ErrorActionPreference = 'Continue'
            & $bookingAdmin --no-defaults --host=127.0.0.1 --port=13371 --user=root --connect-timeout=1 ping 2>$null | Out-Null
            $bookingProbeExit = $LASTEXITCODE
        } finally { $ErrorActionPreference = $bookingPreviousErrorPreference }
        if ($bookingProbeExit -eq 0) { $bookingReady = $true; break }
    }
    if (!$bookingReady) { throw 'Disposable MySQL did not start' }
    & php -d xdebug.mode=off (Join-Path $bookingRepo 'rest/tests/patient_booking_mysql.php') --isolated-local-mysql
    if ($LASTEXITCODE -ne 0) { throw 'Patient booking regression failed' }
    & php -d xdebug.mode=off (Join-Path $bookingRepo 'rest/tests/patient_booking_provisioning.php') --isolated-local-mysql
    if ($LASTEXITCODE -ne 0) { throw 'Patient booking provisioning regression failed' }
} finally {
    $bookingProcess.Refresh()
    if (!$bookingProcess.HasExited) {
        & $bookingAdmin --no-defaults --host=127.0.0.1 --port=13371 --user=root shutdown 2>$null | Out-Null
        if (!$bookingProcess.WaitForExit(5000)) { Stop-Process -Id $bookingProcess.Id }
    }
}
