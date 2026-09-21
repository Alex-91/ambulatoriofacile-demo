<?php
namespace Tests\Unit;

use App\Services\{PolyclinicAdministrationService as Clinic,PolyclinicMoney as Money,PolyclinicAccountingExport,PolyclinicElectronicInvoice};
use CodeIgniter\Test\CIUnitTestCase;

final class PolyclinicAdministrationTest extends CIUnitTestCase
{
    protected $db;
    private Clinic $clinic;
    private array $ids=[];
    protected function setUp(): void
    {
        parent::setUp();
        $this->db=$this->isolatedDatabase();
        require_once APPPATH.'Database/Migrations/2026-07-06-000003_CreateBillingDocumentsTable.php';
        require_once APPPATH.'Database/Polyclinic/CreatePolyclinicAdministration.php';
        (new \App\Database\Migrations\CreateBillingDocumentsTable(\Config\Database::forge($this->db)))->up();
        foreach (['due_date','payment_status','paid_at','patient_email','invoice_email_sent_at','last_reminder_sent_at','email_last_recipient','email_last_error','reminder_count'] as $column) $this->db->query('ALTER TABLE billing_documents ADD '.$column.' TEXT');
        (new \App\Database\Polyclinic\CreatePolyclinicAdministration(\Config\Database::forge($this->db)))->up();
        $this->db->query('CREATE TABLE dap12_agenda_appuntamenti (id_appuntamento INTEGER PRIMARY KEY,id_client INTEGER,id_dot INTEGER,stato TEXT)');
        $this->clinic=new Clinic($this->db,7,static fn($id)=>$id===100?['id_client'=>100,'patient_last_name'=>'Paziente','patient_first_name'=>'Sintetico','patient_tax_code'=>'VRDLGI70A01H501X']:[]);
        $this->ids['branch']=$this->catalog('branch','Cardiologia');
        $this->ids['doctor']=$this->catalog('doctor','Dottore sintetico',['agenda_id'=>11]);
        $this->ids['service']=$this->catalog('service','Visita',['branch_id'=>$this->ids['branch'],'price'=>'100.00']);
        $this->ids['rule']=$this->catalog('rule','Compenso',['doctor_id'=>$this->ids['doctor'],'service_id'=>$this->ids['service'],'mode'=>'percent','value'=>'50','basis'=>'collected']);
    }
    protected function tearDown(): void { $this->db->close(); parent::tearDown(); }
    private function isolatedDatabase()
    {
        $lab=getenv('POLYCLINIC_TEST_LAB');
        if (!$lab) return \Config\Database::connect(['DBDriver'=>'SQLite3','database'=>':memory:','DBPrefix'=>'','DBDebug'=>true],false);
        $root=realpath(WRITEPATH.'polyclinic-labs'); $path=realpath($lab);
        if (!$root || !$path || dirname($path)!==$root || !preg_match('/^[a-f0-9]{32}$/D',basename($path))) throw new \RuntimeException('Synthetic lab required.');
        $manifest=json_decode(file_get_contents($path.'/lab.json'),true,512,JSON_THROW_ON_ERROR);
        if (($manifest['mode']??'')!=='POLYCLINIC_SYNTHETIC') throw new \RuntimeException('Synthetic marker required.');
        $config=['DBDriver'=>'MySQLi','hostname'=>'127.0.0.1','port'=>(int)$manifest['port'],'username'=>'root','password'=>'','database'=>'mysql','DBPrefix'=>'','DBDebug'=>true,'charset'=>'utf8mb4','DBCollat'=>'utf8mb4_unicode_ci'];
        $admin=\Config\Database::connect($config,false);
        $actual=$admin->query('SELECT @@datadir AS path')->getRowArray()['path'];
        if (realpath($actual)!==realpath($path.'/mysql')) throw new \RuntimeException('Refusing non-synthetic database server.');
        $name='pc_unit_'.bin2hex(random_bytes(8));
        $admin->query('CREATE DATABASE '.$name.' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'); $admin->close();
        $config['database']=$name;
        return \Config\Database::connect($config,false);
    }
    private function key(): string { return bin2hex(random_bytes(16)); }
    private function catalog(string $kind,string $name,array $extra=[]): int { return $this->clinic->saveCatalog($extra+['kind'=>$kind,'code'=>$kind.'-'.substr($this->key(),0,8),'name'=>$name,'active'=>1]); }
    private function invoice(array $extra=[],array $orderExtra=[]): int
    {
        $e=$this->clinic->arrive(['patient_id'=>100,'doctor_id'=>$this->ids['doctor'],'visit_date'=>'2026-09-21','request_key'=>$this->key()]);
        $this->clinic->addOrder($orderExtra+['encounter_id'=>$e,'doctor_id'=>$this->ids['doctor'],'service_id'=>$this->ids['service'],'quantity'=>1,'request_key'=>$this->key()]);
        foreach (['waiting','in_care','completed'] as $v=>$state) $this->clinic->transition(['id'=>$e,'state'=>$state,'version'=>$v]);
        return $this->clinic->issueInvoice($extra+['encounter_id'=>$e,'issue_date'=>'2026-09-21','due_date'=>'2026-09-30','vat_nature'=>'N4','stamp'=>'2.00','request_key'=>$this->key()],[]);
    }
    private function pay(int $id,string $amount,array $extra=[]): int { return $this->clinic->payment($extra+['billing_id'=>$id,'amount'=>$amount,'payment_date'=>'2026-09-21','method'=>'bank_transfer','payer'=>'patient','reference'=>'Prova','request_key'=>$this->key()]); }
    private function rejects(callable $fn): void
    {
        try { $fn(); $this->fail('Operazione doveva essere respinta.'); } catch (\DomainException|\InvalidArgumentException|\RuntimeException $e) { $this->assertNotSame('',$e->getMessage()); }
    }

