<?php

namespace Tests\Unit;

use App\Models\FseDocumentModel;
use App\Models\FseDocumentEventModel;
use App\Services\{FseAuditService, FseArtifactValidationService, FseCdaRsaBuilderService, FseDocumentService, FseDocumentLifecycle, FseDispatchService, FseGatewayClient, FseProfileService, FseRevisionService, FseSecretsService, FseTenantDatabaseContextService, FseTenantSchemaService};
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

final class FseRevisionTest extends CIUnitTestCase
{
    private array $contextsByTenant = [];
    private array $artifacts = [];
    private FseTenantDatabaseContextService $contexts;
    private FseTenantSchemaService $schema;
    private FseSecretsService $secrets;
    private FseArtifactValidationService $validation;
    private FseProfileService $profiles;
    private array $payload;

    protected function setUp(): void
    {
        parent::setUp();
        foreach ([42, 43] as $tenant) {
            $db = Database::connect(['DBDriver'=>'SQLite3', 'database'=>':memory:', 'DBPrefix'=>'', 'DBDebug'=>true], false);
            $forge = Database::forge($db);
            foreach (['2026-09-04-010003_CreateFseDocumentsTables'=>'CreateFseDocumentsTables', '2026-09-07-220001_AddFseServiceDescription'=>'AddFseServiceDescription', '2026-09-07-230001_AddFseDocumentRevisions'=>'AddFseDocumentRevisions', '2026-09-08-100001_AddFseProfileSnapshot'=>'AddFseProfileSnapshot'] as $file=>$name) {
                require_once APPPATH . 'Database/Migrations/' . $file . '.php';
                $class = 'App\\Database\\Migrations\\' . $name; (new $class($forge))->up();
            }
            $events = new FseDocumentEventModel($db);
            $this->contextsByTenant[$tenant] = ['db'=>$db, 'documents'=>new FseDocumentModel($db), 'events'=>$events, 'audit'=>new FseAuditService($events)];
        }
        $this->contexts = $this->getMockBuilder(FseTenantDatabaseContextService::class)->disableOriginalConstructor()->onlyMethods(['resolveTenantContext'])->getMock();
        $this->contexts->method('resolveTenantContext')->willReturnCallback(fn($id) => $this->contextsByTenant[$id] ?? throw new \RuntimeException('Unknown test tenant'));
        $this->schema = $this->createMock(FseTenantSchemaService::class);
        $this->schema->method('ensureTenantSchemaReady')->willReturn(['ready'=>true, 'status'=>'ok']);
        $this->schema->method('isReady')->willReturn(true);
        $this->secrets = $this->createMock(FseSecretsService::class);
        $this->secrets->method('encrypt')->willReturnCallback(fn($v) => $v === null || $v === '' ? null : 'test-only:' . base64_encode($v));
        $this->secrets->method('decrypt')->willReturnCallback(fn($v) => $v ? base64_decode(substr($v, 10)) : null);
        $this->validation = $this->createMock(FseArtifactValidationService::class);
        $this->validation->method('storedArtifact')->willReturnCallback(fn($tenant, $id) => $this->artifacts[$tenant][$id] ?? throw new \RuntimeException('Original artifact missing'));
        $this->payload = require SUPPORTPATH . 'fse_synthetic.php';
        $this->profiles = $this->getMockBuilder(FseProfileService::class)->disableOriginalConstructor()->onlyMethods(['runtimeProfileForTenant'])->getMock();
        $this->profiles->method('runtimeProfileForTenant')->willReturn($this->payload + ['id_fse_profile'=>7, 'is_enabled'=>1, 'access_mode'=>'gateway']);
    }

    protected function tearDown(): void
    {
        foreach ($this->contextsByTenant as $context) $context['db']->close();
        parent::tearDown();
    }

