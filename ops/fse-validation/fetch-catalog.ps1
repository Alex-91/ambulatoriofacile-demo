param([string]$Destination = (Join-Path $PSScriptRoot '../../rest/writable/fse-validation-catalog'))
$ErrorActionPreference = 'Stop'
# Deliberately pinned: refreshing rules requires a reviewed code change and regression tests.
$revision = '687cf371e1d0caf4f5f9f7bcc80eab3a97f09885'
$repository = 'ministero-salute/it-fse-catalogs'
$destinationPath = [IO.Path]::GetFullPath($Destination)
if (Test-Path -LiteralPath (Join-Path $destinationPath 'manifest.json')) {
    throw 'Catalog already present. Use a new destination to preserve previous evidence.'
}
$tree = Invoke-RestMethod "https://api.github.com/repos/$repository/git/trees/${revision}?recursive=1"
$entries = $tree.tree | Where-Object { $_.type -eq 'blob' -and ($_.path -like 'schema/POCD_MT000040UV02/*.xsd' -or $_.path -in @('schematron/schematron_RSA_v8.3.sch','schematron/schematronFSE_RAD_v4.1.sch')) }
if (@($entries).Count -ne 13) { throw 'Unexpected official catalog file count.' }
$hashes = [ordered]@{}
foreach ($entry in $entries) {
    $target = Join-Path $destinationPath $entry.path
    New-Item -ItemType Directory -Path (Split-Path $target) -Force | Out-Null
    Invoke-WebRequest "https://raw.githubusercontent.com/$repository/$revision/$($entry.path)" -OutFile $target
    $hashes[$entry.path] = (Get-FileHash -LiteralPath $target -Algorithm SHA256).Hash.ToLowerInvariant()
}
$manifest = [ordered]@{ repository = $repository; revision = $revision; xsd = 'schema/POCD_MT000040UV02/CDA.xsd'; schematron = 'schematron/schematron_RSA_v8.3.sch'; files = $hashes }
[IO.File]::WriteAllText((Join-Path $destinationPath 'manifest.json'), ($manifest | ConvertTo-Json -Depth 5), [Text.UTF8Encoding]::new($false))
Write-Output "Downloaded 13 official files at $revision into $destinationPath"
