[CmdletBinding()]
param()
$ErrorActionPreference = 'Stop'
$repo = (Resolve-Path (Join-Path $PSScriptRoot '../..')).Path
$parent = Join-Path $repo 'rest/writable/fse-framework-compat'
$lab = Join-Path $parent ([guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $lab -Force | Out-Null
$versions = [ordered]@{
    '4.6.0' = '5f37fda626cae9e9c81244777263b70c581fa27d'
    '4.7.4' = '2bd0f01d2813f9ec06db42643ce39d9f5428bf6d'
}
function Get-TreeInventory([string]$Directory) {
    $result = [ordered]@{}
    Get-ChildItem -LiteralPath $Directory -File -Recurse | Sort-Object FullName | ForEach-Object {
        $relative = [IO.Path]::GetRelativePath($Directory, $_.FullName).Replace('\', '/')
        $bytes = [IO.File]::ReadAllBytes($_.FullName)
        $normalized = $null
        try {
            $utf8 = [Text.UTF8Encoding]::new($false, $true)
            $value = $utf8.GetString($bytes).Replace("`r`n", "`n")
            $normalized = [Convert]::ToHexString([Security.Cryptography.SHA256]::HashData($utf8.GetBytes($value))).ToLowerInvariant()
        } catch [Text.DecoderFallbackException] { }
        $result[$relative] = [ordered]@{
            sha256 = (Get-FileHash -LiteralPath $_.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
            lf_sha256 = $normalized
        }
    }
    return $result
}
$before = Get-TreeInventory (Join-Path $repo 'rest/system')
$sources = [ordered]@{}
foreach ($version in $versions.Keys) {
    $commit = $versions[$version]
    $url = "https://codeload.github.com/codeigniter4/CodeIgniter4/zip/$commit"
    $archive = Join-Path $lab "$version.zip"
    Invoke-WebRequest -Uri $url -OutFile $archive -Headers @{ 'User-Agent' = 'AmbulatorioFacile-framework-preflight' }
    $destination = Join-Path $lab "source-$version"
    # Reject paths that could escape the new private extraction directory.
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $zip = [IO.Compression.ZipFile]::OpenRead($archive)
    try {
        $boundary = [IO.Path]::GetFullPath($destination) + [IO.Path]::DirectorySeparatorChar
        foreach ($entry in $zip.Entries) {
            $target = [IO.Path]::GetFullPath((Join-Path $destination $entry.FullName))
            if (-not $target.StartsWith($boundary, [StringComparison]::OrdinalIgnoreCase)) {
                throw "Unsafe archive entry: $($entry.FullName)"
            }
        }
    } finally { $zip.Dispose() }
    Expand-Archive -LiteralPath $archive -DestinationPath $destination
    $sourceRoot = Join-Path $destination "CodeIgniter4-$commit"
    if (-not (Test-Path -LiteralPath (Join-Path $sourceRoot 'system/Boot.php'))) { throw 'Source layout missing' }
    $sources[$version] = [ordered]@{
        commit = $commit; url = $url
        archive_sha256 = (Get-FileHash -LiteralPath $archive -Algorithm SHA256).Hash.ToLowerInvariant()
        root = $sourceRoot
    }
}
$upstream = Get-TreeInventory (Join-Path $sources['4.6.0'].root 'system')
$differences = @()
$lineEndingsOnly = @()
foreach ($name in (@($before.Keys) + @($upstream.Keys) | Sort-Object -Unique)) {
    if (-not $before.Contains($name)) { $differences += @{path=$name; kind='missing_locally'} }
    elseif (-not $upstream.Contains($name)) { $differences += @{path=$name; kind='local_only'} }
    elseif ($before[$name].sha256 -ne $upstream[$name].sha256) {
        if ($null -ne $before[$name].lf_sha256 -and $before[$name].lf_sha256 -eq $upstream[$name].lf_sha256) {
            $lineEndingsOnly += $name
        } else { $differences += @{path=$name; kind='modified'} }
    }
}
$after = Get-TreeInventory (Join-Path $repo 'rest/system')
$unchanged = ($before | ConvertTo-Json -Depth 5 -Compress) -ceq ($after | ConvertTo-Json -Depth 5 -Compress)
$report = [ordered]@{
    mode = 'LOCAL_FRAMEWORK_COMPATIBILITY_ONLY'
    created_utc = [DateTime]::UtcNow.ToString('o')
    baseline_version = '4.6.0'; candidate_version = '4.7.4'
    sources = $sources; active_file_count = $before.Count
    baseline_upstream_file_count = $upstream.Count
    active_system_inventory = $before
    local_differences = @($differences); line_endings_only = @($lineEndingsOnly)
    active_system_unchanged = $unchanged
    status = 'SOURCE_DOWNLOADED_AND_COMPARED_NOT_ACTIVATED'
}
$report | ConvertTo-Json -Depth 10 | Set-Content -LiteralPath (Join-Path $lab 'lab.json') -Encoding utf8
if (-not $unchanged) { throw 'Active system changed during preparation' }
[pscustomobject]@{ lab=$lab; files=$before.Count; differences=$differences; line_endings_only=$lineEndingsOnly.Count; active_system_unchanged=$unchanged } | ConvertTo-Json -Depth 6
