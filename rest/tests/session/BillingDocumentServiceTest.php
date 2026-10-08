<?php

use App\Config\TsBilling;
use App\Services\BillingDocumentService;
use App\Services\BillingDocumentSettingsService;
use App\Services\BillingTenantDatabaseContextService;
use App\Services\BillingTenantSchemaService;
use App\Services\TsFeatureService;
use App\Services\TsProfileService;
use CodeIgniter\Database\SQLite3\Connection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

/**
 * @internal
 */
final class BillingDocumentServiceTest extends CIUnitTestCase
{
    public function testSearchServiceCatalogReturnsSavedServicesAndActiveVisitTypes(): void
    {
        $settings = $this->getMockBuilder(BillingDocumentSettingsService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['resolveTenantSettings'])
            ->getMock();

        $settings->expects($this->exactly(2))
            ->method('resolveTenantSettings')
            ->with(21)
            ->willReturn([
                'config' => [
                    'service_catalog' => [
                        ['description' => 'Visita Test', 'unit_amount' => '75.00'],
                        ['description' => 'Controllo', 'unit_amount' => '40.00'],
                    ],
                ],
            ]);

        $db = Database::connect([
            'DSN' => '',
            'hostname' => '',
            'username' => '',
            'password' => '',
            'database' => ':memory:',
            'DBDriver' => 'SQLite3',
            'DBPrefix' => '',
            'pConnect' => false,
            'DBDebug' => true,
            'charset' => 'utf8',
            'DBCollat' => 'utf8_general_ci',
        ]);
        $db->query('CREATE TABLE dap44_agenda_tipi_visita (id INTEGER PRIMARY KEY AUTOINCREMENT, nome VARCHAR(120), attivo INTEGER, ordinamento INTEGER)');
        $db->query("INSERT INTO dap44_agenda_tipi_visita (nome, attivo, ordinamento) VALUES ('Visita Test', 1, 1), ('Test rapido', 1, 2), ('Test disattivato', 0, 3)");

        $tenantDbContext = $this->getMockBuilder(BillingTenantDatabaseContextService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['resolveTenantContext'])
            ->getMock();

        $tenantDbContext->expects($this->exactly(2))
            ->method('resolveTenantContext')
            ->with(21)
            ->willReturn(['db' => $db]);

        $service = new BillingDocumentService($settings, $tenantDbContext);

        $allResults = $service->searchServiceCatalogForTenant(21, '', 20);
        $filteredResults = $service->searchServiceCatalogForTenant(21, 'test', 20);

        $this->assertSame(['Visita Test', 'Controllo', 'Test rapido'], array_column($allResults, 'description'));
        $this->assertSame(['Test rapido', 'Visita Test'], array_column($filteredResults, 'description'));
        $this->assertSame('75.00', $filteredResults[1]['unit_amount']);
        $this->assertSame('service_catalog', $filteredResults[1]['source']);
        $this->assertSame('visit_type', $filteredResults[0]['source']);
    }

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
                'message' => 'La tabella billing_documents non è ancora disponibile nel database di questo spazio.',
            ]);

        $tsProfiles = $this->getMockBuilder(TsProfileService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['resolveServiceExpenseTypeMapForTenant'])
            ->getMock();
        $tsProfiles->expects($this->once())
            ->method('resolveServiceExpenseTypeMapForTenant')
            ->with(12)
            ->willReturn([]);

        $service = new BillingDocumentService(
            $settings,
            $tenantDbContext,
            $tsFeatures,
            config(TsBilling::class),
            $schema,
            tsProfiles: $tsProfiles
        );

        $formContext = $service->buildFormContext(12);

        $this->assertFalse((bool) ($formContext['table_available'] ?? true));
        $this->assertSame(
            'La tabella billing_documents non è ancora disponibile nel database di questo spazio.',
            $formContext['schema_message'] ?? ''
        );
        $this->assertSame(0, (int) ($formContext['document']['id_billing_document'] ?? -1));
        $this->assertSame('', (string) ($formContext['document']['document_number'] ?? ''));
        $this->assertSame('waiting_module', (string) ($formContext['document']['ts_sync_state'] ?? ''));
    }

    public function testSaveDraftForTenantTreatsFinalPrefixedModesAsIssuedDocuments(): void
    {
        $settings = $this->getMockBuilder(BillingDocumentSettingsService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['resolveTenantSettings', 'rememberServiceCatalogItems'])
            ->getMock();

        $settings->expects($this->once())
            ->method('rememberServiceCatalogItems')
            ->with(9, $this->callback(static fn (array $items): bool => count($items) === 1
                && $items[0]['description'] === 'Seduta fisioterapica'), 42);

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

        $db = Database::connect(['DBDriver'=>'SQLite3', 'database'=>':memory:', 'DBPrefix'=>'', 'DBDebug'=>true], false);
        $db->query('CREATE TABLE billing_documents (document_number VARCHAR(32), issue_date DATE)');
        require_once APPPATH . 'Database/Migrations/2026-10-08-100001_ProtectBillingNumbering.php';
        (new \App\Database\Migrations\ProtectBillingNumbering(Database::forge($db)))->up();

        $documents = $this->getMockBuilder(\App\Models\BillingDocumentModel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['findByDocumentNumberAndDate', 'insert', 'find'])
            ->getMock();

        $documents->expects($this->once())
            ->method('insert')
            ->with($this->callback(static function (array $record): bool {
                return ($record['document_number'] ?? '') === 'FT-2026-0001'
                    && ($record['local_state'] ?? '') === 'issued'
                    && (json_decode($record['template_snapshot_json'], true)['patient_details']['patient_address'] ?? '') === 'Via Sintetica 12'
                    && (json_decode($record['template_snapshot_json'], true)['patient_details']['patient_city'] ?? '') === 'Firenze'
                    && (int) ($record['created_by'] ?? 0) === 17
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

        $tsProfiles = $this->getMockBuilder(TsProfileService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['resolveExpenseTypeForLineItems'])
            ->getMock();
        $tsProfiles->expects($this->once())
            ->method('resolveExpenseTypeForLineItems')
            ->with(9, $this->callback(static fn (array $items): bool => count($items) === 1
                && $items[0]['description'] === 'Seduta fisioterapica'))
            ->willReturn(null);

        $service = new BillingDocumentService(
            $settings,
            $tenantDbContext,
            $tsFeatures,
            config(TsBilling::class),
            $schema,
            tsProfiles: $tsProfiles
        );

        $result = $service->saveDraftForTenant(9, [
            'document_number' => 'FT-20260706-01',
            'document_type' => 'invoice',
            'issue_date' => '2026-07-06',
            'payment_date' => '2026-07-06',
            'patient_name' => 'Mario Rossi',
            'patient_address' => 'Via Sintetica 12',
            'patient_city' => 'Firenze',
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
        ], 17, 'final_send_ts', 42);

        $this->assertSame('issued', (string) ($result['local_state'] ?? ''));
        $this->assertSame(55, (int) (($result['document']['id_billing_document'] ?? 0)));
        $this->assertSame('ready', (string) ($result['document']['ts_sync_state'] ?? ''));
    }

    public function testProtectedNumberingLifecycleOnRealDatabase(): void
    {
        $db = Database::connect(['DBDriver'=>'SQLite3','database'=>':memory:','DBPrefix'=>'','DBDebug'=>true], false);
        $fields = (new ReflectionClass(\App\Models\BillingDocumentModel::class))->getDefaultProperties()['allowedFields'];
        $db->query('CREATE TABLE billing_documents (id_billing_document INTEGER PRIMARY KEY AUTOINCREMENT,'.implode(',', array_map(static fn($f)=>$f.' TEXT', $fields)).')');
        require_once APPPATH . 'Database/Migrations/2026-10-08-100001_ProtectBillingNumbering.php';
        (new \App\Database\Migrations\ProtectBillingNumbering(Database::forge($db)))->up();
        $documents = new \App\Models\BillingDocumentModel($db);
        $context = $this->getMockBuilder(BillingTenantDatabaseContextService::class)->disableOriginalConstructor()->onlyMethods(['resolveTenantContext'])->getMock();
        $context->method('resolveTenantContext')->willReturn(['db'=>$db,'documents'=>$documents]);
        $settings = $this->getMockBuilder(BillingDocumentSettingsService::class)->disableOriginalConstructor()->onlyMethods(['resolveTenantSettings','rememberServiceCatalogItems'])->getMock();
        $settings->method('resolveTenantSettings')->willReturn(['config'=>['document_code_prefix'=>'FT']]);
        $schema = $this->getMockBuilder(BillingTenantSchemaService::class)->disableOriginalConstructor()->onlyMethods(['ensureTenantSchemaReady'])->getMock();
        $schema->method('ensureTenantSchemaReady')->willReturn(['ready'=>true]);
        $features = $this->getMockBuilder(TsFeatureService::class)->disableOriginalConstructor()->onlyMethods(['isEnabledForTenant'])->getMock();
        $features->method('isEnabledForTenant')->willReturn(false);
        $profiles = $this->getMockBuilder(TsProfileService::class)->disableOriginalConstructor()->onlyMethods(['resolveExpenseTypeForLineItems'])->getMock();
        $profiles->method('resolveExpenseTypeForLineItems')->willReturn(null);
        $service = new BillingDocumentService($settings,$context,$features,config(TsBilling::class),$schema,tsProfiles:$profiles);
        $payload = ['document_number'=>'FORGED','issue_date'=>'2026-12-31','patient_name'=>'SYNTHETIC','item_description'=>['Visita'],'item_qty'=>[1],'item_unit_amount'=>[100]];
        $draft = $service->saveDraftForTenant(1,$payload)['document'];
        $this->assertStringStartsWith('BOZZA-', $draft['document_number']);
        $this->assertSame(0, $db->table('billing_numbering_counters')->countAllResults());
        $payload['id_billing_document'] = $draft['id_billing_document'];
        $issued = $service->saveDraftForTenant(1,$payload,0,'final')['document'];
        $this->assertSame('FT-2026-0001', $issued['document_number']);
        foreach (['draft','final'] as $mode) {
            try { $service->saveDraftForTenant(1,$payload,0,$mode); $this->fail('Issued invoice overwritten'); }
            catch (RuntimeException $e) { $this->assertStringContainsString('definitiva', $e->getMessage()); }
        }
        $this->assertSame('FT-2026-0001', $documents->find($draft['id_billing_document'])['document_number']);
        $this->assertSame(1, (int)$db->table('billing_numbering_counters')->get()->getRowArray()['last_number']);
        $bridge = (new ReflectionClass(\App\Services\BillingTsBridgeService::class))->newInstanceWithoutConstructor();
        $action = (new ReflectionMethod($bridge,'buildBillingDocumentActionState'))->invoke($bridge,$issued,null);
        $this->assertFalse($action['can_delete']);
        $this->assertFalse($action['can_edit']);
        unset($payload['id_billing_document']);
        $payload['issue_date'] = '2027-01-01';
        $this->assertSame('FT-2027-0001', $service->saveDraftForTenant(1,$payload,0,'final')['document']['document_number']);
        $payload['issue_date'] = '2027-02-30';
        $this->expectException(RuntimeException::class);
        $service->saveDraftForTenant(1,$payload,0,'final');
    }
}
