<?php
namespace Tests\Unit;

use App\Services\{UnifiedBillingArchive,BillingCapabilities,PolyclinicAdministrationService};
use CodeIgniter\Test\CIUnitTestCase;

final class UnifiedBillingTest extends CIUnitTestCase
{
    protected $db;
    protected function setUp(): void
    {
        parent::setUp();
        $this->db=\Config\Database::connect(['DBDriver'=>'SQLite3','database'=>':memory:','DBPrefix'=>'','DBDebug'=>true],false);
        require_once APPPATH.'Database/Migrations/2026-07-06-000003_CreateBillingDocumentsTable.php';
        require_once APPPATH.'Database/Polyclinic/CreatePolyclinicAdministration.php';
        (new \App\Database\Migrations\CreateBillingDocumentsTable(\Config\Database::forge($this->db)))->up();
        foreach (['due_date','payment_status','paid_at','patient_email','invoice_email_sent_at','last_reminder_sent_at','email_last_recipient','email_last_error','reminder_count'] as $column) $this->db->query('ALTER TABLE billing_documents ADD '.$column.' TEXT');
        (new \App\Database\Polyclinic\CreatePolyclinicAdministration(\Config\Database::forge($this->db)))->up();
        $this->db->table('billing_documents')->insert(['document_number'=>'OLD-1','issue_date'=>'2026-09-21','patient_name'=>'Originale','amount_total'=>'50','local_state'=>'issued']);
        foreach ([1,2] as $id) {
            $this->db->table('pc_documents')->insert(['id_billing_document'=>$id,'document_number'=>'PC-'.$id,'issue_date'=>'2026-09-21','patient_name'=>'Sintetico','amount_total'=>$id===1?'100':'20','subtotal_amount'=>$id===1?'100':'20','local_state'=>'issued','document_type'=>$id===1?'invoice':'credit_note','ts_sync_enabled'=>0]);
            $this->db->table('pc_document_state')->insert(['billing_id'=>$id,'original_id'=>$id===2?1:null,'request_key'=>'migration-test-key-'.$id,'request_hash'=>'test','created_at'=>'2026-09-21 10:00:00']);
        }
        $this->db->table('pc_payments')->insert(['billing_id'=>1,'amount_cents'=>4000,'method'=>'pos','payer'=>'patient','payment_date'=>'2026-09-21','request_key'=>'migration-payment','request_hash'=>'test']);
        $this->db->table('pc_installments')->insert(['billing_id'=>1,'due_date'=>'2026-10-21','amount_cents'=>6000]);
        $this->db->table('pc_credit_allocations')->insert(['credit_id'=>2,'order_id'=>1,'amount_cents'=>2000]);
        $this->db->table('pc_orders')->insert(['id'=>1,'billing_id'=>1,'total_cents'=>10000,'payer_cents'=>0,'request_key'=>'migration-order','request_hash'=>'test']);
        $this->db->table('pc_settlements')->insert(['billing_id'=>1,'doctor_id'=>1,'amount_cents'=>1000,'payment_date'=>'2026-09-21','request_key'=>'migration-settlement','request_hash'=>'test']);
    }
    protected function tearDown(): void { $this->db->close(); parent::tearDown(); }

    public function testWorkspaceUsesRealBalancesWithoutCountingCreditsAsCash(): void
    {
        (new UnifiedBillingArchive())->migrate($this->db,true);
        $this->db->table('pc_orders')->where('id',1)->update(['doctor_id'=>7,'snapshot_json'=>json_encode(['doctor'=>'Dottoressa Test','agreement'=>'Fondo Test'])]);
        $before=$this->db->table('billing_documents')->orderBy('id_billing_document')->get()->getResultArray();
        $rows=(new \App\Services\BillingWorkspacePresenter())->enrich($this->db,$before,['billing_agreements'=>true]);
        $this->assertSame(13000,array_sum(array_column($rows,'revenue_cents')));
        $this->assertSame(4000,array_sum(array_column($rows,'cash_cents')));
        $this->assertSame(9000,array_sum(array_column($rows,'outstanding_cents')));
        $this->assertSame('Rettificata',$rows[1]['status_label']);
        $this->assertSame('Nota di credito',$rows[2]['status_label']);
        $this->assertSame([7=>'Dottoressa Test'],$rows[1]['doctors']);
        $this->assertSame(['Fondo Test'],$rows[1]['agreements']);
        $this->assertSame(0,$rows[1]['fee_due_cents']);
        $this->assertSame($before,$this->db->table('billing_documents')->orderBy('id_billing_document')->get()->getResultArray());
    }

