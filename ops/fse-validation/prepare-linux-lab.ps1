# Materialize a private, allowlisted, synthetic-only context. No remote writes or DB access.
[CmdletBinding()]
param([string]$FrameworkLab, [string]$DependencyLab)
$ErrorActionPreference='Stop'
$repo=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$variant='baseline'
$systemSource=Join-Path $repo 'rest/system'
$composerSource=$repo
if ([bool]$FrameworkLab -ne [bool]$DependencyLab) { throw 'Select both marked candidate laboratories or neither.' }
if ($FrameworkLab) {
    $FrameworkLab=(Resolve-Path -LiteralPath $FrameworkLab).Path
    $DependencyLab=(Resolve-Path -LiteralPath $DependencyLab).Path
    foreach ($entry in @(@($FrameworkLab,'fse-framework-compat'),@($DependencyLab,'fse-dependency-compat'))) {
        if ([IO.Path]::GetDirectoryName($entry[0]) -ne (Join-Path $repo ('rest/writable/'+$entry[1])) -or [IO.Path]::GetFileName($entry[0]) -notmatch '^[a-f0-9]{32}$') { throw 'Invalid candidate laboratory.' }
    }
    $framework=Get-Content -LiteralPath (Join-Path $FrameworkLab 'lab.json') -Raw | ConvertFrom-Json
    $dependencies=Get-Content -LiteralPath (Join-Path $DependencyLab 'lab.json') -Raw | ConvertFrom-Json
    if ($framework.mode -ne 'LOCAL_FRAMEWORK_COMPATIBILITY_ONLY' -or !$framework.active_system_unchanged -or $framework.candidate_version -ne '4.7.4' -or $dependencies.mode -ne 'LOCAL_DEPENDENCY_COMPATIBILITY_ONLY' -or $dependencies.install_exit_code -ne 0 -or !$dependencies.layout_valid -or !$dependencies.source_unchanged) { throw 'Incomplete candidate laboratories.' }
    foreach ($entry in $dependencies.source_sha256.PSObject.Properties) {
        if ((Get-FileHash -LiteralPath (Join-Path $repo $entry.Name) -Algorithm SHA256).Hash -ne $entry.Value) { throw 'Active dependency baseline changed.' }
    }
    $systemSource=Join-Path $FrameworkLab 'source-4.7.4/CodeIgniter4-2bd0f01d2813f9ec06db42643ce39d9f5428bf6d/system'
    $composerSource=Join-Path $DependencyLab 'candidate'
    foreach ($name in @('composer.json','composer.lock')) {
        if ((Get-FileHash -LiteralPath (Join-Path $composerSource $name) -Algorithm SHA256).Hash -ne $dependencies.candidate_sha256.$name) { throw 'Candidate lock changed.' }
    }
    if ((Get-Content -LiteralPath (Join-Path $systemSource 'CodeIgniter.php') -Raw) -notmatch "CI_VERSION = '4\.7\.4'") { throw 'Wrong candidate framework.' }
    $variant='candidate'
}
$runtime=& (Join-Path $PSScriptRoot 'prepare-container.ps1') -AsObject
$labId=[Guid]::NewGuid().ToString('N')
$context=Join-Path $repo ('rest/writable/fse-linux-labs/'+$labId)
$app=Join-Path $context 'app'
New-Item -ItemType Directory -Path $app | Out-Null
if ([IO.Path]::GetFullPath($runtime.Context) -notmatch ('^'+[regex]::Escape((Join-Path $repo 'rest/writable/fse-container-contexts'))+'[\\/][a-f0-9]{32}$')) { throw 'Unexpected runtime context.' }
Copy-Item -LiteralPath $runtime.Context -Destination (Join-Path $context 'runtime') -Recurse
$encoding=New-Object Text.UTF8Encoding($false)
$hashes=[ordered]@{}
$origins=[ordered]@{}
function Copy-LabSource([string]$Relative, [string]$SourceOverride='') {
    if ($Relative -notmatch '^(rest/(app|system|tests)/|public/|ops/fse-validation/|composer\.(json|lock)$)' -or $Relative.Contains('\') -or ($Relative.Split('/') | Where-Object { $_ -in @('.','..','') })) { throw ('Unsafe source path: '+$Relative) }
    $source=Get-Item -LiteralPath $(if ($SourceOverride) { $SourceOverride } else { Join-Path $repo $Relative })
    if ($source.PSIsContainer -or ($source.Attributes -band [IO.FileAttributes]::ReparsePoint)) { throw 'Regular files only.' }
    if (!$source.FullName.StartsWith($repo+[IO.Path]::DirectorySeparatorChar, [StringComparison]::OrdinalIgnoreCase)) { throw 'Source must remain inside the repository.' }
    $destination=Join-Path $app $Relative
    [IO.Directory]::CreateDirectory([IO.Path]::GetDirectoryName($destination)) | Out-Null
    [IO.File]::Copy($source.FullName,$destination,$false)
    $hashes[$Relative]=(Get-FileHash -LiteralPath $destination -Algorithm SHA256).Hash.ToLowerInvariant()
    $origins[$Relative]=$source.FullName.Substring($repo.Length+1).Replace('\','/')
}
foreach ($path in @('composer.json','composer.lock')) { Copy-LabSource $path (Join-Path $composerSource $path) }
# Only source PHP and inert browser assets; no dumps, vendor copies, .env, uploads or runtime.
foreach ($folder in @('rest/app','rest/system','public')) {
    $sourceFolder=if ($folder -eq 'rest/system') { $systemSource } else { Join-Path $repo $folder }
    foreach ($file in (Get-ChildItem -LiteralPath $sourceFolder -Recurse -File | Sort-Object FullName)) {
        $relative=$folder+'/'+$file.FullName.Substring($sourceFolder.Length+1).Replace('\','/')
        $extension=$file.Extension.ToLowerInvariant()
        if (($folder -ne 'public' -and $extension -eq '.php') -or ($folder -eq 'public' -and $extension -in @('.js','.css','.png','.jpg','.jpeg','.gif','.svg','.woff','.woff2','.ttf','.ico','.webp'))) {
            Copy-LabSource $relative $file.FullName
        }
    }
}
foreach ($file in (Get-ChildItem -LiteralPath (Join-Path $repo 'rest/tests/unit') -Filter 'Fse*Test.php')) { Copy-LabSource ('rest/tests/unit/'+$file.Name) }
Copy-LabSource 'rest/tests/_support/fse_synthetic.php'
foreach ($name in @('ui-preview.php','gateway-negative-jwt.php','prepare-rsa-negative-fixtures.py')) { Copy-LabSource ('ops/fse-validation/'+$name) }
foreach ($name in @('BillingHttpPreparationTest.php','AdministrativeClosureTest.php','ClinicalRecordTest.php','ClinicalFeatureTest.php','TsReconciliationTest.php','BillingEmailClosureTest.php','TsOperationClosureTest.php')) { Copy-LabSource ('rest/tests/unit/'+$name) }
foreach ($name in @('BillingDocumentServiceTest.php','BillingTsBridgeServiceTest.php')) { Copy-LabSource ('rest/tests/session/'+$name) }
foreach ($name in @('app-lab-clinical.php','app-lab-clinical-http.py','test_clinical_signature.py','linux-clinical-bootstrap.php','linux-clinical.xml')) { Copy-LabSource ('ops/fse-validation/'+$name) }
foreach ($name in @('lab-recovery-pair.php','app-lab-http.py','app-lab-ui-audit.php','app-lab-billing.php','app-lab-billing-http.py')) { Copy-LabSource ('ops/fse-validation/'+$name) }
foreach ($name in @('app-lab-common.php','app-lab-database.php','app-lab-boot.php','app-lab-router.php','app-lab-seed.php','app-lab-rehearsal.php','app-lab-rehearsal.py','app-lab-recovery.php','app-lab-sign.py','php-bootstrap.php','phpunit.xml','synthetic-database.php','lab-concurrency-worker.php','validator.py','test_validator.py','synthetic-cda.php','linux-lab-init.php','linux-lab-entrypoint.sh')) { Copy-LabSource ('ops/fse-validation/'+$name) }
Copy-Item -LiteralPath (Join-Path $PSScriptRoot 'linux-lab-composer.json') -Destination (Join-Path $app 'rest/composer.json')
$hashes['rest/composer.json']=(Get-FileHash -LiteralPath (Join-Path $app 'rest/composer.json') -Algorithm SHA256).Hash.ToLowerInvariant()
$origins['rest/composer.json']='ops/fse-validation/linux-lab-composer.json'
Copy-Item -LiteralPath (Join-Path $PSScriptRoot 'linux-lab-composer.lock') -Destination (Join-Path $app 'rest/composer.lock')
$hashes['rest/composer.lock']=(Get-FileHash -LiteralPath (Join-Path $app 'rest/composer.lock') -Algorithm SHA256).Hash.ToLowerInvariant()
$origins['rest/composer.lock']='ops/fse-validation/linux-lab-composer.lock'
Copy-Item -LiteralPath (Join-Path $PSScriptRoot 'linux-lab.Dockerfile') -Destination (Join-Path $context 'Dockerfile')
Copy-Item -LiteralPath (Join-Path $PSScriptRoot 'linux-lab.dockerignore') -Destination (Join-Path $context '.dockerignore')
$compose=(Get-Content -LiteralPath (Join-Path $PSScriptRoot 'linux-lab.compose.yaml') -Raw).Replace('__LAB_ID__',$labId)
[IO.File]::WriteAllText((Join-Path $context 'compose.yaml'),$compose,$encoding)
Copy-Item -LiteralPath (Join-Path $PSScriptRoot 'verify-linux-bundle.py') -Destination (Join-Path $context 'runtime/verify-linux-bundle.py')
Copy-Item -LiteralPath (Join-Path $PSScriptRoot 'linux-images.lock.json') -Destination (Join-Path $context 'runtime/linux-images.lock.json')
[IO.File]::WriteAllText((Join-Path $context 'runtime/linux-lab-image'),'FSE_SYNTHETIC_LINUX_ONLY',$encoding)
[IO.File]::WriteAllText((Join-Path $context 'runtime/app-manifest.json'),([ordered]@{mode='SYNTHETIC_APP_SOURCE';variant=$variant;files=$hashes;source_files=$origins}|ConvertTo-Json -Depth 5),$encoding)
$runtimeHashes=[ordered]@{}
foreach ($file in (Get-ChildItem -LiteralPath (Join-Path $context 'runtime') -Recurse -File | Where-Object Name -ne 'bundle-manifest.json' | Sort-Object FullName)) {
    $relative=$file.FullName.Substring((Join-Path $context 'runtime').Length+1).Replace('\','/')
    $runtimeHashes[$relative]=(Get-FileHash -LiteralPath $file.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
}
[IO.File]::WriteAllText((Join-Path $context 'runtime/bundle-manifest.json'),([ordered]@{mode='OFFLINE_SYNTHETIC_ONLY';files=$runtimeHashes}|ConvertTo-Json -Depth 5),$encoding)
[pscustomobject]@{Context=$context;LabId=$labId;Variant=$variant;Status='PREPARED_NOT_BUILT';LinuxExecution='NOT_EXECUTED';RemoteChanges=$false;ApplicationFiles=$hashes.Count}
