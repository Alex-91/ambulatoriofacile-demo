# No Docker/build/deploy call: materialize a new, allowlisted, synthetic-only build context.
param([switch]$AsObject)
$ErrorActionPreference = 'Stop'
$repoRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$privateRoot = Join-Path $repoRoot 'rest/writable'
$context = Join-Path $privateRoot ('fse-container-contexts/' + [Guid]::NewGuid().ToString('N'))
$catalog = Join-Path $privateRoot 'fse-validation-catalog'
$manifest = Get-Content -LiteralPath (Join-Path $catalog 'manifest.json') -Raw | ConvertFrom-Json
if ($manifest.revision -ne '687cf371e1d0caf4f5f9f7bcc80eab3a97f09885') { throw 'Unexpected catalog revision.' }
$schArchive = Join-Path $privateRoot 'schxslt-1.10.1.zip'
if ((Get-FileHash -LiteralPath $schArchive -Algorithm SHA256).Hash.ToLowerInvariant() -ne '4f4f21edab7b37f96ad59ae12a344d3510f1092ac46b6d81a4efa0120b73cb58') { throw 'SchXslt archive hash mismatch.' }
New-Item -ItemType Directory -Path $context | Out-Null
function Copy-ApprovedFile([string]$Source, [string]$Relative) {
    if ($Relative -notmatch '^[A-Za-z0-9_./-]+$' -or $Relative.StartsWith('/') -or $Relative.Contains('..')) { throw 'Unsafe bundle path.' }
    $sourceFile = Get-Item -LiteralPath $Source
    if ($sourceFile.PSIsContainer -or ($sourceFile.Attributes -band [IO.FileAttributes]::ReparsePoint)) { throw 'Only regular files allowed.' }
    $destination = Join-Path $context $Relative
    New-Item -ItemType Directory -Path (Split-Path $destination) -Force | Out-Null
    Copy-Item -LiteralPath $sourceFile.FullName -Destination $destination
}
foreach ($file in @('validator.py','runtime-smoke.py','requirements.lock.txt')) { Copy-ApprovedFile (Join-Path $PSScriptRoot $file) $file }
Copy-ApprovedFile (Join-Path $PSScriptRoot 'runtime.Dockerfile') 'Dockerfile'
Copy-ApprovedFile (Join-Path $catalog 'manifest.json') 'catalog/manifest.json'
foreach ($property in $manifest.files.PSObject.Properties) {
    $relative = $property.Name
    if ($relative -notmatch '^(schema|schematron)/[A-Za-z0-9_./-]+$' -or $relative.Contains('..')) { throw 'Unsafe catalog path.' }
    $source = Join-Path $catalog $relative
    if ((Get-FileHash -LiteralPath $source -Algorithm SHA256).Hash.ToLowerInvariant() -ne $property.Value) { throw 'Catalog hash mismatch.' }
    Copy-ApprovedFile $source ('catalog/' + $relative)
}
# The compiler archive has been SHA-256 checked before extraction; never copy a private settings file.
Expand-Archive -LiteralPath $schArchive -DestinationPath (Join-Path $context 'schxslt')
Copy-ApprovedFile (Join-Path $privateRoot 'verapdf-1.30.2/bin/cli-1.30.2.jar') 'verapdf/bin/cli-1.30.2.jar'
$settings = Get-Content -LiteralPath (Join-Path $PSScriptRoot 'settings.example.json') -Raw | ConvertFrom-Json
$utf8 = New-Object Text.UTF8Encoding($false)
[IO.File]::WriteAllText((Join-Path $context 'settings.json'), ($settings | ConvertTo-Json -Depth 4), $utf8)
$cda = & php (Join-Path $PSScriptRoot 'synthetic-cda.php')
if ($LASTEXITCODE -ne 0 -or !$cda -or $cda -notmatch 'ClinicalDocument') { throw 'Synthetic CDA generation failed.' }
[IO.File]::WriteAllText((Join-Path $context 'synthetic.xml'), ($cda -join "`n"), $utf8)
$hashes = [ordered]@{}
foreach ($file in (Get-ChildItem -LiteralPath $context -Recurse -File | Sort-Object FullName)) {
    $relative = $file.FullName.Substring($context.Length + 1).Replace('\','/')
    $hashes[$relative] = (Get-FileHash -LiteralPath $file.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
}
$bundle = [ordered]@{mode='OFFLINE_SYNTHETIC_ONLY'; linux_execution='NOT_EXECUTED'; official_accreditation_evidence=$false; files=$hashes}
[IO.File]::WriteAllText((Join-Path $context 'bundle-manifest.json'), ($bundle | ConvertTo-Json -Depth 5), $utf8)
if ($AsObject) { [PSCustomObject]@{Context=$context;Status='PREPARED_NOT_BUILT'} }
else {
    Write-Output ('Prepared synthetic-only Docker context: ' + $context)
    Write-Output 'Not built or deployed. Follow runtime-container.md on an isolated Docker host.'
}
