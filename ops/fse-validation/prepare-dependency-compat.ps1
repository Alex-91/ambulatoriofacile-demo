# Install only in a fresh ignored compatibility folder. Active vendor, framework and DB are untouched.
[CmdletBinding()]
param([Parameter(Mandatory=$true)][string]$Candidate)
$ErrorActionPreference='Stop'
$repo=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$candidateRoot=(Resolve-Path -LiteralPath $Candidate).Path
$allowed=Join-Path $repo 'rest/writable/fse-dependency-candidates'
if ($candidateRoot -notmatch ('^'+[regex]::Escape($allowed)+'[\\/][a-f0-9]{32}$') -or ((Get-Item -LiteralPath $candidateRoot).Attributes -band [IO.FileAttributes]::ReparsePoint)) { throw 'A private generated candidate is required.' }
$candidateSummary=Get-Content -LiteralPath (Join-Path $candidateRoot 'summary.json') -Raw | ConvertFrom-Json
if ($candidateSummary.status -ne 'resolved_audit_clean_NOT_INSTALLED_OR_TESTED' -or !$candidateSummary.source_unchanged) { throw 'Candidate resolution must be complete.' }
$id=[Guid]::NewGuid().ToString('N')
$lab=Join-Path $repo ('rest/writable/fse-dependency-compat/'+$id)
$install=Join-Path $lab 'candidate'
New-Item -ItemType Directory -Path $install | Out-Null
$hashes=[ordered]@{}
foreach ($relative in @('composer.json','composer.lock','vendor/composer/installed.json','rest/vendor/composer/installed.json','rest/system/CodeIgniter.php')) {
    $hashes[$relative]=(Get-FileHash -LiteralPath (Join-Path $repo $relative)).Hash
}
$candidateHashes=[ordered]@{}
foreach ($name in @('composer.json','composer.lock')) {
    Copy-Item -LiteralPath (Join-Path $candidateRoot $name) -Destination (Join-Path $install $name)
    $candidateHashes[$name]=(Get-FileHash -LiteralPath (Join-Path $install $name)).Hash
}
$encoding=[Text.UTF8Encoding]::new($false)
$oldEnv=@{}
foreach ($key in @('COMPOSER','COMPOSER_HOME','COMPOSER_VENDOR_DIR','COMPOSER_BIN_DIR','COMPOSER_CACHE_DIR','COMPOSER_AUTH')) {
    if (!$oldEnv.ContainsKey($key)) { $oldEnv[$key]=[Environment]::GetEnvironmentVariable($key,'Process') }
    [Environment]::SetEnvironmentVariable($key,$null,'Process')
}
try {
    # An empty COMPOSER_VENDOR_DIR on Windows means the working directory, not vendor/.
    $env:COMPOSER=Join-Path $install 'composer.json'
    $env:COMPOSER_VENDOR_DIR=Join-Path $install 'vendor'
    $env:COMPOSER_BIN_DIR=Join-Path $install 'vendor/bin'
    $env:COMPOSER_HOME=Join-Path $lab 'composer-home'
    $env:COMPOSER_CACHE_DIR=Join-Path $lab 'composer-cache'
    $raw=& composer --no-interaction --no-plugins --no-scripts --working-dir $install install --no-dev --prefer-dist --no-progress 2>&1
    $code=$LASTEXITCODE
    [IO.File]::WriteAllText((Join-Path $lab 'install.log'),($raw -join "`n"),$encoding)
} finally {
    foreach ($key in $oldEnv.Keys) { [Environment]::SetEnvironmentVariable($key,$oldEnv[$key],'Process') }
}
$unchanged=$true
foreach ($path in $hashes.Keys) { if ((Get-FileHash -LiteralPath (Join-Path $repo $path)).Hash -ne $hashes[$path]) { $unchanged=$false } }
foreach ($name in $candidateHashes.Keys) { if ((Get-FileHash -LiteralPath (Join-Path $install $name)).Hash -ne $candidateHashes[$name]) { $unchanged=$false } }
$layoutValid=(Test-Path -LiteralPath (Join-Path $install 'vendor/autoload.php')) -and (Test-Path -LiteralPath (Join-Path $install 'vendor/composer/installed.json'))
$marker=[ordered]@{mode='LOCAL_DEPENDENCY_COMPATIBILITY_ONLY';lab_id=$id;generated_at=[DateTime]::UtcNow.ToString('o');candidate_id=(Split-Path $candidateRoot -Leaf);install_exit_code=$code;layout_valid=$layoutValid;source_unchanged=$unchanged;source_sha256=$hashes;candidate_sha256=$candidateHashes;production_changed=$false;database_used=$false}
[IO.File]::WriteAllText((Join-Path $lab 'lab.json'),($marker | ConvertTo-Json -Depth 6),$encoding)
if ($code -ne 0 -or !$unchanged -or !$layoutValid) { throw ('Isolated installation failed; diagnostics retained at '+$lab) }
[pscustomobject]@{Lab=$lab;Status='CANDIDATE_INSTALLED_IN_PRIVATE_COPY_NOT_ACTIVE';SourceUnchanged=$unchanged}
