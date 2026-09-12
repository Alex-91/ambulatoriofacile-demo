# Read-only audit; no scripts/plugins/update/install. Reports contain public dependency metadata only.
[CmdletBinding()]
param()
$ErrorActionPreference = 'Stop'
$repo = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$directory = Join-Path $repo ('rest/writable/fse-dependency-audits/' + [Guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $directory | Out-Null
$encoding = [Text.UTF8Encoding]::new($false)
$scopes = @(
    @{Name='product-lock'; Path=$repo; Locked=$true},
    @{Name='product-installed-local'; Path=$repo; Locked=$false},
    @{Name='framework-installed-local'; Path=(Join-Path $repo 'rest'); Locked=$false}
)
$results = @()
foreach ($scope in $scopes) {
    $arguments = @('--no-interaction','--no-plugins','--no-scripts','--working-dir',$scope.Path,'audit','--format=json')
    if ($scope.Locked) { $arguments += '--locked' }
    $raw = & composer @arguments 2> (Join-Path $directory ($scope.Name+'.stderr.txt'))
    $code = $LASTEXITCODE
    $text = $raw -join "`n"
    [IO.File]::WriteAllText((Join-Path $directory ($scope.Name+'.json')), $text, $encoding)
    $parsed = $text | ConvertFrom-Json
    if ($null -eq $parsed.advisories -or $code -notin @(0,1,2,3)) { throw 'Composer audit incomplete; retained diagnostics in private report folder.' }
    $packages = @()
    if ($parsed.advisories -is [pscustomobject]) { $packages = @($parsed.advisories.PSObject.Properties) }
    $results += [ordered]@{scope=$scope.Name;exit_code=$code;advisory_packages=@($packages | ForEach-Object { $_.Name });abandoned=$parsed.abandoned}
}
$summary = [ordered]@{generated_at=[DateTime]::UtcNow.ToString('o');production_inspected=$false;dependencies_changed=$false;results=$results}
[IO.File]::WriteAllText((Join-Path $directory 'summary.json'), ($summary | ConvertTo-Json -Depth 12), $encoding)
[pscustomobject]@{ReportDirectory=$directory;Results=$results}
