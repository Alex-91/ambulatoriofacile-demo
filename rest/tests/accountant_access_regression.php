<?php
// Dedicated generated runtime, separate from the app's writable sessions/cache.
define('WRITEPATH', dirname(__DIR__) . '/build/accountant-runtime/');
foreach (['cache','logs','session','billing-pdf-runtime/20/fonts','billing-pdf-runtime/20/temp'] as $dir) {
    if (!is_dir(WRITEPATH.$dir)) { mkdir(WRITEPATH.$dir,0777,true); }
}
require __DIR__ . '/_support/billing_port_bootstrap.php';
require __DIR__ . '/_support/accountant_synthetic_boundaries.php';
function checkAccountant(bool $ok, string $message): void
{
    if (!$ok) { throw new \RuntimeException($message); }
}
$db = \Config\Database::connect(['DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => '', 'DBDebug' => true], false);
require APPPATH . 'Database/Accountant/AddAccountantDelegations.php';
$migration = new \App\Database\Accountant\AddAccountantDelegations(\Config\Database::forge($db));
$migration->up();
$service = new \App\Services\AccountantDelegationService($db);
checkAccountant($service->ready(), 'Migration must create all tables');
$a = $service->invite(10, 'Medico A', 'TEST@example.com', 2025);
$b = $service->invite(20, 'Medico B', 'test@example.com', 2024);
checkAccountant(!$service->grant('test@example.com', 10), 'Pending invitation must not grant access');
checkAccountant($service->accept($a['token']) === 'test@example.com', 'Invitation must verify the mailbox and activate');
checkAccountant($service->accept($a['token']) === null, 'Invitation replay must fail');
checkAccountant($service->grant('test@example.com', 10) !== null, 'Accepted delegation must work');
checkAccountant($service->grant('other@example.com', 10) === null, 'Another mailbox must be denied');
checkAccountant($service->grant('test@example.com', 20) === null, 'Another doctor without active delegation must be denied');
checkAccountant($service->accept($b['token']) !== null && count($service->forEmail('test@example.com')) === 2, 'One accountant may hold multiple delegations');
$token = $service->loginToken('test@example.com');
checkAccountant($token !== null && $service->loginToken('test@example.com') === null, 'Login emails must have cooldown');
checkAccountant($service->consumeLogin($token) === 'test@example.com', 'Valid login must work');
checkAccountant($service->consumeLogin($token) === null, 'Login token replay must fail');
$service->revoke(20, $a['id']);
checkAccountant($service->grant('test@example.com', 10) !== null, 'Doctor cannot revoke another doctor delegation');
$service->revoke(10, $a['id']);
checkAccountant($service->grant('test@example.com', 10) === null, 'Revocation must immediately affect an existing identity');
checkAccountant($service->grant('test@example.com', 20) !== null, 'Revocation must not affect other doctors');
$expired = $service->invite(30, 'Medico C', 'expired@example.com', 2025);
$db->table('billing_accountant_delegations')->where('id', $expired['id'])->update(['expires_at'=>'2000-01-01 00:00:00']);
checkAccountant($service->accept($expired['token']) === null, 'Expired invitation must fail');
$revoked = $service->invite(40, 'Medico D', 'revoked@example.com', 2025);
$service->revoke(40, $revoked['id']);
checkAccountant($service->accept($revoked['token']) === null, 'Revoked invitation must fail');
$db->table('billing_accountant_logins')->insert(['email'=>'test@example.com','token_hash'=>hash('sha256', str_repeat('a',64)), 'expires_at'=>'2000-01-01 00:00:00']);
checkAccountant($service->consumeLogin(str_repeat('a',64)) === null, 'Expired login must fail');
$policy = \App\Services\AccountantAccessPolicy::class;
$filters = $policy::filters(['date_from'=>'2001-01-01','date_to'=>'2026-12-31','period'=>'all'], '2025-01-01');
checkAccountant($filters['date_from'] === '2025-01-01' && $filters['period'] === 'custom', 'Forged filters cannot widen authorized history');
$doc = ['_export_tenant_id'=>10,'local_state'=>'issued','issue_date'=>'2025-01-01'];
checkAccountant($policy::documentAllowed($doc,10,'2025-01-01'), 'Authorized invoice must pass');
checkAccountant(!$policy::documentAllowed($doc,20,'2025-01-01'), 'Cross-doctor direct PDF must fail');
checkAccountant(!$policy::documentAllowed($doc,10,'2026-01-01'), 'Direct PDF cannot bypass year restriction');
checkAccountant(!$policy::documentAllowed(array_replace($doc,['local_state'=>'draft']),10,'2025-01-01'), 'Draft PDF must fail');
checkAccountant(count($service->history(10)) === 3, 'Audit includes invitation, acceptance and revocation');
checkAccountant(count($service->history(99)) === 0, 'Audit must be doctor-scoped');

// Actual platform entitlement checks + tenant database resolution, with two separate SQLite stores.
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
(new ReflectionProperty(\CodeIgniter\Database\Config::class, 'instances'))->setValue(null, ['platform' => $db, 'tests' => $db]);
$db->query('CREATE TABLE platform_tenants (id_tenant INTEGER PRIMARY KEY, tenant_name TEXT, status TEXT, is_active INTEGER, id_package INTEGER)');
$db->query('CREATE TABLE platform_features (id_feature INTEGER PRIMARY KEY, feature_key TEXT, feature_name TEXT, description TEXT, default_enabled INTEGER, is_tenant_managed INTEGER, tenant_default_enabled INTEGER)');
$db->query('CREATE TABLE platform_tenant_features (id_tenant INTEGER, id_feature INTEGER, is_enabled INTEGER)');
$db->query('CREATE TABLE platform_package_features (id_package INTEGER, id_feature INTEGER, is_enabled INTEGER)');
$db->query('CREATE TABLE platform_tenant_feature_preferences (id_tenant_feature_preference INTEGER PRIMARY KEY AUTOINCREMENT, id_tenant INTEGER, id_feature INTEGER, is_enabled INTEGER, source TEXT, config_json TEXT, updated_by_platform_user INTEGER, created_at TEXT, updated_at TEXT)');
$db->table('platform_features')->insert(['id_feature'=>1, 'feature_key'=>\App\Config\BillingModule::FEATURE_KEY, 'default_enabled'=>1,'is_tenant_managed'=>1,'tenant_default_enabled'=>1]);
$fields = (new ReflectionClass(\App\Models\BillingDocumentModel::class))->getDefaultProperties()['allowedFields'];
foreach ([20,30] as $tenant) {
    $db->table('platform_tenants')->insert(['id_tenant'=>$tenant,'tenant_name'=>'Studio sintetico '.$tenant,'status'=>'active','is_active'=>1]);
    $tenantDb = \Config\Database::connect(['DBDriver'=>'SQLite3','database'=>':memory:','DBPrefix'=>'','DBDebug'=>true],false);
    $tenantDb->query('CREATE TABLE billing_documents (id_billing_document INTEGER PRIMARY KEY, '.implode(',',array_map(fn($f)=>$f.' TEXT',$fields)).')');
    \App\Services\TenantDatabaseConnector::$databases[$tenant] = $tenantDb;
}
$invoice = ['id_billing_document'=>1,'document_number'=>'TEST-001','document_type'=>'invoice','issue_date'=>'2025-05-10',
    'patient_name'=>'Cliente sintetico','patient_tax_code'=>'00123456789','payment_status'=>'paid','payment_method'=>'cash',
    'amount_total'=>102,'subtotal_amount'=>100,'stamp_duty_amount'=>2,'local_state'=>'issued','ts_sync_state'=>'not_requested',
    'vat_rate'=>0,'line_items_json'=>'[]','template_snapshot_json'=>'{"document_title":"Fattura sintetica","fiscal_data":{"business_name":"Studio di prova"}}'];
$tenantDb = \App\Services\TenantDatabaseConnector::$databases[20];
$tenantDb->table('billing_documents')->insert($invoice);
$tenantDb->table('billing_documents')->insert(array_replace($invoice,['id_billing_document'=>3,'local_state'=>'draft','document_number'=>'DRAFT']));
$tenantDb->table('billing_documents')->insert(array_replace($invoice,['id_billing_document'=>4,'issue_date'=>'2023-05-10','document_number'=>'OLD']));
\App\Services\TenantDatabaseConnector::$databases[30]->table('billing_documents')->insert(array_replace($invoice,['id_billing_document'=>2,'document_number'=>'OTHER-TENANT']));
$db->resetDataCache();
helper(['form','url']);
session()->set(['accountant_email'=>'test@example.com','accountant_until'=>time()+3600]);
$appConfig=config('App'); $appConfig->baseURL='http://localhost/subfolder/';
$request=new \CodeIgniter\HTTP\IncomingRequest($appConfig,new \CodeIgniter\HTTP\SiteURI($appConfig,'commercialista/medico/20'),'',new \CodeIgniter\HTTP\UserAgent());
$request->setGlobal('get',['date_from'=>'2025-01-01','date_to'=>'2025-12-31']);
$controller=new \App\Controllers\AccountantController();
$reset=function() use ($controller,$request,$appConfig) { $controller->initController($request,new \CodeIgniter\HTTP\Response($appConfig),service('logger')); };
$reset();
$authorized=new ReflectionMethod($controller,'authorized');
checkAccountant($authorized->invoke($controller,20)!==null,'Platform tenant delegation authorizes');
checkAccountant($authorized->invoke($controller,30)===null,'Other database must be denied');
$db->table('platform_tenants')->where('id_tenant',20)->update(['is_active'=>0]);
checkAccountant($authorized->invoke($controller,20)===null,'Inactive tenant denied');
$db->table('platform_tenants')->where('id_tenant',20)->update(['is_active'=>1]);
$db->table('platform_tenant_features')->insert(['id_tenant'=>20,'id_feature'=>1,'is_enabled'=>0]);
checkAccountant($authorized->invoke($controller,20)===null,'Disabled billing denied, including local environment');
$db->table('platform_tenant_features')->where('id_tenant',20)->update(['is_enabled'=>1]);
session()->set('accountant_until',time()-1);
checkAccountant($authorized->invoke($controller,20)===null,'Expired session denied');
session()->set('accountant_until',time()+3600);
$isolation=new \App\Filters\AccountantIsolationFilter();
foreach (['agenda','admin/fatturazione-documenti/save','login/spazio/fatturazione/save','api/doctors','auth/handoff','posta','cartella-clinica/1'] as $path) {
    $r=new \CodeIgniter\HTTP\IncomingRequest($appConfig,new \CodeIgniter\HTTP\SiteURI($appConfig,$path),'',new \CodeIgniter\HTTP\UserAgent());
    checkAccountant($isolation->before($r) instanceof \CodeIgniter\HTTP\RedirectResponse,'Isolation: '.$path);
}
checkAccountant($isolation->before($request)===null,'Portal reachable');
$page=$controller->documents(20);
checkAccountant(is_string($page) && str_contains($page,'TEST-001') && !str_contains($page,'DRAFT') && !str_contains($page,'OTHER-TENANT'),'Actual list scope');
foreach (['csv','pdf','zip','xlsx'] as $format) {
    $reset(); $response=$controller->export(20,$format);
    $prefix=['csv'=>"\xEF\xBB\xBF",'pdf'=>'%PDF-','zip'=>'PK','xlsx'=>'PK'][$format];
    checkAccountant(str_starts_with($response->getBody(),$prefix),'Actual export '.$format.' status '.$response->getStatusCode());
    if ($format === 'csv') {
        foreach (['DRAFT','OLD','OTHER-TENANT'] as $forbidden) checkAccountant(!str_contains($response->getBody(),$forbidden),'CSV boundary '.$forbidden);
    }
}
$reset(); checkAccountant(str_starts_with($controller->invoice(20,1)->getBody(),'%PDF-'),'Actual single invoice PDF');
foreach ([2,3,4] as $id) { $reset(); checkAccountant($controller->invoice(20,$id)->getStatusCode()===404,'Forbidden invoice '.$id); }
$reports=new \App\Services\BillingReportService();
foreach ([[],['0'],['1 OR 1=1'],[2],array_fill(0,501,1)] as $ids) {
    try { $reports->selectedDocumentsForTenant(20,$ids); throw new LogicException('Invalid selection accepted'); }
    catch (InvalidArgumentException|RuntimeException $e) {}
}
$request->setHeader('X-Requested-With','XMLHttpRequest');
foreach (['csv','xlsx','pdf'] as $format) {
    $request->setGlobal('post',['billing_document_ids'=>['1'],'export_format'=>$format]); $reset();
    checkAccountant($controller->exportSelected(20)->getStatusCode()===200,'Delegated selection '.$format);
}
foreach ([['3'],['4'],['2']] as $ids) {
    $request->setGlobal('post',['billing_document_ids'=>$ids,'export_format'=>'csv']); $reset();
    checkAccountant($controller->exportSelected(20)->getStatusCode()===422,'Selection cannot expose draft/old/other tenant');
}
// Settings are independent by tenant and survive saves from other settings panels.
$feature=new class extends \App\Services\BillingFeatureService { public function __construct() {} public function isEnabledForTenant(int $tenantId): bool {return true;} };
$settings=new \App\Services\BillingDocumentSettingsService($feature);
$settings->saveTenantSettings(20,['xlsx_columns'=>['document_number','stamp_duty_amount']],1);
$settings->saveTenantSettings(30,['xlsx_columns'=>['patient_name']],1);
$settings->saveTenantSettings(20,['document_title'=>'Nuovo titolo'],1);
checkAccountant($settings->resolveXlsxColumns(20)===['document_number','stamp_duty_amount'],'Other panels preserve export preferences');
checkAccountant($settings->resolveXlsxColumns(30)===['patient_name'],'Separate tenant preferences');
$reset(); $xlsx=$controller->export(20,'xlsx')->getBody();
$path=tempnam(sys_get_temp_dir(),'synthetic-xlsx-'); file_put_contents($path,$xlsx);
$zip=new ZipArchive(); checkAccountant($zip->open($path)===true,'Configured delegated XLSX is readable');
$xml=$zip->getFromName('xl/worksheets/sheet1.xml'); $zip->close(); unlink($path);
checkAccountant(str_contains($xml,'Marca da bollo') && !str_contains($xml,'Destinatario'),'Delegated XLSX uses owner columns and order');
foreach (['DRAFT','OLD','OTHER-TENANT'] as $forbidden) checkAccountant(!str_contains($xml,$forbidden),'XLSX boundary '.$forbidden);
$tenantDb->table('billing_documents')->insert(array_replace($invoice,['id_billing_document'=>5,'document_number'=>'../../SYNTHETIC-5']));
$file=(new \App\Services\BillingDocumentExportService())->build(20,(new \App\Services\BillingReportService())->selectedDocumentsForTenant(20,[1,5]));
checkAccountant($file['type']==='application/zip','Multiple selected PDFs become ZIP');
$path=tempnam(sys_get_temp_dir(),'synthetic-zip-');file_put_contents($path,$file['body']);
checkAccountant($zip->open($path)===true && $zip->numFiles===2,'ZIP contains exactly two documents');
for($i=0;$i<2;$i++) checkAccountant(str_starts_with($zip->getFromIndex($i),'%PDF-') && !str_contains($zip->getNameIndex($i),'/'),'PDF contents and safe archive names');
$zip->close();unlink($path);
foreach ([[],array_fill(0,51,$invoice)] as $invalid) {
    try {(new \App\Services\BillingDocumentExportService())->build(20,$invalid); throw new LogicException('PDF limit ignored');}
    catch(InvalidArgumentException $e) {}
}
$config=new \Config\Filters();
checkAccountant(array_key_first($config->globals['before'])===0 && $config->globals['before'][0]==='accountantisolation','Isolation runs before runtime database/auth filters');
checkAccountant(in_array('commercialista/*',$config->filters['billingcsrf']['before'],true),'All portal mutations protected by CSRF');
checkAccountant(in_array('admin/fatturazione-commercialista/*',$config->filters['billingcsrf']['before'],true),'Invite/revoke protected by CSRF');
$settingsController=(new ReflectionClass(\App\Controllers\Tenant\BillingSettingsController::class))->newInstanceWithoutConstructor();
$settingsController->initController($request,new \CodeIgniter\HTTP\Response($appConfig),service('logger'));
$contextService=new class extends \App\Services\TenantContextService {
    public function __construct() {}
    public function getCurrentTenant(): ?\App\Libraries\TenantContext { return new \App\Libraries\TenantContext(tenantId:20,tenantRole:'tenant_admin'); }
};
(new ReflectionProperty($settingsController,'tenantContext'))->setValue($settingsController,$contextService);
$request->setGlobal('post',['xlsx_columns_present'=>'1','xlsx_columns'=>['patient_name']]);
try {(new ReflectionMethod($settingsController,'requestConfigPayload'))->invoke($settingsController,[]);throw new LogicException('Non-owner changed XLSX fields');}
catch(RuntimeException $e) {checkAccountant(str_contains($e->getMessage(),'Solo il titolare'),'Non-owner fields rejected');}
$grant=$service->grant('test@example.com',20);
$service->revoke(20,$b['id']);
checkAccountant(!(new ReflectionMethod($controller,'grantUnchanged'))->invoke($controller,$grant),'Final delivery check detects revocation');
foreach (['csv','xlsx','pdf','zip'] as $format) { $reset(); checkAccountant($controller->export(20,$format)->getStatusCode()===403,'Revoked export '.$format); }
$migration->down(); checkAccountant(!$service->ready(),'Migration rollback removes only delegation tables');
session()->destroy();
echo "PASS: SQLite migration up/down, single-use/expired tokens, multi-tenant delegation, entitlements, sessions, route isolation, actual list/CSV/XLSX/PDF/ZIP, selections, preference preservation and final revocation checks.\n";

