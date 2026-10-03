[CmdletBinding()]
param([ValidateSet('Prepare','Status','Deploy')][string]$Action='Status',[Parameter(Mandatory=$true)][string]$ConfigPath)
$ErrorActionPreference='Stop'
$cfg=Get-Content -LiteralPath $ConfigPath -Raw|ConvertFrom-Json
$api=$cfg.coolifyBaseUrl.TrimEnd('/')+'/api/v1'
$headers=@{Authorization='Bearer '+$cfg.coolifyToken}
$appName='ambulatoriofacile-menu-test'
$testDb='uapmyovmgml4ov24y4d94tao'
$testUrl='https://af-menu-test.178.104.113.107.sslip.io'
function Api([string]$method,[string]$path,$body=$null){
 $params=@{Method=$method;Uri=$api+$path;Headers=$headers;TimeoutSec=45}
 if($null -ne $body){$params.ContentType='application/json';$params.Body=$body|ConvertTo-Json -Depth 20 -Compress}
 try {Invoke-RestMethod @params} catch {throw "Coolify $method $path failed (details suppressed to protect credentials): $($_.Exception.Response.StatusCode)"}
}
$apps=@(Api Get '/applications')
$matches=@($apps|Where-Object name -eq $appName)
if($matches.Count -gt 1){throw 'Ambiguous test application'}
$app=if($matches.Count){$matches[0]}else{$null}
if($Action -eq 'Prepare'){
 $db=Api Get ('/databases/'+$testDb)
 if($db.status -ne 'running:healthy' -or $db.is_public){throw 'Test database must be healthy and private'}
 if(!$app){
  $live=Api Get '/applications/eei0est5n3e3l638djjynuk4'
  $gitUrl=if($live.git_full_url){$live.git_full_url}else{'https://github.com/'+$live.git_repository}
  $created=Api Post '/applications/public' @{
   project_uuid='gtvqsgovexa13icsiwa3cl4o';server_uuid='j7csvwde1m5howpxqcfhb8uv';environment_uuid='sgurxzkkkk06o68phpm3h22v';
   name=$appName;description='Isolated navigation preview with copied tenant accounts. No production database or storage.';
   git_repository=$gitUrl;git_branch='codex/unified-menu-test';build_pack='dockerfile';dockerfile_location='/Dockerfile';ports_exposes='80';
   domains=$testUrl;instant_deploy=$false;health_check_enabled=$true;health_check_path='/login';health_check_port='80';health_check_return_code=200
  }
  if(!$created.uuid){throw 'Creation outcome unknown; inspect application name before retrying'}
  $app=Api Get ('/applications/'+$created.uuid)
 }
 if($app.git_branch -ne 'codex/unified-menu-test'){throw 'Unexpected branch on preview'}
 $envs=@{}
 foreach($entry in @(Api Get '/applications/eei0est5n3e3l638djjynuk4/envs')){
  if(!$entry.is_preview -and $entry.key -in @('DB_ENCRYPTION_KEY','DB_ENCRYPTION_MODE','FILE_CRYPT_ALGO','FILE_CRYPT_KEY','FILE_CRYPT_IV','PLATFORM_MASTER_EMAILS','PRODUCT_BRAND_NAME','PRODUCT_BRAND_SHORT_NAME')){$envs[$entry.key]=[string]$entry.value}
 }
 $envs.CI_ENVIRONMENT='production';$envs.RUN_MIGRATIONS='1';$envs.BOOTSTRAP_DEMO_DB='0';$envs.RUN_DEMO_SEED='0';$envs.DEMO_AUTO_RESET_ENABLED='0';$envs.DEMO_SITE_ENABLED='0'
 $envs.APP_BASE_URL=$testUrl+'/app/';$envs.APP_CANONICAL_URL=$testUrl;$envs.APP_PUBLIC_ACCESS_BASE_URL=$testUrl+'/';$envs.APP_ROOT_ENTRY='login'
 # Generate a new preview-only token signing key; never reuse a live signing key.
 $envs.AUTH_HANDOFF_SECRET=[Guid]::NewGuid().ToString('N')+[Guid]::NewGuid().ToString('N')
 $envs.AUTH_HANDOFF_ISSUER=$testUrl
 $envs.AF_NAVIGATION_TEST='1';$envs.AF_TEST_DB_HOST=$testDb;$envs.LEGACY_UPLOAD_PATH='/var/www/html/upload'
 foreach($prefix in @('DB','PLATFORM_DB')){$envs[$prefix+'_HOST']=$testDb;$envs[$prefix+'_DATABASE']='ambulatoriofacile_login';$envs[$prefix+'_USERNAME']='root';$envs[$prefix+'_PASSWORD']=[string]$db.mysql_root_password;$envs[$prefix+'_PORT']='3306';$envs[$prefix+'_DRIVER']='MySQLi'}
 $envs.TENANT_PROVISIONING_FORCE_RUNTIME_OVERRIDE='1';$envs.TENANT_PROVISIONING_RUNTIME_HOST=$testDb;$envs.TENANT_PROVISIONING_RUNTIME_USERNAME='root';$envs.TENANT_PROVISIONING_RUNTIME_PASSWORD_REF='PLATFORM_DB_PASSWORD'
 $envs.TENANT_PROVISIONING_ADMIN_HOST=$testDb;$envs.TENANT_PROVISIONING_ADMIN_USERNAME='root';$envs.TENANT_PROVISIONING_ADMIN_PASSWORD=[string]$db.mysql_root_password
 $envs.EMAIL_PROTOCOL='smtp';$envs.EMAIL_SMTP_HOST='127.0.0.1';$envs.EMAIL_SMTP_PORT='9';$envs.EMAIL_SMTP_TIMEOUT='1';$envs.PUSH_MODE='disabled';$envs.WHATSAPP_PROVIDER='disabled';$envs.APP_MAINTENANCE_MODE='0'
 $existing=@(Api Get ('/applications/'+$app.uuid+'/envs'))
 foreach($key in $envs.Keys){
  $method=if(@($existing|Where-Object {$_.key -eq $key -and !$_.is_preview}).Count){'Patch'}else{'Post'}
  Api $method ('/applications/'+$app.uuid+'/envs') @{key=$key;value=$envs[$key];is_preview=$false;is_buildtime=$false;is_runtime=$true;is_literal=$true;is_multiline=$false;is_shown_once=$false}|Out-Null
 }
 $storages=Api Get ('/applications/'+$app.uuid+'/storages')
 foreach($storage in @(@{name='af-menu-test-upload';mount_path='/var/www/html/upload'},@{name='af-menu-test-writable';mount_path='/var/www/html/rest/writable'})){
  if(($storages|ConvertTo-Json -Depth 10) -notmatch [regex]::Escape($storage.mount_path)){Api Post ('/applications/'+$app.uuid+'/storages') @{type='persistent';name=$storage.name;mount_path=$storage.mount_path}|Out-Null}
 }
 Write-Output 'Preview configuration prepared; no production settings changed.'
}
if(!$app){throw 'Preview not created'}
if($app.name -ne $appName -or $app.uuid -in @('eei0est5n3e3l638djjynuk4','oi549qyu9k93q0grjuctnhfm','nschcwu49cu4bmbj9ka3uesk')){throw 'Preview identity check failed'}
if($Action -eq 'Deploy'){Api Get ('/deploy?uuid='+$app.uuid+'&force=false')|ConvertTo-Json -Depth 5}
$app=Api Get ('/applications/'+$app.uuid)
[pscustomobject]@{uuid=$app.uuid;name=$app.name;branch=$app.git_branch;status=$app.status;url=$app.fqdn}|ConvertTo-Json