    private function documents(): FseDocumentService
    {
        return new FseDocumentService($this->contexts, $this->profiles, $this->secrets, schema:$this->schema, validation:$this->validation);
    }
    private function revisions(): FseRevisionService { return new FseRevisionService($this->contexts, $this->schema, $this->secrets, $this->validation); }
    private function original(int $tenant = 42): array
    {
        $document = $this->documents()->saveDraftForTenant($tenant, $this->payload);
        $id = (int) $document['id_fse_document'];
        $cda = (new FseCdaRsaBuilderService())->build(array_merge($this->payload, $document, ['document_oid_root'=>'1.2.3.4']));
        $this->artifacts[$tenant][$id] = $cda;
        $this->contextsByTenant[$tenant]['documents']->update($id, ['local_state'=>'signed', 'cda_path'=>'synthetic.xml', 'cda_sha256'=>hash('sha256', $cda), 'signed_pdf_path'=>'original.pdf', 'signed_pdf_sha256'=>str_repeat('a', 64), 'document_oid_root'=>'1.2.3.4']);
        return $this->contextsByTenant[$tenant]['documents']->find($id);
    }

    public function testCorrectionPreservesOriginalAndDoesNotCopyRemoteEvidence(): void
    {
        $original = $this->original(); $id = (int) $original['id_fse_document'];
        $newId = $this->revisions()->create(42, $id, 'Motivo clinico sintetico riservato', 9);
        $new = $this->contextsByTenant[42]['documents']->find($newId);
        $this->assertSame($original, $this->contextsByTenant[42]['documents']->find($id));
        $this->assertSame($original['set_id'], $new['set_id']);
        $this->assertNotSame($original['document_unique_id'], $new['document_unique_id']);
        $this->assertSame('draft', $new['local_state']);
        $this->assertSame(2, (int) $new['version_number']);
        $this->assertSame($original['report_text_enc'], $new['report_text_enc']);
        $this->assertNull($new['signed_pdf_path']); $this->assertNull($new['workflow_instance_id']);
        $this->assertStringNotContainsString('Motivo clinico', $new['revision_reason_enc']);
        $this->assertStringNotContainsString('Motivo clinico', json_encode($this->contextsByTenant[42]['events']->listForDocument($newId)));
        $this->assertCount(2, $this->revisions()->history(42, $new));
        $this->assertSame($newId, $this->revisions()->create(42, $id, 'Double click'));
        $this->assertSame(2, $this->contextsByTenant[42]['documents']->countAllResults());
    }

    public function testAnExistingDraftCannotBeReassignedToAnotherProfile(): void
    {
        $draft=$this->documents()->saveDraftForTenant(42,$this->payload);
        $this->expectExceptionMessage('profilo del referto è già associato');
        $this->documents()->saveDraftForTenant(42,array_replace($this->payload,$draft,['id_fse_profile'=>8]));
    }

    public function testProfileRegimeMustMatchTheNewDocument(): void
    {
        $this->profiles=$this->getMockBuilder(FseProfileService::class)->disableOriginalConstructor()->onlyMethods(['runtimeProfileForTenant'])->getMock();
        $this->profiles->method('runtimeProfileForTenant')->willReturn($this->payload+['id_fse_profile'=>7,'care_regime'=>'SSR']);
        $this->expectExceptionMessage('Regime del referto diverso');
        $this->documents()->saveDraftForTenant(42,$this->payload+['administrative_request'=>'NOSSN']);
    }

    public function testDraftEditsKeepTheOriginalProfileSnapshot(): void
    {
        $draft=$this->documents()->saveDraftForTenant(42,$this->payload);
        $snapshot=$draft['profile_snapshot_json'];
        $this->assertSame(7,json_decode($snapshot,true)['id_fse_profile']);
        $this->assertStringNotContainsString('author_cf',$snapshot);
        $edited=$this->documents()->saveDraftForTenant(42,array_replace($this->payload,$draft,['report_text'=>'Synthetic revision of draft']));
        $this->assertSame($snapshot,$edited['profile_snapshot_json']);
    }

    public function testCdaRevisionContainsVerifiedParentAndStableRoot(): void
    {
        $original = $this->original();
        $id = $this->revisions()->create(42, (int) $original['id_fse_document'], 'Correzione');
        $data = $this->documents()->runtimeDocument(42, $id);
        $data['previous_document'] = $this->revisions()->parentForCda(42, $data);
        $cda = (new FseCdaRsaBuilderService())->build($data + $this->payload);
        $this->assertStringContainsString('<relatedDocument typeCode="RPLC">', $cda);
        $this->assertStringContainsString('extension="' . $original['document_unique_id'] . '"', $cda);
        $this->assertStringContainsString('<versionNumber value="2"/>', $cda);
    }