    public function testMoneyUsesExactCentsAndRejectsAmbiguousInput(): void
    {
        $this->assertSame(10001,Money::cents('100,01')); $this->assertSame('-0.01',Money::decimal(-1));
        foreach (['1.234','1,000.20','abc','1e3'] as $v) $this->rejects(fn()=>Money::cents($v));
    }
    public function testLegacyDashboardScheduleAndReportsRemainUnchangedWithBothModules(): void
    {
        $this->db->table('billing_documents')->insert(['document_number'=>'LEGACY-1','issue_date'=>'2026-09-21','due_date'=>'2026-09-30','patient_name'=>'Cliente fatturazione classica','amount_total'=>'70.00','subtotal_amount'=>'70.00','local_state'=>'issued','payment_status'=>'unpaid']);
        $legacy=$this->db->table('billing_documents')->get()->getResultArray();
        $context=$this->createMock(\App\Services\BillingTenantDatabaseContextService::class);
        $context->method('resolveTenantContext')->willReturn(['db'=>$this->db]);
        $schema=$this->createMock(\App\Services\BillingTenantSchemaService::class);
        $schema->method('ensureTenantSchemaReady')->willReturn(['ready'=>true]);
        $billing=new \App\Services\BillingDocumentService(tenantDbContext:$context,schema:$schema);
        $reports=new \App\Services\BillingReportService($context,$schema,$billing);
        $before=[$billing->buildDashboardForTenant(1),$billing->buildPaymentScheduleForTenant(1),$reports->buildReportForTenant(1)];
        $id=$this->invoice(); $this->assertSame(1,$id); // Same numeric ID in distinct archives.
        $this->pay($id,'51');
        $this->clinic->credit(['billing_id'=>$id,'amount'=>'10','issue_date'=>'2026-09-22','reason'=>'Prova isolamento','request_key'=>$this->key()]);
        $this->assertSame($legacy,$this->db->table('billing_documents')->get()->getResultArray());
        $this->assertSame($before,[$billing->buildDashboardForTenant(1),$billing->buildPaymentScheduleForTenant(1),$reports->buildReportForTenant(1)]);
        $config=['customer_account'=>'C','revenue_account'=>'R','vat_account'=>'I','stamp_account'=>'S','cash_account'=>'CA','bank_account'=>'B'];
        $journal=(new PolyclinicAccountingExport())->journal($this->db,'2026-09-01','2026-09-30',$config);
        $this->assertNotContains('LEGACY-1',array_column($journal,'document'));
        $this->assertNotEmpty($journal);
    }