    public function testMigrationPreservesOriginalsAndAllReferencesAndIsRepeatable(): void
    {
        $original=$this->db->table('billing_documents')->get()->getResultArray();
        $archive=$this->db->table('pc_documents')->get()->getResultArray();
        $service=new UnifiedBillingArchive();
        $this->assertSame(2,$service->migrate($this->db)['documents']);
        $this->assertSame($original,$this->db->table('billing_documents')->get()->getResultArray());
        $result=$service->migrate($this->db,true); $map=$result['mapping'];
        $this->assertSame([1=>3,2=>4],$map);
        $this->assertSame('billing_documents',UnifiedBillingArchive::table($this->db));
        $this->assertSame($original[0],$this->db->table('billing_documents')->where('id_billing_document',1)->get()->getRowArray());
        $this->assertSame($archive,$this->db->table('pc_documents')->get()->getResultArray());
        $this->assertSame(3,(int)$this->db->table('pc_payments')->get()->getRowArray()['billing_id']);
        $this->assertSame(3,(int)$this->db->table('pc_installments')->get()->getRowArray()['billing_id']);
        $this->assertSame(3,(int)$this->db->table('pc_orders')->get()->getRowArray()['billing_id']);
        $this->assertSame(3,(int)$this->db->table('pc_settlements')->get()->getRowArray()['billing_id']);
        $this->assertSame(4,(int)$this->db->table('pc_credit_allocations')->get()->getRowArray()['credit_id']);
        $this->assertSame(3,(int)$this->db->table('pc_document_state')->where('billing_id',4)->get()->getRowArray()['original_id']);
        $this->assertTrue($service->migrate($this->db,true)['already_unified']);
        $this->assertSame(3,$this->db->table('billing_documents')->countAllResults());
        $this->assertTrue(UnifiedBillingArchive::manages($this->db,3));
        $this->assertFalse(UnifiedBillingArchive::manages($this->db,1));
        $clinic=new PolyclinicAdministrationService($this->db);
        $this->assertSame(4000,$clinic->balance(3)['due_cents']);
        $this->assertNotSame('',$clinic->tsBlockingReason(3));
        $this->assertSame(0,(int)$clinic->document(3)['ts_sync_enabled']);
        $this->expectException(\DomainException::class);
        UnifiedBillingArchive::assertOrdinaryMutation($this->db,3);
    }