    public function testTenantCannotSeeOrCloneAnotherTenantsOriginal(): void
    {
        $original = $this->original();
        $this->assertSame([], $this->revisions()->history(43, $original));
        $this->expectExceptionMessage('originale consolidato');
        $this->revisions()->create(43, (int) $original['id_fse_document'], 'Cross tenant');
    }

    public function testSameLocalIdInTwoTenantsNeverMixesHistories(): void
    {
        $first = $this->original(42); $second = $this->original(43);
        $this->assertSame($first['id_fse_document'], $second['id_fse_document']);
        $a = $this->revisions()->create(42, (int) $first['id_fse_document'], 'A');
        $b = $this->revisions()->create(43, (int) $second['id_fse_document'], 'B');
        $this->assertNotSame($this->documents()->runtimeDocument(42, $a)['set_id'], $this->documents()->runtimeDocument(43, $b)['set_id']);
    }

    public function testStaleDraftSaveIsRejected(): void
    {
        $first = $this->documents()->saveDraftForTenant(42, $this->payload);
        $this->documents()->saveDraftForTenant(42, $first + ['service_description'=>'Replacement']);
        $this->expectExceptionMessage('La bozza è stata modificata');
        $this->documents()->saveDraftForTenant(42, $first);
    }

    public function testRevisionCannotChangePatient(): void
    {
        $original = $this->original(); $id = $this->revisions()->create(42, (int) $original['id_fse_document'], 'Correction');
        $data = $this->documents()->runtimeDocument(42, $id); $data['patient_cf'] = 'BNCLGU80A01H501U';
        $this->expectExceptionMessage('non può cambiare il paziente');
        $this->documents()->saveDraftForTenant(42, $data);
    }

    public function testRejectedSignedOriginalRemainsImmutable(): void
    {
        $original = $this->original(); $this->contextsByTenant[42]['documents']->update($original['id_fse_document'], ['local_state'=>'rejected']);
        $data = $this->documents()->runtimeDocument(42, $original['id_fse_document']);
        $this->assertFalse(FseDocumentLifecycle::isEditable($data));
        $this->expectExceptionMessage('non può essere sovrascritto');
        $this->documents()->saveDraftForTenant(42, $data);
    }

    public function testRevisionCannotBePublishedAsNewDocument(): void
    {
        $original = $this->original(); $id = $this->revisions()->create(42, (int) $original['id_fse_document'], 'Correction');
        $gateway = $this->createMock(FseGatewayClient::class); $gateway->expects($this->never())->method('create');
        $this->expectExceptionMessage('Non inviare come nuovo documento');
        (new FseDispatchService($this->contexts, $this->profiles, $gateway, $this->validation))->publish(42, $id);
    }

    public function testMissingOriginalArtifactBlocksCorrection(): void
    {
        $original = $this->original(); $this->artifacts = [];
        $this->expectExceptionMessage('Original artifact missing');
        $this->revisions()->create(42, (int) $original['id_fse_document'], 'Correction');
    }

    public function testRevisionMigrationIsIdempotent(): void
    {
        $original = $this->original();
        $migration = new \App\Database\Migrations\AddFseDocumentRevisions(Database::forge($this->contextsByTenant[42]['db']));
        $migration->up(); $migration->up();
        $this->assertSame($original, $this->contextsByTenant[42]['documents']->find($original['id_fse_document']));
        $this->assertArrayHasKey('uq_fse_previous_document', $this->contextsByTenant[42]['db']->getIndexData('fse_documents'));
    }

    public function testMissingRevisionAuditRollsBackChildWithoutChangingOriginal(): void
    {
        $original=$this->original();
        $db=$this->contextsByTenant[42]['db'];
        $events=$this->getMockBuilder(FseDocumentEventModel::class)->setConstructorArgs([$db])->onlyMethods(['insert'])->getMock();
        $events->method('insert')->willReturn(false);
        $this->contextsByTenant[42]['audit']=new FseAuditService($events);
        try {
            $this->revisions()->create(42,(int)$original['id_fse_document'],'Synthetic correction',1);
            $this->fail('Revision without audit accepted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('nessun originale',$e->getMessage());
        }
        $this->assertSame(1,$this->contextsByTenant[42]['documents']->countAllResults());
        $this->assertSame($original,$this->contextsByTenant[42]['documents']->find($original['id_fse_document']));
    }
}