    public function testPolyclinicDoesNotRequireLegacyBillingSchema(): void
    {
        \Config\Database::forge($this->db)->dropTable('billing_documents');
        $this->assertTrue($this->clinic->ready());
        $id=$this->invoice(); $this->pay($id,'102');
        $this->assertSame(0,$this->clinic->balance($id)['due_cents']);
        $this->assertFalse($this->db->tableExists('billing_documents'));
    }

    public function testDedicatedPdfRendersTaxAndCreditWithoutLegacyDocumentLookup(): void
    {
        helper('url');
        $id=$this->invoice(['vat_rate'=>'22','vat_nature'=>'','stamp'=>'0']);
        $credit=$this->clinic->credit(['billing_id'=>$id,'amount'=>'61','issue_date'=>'2026-09-22','reason'=>'Rettifica test','request_key'=>$this->key()]);
        $document=$this->clinic->document($credit);
        $html=view('admin/polyclinic/document_pdf',['preview'=>['document'=>$document,'document_type_label'=>'Nota di credito','line_items'=>json_decode($document['line_items_json'],true),'template'=>[]]]);
        $this->assertStringContainsString('Nota di credito',$html);
        $this->assertStringContainsString('IVA',$html);
        $this->assertStringContainsString('11,00',$html);
        require_once dirname(APPPATH,2).'/vendor/autoload.php';
        $options=new \Dompdf\Options(); $options->setIsRemoteEnabled(false);
        $pdf=new \Dompdf\Dompdf($options); $pdf->loadHtml($html); $pdf->render();
        $this->assertStringStartsWith('%PDF-',$pdf->output());
    }
    public function testJourneyPartialPaymentsCreditRefundAndCompensationRecovery(): void
    {
        $id=$this->invoice(); $this->assertSame(10200,$this->clinic->balance($id)['due_cents']);
        $key=$this->key(); $p=$this->pay($id,'51.00',['request_key'=>$key]);
        $this->assertSame($p,$this->pay($id,'51.00',['request_key'=>$key]));
        $this->assertSame('partial',$this->clinic->document($id)['payment_status']);
        $this->assertSame(2500,$this->clinic->compensation($id)[0]['earned_cents']);
        $this->rejects(fn()=>$this->pay($id,'51.01'));
        $this->pay($id,'51.00');
        $this->clinic->settle(['billing_id'=>$id,'doctor_id'=>$this->ids['doctor'],'amount'=>'50','payment_date'=>'2026-09-21','reference'=>'Bonifico medico','request_key'=>$this->key()]);
        $credit=$this->clinic->credit(['billing_id'=>$id,'amount'=>'51','issue_date'=>'2026-09-22','reason'=>'Storno metà','request_key'=>$this->key()]);
        $this->assertSame('credit_note',$this->clinic->document($credit)['document_type']);
        $this->assertSame(5100,$this->clinic->balance($id)['refund_due_cents']);
        $this->assertSame(-2500,$this->clinic->compensation($id)[0]['due_cents']);
        $this->pay($id,'-51',['payment_date'=>'2026-09-22']);
        $this->assertSame(0,$this->clinic->balance($id)['refund_due_cents']);
        $this->clinic->settle(['billing_id'=>$id,'doctor_id'=>$this->ids['doctor'],'amount'=>'-25','payment_date'=>'2026-09-22','reference'=>'Recupero','request_key'=>$this->key()]);
        $this->assertSame(0,$this->clinic->compensation($id)[0]['due_cents']);
        $this->rejects(fn()=>$this->clinic->credit(['billing_id'=>$id,'amount'=>'51.01','issue_date'=>'2026-09-22','reason'=>'Troppo','request_key'=>$this->key()]));
        $this->assertSame(0,$this->db->table('billing_documents')->countAllResults());
    }
    public function testAgreementTariffAndPayerQuotasAreEnforced(): void
    {
        $list=$this->catalog('list','Assicurazione'); $this->clinic->saveTariff(['list_id'=>$list,'service_id'=>$this->ids['service'],'price'=>'80']);
        $agreement=$this->catalog('agreement','Fondo',['list_id'=>$list,'coverage'=>'75','agreement_kind'=>'insurance','payer'=>'Fondo sintetico','authorization_required'=>1]);
        $id=$this->invoice([],['agreement_id'=>$agreement,'authorization'=>'AUT-TEST']);
        $b=$this->clinic->balance($id); $this->assertSame(8200,$b['total_cents']); $this->assertSame(6000,$b['organization_net_cents']); $this->assertSame(2200,$b['patient_net_cents']);
        $this->rejects(fn()=>$this->pay($id,'22.01'));
        $this->pay($id,'22'); $this->pay($id,'60',['payer'=>'organization']); $this->assertSame(0,$this->clinic->balance($id)['due_cents']);
    }
    public function testInstallmentsMustBalanceAndDetectStaleVersion(): void
    {
        $id=$this->invoice(); $this->rejects(fn()=>$this->clinic->installments(['billing_id'=>$id,'version'=>0,'dates'=>['2026-10-01'],'amounts'=>['100']]));
        $this->clinic->installments(['billing_id'=>$id,'version'=>0,'dates'=>['2026-10-01','2026-11-01'],'amounts'=>['51','51']]);
        $this->pay($id,'60'); $detail=$this->clinic->detail($id); $this->assertSame(0,$detail['installments'][0]['due_cents']); $this->assertSame(4200,$detail['installments'][1]['due_cents']);
        $this->rejects(fn()=>$this->clinic->installments(['billing_id'=>$id,'version'=>0,'dates'=>['2026-10-01'],'amounts'=>['102']]));
    }
    public function testReportUsesCreditDateAndFiltersAndPreservesHistoricalNames(): void
    {
        $id=$this->invoice(); $this->clinic->credit(['billing_id'=>$id,'amount'=>'51','issue_date'=>'2026-10-02','reason'=>'Storno','request_key'=>$this->key()]);
        $sept=$this->clinic->report(['from'=>'2026-09-01','to'=>'2026-09-30','group'=>'branch']);
        $oct=$this->clinic->report(['from'=>'2026-10-01','to'=>'2026-10-31','group'=>'service']);
        $this->assertSame(10000,$sept['rows'][0]['net_cents']); $this->assertSame(-5000,$oct['rows'][0]['net_cents']);
        $this->assertSame([],$this->clinic->report(['doctor_id'=>999])['rows']);
        $this->clinic->saveCatalog(['id'=>$this->ids['branch'],'version'=>0,'kind'=>'branch','code'=>'CARD','name'=>'Nuovo nome','active'=>1]);
        $this->assertSame('Cardiologia',$this->clinic->report(['from'=>'2026-09-01','to'=>'2026-09-30','group'=>'branch'])['rows'][0]['label']);
    }
    public function testCashJournalIsBalancedAndCreditsReverseEntries(): void
    {
        $id=$this->invoice(); $this->pay($id,'30'); $this->clinic->credit(['billing_id'=>$id,'amount'=>'51','issue_date'=>'2026-09-22','reason'=>'Storno','request_key'=>$this->key()]);
        $export=new PolyclinicAccountingExport(); $config=['customer_account'=>'100','revenue_account'=>'200','vat_account'=>'300','stamp_account'=>'400','cash_account'=>'500','bank_account'=>'600'];
        $journal=$export->journal($this->db,'2026-09-01','2026-09-30',$config); $balance=[];
        foreach($journal as $row) $balance[$row['entry_id']]=($balance[$row['entry_id']]??0)+Money::cents($row['debit'])-Money::cents($row['credit']);
        $this->assertCount(3,$balance); $this->assertSame([0,0,0],array_values($balance));
        $csv=$export->csv([['customer'=>'=HYPERLINK("bad")']],['customer']); $this->assertStringContainsString("'=HYPERLINK",$csv);
    }
    public function testAccessBoundariesAndInvalidStateTransitions(): void
    {
        $this->rejects(fn()=>$this->clinic->arrive(['patient_id'=>999,'doctor_id'=>$this->ids['doctor'],'visit_date'=>'2026-09-21','request_key'=>$this->key()]));
        $e=$this->clinic->arrive(['patient_id'=>100,'doctor_id'=>$this->ids['doctor'],'visit_date'=>'2026-09-21','request_key'=>$this->key()]);
        $this->rejects(fn()=>$this->clinic->transition(['id'=>$e,'state'=>'completed','version'=>0]));
        $this->clinic->transition(['id'=>$e,'state'=>'waiting','version'=>0]);
        $this->rejects(fn()=>$this->clinic->transition(['id'=>$e,'state'=>'in_care','version'=>0]));
        $this->rejects(fn()=>$this->clinic->document(99999));
        $this->rejects(fn()=>$this->clinic->saveCatalog(['kind'=>'service','name'=>'Bad','code'=>'BAD','branch_id'=>$this->ids['doctor'],'price'=>'10','active'=>1]));
    }
    public function testMigrationCanRunTwiceWithoutErasingData(): void
    {
        $id=$this->invoice(); (new \App\Database\Polyclinic\CreatePolyclinicAdministration(\Config\Database::forge($this->db)))->up();
        $this->assertSame(10200,$this->clinic->balance($id)['total_cents']);
    }
    public function testElectronicInvoiceExcludesClinicalDataAndRejectsPrivatePatient(): void
    {
        $recipient=['type'=>'business','name'=>'Ente & Prova','vat_number'=>'12345678901','address'=>'Via Test 1','postal_code'=>'00100','city'=>'Roma','province'=>'RM','recipient_code'=>'0000000'];
        $issuer=['business_name'=>'Centro Test','vat_number'=>'12345678901','address'=>'Via Test 2','postal_code'=>'00100','city'=>'Roma','province'=>'RM','tax_regime'=>'RF01'];
        $id=$this->invoice(); $d=$this->clinic->document($id); $xml=(new PolyclinicElectronicInvoice())->build($d,$recipient,$issuer);
        $dom=new \DOMDocument(); $this->assertTrue($dom->loadXML($xml)); $this->assertSame('FPR12',$dom->documentElement->getAttribute('versione'));
        $this->assertStringNotContainsString('VRDLGI',$xml); $this->assertStringNotContainsString('Sintetico',$xml); $this->assertStringContainsString('Ente &amp; Prova',$xml);
        $this->rejects(fn()=>(new PolyclinicElectronicInvoice())->build($d,['type'=>'patient'],$issuer));
    }

