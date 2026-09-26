<?php
namespace Tests\Unit;
use App\Services\{BillingDocumentDesigner,BillingDocumentService,TsDocumentService,TsProfileService,TsTenantDatabaseContextService,TsSecretsService,TsAuditService,TsDispatchService};
use App\Models\{TsDocumentModel,TsDocumentEventModel};
use CodeIgniter\Test\CIUnitTestCase;
final class BillingPortRegressionTest extends CIUnitTestCase
{
 public function testAgendaPreservesTheConfiguredSRDefault(): void {
  $agenda=(new \ReflectionClass(\App\Controllers\Agenda::class))->newInstanceWithoutConstructor();
  $prefill=(new \ReflectionMethod($agenda,'buildBillingAppointmentDocumentPrefill'))->invoke($agenda,[],true,'/agenda');
  $this->assertArrayNotHasKey('ts_expense_type_code',$prefill['document']);
  $controller=(new \ReflectionClass(\App\Controllers\Admin\BillingDocumentsController::class))->newInstanceWithoutConstructor();
  $form=(new \ReflectionMethod($controller,'applyAppointmentPrefill'))->invoke($controller,['document'=>['ts_expense_type_code'=>'SR','stamp_duty_amount'=>2]],$prefill);
  $this->assertSame('SR',$form['document']['ts_expense_type_code']);
  $this->assertSame(2,$form['document']['stamp_duty_amount']);
 }
 public function testRefreshSavesOldDraftAndRefusesHistoricalOrConcurrentChanges(): void {
  $previous=getenv('TS_BILLING_SECRET_KEY');putenv('TS_BILLING_SECRET_KEY=synthetic-billing-port');
  $db=\Config\Database::connect(['DBDriver'=>'SQLite3','database'=>':memory:','DBPrefix'=>'','DBDebug'=>true],false);
  try {
   $fields=(new \ReflectionClass(TsDocumentModel::class))->getDefaultProperties()['allowedFields'];
   $db->query('CREATE TABLE ts_documents (id_ts_document INTEGER PRIMARY KEY AUTOINCREMENT,'.implode(',',array_map(static fn($f)=>$f.' TEXT',$fields)).')');
   $db->query('CREATE TABLE ts_document_events (id_ts_event INTEGER PRIMARY KEY AUTOINCREMENT,id_ts_document INTEGER,event_type TEXT,event_level TEXT,message TEXT,context_json TEXT,created_by INTEGER,created_at TEXT)');
   $documents=new TsDocumentModel($db);$events=new TsDocumentEventModel($db);$secrets=new TsSecretsService();
   $id=(int)$documents->insert(['source_type'=>'billing','local_state'=>'to_validate','id_ts_profile'=>1,'document_number'=>'SYNTHETIC-PORT','document_type'=>'F','issue_date'=>'2026-09-25','payment_date'=>'2026-09-25','expense_type_code'=>'SR','payment_mode'=>'tracciato','amount_total'=>100,'vat_rate'=>'0.00','vat_nature'=>'ART. 10','patient_cf_enc'=>$secrets->encrypt('RSSMRA80A01H501U'),'document_device'=>0]);
   $context=$this->getMockBuilder(TsTenantDatabaseContextService::class)->disableOriginalConstructor()->onlyMethods(['resolveTenantContext'])->getMock();
   $context->method('resolveTenantContext')->willReturn(['db'=>$db,'documents'=>$documents,'events'=>$events,'audit'=>new TsAuditService($events)]);
   $profiles=$this->getMockBuilder(TsProfileService::class)->disableOriginalConstructor()->onlyMethods(['findProfileById'])->getMock();
   $profiles->method('findProfileById')->willReturn(['id_ts_profile'=>1,'owner_piva'=>'12345678903','metadata_json'=>'{"document_defaults":{"vat_nature_code":"N4"}}']);
   $service=new TsDocumentService(profiles:$profiles,tenantDbContext:$context);
   $service->saveDraftForTenant(42,['id_ts_document'=>$id],7,'validate');
   $saved=$documents->find($id);$this->assertNull($saved['vat_rate']);$this->assertSame('N4',$saved['vat_nature']);
   foreach ([['local_state'=>'sending'],['local_state'=>'sent'],['ts_protocol'=>'SYNTHETIC-PROTOCOL'],['ts_sent_at'=>'2026-09-25 12:00:00'],['ts_state'=>'accepted']] as $lock) {
    $documents->update($id,array_merge(['local_state'=>'ready','ts_protocol'=>null,'ts_sent_at'=>null,'ts_state'=>null],$lock));
    $current=$documents->find($id);$this->assertFalse($documents->updateEditableSnapshot($id,$current,['vat_nature'=>'N1']));
    $this->assertSame('N4',$documents->find($id)['vat_nature']);
   }
   $documents->update($id,['local_state'=>'ready','ts_state'=>null]);$stale=$documents->find($id);
   $documents->update($id,['notes'=>'Changed by another operator']);
   $this->assertFalse($documents->updateEditableSnapshot($id,$stale,['vat_nature'=>'N1']));
  } finally {$db->close();putenv($previous===false?'TS_BILLING_SECRET_KEY':'TS_BILLING_SECRET_KEY='.$previous);}
 }
 public function testS041RemainsAnExternalRejectionWithItsMessage(): void {
  $service=(new \ReflectionClass(TsDispatchService::class))->newInstanceWithoutConstructor();
  (new \ReflectionProperty($service,'config'))->setValue($service,config(\App\Config\TsBilling::class));
  $response=['esitoChiamata'=>'1','listaMessaggi'=>['messaggio'=>[['codice'=>'S041','descrizione'=>'Codice fiscale cittadino coincidente con proprietario','tipo'=>'E']]]];
  $message=(new \ReflectionMethod($service,'extractResponseMessage'))->invoke($service,$response);
  $this->assertStringContainsString('S041',$message);$this->assertStringContainsString('coincidente',$message);
  $this->assertFalse((new \ReflectionMethod($service,'evaluateSoapSuccess'))->invoke($service,$response));
 }
 public function testLocalTsContractsAndCertificateLoadWithoutNetwork(): void {
  foreach (glob(APPPATH.'ThirdParty/TesseraSanitaria/wsdl/*.wsdl') as $path) {
   $xml=new \DOMDocument();$this->assertTrue($xml->load($path,LIBXML_NONET));
   foreach($xml->getElementsByTagNameNS('http://www.w3.org/2001/XMLSchema','import') as $import) {
    $location=$import->getAttribute('schemaLocation');if($location!=='')$this->assertFileExists(dirname($path).'/'.$location);
   }
   $soap=new \SoapClient($path,['cache_wsdl'=>WSDL_CACHE_NONE]);$this->assertNotEmpty($soap->__getFunctions());
  }
  $cert=file_get_contents(APPPATH.'ThirdParty/TesseraSanitaria/certs/SanitelCF.cer');
  if(!str_contains($cert,'BEGIN CERTIFICATE'))$cert="-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode($cert),64,"\n")."-----END CERTIFICATE-----\n";
  $this->assertNotFalse(openssl_x509_read($cert));
 }
}
