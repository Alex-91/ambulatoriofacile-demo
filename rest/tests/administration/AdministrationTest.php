<?php
namespace Tests\Administration;
require_once __DIR__.'/../_support/ClinicalMasterPlatformFixture.php';
use App\Services\{AdministrationService as A,AdministrationFeatureService};
use CodeIgniter\Test\CIUnitTestCase;
class Gate extends AdministrationFeatureService
{
    public array $disabled=[];
    public function enabled(int $tenant,string $module): bool { return isset(self::MODULES[$module]) && !in_array($module,$this->disabled,true); }
}
final class AdministrationTest extends CIUnitTestCase
{
    protected $db; private $platform; private string $key; private A $admin; private Gate $gate;
    protected function setUp(): void
    {
        parent::setUp(); $this->key=config(\App\Config\Crypto::class)->keyHex; config(\App\Config\Crypto::class)->keyHex=bin2hex(random_bytes(32));
        $this->platform=new \Tests\Support\ClinicalMasterPlatformFixture();
        $this->db=\Config\Database::connect(['DBDriver'=>'SQLite3','database'=>':memory:','DBPrefix'=>'','DBDebug'=>true],false);
        $this->db->query('CREATE TABLE dap01_users (id_user INTEGER PRIMARY KEY,is_active INTEGER)');
        $this->db->query('CREATE TABLE dap03_personale (id_personale INTEGER PRIMARY KEY,id_user INTEGER,tipo INTEGER,is_active INTEGER)');
        $this->db->query('CREATE TABLE dap02_clients (id_client INTEGER PRIMARY KEY)');
        foreach([1=>1,6=>4] as $id=>$role) { $this->db->table('dap01_users')->insert(['id_user'=>$id,'is_active'=>1]); $this->db->table('dap03_personale')->insert(['id_personale'=>$id*10,'id_user'=>$id,'tipo'=>$role,'is_active'=>1]); }
        $this->db->table('dap02_clients')->insert(['id_client'=>100]);
        $this->gate=new Gate(); $this->admin=new A($this->db,42,6,$this->gate); $this->admin->initialize();
    }
    protected function tearDown(): void { $this->db->close(); $this->platform->close(); config(\App\Config\Crypto::class)->keyHex=$this->key; parent::tearDown(); }
    private function deny(callable $call): void { try { $call(); } catch (\RuntimeException $e) { $this->addToAssertionCount(1); return; } $this->fail('Unexpectedly authorized'); }
    private function catalog(string $kind='service',array $extra=[]): string
    { return $this->admin->saveCatalog($kind,$extra+['name'=>'Synthetic '.$kind,'from'=>'2020-01-01','active'=>'1','code'=>'VIS','list'=>'Privati','price'=>'100.01','type'=>'fund','coverage'=>'80','deductible'=>'5','cap'=>'60','staff_id'=>10,'basis'=>'paid','percent'=>'30','fixed'=>'2']); }
    private function quote(array $extra=[]): string
    { $service=$this->catalog(); return $this->admin->createQuote($extra+['patient_id'=>100,'date'=>date('Y-m-d'),'expires'=>date('Y-m-d',strtotime('+30 days')),'service_id'=>[$service],'quantity'=>['1'],'discount'=>'0','request_token'=>bin2hex(random_bytes(16))]); }
    private function accepted(): string
    { $id=$this->quote(); $this->admin->quoteState($id,1,'offered','Consegna sintetica'); $this->admin->quoteState($id,2,'accepted','Accettazione sintetica'); return $id; }
    private function case(): string
    { return $this->admin->createCase(['regime'=>'payer','quote_id'=>$this->accepted(),'payer_id'=>$this->catalog('payer'),'reference'=>'SYNTHETIC','due_on'=>date('Y-m-d')]); }
    public function testMoneyRoundingAndValidation(): void
    {
        $this->assertSame(10001,A::cents('100,01')); $this->assertSame('0.09',A::money(9));
        foreach(['-1','1.001','1e3','NaN','1,000.00',''] as $v) $this->deny(fn()=>A::cents($v));
        $id=$this->quote(['discount'=>'12.5']); $this->assertSame(8751,(int)$this->admin->detail('quotes',$id)['total_cents']);
    }
    public function testPreparationAndAccessFailClosed(): void
    {
        $this->admin->initialize(); $this->assertTrue($this->admin->ready());
        $this->deny(fn()=>(new A($this->db,42,1,$this->gate))->list('quotes'));
        $this->deny(fn()=>(new A($this->db,43,6,$this->gate))->list('quotes'));
        $this->gate->disabled=['admin_quotes']; $this->deny(fn()=>$this->quote());
        $this->assertSame(0,$this->db->table('administration_quotes')->countAllResults());
    }
    public function testSnapshotsEncryptedAndRevisionProtects(): void
    {
        $s=$this->catalog(); $id=$this->quote(['service_id'=>[$s]]);
        $this->catalog('service',['id'=>$s,'revision'=>1,'price'=>'900']);
        $q=$this->admin->detail('quotes',$id); $this->assertSame(10001,(int)$q['total_cents']);
        $raw=$this->db->table('administration_quotes')->where('id',$id)->get()->getRowArray(); $this->assertStringNotContainsString('Synthetic',$raw['payload_enc']);
        $this->admin->quoteState($id,1,'offered','Test'); $this->deny(fn()=>$this->admin->quoteState($id,1,'accepted','Stale'));
        $this->assertCount(2,$this->admin->detail('quotes',$id)['data']['history']);
        $this->deny(fn()=>$this->admin->detail('catalog',$s));
    }
    public function testDuplicateQuoteTokenIsRejected(): void
    { $token=bin2hex(random_bytes(16)); $this->quote(['request_token'=>$token]); $this->deny(fn()=>$this->quote(['request_token'=>$token])); $this->assertSame(1,$this->db->table('administration_quotes')->countAllResults()); }
    public function testAcceptedQuoteCaseUniquenessAndCoverage(): void
    {
        $id=$this->case(); $c=$this->admin->detail('cases',$id); $this->assertSame(6000,(int)$c['payer_cents']); $this->assertSame(4001,(int)$c['patient_cents']);
        $this->deny(fn()=>$this->admin->createCase(['regime'=>'payer','quote_id'=>$c['quote_id'],'payer_id'=>$this->catalog('payer'),'reference'=>'Duplicate','due_on'=>date('Y-m-d')]));
        $this->deny(fn()=>$this->admin->quoteState($c['quote_id'],4,'cancelled','Cancel'));
        $this->assertSame(1,$this->db->table('administration_cases')->countAllResults());
    }
    public function testReceiptsOverpaymentConcurrencyReconciliationAndVoid(): void
    {
        $id=$this->case(); $this->admin->caseState($id,1,'authorized','AUTH');
        $r=['party'=>'patient','amount'=>'40.01','paid_on'=>date('Y-m-d'),'method'=>'Bonifico','reference'=>'TEST'];
        $receipt=$this->admin->receipt($id,2,$r); $this->deny(fn()=>$this->admin->receipt($id,2,$r));
        $this->deny(fn()=>$this->admin->receipt($id,3,$r));
        $this->admin->receipt($id,3,array_replace($r,['party'=>'payer','amount'=>'60']));
        $this->admin->caseState($id,4,'submitted','PORTAL REF'); $this->admin->caseState($id,5,'reconciled','BANK REF');
        $this->admin->voidReceipt($id,6,$receipt,'Errore registrazione');
        $c=$this->admin->detail('cases',$id); $this->assertSame('submitted',$c['state']); $this->assertSame(6000,(int)$c['received_cents']);
        $this->deny(fn()=>$this->admin->caseState($id,7,'reconciled','Missing balance'));
        $this->assertSame(2,$this->db->table('administration_receipts')->countAllResults());
    }
    public function testSsnOnlyScopeAndManualTicket(): void
    {
        $q=$this->accepted(); $this->gate->disabled=['admin_payers'];
        $id=$this->admin->createCase(['regime'=>'ssn','quote_id'=>$q,'reference'=>'SSN TEST','due_on'=>date('Y-m-d'),'ticket'=>'10.01','ssn_entity'=>'Ente sintetico','prescription'=>'SYNTHETIC-NRE']);
        $c=$this->admin->detail('cases',$id); $this->assertSame(9000,(int)$c['payer_cents']); $this->assertCount(1,$this->admin->list('cases'));
        $this->gate->disabled=['admin_ssn']; $this->deny(fn()=>$this->admin->detail('cases',$id)); $this->assertSame([],$this->admin->list('cases'));
    }
    public function testCompensationUsesPaidInvoiceAndBlocksChangedSource(): void
    {
        $this->db->query('CREATE TABLE billing_documents (id_billing_document INTEGER PRIMARY KEY,local_state TEXT,document_type TEXT,payment_status TEXT,amount_total TEXT,document_number TEXT,issue_date TEXT,line_items_json TEXT)');
        $this->db->table('billing_documents')->insert(['id_billing_document'=>1,'local_state'=>'issued','document_type'=>'invoice','payment_status'=>'unpaid','amount_total'=>'100.01','document_number'=>'SYN1','issue_date'=>date('Y-m-d'),'line_items_json'=>'[]']);
        $input=['rule_id'=>$this->catalog('rule'),'billing_id'=>1,'reference'=>'Attribuzione sintetica'];
        $this->deny(fn()=>$this->admin->accrue($input));
        $this->db->table('billing_documents')->where('id_billing_document',1)->update(['payment_status'=>'paid']);
        $id=$this->admin->accrue($input); $this->assertSame(3200,(int)$this->admin->detail('compensation',$id)['amount_cents']);
        $this->deny(fn()=>$this->admin->accrue($input));
        $this->admin->compensationState($id,1,'approved','APP');
        $this->db->table('billing_documents')->where('id_billing_document',1)->update(['amount_total'=>'99.00']);
        $this->deny(fn()=>$this->admin->compensationState($id,2,'settled','PAY'));
        $this->admin->compensationState($id,2,'void','Sorgente variata'); $this->assertSame('void',$this->admin->detail('compensation',$id)['state']);
        $this->assertSame($id,$this->admin->accrue($input));
        $recalculated=$this->admin->detail('compensation',$id); $this->assertSame(3170,(int)$recalculated['amount_cents']);
        $this->assertSame(3200,$recalculated['data']['previous_calculations'][0]['amount_cents']);
    }
    public function testCsvNeutralizesSpreadsheetFormulaAndQuotesNewlines(): void
    { $csv=A::csv([['=HYPERLINK("bad")',"@SUM(1)","a;b\nc"]]); $this->assertStringContainsString("'=HYPERLINK",$csv); $this->assertStringContainsString("'@SUM",$csv); $this->assertStringContainsString('"a;b',$csv); }
    public function testQuoteViewEscapesUntrustedNames(): void
    {
        helper(['url','form']); $row=$this->admin->detail('quotes',$this->quote()); $row['data']['notes']='<script>bad()</script>';
        $html=view('administration/quote_pdf',['row'=>$row,'patient'=>['patient_name'=>'<img src=x onerror=bad()>'],'tenant'=>['name'=>'Test']],['saveData'=>false]);
        $this->assertStringNotContainsString('<script>',$html); $this->assertStringNotContainsString('<img src=x',$html); $this->assertStringContainsString('100,01 EUR',$html);
    }
    public function testViewsRenderAvailableModulesAndCsrfForms(): void
    {
        helper(['url','form']);
        $base=['tenant'=>['tenant_name'=>'Test'],'modules'=>$this->admin->modules(),'ready'=>true,'page'=>1,'more'=>false,'catalog'=>['service'=>[],'payer'=>[],'rule'=>[]],'patients'=>[['id_client'=>100,'patient_name'=>'SINTETICO']],'staff'=>[['id_personale'=>10,'nome'=>'Medico','cognome'=>'Test']]];
        foreach(['catalog','quotes','cases','compensation'] as $section) {
            $html=view('administration/index',$base+['section'=>$section,'rows'=>[]],['saveData'=>false]);
            $this->assertStringContainsString('Amministrazione',$html);
            if ($section!=='cases') $this->assertStringContainsString(csrf_token(),$html);
        }
        foreach(['quotes'=>$this->accepted(),'cases'=>$this->case()] as $section=>$id) {
            $html=view('administration/detail',$base+['section'=>$section,'row'=>$this->admin->detail($section,$id),'patient'=>['patient_name'=>'SINTETICO'],'payers'=>[],'audit'=>$this->admin->auditTrail($id)],['saveData'=>false]);
            $this->assertStringContainsString('Storico',$html); $this->assertStringContainsString(csrf_token(),$html);
        }
    }
    public function testPartialSchemaIsNotReady(): void
    {
        $this->db->query('ALTER TABLE administration_quotes DROP COLUMN expires_on'); unset($this->db->dataCache['field_names']['administration_quotes']);
        $this->assertFalse($this->admin->ready()); $this->deny(fn()=>$this->quote());
    }
    public function testFutureRevisionCannotOverwriteChangesAndFailedReceiptRollsBack(): void
    {
        $id=$this->case(); $this->admin->caseState($id,1,'authorized','AUTH');
        $r=['party'=>'payer','amount'=>'60','paid_on'=>date('Y-m-d'),'method'=>'Bonifico','reference'=>'TEST'];
        $this->deny(fn()=>$this->admin->receipt($id,3,$r));
        $this->db->query("CREATE TRIGGER fail_receipt BEFORE INSERT ON administration_receipts BEGIN SELECT RAISE(ABORT,'synthetic failure'); END");
        $this->deny(fn()=>$this->admin->receipt($id,2,$r));
        $c=$this->admin->detail('cases',$id); $this->assertSame(0,(int)$c['received_cents']); $this->assertSame(2,(int)$c['revision']);
    }
}
