<?php
namespace Tests\Unit;

use App\Models\{FseDocumentEventModel,FseDocumentModel};
use App\Services\{FseAuditService,FseArtifactValidationService,FseDocumentService,FsePdfEnvelopeService,FseProfileService,FseSecretsService,FseStorageService,FseTenantDatabaseContextService,FseTenantSchemaService};
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

final class FseLocalPersistenceTest extends CIUnitTestCase
{
    private array $context;
    private FseDocumentService $service;
    private array $payload;
    private array $files=[];

    protected function setUp(): void
    {
        parent::setUp();
        $db=Database::connect(['DBDriver'=>'SQLite3','database'=>':memory:','DBPrefix'=>'','DBDebug'=>true],false);
        $forge=Database::forge($db);
        foreach (['2026-09-04-010003_CreateFseDocumentsTables'=>'CreateFseDocumentsTables',
            '2026-09-07-220001_AddFseServiceDescription'=>'AddFseServiceDescription',
            '2026-09-07-230001_AddFseDocumentRevisions'=>'AddFseDocumentRevisions',
            '2026-09-08-100001_AddFseProfileSnapshot'=>'AddFseProfileSnapshot'] as $file=>$name) {
            require_once APPPATH.'Database/Migrations/'.$file.'.php';
            $class='App\\Database\\Migrations\\'.$name; (new $class($forge))->up();
        }
        $events=new FseDocumentEventModel($db);
        $this->context=['db'=>$db,'documents'=>new FseDocumentModel($db),'events'=>$events,'audit'=>new FseAuditService($events)];
        $contexts=$this->getMockBuilder(FseTenantDatabaseContextService::class)->disableOriginalConstructor()->onlyMethods(['resolveTenantContext'])->getMock();
        $contexts->method('resolveTenantContext')->willReturnCallback(fn($tenant)=>$tenant===42?$this->context:throw new \RuntimeException('Unknown synthetic tenant'));
        $schema=$this->createMock(FseTenantSchemaService::class);
        $schema->method('ensureTenantSchemaReady')->willReturn(['ready'=>true,'status'=>'ok']);
        $schema->method('isReady')->willReturn(true);
        $secrets=$this->createMock(FseSecretsService::class);
        $secrets->method('encrypt')->willReturnCallback(fn($v)=>$v===null||$v===''?null:'test:'.base64_encode($v));
        $secrets->method('decrypt')->willReturnCallback(fn($v)=>$v?base64_decode(substr($v,5)):null);
        $this->payload=require SUPPORTPATH.'fse_synthetic.php';
        $profile=$this->payload+['id_fse_profile'=>7,'access_mode'=>'toscana_privati','document_oid_root'=>'1.2.3.4'];
        $profiles=$this->getMockBuilder(FseProfileService::class)->disableOriginalConstructor()->onlyMethods(['runtimeProfileForTenant','runtimeProfileForDocument'])->getMock();
        $profiles->method('runtimeProfileForTenant')->willReturn($profile);
        $profiles->method('runtimeProfileForDocument')->willReturn($profile);
        $pdf=$this->createMock(FsePdfEnvelopeService::class);
        $pdf->method('build')->willReturn('%PDF-synthetic-only');
        $storage=$this->createMock(FseStorageService::class);
        $storage->method('store')->willReturnCallback(function($tenant,$id,$name,$contents) {
            $path='synthetic/'.$tenant.'/'.$id.'/'.$name;
            $this->files[$path]=$contents;
            return $path;
        });
        $validation=$this->createMock(FseArtifactValidationService::class);
        $validation->method('storedArtifact')->willReturn('synthetic-artifact');
        $validation->method('check')->willReturn(['signature'=>'valid','pdfa'=>'3b']);
        $this->service=new FseDocumentService($contexts,$profiles,$secrets,pdf:$pdf,storage:$storage,schema:$schema,validation:$validation);
    }

    protected function tearDown(): void { $this->context['db']->close(); parent::tearDown(); }
    private function row(int $id): array { return $this->context['documents']->find($id); }
    private function draft(): int { return (int)$this->service->saveDraftForTenant(42,$this->payload,1)['id_fse_document']; }
    private function failAudit(bool $silent=false): void
    {
        $events=$this->getMockBuilder(FseDocumentEventModel::class)->setConstructorArgs([$this->context['db']])->onlyMethods(['insert'])->getMock();
        if ($silent) $events->method('insert')->willReturn(false);
        else $events->method('insert')->willThrowException(new \RuntimeException('Synthetic audit outage'));
        $this->context['audit']=new FseAuditService($events);
    }
    private function rejected(callable $operation): void
    {
        try { $operation(); } catch (\RuntimeException $e) { $this->assertNotSame('',$e->getMessage()); return; }
        $this->fail('An incomplete local write was reported as successful');
    }

