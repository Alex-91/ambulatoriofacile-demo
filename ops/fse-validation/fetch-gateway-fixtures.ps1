param()
$ErrorActionPreference = 'Stop'
$taskFseRevision = 'd937255fd7e9c079c5641c537da17fe98a2f2259'
$taskFseRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../.local/fse-accreditamento'))
$taskFseDest = Join-Path $taskFseRoot ('official-sources/' + $taskFseRevision)
if (Test-Path -LiteralPath $taskFseDest) { throw 'Source snapshot already exists; preserve it and inspect its manifest.' }
New-Item -ItemType Directory -Path $taskFseDest | Out-Null
$taskFseBase = 'https://raw.githubusercontent.com/ministero-salute/it-fse-accreditamento/' + $taskFseRevision
$taskFseXmlBase = $taskFseBase + '/Test%20Case/Validazione/Documenti%20XML%20Casi%20OK/7%20-%20Casi%20OK%20Referto%20Specialistico%20Ambulatoriale/'
$taskFseDownloads = [ordered]@{}
foreach ($taskFseCase in @(1,2,3,4,24,25)) { $taskFseDownloads[('rsa-case-' + $taskFseCase + '.xml')] = $taskFseXmlBase + 'RSA%20-%20Caso%20di%20Test%20' + $taskFseCase + '.xml' }
$taskFseDownloads['accreditamento-checklist_V9.0.0.xlsx'] = $taskFseBase + '/Test%20Case/accreditamento-checklist_V9.0.0.xlsx'
foreach ($taskFseKind in @('OK','KO')) { $taskFseDownloads[('RSA-' + $taskFseKind + '.xlsx')] = $taskFseBase + '/Test%20Case/Validazione/7-Referto%20Specialistico%20Ambulatoriale/CDA2_Referto_Specialistica_Ambulatoriale_' + $taskFseKind + '.xlsx' }
$taskFseDownloads['schedaAPIClient_HTTPS-FSE2_v3.3.ods'] = 'https://cart.regione.toscana.it/portale/wp-content/uploads/2026/05/schedaAPIClient_HTTPS-FSE2_v3.3.ods'
$taskFseManifest = [ordered]@{ mode='PREPARATORY_OFFICIAL_EXAMPLES'; revision=$taskFseRevision; retrieved_at=[DateTime]::UtcNow.ToString('o'); valid_for_new_accreditation_session='NOT_CONFIRMED'; files=[ordered]@{} }
foreach ($taskFseName in $taskFseDownloads.Keys) {
    $taskFsePath = Join-Path $taskFseDest $taskFseName
    Invoke-WebRequest -Uri $taskFseDownloads[$taskFseName] -OutFile $taskFsePath -MaximumRedirection 0 -TimeoutSec 30
    $taskFseManifest.files[$taskFseName] = [ordered]@{ url=$taskFseDownloads[$taskFseName]; sha256=(Get-FileHash -LiteralPath $taskFsePath -Algorithm SHA256).Hash.ToLowerInvariant() }
}
$taskFseManifest | ConvertTo-Json -Depth 6 | Set-Content -LiteralPath (Join-Path $taskFseDest 'manifest.json') -Encoding utf8
Write-Output $taskFseDest