    public function testCollisionFailsWithoutChangingEitherArchive(): void
    {
        $this->db->table('billing_documents')->where('id_billing_document',1)->update(['document_number'=>'PC-1']);
        try { (new UnifiedBillingArchive())->migrate($this->db,true); $this->fail('Expected collision'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('duplicati',$e->getMessage()); }
        $this->assertSame(1,$this->db->table('billing_documents')->countAllResults());
        $this->assertSame(1,(int)$this->db->table('pc_payments')->get()->getRowArray()['billing_id']);
        $this->assertSame([],UnifiedBillingArchive::state($this->db));
    }

    public function testRequestStartedBeforeMigrationCannotWriteUsingAnOldIdentifier(): void
    {
        $oldRequest=new PolyclinicAdministrationService($this->db);
        (new UnifiedBillingArchive())->migrate($this->db,true);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Archivio aggiornato');
        $oldRequest->payment(['billing_id'=>1,'amount'=>'10','method'=>'pos','payment_date'=>'2026-09-27','request_key'=>'stale-operation-key-123']);
    }

    public function testCapabilitiesAreIndependentAndDoNotDependOnPolyclinicFlag(): void
    {
        foreach (array_keys(BillingCapabilities::FEATURES) as $key) {
            $caps=BillingCapabilities::fromMap(['billing'=>true,$key=>true,'polyclinic_billing'=>false]);
            $this->assertTrue($caps[$key]); $this->assertCount(1,array_filter($caps));
            BillingCapabilities::assertCommand($caps,['action'=>'arrival']);
        }
        $this->assertCount(0,array_filter(BillingCapabilities::fromMap(['billing'=>false,'billing_services'=>true])));
        $this->expectException(\DomainException::class);
        BillingCapabilities::assertCommand(['billing_services'=>true,'billing_agreements'=>false],['action'=>'order','agreement_id'=>1]);
    }

    public function testNewInvoiceUsesCommonArchiveWithoutCompensationAndAppearsInTsQueue(): void
    {
        (new UnifiedBillingArchive())->migrate($this->db,true);
        $clinic=new PolyclinicAdministrationService($this->db,1,fn($id)=>['id_client'=>$id,'patient_first_name'=>'Mario','patient_last_name'=>'Sintetico','patient_tax_code'=>'VRDLGU70A01H501O'],false);
        $branch=$clinic->saveCatalog(['kind'=>'branch','code'=>'BR','name'=>'Branca','active'=>1]);
        $doctor=$clinic->saveCatalog(['kind'=>'doctor','code'=>'DR','name'=>'Medico','active'=>1]);
        $service=$clinic->saveCatalog(['kind'=>'service','code'=>'SR','name'=>'Prestazione','branch_id'=>$branch,'price'=>'100','active'=>1]);
        $key=fn()=>bin2hex(random_bytes(16));
        $encounter=$clinic->arrive(['patient_id'=>123,'doctor_id'=>$doctor,'visit_date'=>'2026-09-27','request_key'=>$key()]);
        $clinic->addOrder(['encounter_id'=>$encounter,'service_id'=>$service,'quantity'=>1,'request_key'=>$key()]);
        foreach (['waiting','in_care','completed'] as $version=>$state) $clinic->transition(['id'=>$encounter,'state'=>$state,'version'=>$version]);
        $id=$clinic->issueInvoice(['encounter_id'=>$encounter,'issue_date'=>'2026-09-27','vat_nature'=>'N4','request_key'=>$key()],[]);
        $this->assertGreaterThan(4,$id); $this->assertSame(2,$this->db->table('pc_documents')->countAllResults());
        $clinic->payment(['billing_id'=>$id,'amount'=>'100','payment_date'=>'2026-09-27','method'=>'pos','request_key'=>$key()]);
        $this->assertSame('',$clinic->tsBlockingReason($id));
        $this->assertSame(0,$clinic->compensation($id)[0]['earned_cents']);
        $version=$clinic->detail($id)['state']['version'];
        $clinic->configureTs(['billing_id'=>$id,'version'=>$version,'ts_sync_enabled'=>1,'ts_expense_type_code'=>'SP','ts_opposition_flag'=>1]);
        $fields=(new \ReflectionClass(\App\Models\TsDocumentModel::class))->getDefaultProperties()['allowedFields'];
        $fields=array_diff(array_unique(array_merge($fields,['created_at','updated_at'])),['id_ts_document']);
        $this->db->query('CREATE TABLE ts_documents (id_ts_document INTEGER PRIMARY KEY AUTOINCREMENT,'.implode(',',array_map(fn($f)=>$f.' TEXT',$fields)).')');
        $context=$this->createMock(\App\Services\BillingTenantDatabaseContextService::class);
        $context->method('resolveTenantContext')->willReturn(['db'=>$this->db]);
        $bridge=new \App\Services\BillingTsBridgeService(billingContext:$context);
        $queue=$bridge->buildQueueForTenant(44);
        $this->assertSame([$id],array_column($queue['pending_documents'],'id_billing_document'));
        $this->assertTrue($queue['pending_documents'][0]['ts_opposition_flag']);
        $this->assertSame('SP',$queue['pending_documents'][0]['ts_expense_type_code']);
        $summary=UnifiedBillingArchive::collectionSummary($this->db,[]);
        $this->assertSame(90.0,(float)$summary['outstanding_amount']); // ordinary 50 + managed 40
        $export=new \App\Services\PolyclinicAccountingExport();
        $journal=$export->journal($this->db,'2026-09-01','2026-09-30',['customer_account'=>'C','revenue_account'=>'R','vat_account'=>'V','stamp_account'=>'S','cash_account'=>'CA','bank_account'=>'B']);
        $this->assertSame(0,array_sum(array_map(fn($r)=>\App\Services\PolyclinicMoney::cents($r['debit'])-\App\Services\PolyclinicMoney::cents($r['credit']),$journal)));
    }
}
