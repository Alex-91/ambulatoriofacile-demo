<?php

use App\Services\BillingTenantDatabaseContextService;
use App\Services\BillingTsBridgeService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class BillingTsBridgeServiceTest extends CIUnitTestCase
{
    public function testPreparationUsesProfileNatureAndKeepsInvoiceTextSeparate(): void
    {
        $previousKey=getenv('TS_BILLING_SECRET_KEY');putenv('TS_BILLING_SECRET_KEY=synthetic-unit-test-only');
        $db = \Config\Database::connect(['DBDriver'=>'SQLite3','database'=>':memory:','DBPrefix'=>'','DBDebug'=>true], false);
        try {
            foreach ([\App\Models\BillingDocumentModel::class=>['billing_documents','id_billing_document'], \App\Models\TsDocumentModel::class=>['ts_documents','id_ts_document']] as $class=>[$table,$key]) {
                $fields=(new \ReflectionClass($class))->getDefaultProperties()['allowedFields'];
                $fields=array_diff(array_unique(array_merge($fields,['created_at','updated_at'])),[$key]);
                $db->query("CREATE TABLE $table ($key INTEGER PRIMARY KEY AUTOINCREMENT,".implode(',',array_map(static fn($f)=>$f.' TEXT',$fields)).')');
            }
            $billing=new \App\Models\BillingDocumentModel($db);$ts=new \App\Models\TsDocumentModel($db);
            $billingContext=$this->getMockBuilder(BillingTenantDatabaseContextService::class)->disableOriginalConstructor()->onlyMethods(['resolveTenantContext'])->getMock();
            $billingContext->method('resolveTenantContext')->willReturn(['db'=>$db,'documents'=>$billing]);
            $tsContext=$this->getMockBuilder(\App\Services\TsTenantDatabaseContextService::class)->disableOriginalConstructor()->onlyMethods(['resolveTenantContext'])->getMock();
            $tsContext->method('resolveTenantContext')->willReturn(['db'=>$db,'documents'=>$ts]);
            $profiles=$this->getMockBuilder(\App\Services\TsProfileService::class)->disableOriginalConstructor()->onlyMethods(['getDefaultProfileForTenant'])->getMock();
            $profiles->method('getDefaultProfileForTenant')->willReturn(['id_ts_profile'=>1,'is_enabled'=>1,'owner_piva'=>'12345678903','metadata_json'=>json_encode(['document_defaults'=>['vat_nature_code'=>'N4']])]);
            $settings=$this->getMockBuilder(\App\Services\BillingDocumentSettingsService::class)->disableOriginalConstructor()->onlyMethods(['resolveTenantSettings'])->getMock();
            $settings->method('resolveTenantSettings')->willReturn(['config'=>[]]);
            $dispatch=$this->getMockBuilder(\App\Services\TsDispatchService::class)->disableOriginalConstructor()->onlyMethods(['dispatchDocument'])->getMock();
            $dispatch->expects($this->never())->method('dispatchDocument');
            $service=new BillingTsBridgeService(billingContext:$billingContext,tsContext:$tsContext,billingSettings:$settings,tsProfiles:$profiles,dispatch:$dispatch);
            foreach ([['0.00','N4','ready',null],['22.00','','ready',22.0],['22.00','ART. 10','ready',22.0]] as $i=>[$rate,$nature,$state,$expectedRate]) {
                $id=(int)$billing->insert(['id_client'=>101,'document_number'=>'SYNTHETIC-'.$i,'document_type'=>'invoice','issue_date'=>'2026-09-12','payment_date'=>'2026-09-12','payment_status'=>'paid','patient_name'=>'SYNTHETIC','patient_tax_code'=>'VRDLGU70A01H501O','local_state'=>'issued','ts_sync_enabled'=>1,'ts_expense_type_code'=>'SP','payment_method'=>'bank_transfer','amount_total'=>100,'vat_rate'=>$rate,'vat_nature'=>$nature]);
                $result=$service->prepareBillingDocumentForTs(42,$id,1);
                $this->assertSame($state,$result['status'],json_encode($result['validation']));
                $saved=$result['ts_document'];
                $this->assertSame($expectedRate,$saved['vat_rate']===null ? null : (float)$saved['vat_rate']);
                $this->assertSame($expectedRate === null ? 'N4' : null, $saved['vat_nature']);
                $this->assertSame($nature, $billing->find($id)['vat_nature']);
                $this->assertSame($id,(int)$saved['source_ref_id']);
                $this->assertNull($saved['ts_protocol']);
            }
        } finally { $db->close();putenv($previousKey===false ? 'TS_BILLING_SECRET_KEY' : 'TS_BILLING_SECRET_KEY='.$previousKey); }
    }

    public function testBuildQueueForTenantReturnsEmptyListsWhenBillingTableIsMissing(): void
    {
        $db = $this->getMockBuilder(\CodeIgniter\Database\SQLite3\Connection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['tableExists'])
            ->getMock();

        $db->expects($this->once())
            ->method('tableExists')
            ->with('billing_documents')
            ->willReturn(false);

        $billingContext = $this->getMockBuilder(BillingTenantDatabaseContextService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['resolveTenantContext'])
            ->getMock();

        $billingContext->expects($this->once())
            ->method('resolveTenantContext')
            ->with(14)
            ->willReturn([
                'db' => $db,
            ]);

        $service = new BillingTsBridgeService($billingContext);
        $queue = $service->buildQueueForTenant(14);

        $this->assertSame([], $queue['pending_documents'] ?? null);
        $this->assertSame([], $queue['sent_documents'] ?? null);
        $this->assertSame(0, (int) ($queue['pending_count'] ?? -1));
        $this->assertSame(0, (int) ($queue['sent_count'] ?? -1));
    }

    public function testSendBillingDocumentsBulkAggregatesSentBlockedAndErroredResults(): void
    {
        $service = $this->getMockBuilder(BillingTsBridgeService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['sendBillingDocument'])
            ->getMock();

        $service->expects($this->exactly(4))
            ->method('sendBillingDocument')
            ->willReturnCallback(static function (int $tenantId, int $billingDocumentId, int $userId): array {
                if ($tenantId !== 21 || $userId !== 77) {
                    throw new \RuntimeException('Parametri bulk inattesi.');
                }

                return match ($billingDocumentId) {
                    10 => [
                        'status' => 'ok',
                        'message' => 'Inviata.',
                        'ts_document' => ['id_ts_document' => 110],
                    ],
                    11 => [
                        'status' => 'blocked',
                        'message' => 'Correggi il codice fiscale.',
                        'ts_document' => [],
                    ],
                    12 => throw new \RuntimeException('Errore SOAP simulato.'),
                    13 => [
                        'status' => 'sent',
                        'message' => 'Gia inviata.',
                        'ts_document' => ['id_ts_document' => 113],
                    ],
                    default => throw new \RuntimeException('Documento inatteso nel bulk test.'),
                };
            });

        $report = $service->sendBillingDocumentsBulk(21, [10, 11, 12, 12, 13, 0, -5], 77);

        $this->assertSame(2, (int) ($report['sent_count'] ?? 0));
        $this->assertSame(1, (int) ($report['blocked_count'] ?? 0));
        $this->assertSame(1, (int) ($report['error_count'] ?? 0));
        $this->assertCount(4, $report['results'] ?? []);
        $this->assertSame('ok', (string) (($report['results'][0]['status'] ?? '')));
        $this->assertSame('blocked', (string) (($report['results'][1]['status'] ?? '')));
        $this->assertSame('error', (string) (($report['results'][2]['status'] ?? '')));
        $this->assertSame('sent', (string) (($report['results'][3]['status'] ?? '')));
    }
}