    public function testReusedRequestKeyWithDifferentAmountIsRejected(): void
    {
        $id=$this->invoice(); $key=$this->key(); $this->pay($id,'10',['request_key'=>$key]);
        $this->rejects(fn()=>$this->pay($id,'20',['request_key'=>$key]));
        $this->assertSame(1000,$this->clinic->balance($id)['paid_cents']);
    }
    public function testRepeatedSmallCreditsNeverCreateNegativeTaxAndFullyReverseInvoice(): void
    {
        $id=$this->invoice();
        foreach (['0.01','0.01','0.49','0.51','50.99','49.99'] as $amount) {
            $credit=$this->clinic->credit(['billing_id'=>$id,'amount'=>$amount,'issue_date'=>'2026-09-22','reason'=>'Storno frazionato','request_key'=>$this->key()]);
            $d=$this->clinic->document($credit);
            $this->assertSame(Money::cents($d['amount_total']),Money::cents($d['subtotal_amount'])+Money::cents($d['stamp_duty_amount']));
        }
        $this->assertSame(0,$this->clinic->balance($id)['net_cents']);
        $this->assertSame(0,$this->clinic->report(['from'=>'2026-09-01','to'=>'2026-09-30'])['rows'][0]['net_cents']);
    }
    public function testTaxableInvoiceAndCreditHaveConsistentGrossNetAndTax(): void
    {
        $id=$this->invoice(['vat_rate'=>'22','vat_nature'=>'','stamp'=>'0']);
        $d=$this->clinic->document($id); $this->assertSame(12200,Money::cents($d['amount_total']));
        $this->pay($id,'61'); $this->assertSame(2500,$this->clinic->compensation($id)[0]['earned_cents']);
        $credit=$this->clinic->credit(['billing_id'=>$id,'amount'=>'61','issue_date'=>'2026-09-22','reason'=>'Storno','request_key'=>$this->key()]);
        $d=$this->clinic->document($credit); $this->assertSame(5000,Money::cents($d['subtotal_amount']));
        $this->assertSame(1100,Money::cents($d['amount_total'])-Money::cents($d['subtotal_amount']));
    }
    public function testNoInvoiceBeforeVisitCompletionAndNoOrderAfterCompletion(): void
    {
        $e=$this->clinic->arrive(['patient_id'=>100,'doctor_id'=>$this->ids['doctor'],'visit_date'=>'2026-09-21','request_key'=>$this->key()]);
        $this->rejects(fn()=>$this->clinic->issueInvoice(['encounter_id'=>$e,'request_key'=>$this->key()],[]));
        foreach (['waiting','in_care','completed'] as $v=>$state) $this->clinic->transition(['id'=>$e,'state'=>$state,'version'=>$v]);
        $this->rejects(fn()=>$this->clinic->addOrder(['encounter_id'=>$e,'service_id'=>$this->ids['service'],'quantity'=>1,'request_key'=>$this->key()]));
        $this->assertSame(0,$this->db->table('pc_documents')->countAllResults());
    }
    public function testFixedBilledFeeIsSnapshotAndCannotBePaidTwice(): void
    {
        $rule=$this->clinic->catalog()['rule'][0];
        $this->clinic->saveCatalog(['id'=>$rule['id'],'kind'=>'rule','code'=>$rule['code'],'name'=>$rule['name'],'active'=>1,'version'=>0,'doctor_id'=>$this->ids['doctor'],'service_id'=>$this->ids['service'],'mode'=>'fixed','value'=>'35','basis'=>'billed']);
        $id=$this->invoice(); $this->assertSame(3500,$this->clinic->compensation($id)[0]['earned_cents']);
        $key=$this->key(); $in=['billing_id'=>$id,'doctor_id'=>$this->ids['doctor'],'amount'=>'35','payment_date'=>'2026-09-21','reference'=>'Liquidazione','request_key'=>$key];
        $s=$this->clinic->settle($in); $this->assertSame($s,$this->clinic->settle($in));
        $this->rejects(fn()=>$this->clinic->settle(array_replace($in,['request_key'=>$this->key()])));
    }
    public function testInvoiceDoubleSubmitAndCreditDoubleSubmitCreateOnlyOneDocument(): void
    {
        $key=$this->key(); $id=$this->invoice(['request_key'=>$key]);
        $order=$this->db->table('pc_orders')->where('billing_id',$id)->get()->getRowArray();
        $same=['encounter_id'=>(int)$order['encounter_id'],'issue_date'=>'2026-09-21','due_date'=>'2026-09-30','vat_nature'=>'N4','stamp'=>'2.00','request_key'=>$key];
        $this->assertSame($id,$this->clinic->issueInvoice($same,[]));
        $credit=['billing_id'=>$id,'amount'=>'10','issue_date'=>'2026-09-22','reason'=>'Storno','request_key'=>$this->key()];
        $c=$this->clinic->credit($credit); $this->assertSame($c,$this->clinic->credit($credit));
        $this->assertSame(2,$this->db->table('pc_documents')->countAllResults());
    }
    public function testReportCreditAllocationsReconcileAcrossMultipleServices(): void
    {
        $second=$this->catalog('service','Controllo',['branch_id'=>$this->ids['branch'],'price'=>'0.03']);
        $this->catalog('rule','Compenso controllo',['doctor_id'=>$this->ids['doctor'],'service_id'=>$second,'mode'=>'percent','value'=>'50','basis'=>'collected']);
        $e=$this->clinic->arrive(['patient_id'=>100,'doctor_id'=>$this->ids['doctor'],'visit_date'=>'2026-09-21','request_key'=>$this->key()]);
        foreach([$this->ids['service'],$second] as $service) $this->clinic->addOrder(['encounter_id'=>$e,'service_id'=>$service,'quantity'=>1,'request_key'=>$this->key()]);
        foreach (['waiting','in_care','completed'] as $v=>$state) $this->clinic->transition(['id'=>$e,'state'=>$state,'version'=>$v]);
        $id=$this->clinic->issueInvoice(['encounter_id'=>$e,'issue_date'=>'2026-09-21','vat_nature'=>'N4','request_key'=>$this->key()],[]);
        foreach(['0.02','100.01'] as $amount) $this->clinic->credit(['billing_id'=>$id,'amount'=>$amount,'issue_date'=>'2026-09-22','reason'=>'Storno','request_key'=>$this->key()]);
        $rows=$this->clinic->report(['from'=>'2026-09-01','to'=>'2026-09-30','group'=>'service'])['rows'];
        $this->assertSame([0,0],array_column($rows,'net_cents'));
    }
    public function testCashReportIncludesPaymentsForInvoicesIssuedInEarlierPeriod(): void
    {
        $id=$this->invoice(); $this->pay($id,'51',['payment_date'=>'2026-10-01']);
        $r=$this->clinic->report(['from'=>'2026-10-01','to'=>'2026-10-31']);
        $this->assertSame(0,$r['rows'][0]['gross_cents']); $this->assertSame(5000,$r['rows'][0]['cash_cents']);
    }
    public function testDemoConnectorsAreIdempotentAndDoNotChangeRealDocuments(): void
    {
        $id=$this->invoice(); $before=$this->clinic->detail($id)['state'];
        foreach (['accounting','sdi'] as $connector) foreach (['accepted','rejected','timeout'] as $scenario) {
            $input=['connector'=>$connector,'scenario'=>$scenario,'from'=>'2026-09-01','to'=>'2026-09-30','request_key'=>$this->key()];
            $result=$this->clinic->simulateConnector($input);
            $this->assertSame($scenario.'_simulated',$result['state']);
            $this->assertFalse($result['network_used']);
            $this->assertSame($result,$this->clinic->simulateConnector($input));
        }
        $this->assertSame(6,$this->db->table('pc_demo_runs')->countAllResults());
        $this->assertSame($before,$this->clinic->detail($id)['state']);
    }
    public function testDemoRejectsUnbalancedJournalAndUnsafeXml(): void
    {
        $demo=new \App\Services\PolyclinicDemoConnector();
        $this->rejects(fn()=>$demo->run('accounting','accepted',['journal'=>[['entry_id'=>'1','account'=>'A','debit'=>'10.00','credit'=>'0.00','document'=>'D']]]));
        $this->rejects(fn()=>$demo->run('sdi','accepted',['xml'=>'<!DOCTYPE a><a/>']));
        $this->rejects(fn()=>$demo->run('sdi','accepted',['xml'=>'<invalid/>']));
    }
    public function testElectronicInvoiceArtifactRetainsIssuerSnapshot(): void
    {
        $issuer=['business_name'=>'Centro Test','vat_number'=>'12345678901','address'=>'Via Test 2','postal_code'=>'00100','city'=>'Roma','province'=>'RM','tax_regime'=>'RF01'];
        $this->clinic->saveSettings('einvoice',$issuer,-1);
        $id=$this->invoice(['recipient_type'=>'business','recipient_name'=>'Azienda Test','recipient_vat_number'=>'12345678901','recipient_address'=>'Via Test 3','recipient_postal_code'=>'00100','recipient_city'=>'Roma','recipient_province'=>'RM','recipient_recipient_code'=>'0000000']);
        $xml=$this->clinic->prepareElectronicInvoice($id); $this->clinic->saveSettings('einvoice',array_replace($issuer,['business_name'=>'Nome modificato']),0);
        $this->assertSame($xml,$this->clinic->prepareElectronicInvoice($id));
        $state=$this->clinic->detail($id)['state']; $this->assertSame('exported',$state['einvoice_state']);
        $this->rejects(fn()=>$this->clinic->recordElectronicOutcome(['billing_id'=>$id,'version'=>$state['version'],'state'=>'delivered','reference'=>'123']));
        $this->clinic->recordElectronicOutcome(['billing_id'=>$id,'version'=>$state['version'],'state'=>'submitted','reference'=>'Canale Test']);
        $this->assertSame('submitted',$this->clinic->detail($id)['state']['einvoice_state']);
    }
}
