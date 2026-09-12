<?php
namespace Tests\Unit;
use App\Services\{TsReconciliationService,TsTenantDatabaseContextService,TsAuditService};
use App\Models\{TsDocumentModel,TsDocumentEventModel};
use CodeIgniter\Test\CIUnitTestCase;
final class TsReconciliationTest extends CIUnitTestCase
{
    protected $db;
    private string $oldKey;
    private TsDocumentModel $documents;
    private TsReconciliationService $reconciliation;
    protected function setUp(): void
    {
        parent::setUp();$crypto=config(\App\Config\Crypto::class);$this->oldKey=$crypto->keyHex;$crypto->keyHex=bin2hex(random_bytes(32));
        $this->db=\Config\Database::connect(['DBDriver'=>'SQLite3','database'=>':memory:','DBPrefix'=>'','DBDebug'=>true],false);
        $this->db->query('CREATE TABLE ts_documents (id_ts_document INTEGER PRIMARY KEY,local_state TEXT,last_error_code TEXT,last_error_message TEXT,updated_at TEXT,request_payload_json TEXT,response_payload_json TEXT,ts_state TEXT,ts_protocol TEXT,ts_sent_at TEXT,updated_by INTEGER,source_type TEXT,source_ref_id INTEGER)');
        $this->db->query('CREATE TABLE ts_document_events (id_ts_event INTEGER PRIMARY KEY AUTOINCREMENT,id_ts_document INTEGER,event_type TEXT,event_level TEXT,message TEXT,context_json TEXT,created_by INTEGER,created_at TEXT)');
        $this->db->table('ts_documents')->insert(['id_ts_document'=>1,'local_state'=>'sending','last_error_code'=>'TS_OUTCOME_UNKNOWN','ts_state'=>'not_sent','updated_at'=>'2026-09-12 10:00:00','source_type'=>'manual','response_payload_json'=>'{"status":"error"}']);
        $this->documents=new TsDocumentModel($this->db);$events=new TsDocumentEventModel($this->db);
        $contexts=$this->getMockBuilder(TsTenantDatabaseContextService::class)->disableOriginalConstructor()->onlyMethods(['resolveTenantContext'])->getMock();
        $contexts->method('resolveTenantContext')->with(42)->willReturn(['db'=>$this->db,'documents'=>$this->documents,'events'=>$events,'audit'=>new TsAuditService($events)]);
        $this->reconciliation=new TsReconciliationService($contexts);
    }
    protected function tearDown(): void { $this->db->close();config(\App\Config\Crypto::class)->keyHex=$this->oldKey;parent::tearDown(); }
    private function input(string $decision='accepted'): array
    { return ['decision'=>$decision,'protocol'=>$decision==='accepted' ? 'SYNTHETIC-123' : '', 'verified'=>'1','note'=>'Controllo fittizio eseguito sul portale di test.','fingerprint'=>TsReconciliationService::fingerprint($this->documents->find(1))]; }
    public function testAcceptedReconciliationRetainsManualProvenanceAndProof(): void
    {
        $proof="%PDF-1.4\n Synthetic receipt";$this->reconciliation->reconcile(42,1,7,$this->input(),$proof);
        $record=$this->documents->find(1);$this->assertSame('sent',$record['local_state']);$this->assertSame('SYNTHETIC-123',$record['ts_protocol']);$this->assertNull($record['ts_sent_at']);
        $this->assertSame('operator_verified',json_decode($record['response_payload_json'],true)['reconciliations'][0]['mode']);
        $this->documents->update(1,['response_payload_json'=>'{}']);
        $this->assertSame($proof,$this->reconciliation->proof(42,1));
    }
    public function testConfirmedNonAcquisitionAllowsRetryOnlyOnce(): void
    {
        $input=$this->input('not_acquired');$this->reconciliation->reconcile(42,1,7,$input,"%PDF-1.4\n Synthetic evidence");
        $this->assertSame('ready',$this->documents->find(1)['local_state']);
        $this->expectException(\RuntimeException::class);$this->reconciliation->reconcile(42,1,7,$input,"%PDF-1.4\n Synthetic evidence");
    }
    public function testActiveTransportCannotBeReconciled(): void
    {
        $this->documents->update(1,['last_error_code'=>null]);
        $this->expectException(\RuntimeException::class);$this->reconciliation->reconcile(42,1,7,$this->input(),"%PDF-1.4\n Synthetic evidence");
    }
    public function testUnconfirmedOrMissingEvidenceCannotUnlockDocument(): void
    {
        $input=$this->input();$input['verified']='0';
        $this->expectException(\InvalidArgumentException::class);$this->reconciliation->reconcile(42,1,7,$input,'');
    }
}
