[CmdletBinding()]
param(
    [string]$ArchivePath = '',
    [string]$OutputPath = '',
    [string]$SourcePage = 'https://www.gardainformatica.it/database-comuni-italiani'
)

$ErrorActionPreference = 'Stop'

$repoRoot = Split-Path -Parent $PSScriptRoot
if ([string]::IsNullOrWhiteSpace($OutputPath)) {
    $OutputPath = Join-Path $repoRoot 'public\data\italian-addresses.json'
}

$OutputPath = [System.IO.Path]::GetFullPath($OutputPath)
$temporaryRoot = Join-Path ([System.IO.Path]::GetTempPath()) ('ambulatoriofacile-address-data-' + [guid]::NewGuid().ToString('N'))
$downloadedArchive = $false
$datasetUpdatedAt = ''

function Get-TitleCaseName {
    param([AllowEmptyString()][string]$Value)

    if ([string]::IsNullOrWhiteSpace($Value)) {
        return ''
    }

    $culture = [System.Globalization.CultureInfo]::GetCultureInfo('it-IT')
    return $culture.TextInfo.ToTitleCase($Value.Trim().ToLower($culture))
}

try {
    New-Item -ItemType Directory -Path $temporaryRoot -Force | Out-Null

    if ([string]::IsNullOrWhiteSpace($ArchivePath)) {
        Write-Host "Cerco l'ultima versione del dataset..."
        $page = Invoke-WebRequest -Uri $SourcePage -UseBasicParsing
        $archiveMatch = [regex]::Match(
            $page.Content,
            '(?i)(?:https?://[^"''<>\s]+)?/gi_db_comuni/gi_db_comuni-[^"''<>\s]+\.zip'
        )

        if (-not $archiveMatch.Success) {
            throw "Link del dataset non trovato nella pagina $SourcePage"
        }

        $archiveUrl = $archiveMatch.Value
        if ($archiveUrl.StartsWith('/')) {
            $archiveUrl = ([uri]::new([uri]$SourcePage, $archiveUrl)).AbsoluteUri
        }

        $ArchivePath = Join-Path $temporaryRoot 'gi_db_comuni.zip'
        Write-Host "Scarico $archiveUrl"
        Invoke-WebRequest -Uri $archiveUrl -OutFile $ArchivePath -UseBasicParsing
        $downloadedArchive = $true

        $dateMatch = [regex]::Match($page.Content, '(?i)Dati aggiornati al\s*(\d{2})/(\d{2})/(\d{4})')
        if ($dateMatch.Success) {
            $datasetUpdatedAt = '{0}-{1}-{2}' -f $dateMatch.Groups[3].Value, $dateMatch.Groups[2].Value, $dateMatch.Groups[1].Value
        }
    }

    $ArchivePath = [System.IO.Path]::GetFullPath($ArchivePath)
    if (-not (Test-Path -LiteralPath $ArchivePath -PathType Leaf)) {
        throw "Archivio non trovato: $ArchivePath"
    }

    if ([string]::IsNullOrWhiteSpace($datasetUpdatedAt)) {
        $archiveDateMatch = [regex]::Match([System.IO.Path]::GetFileName($ArchivePath), '(\d{4})-(\d{2})-(\d{2})')
        if ($archiveDateMatch.Success) {
            $datasetUpdatedAt = $archiveDateMatch.Value
        } else {
            $datasetUpdatedAt = (Get-Date).ToString('yyyy-MM-dd')
        }
    }

    $expandedPath = Join-Path $temporaryRoot 'expanded'
    Expand-Archive -LiteralPath $ArchivePath -DestinationPath $expandedPath -Force

    $jsonPath = Join-Path $expandedPath 'json'
    $municipalities = Get-Content -LiteralPath (Join-Path $jsonPath 'gi_comuni.json') -Raw | ConvertFrom-Json
    $validityRows = Get-Content -LiteralPath (Join-Path $jsonPath 'gi_comuni_validita.json') -Raw | ConvertFrom-Json
    $capRows = Get-Content -LiteralPath (Join-Path $jsonPath 'gi_cap.json') -Raw | ConvertFrom-Json
    $provinceRows = Get-Content -LiteralPath (Join-Path $jsonPath 'gi_province.json') -Raw | ConvertFrom-Json

    $capsByMunicipality = @{}
    foreach ($row in $capRows) {
        $istatCode = [string]$row.codice_istat
        if (-not $capsByMunicipality.ContainsKey($istatCode)) {
            $capsByMunicipality[$istatCode] = New-Object System.Collections.Generic.List[string]
        }

        $cap = ([string]$row.cap).Trim()
        if ($cap -and -not $capsByMunicipality[$istatCode].Contains($cap)) {
            $capsByMunicipality[$istatCode].Add($cap)
        }
    }

    $compactProvinces = @(
        $provinceRows |
            Sort-Object denominazione_provincia, sigla_provincia |
            ForEach-Object {
                [ordered]@{
                    c = ([string]$_.sigla_provincia).Trim()
                    n = ([string]$_.denominazione_provincia).Trim()
                }
            }
    )

    $compactMunicipalities = @(
        $municipalities |
            Sort-Object denominazione_ita_altra, sigla_provincia |
            ForEach-Object {
                $istatCode = [string]$_.codice_istat
                $caps = @()
                if ($capsByMunicipality.ContainsKey($istatCode)) {
                    $caps = @($capsByMunicipality[$istatCode] | Sort-Object)
                }

                [ordered]@{
                    n = ([string]$_.denominazione_ita_altra).Trim()
                    p = ([string]$_.sigla_provincia).Trim()
                    c = $caps
                }
            }
    )

    $historicalSeen = @{}
    $compactHistorical = @(
        foreach ($row in ($validityRows | Where-Object { $_.stato_validita -eq 'Inattivo' } | Sort-Object denominazione_ita, sigla_provincia, data_fine_validita)) {
            $name = Get-TitleCaseName ([string]$row.denominazione_ita)
            $province = ([string]$row.sigla_provincia).Trim()
            $validTo = ([string]$row.data_fine_validita).Trim()
            $key = ($name + '|' + $province + '|' + $validTo).ToUpperInvariant()

            if (-not $historicalSeen.ContainsKey($key)) {
                $historicalSeen[$key] = $true
                [ordered]@{
                    n = $name
                    p = $province
                    u = $validTo
                }
            }
        }
    )

    $payload = [ordered]@{
        v = 1
        updated = $datasetUpdatedAt
        source = $SourcePage
        license = 'MIT - Garda Informatica'
        p = $compactProvinces
        m = $compactMunicipalities
        h = @($compactHistorical)
    }

    $outputDirectory = Split-Path -Parent $OutputPath
    New-Item -ItemType Directory -Path $outputDirectory -Force | Out-Null
    $json = $payload | ConvertTo-Json -Depth 6 -Compress
    $utf8WithoutBom = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllText($OutputPath, $json, $utf8WithoutBom)

    Write-Host ("Creato {0}: {1} comuni attuali, {2} comuni storici, {3} province." -f `
        $OutputPath,
        $compactMunicipalities.Count,
        $compactHistorical.Count,
        $compactProvinces.Count)
} finally {
    $resolvedTemporaryRoot = [System.IO.Path]::GetFullPath($temporaryRoot)
    $systemTemporaryRoot = [System.IO.Path]::GetFullPath([System.IO.Path]::GetTempPath())

    if (
        (Test-Path -LiteralPath $resolvedTemporaryRoot) -and
        $resolvedTemporaryRoot.StartsWith($systemTemporaryRoot, [System.StringComparison]::OrdinalIgnoreCase) -and
        $resolvedTemporaryRoot -ne $systemTemporaryRoot
    ) {
        Remove-Item -LiteralPath $resolvedTemporaryRoot -Recurse -Force
    }
}
