[CmdletBinding()]
param([ValidateSet('Prepare','ConfigureHttps','Read','Start','Stop')][string]$Action='Read',[Parameter(Mandatory=$true)][string]$ConfigPath)
$ErrorActionPreference='Stop'
$repo=[IO.Path]::GetFullPath((Join-Path $PSScriptRoot '../..'))
$cfg=Get-Content -LiteralPath $ConfigPath -Raw|ConvertFrom-Json
$base=$cfg.coolifyBaseUrl.TrimEnd('/')
if($base -ne 'https://coolify.simonefrosini.com'){throw 'Unexpected Coolify instance.'}
$headers=@{Authorization='Bearer '+$cfg.coolifyToken;Accept='application/json'}
$project='gtvqsgovexa13icsiwa3cl4o';$server='j7csvwde1m5howpxqcfhb8uv'
$name='ambulatoriofacile-pacs-synthetic';$envName='pacs-precollaudo'
$dir=Join-Path $repo 'rest/writable/pacs-cloud-lab';$statePath=Join-Path $dir 'state.json'
function Api([string]$Method,[string]$Path,$Body=$null){
 $request=@{Method=$Method;Uri=$base+'/api/v1'+$Path;Headers=$headers;TimeoutSec=30}
 if($null -ne $Body){$request.Body=$Body|ConvertTo-Json -Depth 20 -Compress;$request.ContentType='application/json'}
 $result=Invoke-RestMethod @request; $result | Write-Output
}
if($Action -eq 'Prepare'){
 if(Test-Path $statePath){throw 'Existing lab state; use Read to inspect it.'}
 if(@(Api Get '/services'|Where-Object name -eq $name).Count){throw 'Remote lab already exists; reconcile its identity before creating anything.'}
 $envs=@(Api Get "/projects/$project/environments"|Where-Object name -eq $envName)
 if(!$envs){Api Post "/projects/$project/environments" @{name=$envName}|Out-Null;$envs=@(Api Get "/projects/$project/environments"|Where-Object name -eq $envName)}
 if($envs.Count -ne 1){throw 'Ambiguous PACS test environment.'}
 [IO.Directory]::CreateDirectory($dir)|Out-Null
 $bytes=New-Object byte[] 32;$rng=[Security.Cryptography.RandomNumberGenerator]::Create();$rng.GetBytes($bytes);$rng.Dispose()
 $password=[Convert]::ToBase64String($bytes);$username='af-synthetic'
 $orthanc=@{Name='AF SYNTHETIC PACS ONLY';AuthenticationEnabled=$true;RegisteredUsers=@{$username=$password};RemoteAccessAllowed=$true;DicomServerEnabled=$false;
  StorageDirectory='/var/lib/orthanc/db';IndexDirectory='/var/lib/orthanc/db';MaximumStorageSize=96;OverwriteInstances=$false;
  DicomWeb=@{Enable=$true;Root='/dicom-web/';StudiesMetadata='Full';SeriesMetadata='Full'};HttpDescribeErrors=$false;ExecuteLuaEnabled=$false;RestApiWriteToFileSystemEnabled=$false}
 $compose=@{services=@{orthanc=@{
  image='orthancteam/orthanc@sha256:99082b87c96d56e57472d703ad799b779da7aa35aedac830d58cce646a43643f';
  environment=@{SERVICE_URL_ORTHANC_8042='/';ORTHANC_JSON=($orthanc|ConvertTo-Json -Depth 8 -Compress);DICOM_WEB_PLUGIN_ENABLED='true';VERBOSE_STARTUP='false'};
  user='999:999';tmpfs=@('/var/lib/orthanc/db:size=128m,uid=999,gid=999');mem_limit='512m';cpus=1;pids_limit=128;cap_drop=@('ALL');security_opt=@('no-new-privileges:true');
  labels=@{'af.lab'='pacs-cloud-synthetic'};restart='unless-stopped'
 }}}|ConvertTo-Json -Depth 20
 $composePath=Join-Path $dir 'compose.private.json';[IO.File]::WriteAllText($composePath,$compose)
 $state=@{marker='AF_PACS_CLOUD_SYNTHETIC_V1';name=$name;environmentUuid=$envs[0].uuid;environmentId=$envs[0].id;username=$username;password=$password;tenantId=4;createdAt=[DateTime]::UtcNow.ToString('o')}
 # Save local credentials before the API mutation so an uncertain response cannot orphan them.
 [IO.File]::WriteAllText($statePath,($state|ConvertTo-Json))
 $created=Api Post '/services' @{project_uuid=$project;server_uuid=$server;environment_uuid=$envs[0].uuid;name=$name;description='Synthetic PACS interoperability lab. No production data or shared storage. Tenant 4 test only.';docker_compose_raw=[Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($compose));instant_deploy=$false}
 if(!$created.uuid){throw 'Service creation uncertain. Inspect the exact remote name; do not duplicate.'}
 $state.serviceUuid=$created.uuid;$state.domains=$created.domains
 [IO.File]::WriteAllText($statePath,($state|ConvertTo-Json -Depth 6))
}
if(!(Test-Path $statePath)){throw 'No lab state.'}
$state=Get-Content $statePath -Raw|ConvertFrom-Json
if($state.marker -ne 'AF_PACS_CLOUD_SYNTHETIC_V1' -or !$state.serviceUuid){throw 'Unresolved lab identity.'}
$service=Api Get ('/services/'+$state.serviceUuid)
if($service.name -ne $name -or [int]$service.environment_id -ne [int]$state.environmentId){throw 'Remote lab identity mismatch.'}
if($Action -eq 'ConfigureHttps'){
 $app=@($service.applications|Where-Object name -eq 'orthanc')
 if($app.Count -ne 1){throw 'Ambiguous Orthanc application.'}
 $address=[Uri]$app[0].fqdn
 if($address.Host -notlike ('orthanc-'+$state.serviceUuid+'.*.sslip.io')){throw 'Unexpected generated lab domain.'}
 $baseUrl='https://'+$address.Host
 $raw=Get-Content (Join-Path $dir 'compose.private.json') -Raw
 Api Patch ('/services/'+$state.serviceUuid) @{docker_compose_raw=[Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes($raw));urls=@(@{name='orthanc';url=$baseUrl+':8042'});instant_deploy=$false}|Out-Null
 $service=Api Get ('/services/'+$state.serviceUuid)
 if($service.docker_compose -notmatch 'tls.certresolver=letsencrypt' -or $service.docker_compose -notmatch 'no-new-privileges:true'){throw 'HTTPS/security configuration missing.'}
 $state|Add-Member -NotePropertyName httpsReady -NotePropertyValue $true -Force
 $state|Add-Member -NotePropertyName baseUrl -NotePropertyValue $baseUrl -Force
 $state.domains=@($baseUrl)
 [IO.File]::WriteAllText($statePath,($state|ConvertTo-Json -Depth 8))
}
if($Action -eq 'Start'){
 if(!$state.httpsReady){throw 'Review the generated HTTPS route before starting.'}
 Api Post ('/services/'+$state.serviceUuid+'/start')|Out-Null
}elseif($Action -eq 'Stop'){Api Post ('/services/'+$state.serviceUuid+'/stop')|Out-Null}
@{uuid=$state.serviceUuid;name=$service.name;status=$service.status;domains=$state.domains}|ConvertTo-Json -Depth 6
