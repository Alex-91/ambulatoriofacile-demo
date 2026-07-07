<?php

use App\Config\TsBilling;
use App\Services\BillingDocumentService;
use App\Services\BillingDocumentSettingsService;
use App\Services\BillingTenantDatabaseContextService;
use App\Services\BillingTenantSchemaService;
use App\Services\TsFeatureService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * @internal
 */
final class BillingDocumentServiceTest extends CIUnitTestCase
{
    public function testBuildFormContextReturnsSafeFallbackWhenTenantSchemaIsMissing(): void
    {
        $settings = $this->getMockBuilder(BillingDocumentSettingsService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['resolveTenantSettings'])
            ->getMock();

        $settings->expects($this->once())
            ->method('resolveTenantSettings')
            ->with(12)
            ->willReturn([
                'config' => [
                    'document_code_prefix' => 'FT',
                    'integration_ts' => [
                        'enabled_when_available' => true,
                    ],
                ],
            ]);

        $tenantDbContext = $this->getMockBuilder(BillingTenantDatabaseContextService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['resolveTenantContext'])
            ->getMock();

        $tenantDbContext->expects($this->never())
            ->method('resolveTenantContext');

        $tsFeatures = $this->getMockBuilder(TsFeatureService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isEnabledForTenant'])
            ->getMock();

        $tsFeatures->expects($this->once())
            ->method('isEnabledForTenant')
            ->with(12)
            ->willReturn(false);

        $schema = $this->getMockBuilder(BillingTenantSchemaService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['ensureTenantSchemaReady'])
            ->getMock();

        $schema->expects($this->once())
            ->method('ensureTenantSchemaReady')
            ->with(12)
            ->willReturn([
                'ready' => false,
                'status' => 'error',
                'message' => 'La tabella billing_documents non e ancora disponibile nel database di questo spazio.',
            ]);

        $service = new BillingDocumentService(
            $settings,
            $tenantDbContext,
            $tsFeatures,
            config(TsBilling::class),
            $schema
        );

        $formContext = $service->buildFormContext(12);

        $this->assertFalse((bool) ($formContext['table_available'] ?? true));
        $this->assertSame(
            'La tabella billing_documents non e ancora disponibile nel database di questo spazio.',
            $formContext['schema_message'] ?? ''
        );
        $this->assertSame(0, (int) ($formContext['document']['id_billing_document'] ?? -1));
        $this->assertStringStartsWith('FT-', (string) ($formContext['document']['document_number'] ?? ''));
        $this->assertSame('waiting_module', (string) ($formContext['document']['ts_sync_state'] ?? ''));
    }

    public function testSaveDraftForTenantTreatsFinalPrefixedModesAsIssuedDocuments(): void
    {
        $settings = $this->getMockBuilder(BillingDocumentSettingsService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['resolveTenantSettings'])
            ->getMock();

        $settings->expects($this->once())
            ->method('resolveTenantSettings')
            ->with(9)
            ->willReturn([
                'config' => [
                    'document_code_prefix' => 'FT',
                    'integration_ts' => [
                        'enabled_when_available' => true,
                    ],
                ],
            ]);

        $tsFeatures = $this->getMockBuilder(TsFeatureService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isEnabledForTenant'])
            ->getMock();

        $tsFeatures->expects($this->exactly(2))
            ->method('isEnabledForTenant')
            ->with(9)
            ->willReturn(true);

        $schema = $this->getMockBuilder(BillingTenantSchemaService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['ensureTenantSchemaReady'])
            ->getMock();

        $schema->expects($this->once())
            ->method('ensureTenantSchemaReady')
            ->with(9)
            ->willReturn([
                'ready' => true,
                'status' => 'ok',
                'message' => '',
            ]);

        $db = $this->getMockBuilder(BaseConnection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['tableExists', 'transBegin', 'transStatus', 'transCommit', 'transRollback'])
            ->getMockForAbstractClass();

        $db->expects($this->once())
            ->method('tableExists')
            ->with('billing_documents')
            ->willReturn(true);
        $db->expects($this->once())
            ->method('transBegin');
        $db->expects($this->once())
            ->method('transStatus')
            ->willReturn(true);
        $db->expects($this->once())
            ->method('transCommit');
        $db->expects($this->never())
            ->method('transRollback');

        $documents = $this->getMockBuilder(\App\Models\BillingDocumentModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findByDocumentNumberAndDate', 'insert', 'find'])
            ->getMock();

        $documents->expects($this->once())
            ->method('findByDocumentNumberAndDate')
            ->with('FT-20260706-01', '2026-07-06')
            ->willReturn(null);

        $documents->expects($this->once())
            ->method('insert')
            ->with($this->callback(static function (array $record): bool {
                return ($record['document_number'] ?? '') === 'FT-20260706-01'
                    && ($record['local_state'] ?? '') === 'issued'
                    && (int) ($record['ts_sync_enabled'] ?? 0) === 1
                    && ($record['ts_sync_state'] ?? '') === 'ready'
                    && abs((float) ($record['subtotal_amount'] ?? 0) - 100.0) < 0.001
                    && abs((float) ($record['stamp_duty_amount'] ?? 0) - 22.0) < 0.001
                    && abs((float) ($record['amount_total'] ?? 0) - 122.0) < 0.001;
            }))
            ->willReturn(55);

        $documents->expects($this->once())
            ->method('find')
            ->with(55)
            ->willReturn([
                'id_billing_document' => 55,
                'document_number' => 'FT-20260706-01',
                'local_state' => 'issued',
                'ts_sync_enabled' => 1,
                'ts_sync_state' => 'ready',
            ]);

        $tenantDbContext = $this->getMockBuilder(BillingTenantDatabaseContextService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['resolveTenantContext'])
            ->getMock();

        $tenantDbContext->expects($this->once())
            ->method('resolveTenantContext')
            ->with(9)
            ->willReturn([
                'db' => $db,
                'documents' => $documents,
            ]);

        $service = new BillingDocumentService(
            $settings,
            $tenantDbContext,
            $tsFeatures,
            config(TsBilling::class),
            $schema
        );

        $result = $service->saveDraftForTenant(9, [
            'document_number' => 'FT-20260706-01',
            'document_type' => 'invoice',
            'issue_date' => '2026-07-06',
            'payment_date' => '2026-07-06',
            'patient_name' => 'Mario Rossi',
            'patient_tax_code' => 'RSSMRA80A01H501Z',
            'payment_method' => 'bank_transfer',
            'item_description' => ['Seduta fisioterapica'],
            'item_qty' => ['1'],
            'item_unit_amount' => ['100,00'],
            'stamp_duty_amount' => '22,00',
            'vat_rate' => '0,00',
            'vat_nature' => 'N2.2',
            'notes' => 'Documento con invio TS',
            'ts_sync_enabled' => '1',
            'ts_expense_type_code' => 'SP',
            'ts_opposition_flag' => '0',
        ], 17, 'final_send_ts');

        $this->assertSame('issued', (string) ($result['local_state'] ?? ''));
        $this->assertSame(55, (int) (($result['document']['id_billing_document'] ?? 0)));
        $this->assertSame('ready', (string) ($result['document']['ts_sync_state'] ?? ''));
    }
}
