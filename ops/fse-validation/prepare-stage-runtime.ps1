# Builds a private allowlisted CONTEXT only. No Docker, Coolify, DB or network call.
param()
$ErrorActionPreference='Stop'
$repoRoot=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$prepared=& (Join-Path $PSScriptRoot 'prepare-container.ps1') -AsObject
$context=$prepared.Context
if (!$context -or !(Test-Path -LiteralPath $context)) { throw 'Stage context not created.' }
Copy-Item -LiteralPath (Join-Path $PSScriptRoot 'stage-runtime.Dockerfile') -Destination (Join-Path $context 'Dockerfile')
Copy-Item -LiteralPath (Join-Path $PSScriptRoot 'stage-smoke.php') -Destination (Join-Path $context 'stage-smoke.php')
$sources=@('rest/app/Config/Fse2.php','rest/app/Services/FseArtifactValidationService.php','rest/app/Services/FseValidatorEnvironment.php','rest/app/Services/FseValidationJobs.php',
    'rest/app/Services/FseCdaRsaBuilderService.php','rest/app/Services/FseSyntheticDocument.php','ops/fse-validation/validator.py')
$hashes=[ordered]@{}
foreach ($path in $sources) { $hashes[$path]=(Get-FileHash -LiteralPath (Join-Path $repoRoot $path) -Algorithm SHA256).Hash.ToLowerInvariant() }
$encoding=New-Object Text.UTF8Encoding($false)
[IO.File]::WriteAllText((Join-Path $context 'app-source-manifest.json'),([ordered]@{mode='APP_SOURCE_FOR_ISOLATED_STAGE';files=$hashes} | ConvertTo-Json -Depth 5),$encoding)
$bundle=Get-Content -LiteralPath (Join-Path $context 'bundle-manifest.json') -Raw | ConvertFrom-Json
$bundle.mode='ISOLATED_APP_RUNTIME_STAGE_CONTEXT'
$allHashes=[ordered]@{}
foreach ($file in (Get-ChildItem -LiteralPath $context -Recurse -File | Where-Object Name -ne 'bundle-manifest.json' | Sort-Object FullName)) {
    $relative=$file.FullName.Substring($context.Length+1).Replace('\','/')
    $allHashes[$relative]=(Get-FileHash -LiteralPath $file.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
}
$bundle.files=$allHashes
[IO.File]::WriteAllText((Join-Path $context 'bundle-manifest.json'),($bundle | ConvertTo-Json -Depth 5),$encoding)
Write-Output ([PSCustomObject]@{Context=$context;Status='PREPARED_NOT_BUILT';LinuxExecution='NOT_EXECUTED';ProductionChanged=$false})
