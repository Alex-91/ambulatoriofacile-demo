# Only creates/manages the named FSE capacity probe. Never mutates existing application resources.
[CmdletBinding()]
param([ValidateSet('Prepare','Start','Read','Stop')][string]$Action='Read')
$ErrorActionPreference='Stop'
if ($Action -in @('Prepare','Start')) { throw 'Dockerfile probe disabled: Coolify truncated security options. Use a reviewed native Compose probe; do not remove hardening.' }
$repo=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$config=Get-Content -LiteralPath (Join-Path $repo 'ops/release-config.local.json') -Raw | ConvertFrom-Json
$base=$config.coolifyBaseUrl.TrimEnd('/')
if ($base -ne 'https://coolify.simonefrosini.com') { throw 'Unexpected Coolify destination.' }
$headers=@{Authorization=('Bearer '+$config.coolifyToken);Accept='application/json'}
$project='gtvqsgovexa13icsiwa3cl4o'
$server='j7csvwde1m5howpxqcfhb8uv'
$name='ambulatoriofacile-fse-capacity-probe'
$envName='fse-precollaudo'
$statePath=Join-Path $repo 'rest/writable/fse-coolify-capacity-probe.json'
function Api([string]$Method,[string]$Path,$Body=$null) {
    $request=@{Method=$Method;Uri=($base+'/api/v1'+$Path);Headers=$headers;TimeoutSec=30}
    if($null -ne $Body) { $request.Body=$Body|ConvertTo-Json -Depth 8 -Compress; $request.ContentType='application/json' }
    $result=Invoke-RestMethod @request
    $result | Write-Output
}
if ($Action -eq 'Prepare') {
    if(Test-Path -LiteralPath $statePath) { throw 'Existing probe state: use Read, do not duplicate.' }
    $envs=@(Api Get "/projects/$project/environments")
    $environment=@($envs | Where-Object name -eq $envName)
    if($environment.Count -gt 1) { throw 'Ambiguous FSE environment.' }
    if(!$environment) { Api Post "/projects/$project/environments" @{name=$envName} | Out-Null; $environment=@(Api Get "/projects/$project/environments" | Where-Object name -eq $envName) }
    if($environment.Count -ne 1) { throw 'FSE environment not resolved.' }
    $existing=@(Api Get '/applications' | Where-Object name -eq $name)
    if($existing) { throw 'Probe already exists remotely: inspect before reconciling.' }
    $dockerfile=Get-Content -LiteralPath (Join-Path $PSScriptRoot 'coolify-capacity-probe.Dockerfile') -Raw
    $body=@{project_uuid=$project;server_uuid=$server;environment_uuid=$environment[0].uuid;name=$name;
        description='FSE read-only capacity probe: no data, no mounts, no public listener. Stop after reading.';
        dockerfile=[Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($dockerfile));build_pack='dockerfile';
        ports_exposes='8080';health_check_enabled=$false;connect_to_docker_network=$false;autogenerate_domain=$false;
        instant_deploy=$false;limits_memory='64m';limits_memory_swap='64m';limits_cpus='0.10';
        custom_docker_run_options='--cap-drop=ALL --security-opt=no-new-privileges --read-only --pids-limit=32'}
    $created=Api Post '/applications/dockerfile' $body
    if(!$created.uuid) { throw 'Creation result uncertain; inspect remotely before retrying.' }
    $state=[ordered]@{mode='FSE_CAPACITY_PROBE_ONLY';appUuid=$created.uuid;environmentUuid=$environment[0].uuid;environmentId=$environment[0].id;createdAt=[DateTime]::UtcNow.ToString('o')}
    [IO.File]::WriteAllText($statePath,($state|ConvertTo-Json),(New-Object Text.UTF8Encoding($false)))
}
if(!(Test-Path -LiteralPath $statePath)) { throw 'No probe state. Use explicit Prepare first.' }
$state=Get-Content -LiteralPath $statePath -Raw | ConvertFrom-Json
if($state.mode -ne 'FSE_CAPACITY_PROBE_ONLY' -or $state.appUuid -notmatch '^[a-z0-9]+$') { throw 'Invalid probe state.' }
$probe=Api Get ('/applications/'+$state.appUuid)
if($probe.name -ne $name -or [int]$probe.environment_id -ne [int]$state.environmentId -or $probe.fqdn -or $probe.ports_mappings) { throw 'Probe identity/exposure mismatch.' }
if($Action -eq 'Start') {
    if($probe.limits_memory -ne '64m' -or $probe.limits_cpus -ne '0.10' -or $probe.custom_docker_run_options -notmatch '--pids-limit=32') { throw 'Probe limits not saved.' }
    if($probe.status -like 'running:*') { throw 'Already running; read it instead.' }
    Api Post ('/applications/'+$state.appUuid+'/start') | ConvertTo-Json -Depth 4
} elseif($Action -eq 'Stop') {
    Api Post ('/applications/'+$state.appUuid+'/stop') | ConvertTo-Json -Depth 4
} elseif($Action -eq 'Read' -and $probe.status -like 'running:*') {
    Api Get ('/applications/'+$state.appUuid+'/logs?lines=80') | ConvertTo-Json -Depth 4
}
$probe | Select-Object uuid,name,status,environment_id,fqdn,ports_mappings,limits_memory,limits_cpus,custom_docker_run_options | ConvertTo-Json
