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
    public function testBuildQueueForTenantReturnsEmptyListsWhenBillingTableIsMissing(): void
    {
        $db = $this->getMockBuilder(BaseConnection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['tableExists'])
            ->getMockForAbstractClass();

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
