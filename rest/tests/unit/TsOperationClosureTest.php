<?php
namespace Tests\Unit;
use App\Services\{TsDocumentService,TsTenantDatabaseContextService,TsAuditService};
use App\Models\{TsDocumentModel,TsDocumentEventModel};
use CodeIgniter\Test\CIUnitTestCase;

final class TsOperationClosureTest extends CIUnitTestCase
{
    protected $db;
    private TsDocumentModel $documents;
    private TsDocumentService $service;
    protected function setUp(): void
    {
        parent::setUp();
        $this->db=\Config\Database::connect(['DBDriver'=>'SQLite3','database'=>':memory:','DBPrefix'=>'','DBDebug'=>true],false);
        $fields=(new \ReflectionClass(TsDocumentModel::class))->getDefaultProperties()['allowedFields'];
        $this->db->query('CREATE TABLE ts_documents (id_ts_document INTEGER PRIMARY KEY AUTOINCREMENT,'.implode(',',array_map(static fn($f)=>$f.' TEXT',$fields)).')');
        $this->db->query('CREATE TABLE ts_document_events (id_ts_event INTEGER PRIMARY KEY AUTOINCREMENT,id_ts_document INTEGER,event_type TEXT,event_level TEXT,message TEXT,context_json TEXT,created_by INTEGER,created_at TEXT)');
        $this->documents=new TsDocumentModel($this->db);
        $this->documents->insert(['id_ts_document'=>1,'source_type'=>'manual','local_state'=>'sent','ts_state'=>'accepted','ts_protocol'=>'SYNTHETIC-1']);
        $events=new TsDocumentEventModel($this->db);
        $contexts=$this->getMockBuilder(TsTenantDatabaseContextService::class)->disableOriginalConstructor()->onlyMethods(['resolveTenantContext'])->getMock();
        $contexts->method('resolveTenantContext')->with(42)->willReturn(['db'=>$this->db,'documents'=>$this->documents,'events'=>$events,'audit'=>new TsAuditService($events)]);
        $this->service=new TsDocumentService(tenantDbContext:$contexts);
    }
    protected function tearDown(): void { $this->db->close();parent::tearDown(); }
    public function testRepeatedVariationReusesExistingDraft(): void
    {
        $first=$this->service->createVariationDraftFromDocument(42,1,7);
        $second=$this->service->createVariationDraftFromDocument(42,1,7);
        $this->assertSame($first['document']['id_ts_document'],$second['document']['id_ts_document']);
        $this->assertTrue($second['reused']);$this->assertSame(2,$this->documents->countAllResults());
    }
    public function testPendingVariationBlocksCancellationUntilAbandoned(): void
    {
        $child=$this->service->createVariationDraftFromDocument(42,1,7)['document'];
        try { $this->service->createCancellationOperationFromDocument(42,1,7);$this->fail('Parallel cancellation allowed'); }
        catch (\RuntimeException $e) { $this->assertStringContainsString('già aperta',$e->getMessage()); }
        $this->service->abandonOperation(42,$child['id_ts_document'],7);
        $cancel=$this->service->createCancellationOperationFromDocument(42,1,7)['document'];
        $this->assertSame('ts_cancellation',$cancel['source_type']);$this->assertSame('accepted',$this->documents->find(1)['ts_state']);
    }
    public function testUncertainOperationCannotBeAbandoned(): void
    {
        $child=$this->service->createVariationDraftFromDocument(42,1,7)['document'];
        $this->documents->update($child['id_ts_document'],['local_state'=>'sending']);
        $this->expectException(\RuntimeException::class);$this->service->abandonOperation(42,$child['id_ts_document'],7);
    }
    public function testLegacySiblingCannotSendWhileAnotherOperationIsInFlight(): void
    {
        $child=$this->service->createVariationDraftFromDocument(42,1,7)['document'];
        $this->documents->update($child['id_ts_document'],['local_state'=>'ready']);
        $this->documents->insert(['source_ref_id'=>1,'source_type'=>'ts_cancellation','local_state'=>'sending']);
        $snapshot=$this->documents->find($child['id_ts_document']);
        $this->expectException(\RuntimeException::class);$this->documents->updateEditableSnapshot($child['id_ts_document'],$snapshot,['local_state'=>'sending']);
    }
    public function testAcceptedCancellationUpdatesBothRecords(): void
    {
        $child=$this->service->createCancellationOperationFromDocument(42,1,7)['document'];
        $this->assertTrue($this->documents->updateEditableSnapshot($child['id_ts_document'],$child,['local_state'=>'sending']));
        $this->documents->persistAccepted($child['id_ts_document'],['local_state'=>'sent','ts_state'=>'accepted','ts_protocol'=>'SYNTHETIC-C'],$child,7);
        $this->assertSame('cancelled',$this->documents->find(1)['ts_state']);
        $this->assertSame('sent',$this->documents->find($child['id_ts_document'])['local_state']);
        $this->expectException(\RuntimeException::class);$this->service->createVariationDraftFromDocument(42,1,7);
    }
    public function testFailedChildPersistenceRollsBackParentState(): void
    {
        $child=$this->service->createCancellationOperationFromDocument(42,1,7)['document'];$id=$child['id_ts_document'];
        $this->db->query("CREATE TRIGGER reject_synthetic_accept BEFORE UPDATE ON ts_documents WHEN NEW.id_ts_document=$id AND NEW.local_state='sent' BEGIN SELECT RAISE(ABORT,'Synthetic failure'); END");
        try { $this->documents->persistAccepted($id,['local_state'=>'sent'],$child,7);$this->fail('Synthetic failure ignored'); }
        catch (\Throwable $e) { $this->assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class,$e); }
        $this->assertSame('accepted',$this->documents->find(1)['ts_state']);
        $this->assertSame('ready',$this->documents->find($id)['local_state']);
    }
}