    public function testDraftAndAuditAreCommittedTogether(): void
    {
        $id=$this->draft();
        $this->assertSame('draft',$this->row($id)['local_state']);
        $this->assertCount(1,$this->context['events']->listForDocument($id));
    }
    public function testNewDraftIsRolledBackWhenAuditThrows(): void
    {
        $this->failAudit(); $this->rejected(fn()=>$this->draft());
        $this->assertSame(0,$this->context['documents']->countAllResults());
        $this->assertSame(0,$this->context['events']->countAllResults());
    }
    public function testSilentAuditFailureCannotProduceSuccessfulDraft(): void
    {
        $this->failAudit(true); $this->rejected(fn()=>$this->draft());
        $this->assertSame(0,$this->context['documents']->countAllResults());
    }
    public function testFailedEditPreservesOriginalTokenAndContent(): void
    {
        $id=$this->draft(); $before=$this->row($id);
        $payload=$this->service->runtimeDocument(42,$id); $payload['report_text']='Synthetic changed content';
        $this->failAudit(); $this->rejected(fn()=>$this->service->saveDraftForTenant(42,$payload,1));
        $this->assertSame($before,$this->row($id));
        $this->assertCount(1,$this->context['events']->listForDocument($id));
    }
    public function testFailedPreparationKeepsPriorArtifactReferencesAndBytes(): void
    {
        $id=$this->draft(); $this->service->prepareForSignature(42,$id,1);
        $before=$this->row($id); $oldFiles=$this->files;
        $this->failAudit(); $this->rejected(fn()=>$this->service->prepareForSignature(42,$id,1));
        $after=$this->row($id);
        foreach (['local_state','edit_token','cda_path','cda_sha256','unsigned_pdf_path','unsigned_pdf_sha256'] as $key) $this->assertSame($before[$key],$after[$key],$key);
        foreach ($oldFiles as $path=>$bytes) $this->assertSame($bytes,$this->files[$path]);
        $this->assertCount(4,$this->files,'A new attempt must not overwrite the two earlier artifacts');
        $this->assertCount(2,$this->context['events']->listForDocument($id));
    }
    public function testFailedSignatureAuditCannotSealDocument(): void
    {
        $id=$this->draft(); $this->service->prepareForSignature(42,$id,1); $before=$this->row($id);
        $this->failAudit(); $this->rejected(fn()=>$this->service->acceptSignedPdf(42,$id,'%PDF-'.str_repeat('synthetic',20),1));
        $after=$this->row($id);
        $this->assertSame('ready_to_validate',$after['local_state']);
        $this->assertNull($after['signed_pdf_path']); $this->assertNull($after['signed_pdf_sha256']);
        $this->assertSame($before['unsigned_pdf_path'],$after['unsigned_pdf_path']);
        $this->assertCount(2,$this->context['events']->listForDocument($id));
    }
    public function testNestedFailureCannotCommitPartialDocument(): void
    {
        $this->context['db']->transBegin();
        $this->failAudit(); $this->rejected(fn()=>$this->draft());
        $this->context['db']->transCommit();
        $this->assertSame(0,$this->context['documents']->countAllResults());
    }

    public function testNestedFailurePreservesWorkBeforeTheSavepoint(): void
    {
        $db=$this->context['db']; $db->transBegin();
        $id=$this->draft();
        $this->failAudit(); $this->rejected(fn()=>$this->draft());
        $db->transCommit();
        $this->assertSame(1,$this->context['documents']->countAllResults());
        $this->assertCount(1,$this->context['events']->listForDocument($id));
    }

    public function testDisabledTransactionsFailBeforeWriting(): void
    {
        $this->context['db']->transOff();
        $this->rejected(fn()=>$this->draft());
        $this->assertSame(0,$this->context['documents']->countAllResults());
    }

    public function testFailedCommitRollsBackTheLocalWrite(): void
    {
        $db=$this->getMockBuilder(\CodeIgniter\Database\SQLite3\Connection::class)
            ->setConstructorArgs([['database'=>':memory:','DBPrefix'=>'','DBDebug'=>true]])->onlyMethods(['transCommit','isWriteType'])->getMock();
        $db->method('transCommit')->willReturn(false);
        // CI derives result classes from static::class; avoid a synthetic mock
        // result class for SAVEPOINT statements. SQLite still executes them.
        $db->method('isWriteType')->willReturnCallback(static fn(string $sql)=>!str_starts_with(strtoupper(trim($sql)),'SELECT'));
        $db->query('CREATE TABLE synthetic_commit_probe (id INTEGER PRIMARY KEY)');
        $this->rejected(fn()=>\App\Services\FseLocalPersistenceService::write($db,fn()=>$db->query('INSERT INTO synthetic_commit_probe (id) VALUES (1)')));
        $this->assertSame(0,(int)$db->simpleQuery('SELECT COUNT(*) AS n FROM synthetic_commit_probe')->fetchArray(SQLITE3_ASSOC)['n']);
        $db->close();
    }

    public function testSqlFailureRollsBackEarlierWritesInTheSameOperation(): void
    {
        $db=$this->context['db'];
        $db->query('CREATE TABLE synthetic_query_probe (id INTEGER PRIMARY KEY)');
        $this->rejected(fn()=>\App\Services\FseLocalPersistenceService::write($db,function() use($db) {
            $db->query('INSERT INTO synthetic_query_probe (id) VALUES (1)');
            $db->query('INSERT INTO synthetic_query_probe (id) VALUES (1)');
        }));
        $this->assertSame(0,$db->table('synthetic_query_probe')->countAllResults());
    }
}
