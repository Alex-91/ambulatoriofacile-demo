<?php

namespace Tests\Unit;

use App\Models\FseDocumentModel;
use App\Models\FseDocumentEventModel;
use App\Services\FseAuditService;
use App\Services\FseArtifactValidationService;
use App\Services\FseDispatchService;
use App\Services\FseDocumentService;
use App\Services\FseGatewayClient;
use App\Services\FseProfileService;
use App\Services\FseTenantDatabaseContextService;
use App\Services\FseTenantSchemaService;
use App\Services\FseStorageService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use PHPUnit\Framework\Attributes\DataProvider;

final class FseDispatchSafetyTest extends CIUnitTestCase
{
    private $fixtureDb;
    private string $file;
    private FseDocumentModel $documents;
    private FseDocumentEventModel $events;
    private FseTenantDatabaseContextService $contexts;
    private FseProfileService $profiles;
    private FseArtifactValidationService $validation;

    protected function setUp(): void
    {
        parent::setUp();
        // Explicit, non-shared in-memory DB: never read environment connection credentials.
        $this->fixtureDb = Database::connect(['DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => '', 'DBDebug' => true], false);
        $forge = Database::forge($this->fixtureDb);
        $fields = ['id_fse_document' => ['type' => 'INTEGER', 'auto_increment' => true]];
        foreach (['local_state', 'signed_pdf_path', 'signed_pdf_sha256', 'unsigned_pdf_path', 'validated_at', 'workflow_instance_id', 'gateway_state', 'gateway_http_status', 'last_gateway_message', 'last_response_json', 'trace_id', 'span_id', 'published_at', 'deleted_at', 'updated_by', 'created_at', 'updated_at'] as $field) {
            $fields[$field] = ['type' => 'TEXT', 'null' => true];
        }
        $fields['id_fse_profile'] = ['type'=>'INTEGER', 'default'=>7];
        $fields['profile_snapshot_json'] = ['type'=>'TEXT', 'null'=>true];
        $forge->addField($fields); $forge->addKey('id_fse_document', true); $forge->createTable('fse_documents');
        $fields = ['id_fse_event' => ['type' => 'INTEGER', 'auto_increment' => true]];
        foreach (['id_fse_document', 'event_type', 'event_level', 'message', 'context_json', 'created_by', 'created_at'] as $field) $fields[$field] = ['type' => 'TEXT', 'null' => true];
        $forge->addField($fields); $forge->addKey('id_fse_event', true); $forge->createTable('fse_document_events');
        $this->documents = new FseDocumentModel($this->fixtureDb);
        $this->events = new FseDocumentEventModel($this->fixtureDb);
        $this->contexts = $this->getMockBuilder(FseTenantDatabaseContextService::class)->disableOriginalConstructor()->onlyMethods(['resolveTenantContext'])->getMock();
        $this->contexts->method('resolveTenantContext')->with(42)->willReturn([
            'db' => $this->fixtureDb, 'documents' => $this->documents, 'events' => $this->events, 'audit' => new FseAuditService($this->events),
        ]);
        $this->profiles = $this->getMockBuilder(FseProfileService::class)->disableOriginalConstructor()->onlyMethods(['runtimeProfileForTenant'])->getMock();
        $this->profiles->method('runtimeProfileForTenant')->with(42)->willReturn(['is_enabled' => 1, 'access_mode' => 'gateway']);
        $this->validation = $this->createMock(FseArtifactValidationService::class);
        $this->validation->method('assertForDispatch')->willReturn([]);
        $this->file = tempnam(sys_get_temp_dir(), 'fse-dispatch-');
        file_put_contents($this->file, '%PDF-synthetic-offline-test');
    }

    protected function tearDown(): void
    {
        $this->fixtureDb->close();
        unlink($this->file);
        parent::tearDown();
    }

    private function document(string $state): int
    {
        return (int) $this->documents->insert(['id_fse_profile'=>7, 'profile_snapshot_json'=>'{"id_fse_profile":7}', 'local_state' => $state, 'signed_pdf_path' => $this->file, 'unsigned_pdf_path' => $this->file,
            'validated_at' => date('Y-m-d H:i:s', time() - 60), 'workflow_instance_id' => 'previous-validation-workflow']);
    }

    public function testPublicationTimeoutKeepsLockAndSavesCartIdInAudit(): void
    {
        $id = $this->document('signed');
        $gateway = $this->createMock(FseGatewayClient::class);
        $gateway->expects($this->once())->method('create')->willReturn(['ok' => false, 'outcome_uncertain' => true,
            'payload' => [], 'http_status' => 504, 'message' => 'Synthetic timeout', 'x_cart_id' => 'cart-test-42']);
        $dispatch = new FseDispatchService($this->contexts, $this->profiles, $gateway, $this->validation);
        $result = $dispatch->publish(42, $id);
        $this->assertSame('publishing', $result['document']['local_state']);
        $this->assertNull($result['document']['workflow_instance_id']);
        $this->assertSame('PUBLISH_UNCERTAIN', $result['document']['gateway_state']);
        $saved = json_decode($result['document']['last_response_json'], true);
        $this->assertSame('cart-test-42', $saved['transport']['x_cart_id']);
        $events = $this->events->listForDocument($id);
        $this->assertCount(1, $events);
        $this->assertSame('cart-test-42', json_decode($events[0]['context_json'], true)['x_cart_id']);
        $this->expectExceptionMessage('validato e poi firmato');
        $dispatch->publish(42, $id); // Must not issue a duplicate network write.
    }

    private function expiredSignedDocument(): int
    {
        $id = $this->document('signed');
        $this->documents->update($id, ['validated_at' => '2026-10-01 10:00:00', 'signed_pdf_sha256' => hash_file('sha256', $this->file)]);
        return $id;
    }

    public function testExpiredPublicationNeverContactsGateway(): void
    {
        $id = $this->expiredSignedDocument();
        $gateway = $this->createMock(FseGatewayClient::class);
        $gateway->expects($this->never())->method('create');
        $dispatch = new FseDispatchService($this->contexts, $this->profiles, $gateway, $this->validation, static fn () => strtotime('2026-10-07 10:00:00'));
        try {
            $dispatch->publish(42, $id);
            $this->fail('Expired validation accepted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('5 giorni', $e->getMessage());
            $this->assertSame('signed', $this->documents->find($id)['local_state']);
        }
    }

    public function testExpiryDuringLocalChecksRestoresSignedWithoutSending(): void
    {
        $id = $this->expiredSignedDocument();
        $now = strtotime('2026-10-06 09:59:59');
        $gateway = $this->createMock(FseGatewayClient::class);
        $gateway->expects($this->never())->method('create');
        $validator = $this->createMock(FseArtifactValidationService::class);
        $validator->method('assertForDispatch')->willReturnCallback(static function () use (&$now) { $now += 2; return []; });
        $dispatch = new FseDispatchService($this->contexts, $this->profiles, $gateway, $validator, static function () use (&$now) { return $now; });
        try {
            $dispatch->publish(42, $id);
            $this->fail('Local checks crossed deadline');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('5 giorni', $e->getMessage());
            $this->assertSame('signed', $this->documents->find($id)['local_state']);
        }
    }

    public function testRevalidationPreservesSignedPdfAndRequiresSeparatePublication(): void
    {
        $id = $this->expiredSignedDocument();
        $before = $this->documents->find($id);
        $now = strtotime('2026-10-07 10:00:00');
        $started = $now;
        $gateway = $this->createMock(FseGatewayClient::class);
        $validator = $this->createMock(FseArtifactValidationService::class);
        $validator->expects($this->exactly(2))->method('assertForDispatch')->with(42, $id, $this->isType('array'), true)->willReturn([]);
        $gateway->expects($this->once())->method('validate')->with($this->isType('array'), $this->isType('array'), $this->file)
            ->willReturnCallback(function () use ($id, &$now) {
                $this->assertSame('validating', $this->documents->find($id)['local_state']);
                $this->assertNull($this->documents->find($id)['validated_at']);
                $now += 120;
                return ['ok' => true, 'http_status' => 201, 'payload' => ['workflowInstanceId' => 'REVALIDATED.NEW']];
            });
        $gateway->expects($this->once())->method('create')->willReturn(['ok' => true, 'http_status' => 201, 'payload' => ['workflowInstanceId' => 'PUBLISH.NEW']]);
        $dispatch = new FseDispatchService($this->contexts, $this->profiles, $gateway, $validator, static function () use (&$now) { return $now; });
        $result = $dispatch->validate(42, $id, 9);
        $this->assertSame('signed', $result['document']['local_state']);
        $this->assertNull($result['document']['published_at']);
        $this->assertSame(date('Y-m-d H:i:s', $started), $result['document']['validated_at']);
        $this->assertSame('REVALIDATED.NEW', $result['document']['workflow_instance_id']);
        foreach (['signed_pdf_path', 'signed_pdf_sha256', 'unsigned_pdf_path', 'profile_snapshot_json'] as $field) {
            $this->assertSame($before[$field], $result['document'][$field]);
        }
        $this->assertSame($before['signed_pdf_sha256'], hash_file('sha256', $this->file));
        $this->assertStringContainsString('azione esplicita', $result['message']);
        $events = $this->events->listForDocument($id);
        $this->assertCount(2, $events);
        $this->assertContains('gateway_revalidation_started', array_column($events, 'event_type'));
        $this->assertContains('gateway_revalidation', array_column($events, 'event_type'));
        $this->assertSame('publishing', $dispatch->publish(42, $id)['document']['local_state']);
    }

    public static function revalidationOutcomes(): array
    {
        return [[400, false, false, [], 'signed'], [504, false, true, [], 'validating'],
            [200, true, false, ['workflowInstanceId' => 'UNEXPECTED'], 'validating'],
            [201, true, false, [], 'validating']];
    }

    #[DataProvider('revalidationOutcomes')]
    public function testFailedRevalidationCannotPublishOrRepeatUncertainWrites(int $http, bool $ok, bool $uncertain, array $payload, string $state): void
    {
        $id = $this->expiredSignedDocument();
        $gateway = $this->createMock(FseGatewayClient::class);
        $gateway->expects($this->once())->method('validate')->willReturn(['ok' => $ok, 'http_status' => $http, 'outcome_uncertain' => $uncertain, 'payload' => $payload]);
        $gateway->expects($this->never())->method('create');
        $dispatch = new FseDispatchService($this->contexts, $this->profiles, $gateway, $this->validation, static fn () => strtotime('2026-10-07 10:00:00'));
        $result = $dispatch->validate(42, $id);
        $this->assertSame($state, $result['document']['local_state']);
        $this->assertNull($result['document']['validated_at']);
        $this->assertSame($this->file, $result['document']['signed_pdf_path']);
        try { $dispatch->publish(42, $id); $this->fail('Failed validation published'); } catch (\RuntimeException $e) { $this->assertNotEmpty($e->getMessage()); }
        if ($state === 'validating') {
            $this->expectException(\RuntimeException::class);
            $dispatch->validate(42, $id);
        }
    }

    public function testRevalidationTransportExceptionKeepsLockAndInvalidatesOldStamp(): void
    {
        $id = $this->expiredSignedDocument();
        $gateway = $this->createMock(FseGatewayClient::class);
        $gateway->expects($this->once())->method('validate')->willThrowException(new \RuntimeException('PRIVATE transport failure'));
        $dispatch = new FseDispatchService($this->contexts, $this->profiles, $gateway, $this->validation, static fn () => strtotime('2026-10-07 10:00:00'));
        try { $dispatch->validate(42, $id); $this->fail('Expected uncertain outcome'); } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString('PRIVATE', $e->getMessage());
            $this->assertSame('validating', $this->documents->find($id)['local_state']);
            $this->assertNull($this->documents->find($id)['validated_at']);
            $this->assertNull($this->documents->find($id)['workflow_instance_id']);
        }
        $this->expectException(\RuntimeException::class);
        $dispatch->validate(42, $id);
    }

    public function testInvalidSignedArtifactPreventsRevalidationAndPreservesEvidence(): void
    {
        $id = $this->expiredSignedDocument();
        $before = $this->documents->find($id);
        $gateway = $this->createMock(FseGatewayClient::class);
        $gateway->expects($this->never())->method('validate');
        $validator = $this->createMock(FseArtifactValidationService::class);
        $validator->expects($this->once())->method('assertForDispatch')->with(42, $id, $this->isType('array'), true)->willThrowException(new \RuntimeException('Firma non valida'));
        try {
            (new FseDispatchService($this->contexts, $this->profiles, $gateway, $validator, static fn () => strtotime('2026-10-07 10:00:00')))->validate(42, $id);
            $this->fail('Invalid artifact sent');
        } catch (\RuntimeException $e) {
            $this->assertSame('Firma non valida', $e->getMessage());
            foreach (['local_state', 'signed_pdf_path', 'signed_pdf_sha256', 'validated_at', 'workflow_instance_id'] as $field) {
                $this->assertSame($before[$field], $this->documents->find($id)[$field]);
            }
        }
    }

    public function testConcurrentRevalidationInvalidatesStalePublicationSnapshot(): void
    {
        $id = $this->document('signed');
        $profiles = $this->createMock(FseProfileService::class);
        $profiles->method('runtimeProfileForDocument')->willReturnCallback(function () use ($id) {
            $this->documents->update($id, ['workflow_instance_id' => 'CONCURRENT.REVALIDATION']);
            return ['is_enabled' => 1, 'access_mode' => 'gateway'];
        });
        $gateway = $this->createMock(FseGatewayClient::class);
        $gateway->expects($this->never())->method('create');
        $this->expectExceptionMessage('altra richiesta');
        (new FseDispatchService($this->contexts, $profiles, $gateway, $this->validation))->publish(42, $id);
    }

    public function testDefiniteRevalidationRejectionCanBeRetriedExplicitlyWithoutEditing(): void
    {
        $id = $this->expiredSignedDocument();
        $gateway = $this->createMock(FseGatewayClient::class);
        $gateway->expects($this->exactly(2))->method('validate')->willReturnOnConsecutiveCalls(
            ['ok' => false, 'http_status' => 403, 'payload' => []],
            ['ok' => true, 'http_status' => 201, 'payload' => ['workflowInstanceId' => 'RETRY.VALIDATED']]
        );
        $gateway->expects($this->never())->method('create');
        $dispatch = new FseDispatchService($this->contexts, $this->profiles, $gateway, $this->validation, static fn () => strtotime('2026-10-07 10:00:00'));
        $this->assertFalse($dispatch->validate(42, $id)['ok']);
        $result = $dispatch->validate(42, $id);
        $this->assertTrue($result['ok']);
        $this->assertSame('signed', $result['document']['local_state']);
        $this->assertSame($this->file, $result['document']['signed_pdf_path']);
        $this->assertSame('RETRY.VALIDATED', $result['document']['workflow_instance_id']);
    }

    public function testFreshSignedDocumentCannotBeRevalidated(): void
    {
        $id = $this->document('signed');
        $gateway = $this->createMock(FseGatewayClient::class);
        $gateway->expects($this->never())->method('validate');
        $this->expectException(\RuntimeException::class);
        (new FseDispatchService($this->contexts, $this->profiles, $gateway, $this->validation))->validate(42, $id);
    }

    public static function blockedProfiles(): array
    {
        return [[['is_enabled' => 1, 'access_mode' => 'toscana_privati'], 'Toscana'],
            [['is_enabled' => 0, 'access_mode' => 'gateway'], 'disattivato']];
    }

    #[DataProvider('blockedProfiles')]
    public function testRevalidationDoesNotBypassProfileGates(array $profile, string $message): void
    {
        $id = $this->expiredSignedDocument();
        $profiles = $this->createMock(FseProfileService::class);
        $profiles->method('runtimeProfileForDocument')->willReturn($profile);
        $gateway = $this->createMock(FseGatewayClient::class);
        $gateway->expects($this->never())->method('validate');
        $this->expectExceptionMessage($message);
        (new FseDispatchService($this->contexts, $profiles, $gateway, $this->validation, static fn () => strtotime('2026-10-07 10:00:00')))->validate(42, $id);
    }

    public function testRevalidationLockBlocksConcurrentValidationAndPublication(): void
    {
        $id = $this->expiredSignedDocument();
        $gateway = $this->createMock(FseGatewayClient::class);
        $dispatch = new FseDispatchService($this->contexts, $this->profiles, $gateway, $this->validation, static fn () => strtotime('2026-10-07 10:00:00'));
        $gateway->expects($this->never())->method('create');
        $gateway->expects($this->once())->method('validate')->willReturnCallback(function () use ($id, $dispatch) {
            foreach (['validate', 'publish'] as $method) {
                try { $dispatch->$method(42, $id); $this->fail('Concurrent write accepted'); }
                catch (\RuntimeException $e) { $this->assertNotEmpty($e->getMessage()); }
            }
            return ['ok' => true, 'http_status' => 201, 'payload' => ['workflowInstanceId' => 'LOCKED.VALIDATED']];
        });
        $this->assertSame('signed', $dispatch->validate(42, $id)['document']['local_state']);
    }

    public function testConcurrentRevalidationInvalidatesStaleValidationSnapshot(): void
    {
        $id = $this->expiredSignedDocument();
        $profiles = $this->createMock(FseProfileService::class);
        $profiles->method('runtimeProfileForDocument')->willReturnCallback(function () use ($id) {
            $this->documents->update($id, ['validated_at' => '2026-10-07 10:00:00', 'workflow_instance_id' => 'NEW.VALIDATION']);
            return ['is_enabled' => 1, 'access_mode' => 'gateway'];
        });
        $gateway = $this->createMock(FseGatewayClient::class);
        $gateway->expects($this->never())->method('validate');
        try {
            (new FseDispatchService($this->contexts, $profiles, $gateway, $this->validation, static fn () => strtotime('2026-10-07 10:00:00')))->validate(42, $id);
            $this->fail('Stale snapshot accepted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('altra richiesta', $e->getMessage());
            $this->assertSame('NEW.VALIDATION', $this->documents->find($id)['workflow_instance_id']);
            $this->assertSame('2026-10-07 10:00:00', $this->documents->find($id)['validated_at']);
        }
    }

    public function testHttpRejectionAlsoSavesCartId(): void
    {
        $id = $this->document('signed');
        $gateway = $this->createMock(FseGatewayClient::class);
        $gateway->method('create')->willReturn(['ok' => false, 'payload' => ['detail' => 'Rejected'], 'http_status' => 400, 'message' => 'Rejected', 'x_cart_id' => 'cart-error']);
        $result = (new FseDispatchService($this->contexts, $this->profiles, $gateway, $this->validation))->publish(42, $id);
        $this->assertSame('rejected', $result['document']['local_state']);
        $this->assertSame('cart-error', json_decode($this->events->listForDocument($id)[0]['context_json'], true)['x_cart_id']);
    }

    public function testValidationStatusCannotMarkDocumentPublished(): void
    {
        $id = $this->document('validated');
        $gateway = $this->createMock(FseGatewayClient::class);
        $gateway->method('status')->willReturn(['ok' => true, 'http_status' => 200, 'payload' => [['eventStatus' => 'SUCCESS']]]);
        $result = (new FseDispatchService($this->contexts, $this->profiles, $gateway))->refreshStatus(42, $id);
        $this->assertSame('validated', $result['document']['local_state']);
        $this->assertNull($result['document']['published_at']);
    }

    public function testFailedArtifactCheckNeverContactsGateway(): void
    {
        $id = $this->document('signed');
        $gateway = $this->createMock(FseGatewayClient::class);
        $gateway->expects($this->never())->method('create');
        $validation = $this->createMock(FseArtifactValidationService::class);
        $validation->method('assertForDispatch')->willThrowException(new \RuntimeException('Firma non valida'));
        try {
            (new FseDispatchService($this->contexts, $this->profiles, $gateway, $validation))->publish(42, $id);
            $this->fail('Invalid signature must stop publication');
        } catch (\RuntimeException $e) {
            $this->assertSame('Firma non valida', $e->getMessage());
            $this->assertSame('signed', $this->documents->find($id)['local_state']);
        }
    }

    public function testDeleteStatusUsesPendingOperationEvenAfterIntermediateStatus(): void
    {
        $id = $this->document('deleting');
        $this->documents->update($id, ['gateway_state' => 'IN_PROGRESS']);
        $gateway = $this->createMock(FseGatewayClient::class);
        $gateway->method('status')->willReturn(['ok' => true, 'http_status' => 200, 'payload' => [['eventType'=>'INI_DELETE', 'workflowInstanceId'=>'previous-validation-workflow', 'eventStatus' => 'SUCCESS']]]);
        $result = (new FseDispatchService($this->contexts, $this->profiles, $gateway))->refreshStatus(42, $id);
        $this->assertSame('deleted', $result['document']['local_state']);
        $this->assertNotEmpty($result['document']['deleted_at']);
    }

    public static function protectedStates(): array
    {
        return [['preparing'], ['checking_signature'], ['validated'], ['signed'], ['validating'], ['publishing'], ['deleting'], ['published'], ['rejected'], ['deleted']];
    }

    public static function validationOutcomes(): array
    {
        return [[400,false,false,'rejected','INVALID_REQUEST'],[403,false,false,'rejected','ACCESS_DENIED'],
            [422,false,false,'rejected','CDA_SEMANTIC'],[408,false,true,'validating','OUTCOME_UNCERTAIN'],
            [504,false,true,'validating','OUTCOME_UNCERTAIN'],[200,true,false,'validating','OUTCOME_UNCERTAIN'],
            [201,true,false,'validated','GATEWAY_ACCEPTED']];
    }

    #[DataProvider('validationOutcomes')]
    public function testValidationFailuresAndUnexpectedSuccessCannotUnlockDuplicateWrites(int $http, bool $ok, bool $uncertain, string $state, string $code): void
    {
        $id=$this->document('ready_to_validate');
        $this->documents->update($id,['signed_pdf_path'=>null,'validated_at'=>null,'trace_id'=>'OLD.TRACE','span_id'=>'OLD.SPAN']);
        $gateway=$this->createMock(FseGatewayClient::class);
        $gateway->expects($this->once())->method('validate')->willReturn(['ok'=>$ok,'http_status'=>$http,
            'outcome_uncertain'=>$uncertain,'message'=>'PRIVATE_PATIENT','payload'=>$ok ? ['workflowInstanceId'=>'NEW.WORKFLOW','traceId'=>'NEW.TRACE'] : ['detail'=>'PRIVATE_PATIENT']]);
        $dispatch=new FseDispatchService($this->contexts,$this->profiles,$gateway,$this->validation);
        $result=$dispatch->validate(42,$id);
        $this->assertSame($state,$result['document']['local_state']);
        $this->assertSame($code,$result['feedback']['code']);
        $this->assertSame($ok ? 'NEW.TRACE' : null,$result['document']['trace_id']);
        $this->assertNull($result['document']['span_id']);
        $this->assertStringNotContainsString('PRIVATE_PATIENT',json_encode($result));
        if ($state==='validating') {
            $this->assertFalse($result['ok']);
            $this->assertNull($result['document']['validated_at']);
            $this->expectExceptionMessage('stato previsto');
            $dispatch->validate(42,$id);
        }
    }

    public function testInvalidUploadRestoresValidatedStateAndDoesNotWriteFile(): void
    {
        $id = $this->document('validated');
        $validation = $this->createMock(FseArtifactValidationService::class);
        $validation->method('storedArtifact')->willReturn('synthetic-artifact');
        $validation->expects($this->once())->method('check')->willReturnCallback(function () use ($id) {
            $this->assertSame('checking_signature', $this->documents->find($id)['local_state']);
            throw new \RuntimeException('SIGNATURE_CRYPTO_INVALID');
        });
        $storage = $this->createMock(FseStorageService::class);
        $storage->expects($this->never())->method('store');
        $service = new FseDocumentService($this->contexts, $this->profiles, storage: $storage, validation: $validation);
        try {
            $service->acceptSignedPdf(42, $id, '%PDF-' . str_repeat('synthetic', 20));
            $this->fail('Bad signature accepted');
        } catch (\RuntimeException $e) {
            $this->assertSame('SIGNATURE_CRYPTO_INVALID', $e->getMessage());
            $this->assertSame('validated', $this->documents->find($id)['local_state']);
            $this->assertSame($this->file, $this->documents->find($id)['signed_pdf_path']);
        }
    }

    public function testValidUploadStoresOnlyAfterChecksAndRecordsEvidence(): void
    {
        $id = $this->document('validated');
        $validation = $this->createMock(FseArtifactValidationService::class);
        $validation->method('storedArtifact')->willReturn('synthetic-artifact');
        $validation->expects($this->once())->method('check')->willReturnCallback(function () use ($id) {
            $this->assertSame('checking_signature', $this->documents->find($id)['local_state']);
            return ['signature' => 'valid', 'pdfa' => '3b'];
        });
        $storage = $this->createMock(FseStorageService::class);
        $storage->expects($this->once())->method('store')->willReturn('synthetic-signed.pdf');
        $service = new FseDocumentService($this->contexts, $this->profiles, storage: $storage, validation: $validation);
        $result = $service->acceptSignedPdf(42, $id, '%PDF-' . str_repeat('synthetic', 20));
        $this->assertSame('signed', $result['local_state']);
        $event = $this->events->listForDocument($id)[0];
        $this->assertSame('valid', json_decode($event['context_json'], true)['signature']);
    }

    public function testFailedDeletionDoesNotMakePublishedOriginalEditable(): void
    {
        $id = $this->document('deleting');
        $gateway = $this->createMock(FseGatewayClient::class);
        $gateway->method('status')->willReturn(['ok' => true, 'http_status' => 200, 'payload' => [['eventType'=>'INI_DELETE', 'workflowInstanceId'=>'previous-validation-workflow', 'eventStatus' => 'BLOCKING_ERROR']]]);
        $result = (new FseDispatchService($this->contexts, $this->profiles, $gateway))->refreshStatus(42, $id);
        $this->assertSame('published', $result['document']['local_state']);
        $this->assertNull($result['document']['deleted_at']);
    }

    public function testTransportExceptionKeepsLockInsteadOfAllowingDuplicate(): void
    {
        $id = $this->document('signed');
        $this->documents->update($id,['trace_id'=>'OLD.TRACE','span_id'=>'OLD.SPAN','gateway_http_status'=>201]);
        $gateway = $this->createMock(FseGatewayClient::class);
        $gateway->expects($this->once())->method('create')->willThrowException(new \RuntimeException('PRIVATE remote exception'));
        try {
            (new FseDispatchService($this->contexts, $this->profiles, $gateway, $this->validation))->publish(42, $id);
            $this->fail('Expected uncertain outcome');
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString('PRIVATE', $e->getMessage());
            $this->assertSame('publishing', $this->documents->find($id)['local_state']);
            $this->assertNull($this->documents->find($id)['workflow_instance_id']);
            $this->assertNull($this->documents->find($id)['trace_id']);
            $this->assertNull($this->documents->find($id)['span_id']);
            $this->assertSame(0,(int)$this->documents->find($id)['gateway_http_status']);
        }
    }

    public function testDelayedStatusCannotOverwriteConcurrentDeletion(): void
    {
        $id = $this->document('publishing');
        $gateway = $this->createMock(FseGatewayClient::class);
        $gateway->method('status')->willReturnCallback(function () use ($id) {
            $this->documents->update($id, ['local_state'=>'deleting', 'workflow_instance_id'=>'DELETE.NEW']);
            return ['ok'=>true, 'http_status'=>200, 'payload'=>[['eventType'=>'UAR_FINAL_STATUS', 'workflowInstanceId'=>'previous-validation-workflow', 'eventStatus'=>'SUCCESS']]];
        });
        try {
            (new FseDispatchService($this->contexts, $this->profiles, $gateway))->refreshStatus(42, $id);
            $this->fail('Stale poll should be rejected');
        } catch (\RuntimeException $e) {
            $this->assertSame('deleting', $this->documents->find($id)['local_state']);
            $this->assertSame('DELETE.NEW', $this->documents->find($id)['workflow_instance_id']);
        }
    }

    public function testAcceptedResponseWithoutWorkflowIsUncertain(): void
    {
        $id = $this->document('signed');
        $gateway = $this->createMock(FseGatewayClient::class);
        $gateway->method('create')->willReturn(['ok'=>true, 'http_status'=>200, 'payload'=>[]]);
        $result = (new FseDispatchService($this->contexts, $this->profiles, $gateway, $this->validation))->publish(42, $id);
        $this->assertFalse($result['ok']); $this->assertTrue($result['outcome_uncertain']);
        $this->assertSame('publishing', $result['document']['local_state']);
    }

    #[DataProvider('protectedStates')]
    public function testArtifactRegenerationCannotOverwriteProtectedDocument(string $state): void
    {
        $id = $this->document($state);
        $schema = $this->getMockBuilder(FseTenantSchemaService::class)->disableOriginalConstructor()->getMock();
        $service = new FseDocumentService($this->contexts, $this->profiles, schema: $schema);
        $this->expectExceptionMessage($state === 'deleted' ? 'Referto FSE non disponibile.' : 'Non è possibile rigenerare');
        $service->prepareForSignature(42, $id);
    }
}
