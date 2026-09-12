<?php
namespace Tests\Unit;

use App\Services\{BillingDocumentService, BillingReportService, TsDispatchService, TsProfileService,
    TsDocumentValidationService, TsPayloadBuilderService, TsSoapClientFactory, TsStorageService,
    TsAuditService, TsSupportLogService, TsSupportLogSession, TsTenantDatabaseContextService};
use App\Models\TsDocumentModel;
use CodeIgniter\Test\CIUnitTestCase;

final class AdministrativeClosureTest extends CIUnitTestCase
{
    public function testCsvTreatsPatientAndServiceContentAsText(): void
    {
        $reports = new BillingReportService(documents: $this->getMockBuilder(BillingDocumentService::class)->disableOriginalConstructor()->getMock());
        $csv = $reports->buildAccountantCsv(['documents' => [[
            'document_number' => '=1+1', 'patient_name' => "\t@SUM(1)",
            'service_descriptions' => ['+CMD'], 'notes' => '-1+2', 'amount_total' => 123.45,
        ]]]);
        $this->assertStringContainsString("'=1+1", $csv);
        $this->assertStringContainsString("'\t@SUM(1)", $csv);
        $this->assertStringContainsString("'+CMD", $csv);
        $this->assertStringContainsString("'-1+2", $csv);
        $this->assertStringContainsString('123,45', $csv);
    }

    public function testOnlyOneDispatchCanClaimTheSameSnapshot(): void
    {
        $db = \Config\Database::connect(['DBDriver'=>'SQLite3', 'database'=>':memory:', 'DBPrefix'=>'', 'DBDebug'=>true], false);
        $db->query('CREATE TABLE ts_documents (id_ts_document INTEGER PRIMARY KEY, local_state TEXT, amount_total TEXT, updated_at TEXT)');
        $db->table('ts_documents')->insert(['id_ts_document'=>1,'local_state'=>'ready','amount_total'=>'12.00']);
        $model = new TsDocumentModel($db);
        $snapshot = $model->find(1);
        $this->assertTrue($model->updateEditableSnapshot(1, $snapshot, ['local_state'=>'sending']));
        $this->assertFalse($model->updateEditableSnapshot(1, $snapshot, ['local_state'=>'sending']));
        $this->assertFalse($model->updateEditableSnapshot(1, $snapshot, ['local_state'=>'draft','amount_total'=>'99.00']));
        $this->assertSame('12.00', $model->find(1)['amount_total']);
        $db->close();
    }

    public function testTimeoutDoesNotMakeDocumentSendableAgain(): void
    {
        [$service, $read] = $this->dispatchFixture(new \SoapFault('HTTP', 'Timeout'));
        $result = $service->dispatchDocument(42, 1, 7);
        $record = $read();
        $this->assertSame('error', $result['status']);
        $this->assertSame('sending', $record['local_state']);
        $this->assertSame('TS_OUTCOME_UNKNOWN', $record['last_error_code']);
        $this->expectException(\RuntimeException::class);
        $service->dispatchDocument(42, 1, 7);
    }

    public function testIncompleteReplyCannotBeMarkedAccepted(): void
    {
        [$service, $read] = $this->dispatchFixture(['esitoChiamata'=>'0']);
        $service->dispatchDocument(42, 1, 7);
        $record = $read();
        $this->assertSame('sending', $record['local_state']);
        $this->assertSame('TS_OUTCOME_UNKNOWN', $record['last_error_code']);
    }

    public function testAcceptedReplyPersistsProtocol(): void
    {
        [$service, $read] = $this->dispatchFixture(['esitoChiamata'=>'0','protocollo'=>'SYNTHETIC123']);
        $result = $service->dispatchDocument(42, 1, 7);
        $record = $read();
        $this->assertSame('ok', $result['status']);
        $this->assertSame('sent', $record['local_state']);
        $this->assertSame('SYNTHETIC123', $record['ts_protocol']);
    }

    public function testExplicitRejectionCanBeCorrected(): void
    {
        [$service, $read] = $this->dispatchFixture(['esitoChiamata'=>'ko']);
        $service->dispatchDocument(42, 1, 7);
        $record = $read();
        $this->assertSame('ready', $record['local_state']);
        $this->assertSame('TS_SEND_FAILED', $record['last_error_code']);
    }

    private function dispatchFixture(array|\Throwable $reply): array
    {
        $record = ['id_ts_document'=>1,'id_ts_profile'=>1,'local_state'=>'ready','source_type'=>'manual'];
        $model = $this->getMockBuilder(TsDocumentModel::class)->disableOriginalConstructor()->onlyMethods(['find','update','updateEditableSnapshot','persistAccepted'])->getMock();
        $model->method('find')->willReturnCallback(static function () use (&$record) { return $record; });
        $model->method('update')->willReturnCallback(static function ($id, $values) use (&$record) { $record = array_merge($record, $values); return true; });
        $model->method('persistAccepted')->willReturnCallback(static function ($id, $values) use (&$record) { $record = array_merge($record, $values); return true; });
        $model->method('updateEditableSnapshot')->willReturnCallback(static function ($id, $expected, $values) use (&$record) {
            if ($record !== $expected || $record['local_state'] !== 'ready') return false;
            $record = array_merge($record, $values); return true;
        });
        $profile = $this->getMockBuilder(TsProfileService::class)->disableOriginalConstructor()->getMock();
        $profile->method('findProfileById')->willReturn(['id_ts_profile'=>1,'is_enabled'=>1,'environment'=>'test']);
        $validation = $this->getMockBuilder(TsDocumentValidationService::class)->disableOriginalConstructor()->getMock();
        $validation->method('validateDraft')->willReturn(['valid'=>true,'errors'=>[],'warnings'=>[]]);
        $payload = $this->getMockBuilder(TsPayloadBuilderService::class)->disableOriginalConstructor()->getMock();
        $soap = $this->getMockBuilder(\SoapClient::class)->disableOriginalConstructor()->getMock();
        if ($reply instanceof \Throwable) $soap->expects($this->once())->method('__soapCall')->willThrowException($reply);
        else $soap->expects($this->once())->method('__soapCall')->willReturn((object) $reply);
        $factory = $this->getMockBuilder(TsSoapClientFactory::class)->disableOriginalConstructor()->getMock();
        $factory->method('createForDocumentProfile')->willReturn($soap);
        $audit = $this->getMockBuilder(TsAuditService::class)->disableOriginalConstructor()->getMock();
        $audit->method('record')->willReturn(true);
        $support = $this->getMockBuilder(TsSupportLogService::class)->disableOriginalConstructor()->getMock();
        $support->method('startOperation')->willReturn($this->getMockBuilder(TsSupportLogSession::class)->disableOriginalConstructor()->getMock());
        $context = $this->getMockBuilder(TsTenantDatabaseContextService::class)->disableOriginalConstructor()->getMock();
        $context->method('resolveTenantContext')->willReturn(['documents'=>$model,'audit'=>$audit]);
        $config = new \App\Config\TsBilling(); $config->storeDebugArtifacts = false; $config->documentResponseOkValues = ['0'];
        $service = new TsDispatchService($model, $profile, $validation, $payload, $factory,
            $this->getMockBuilder(TsStorageService::class)->disableOriginalConstructor()->getMock(), $audit, $support, $context, $config);
        return [$service, static function () use (&$record) { return $record; }];
    }
}
