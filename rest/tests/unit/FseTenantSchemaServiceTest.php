<?php

use App\Services\FseTenantSchemaService;
use App\Services\TenantCatalogService;
use App\Services\TenantDatabaseConnector;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

/** @internal */
final class FseTenantSchemaServiceTest extends CIUnitTestCase
{
    public function testCustomMigrationPathIsCanonicalBeforeFileDiscovery(): void
    {
        $runner = new \App\Libraries\FilteredMigrationRunner(config(\Config\Migrations::class));
        $runner->setCustomPath(APPPATH . 'Config/../Database/Migrations/');
        $migrations = $runner->findNamespaceMigrations('App');
        $this->assertNotEmpty($migrations);
        foreach ($migrations as $migration) {
            $this->assertSame(realpath($migration->path), $migration->path);
        }
    }

    public function testUpgradeIsIdempotentAndPreservesExistingClinicalData(): void
    {
        require_once APPPATH . 'Database/Migrations/2026-09-07-220001_AddFseServiceDescription.php';
        $db = Database::connect(['DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => '', 'DBDebug' => true], false);
        try {
            $forge = Database::forge($db);
            $forge->addField(['id' => ['type' => 'INTEGER'], 'report_text_enc' => ['type' => 'TEXT']]);
            $forge->createTable('fse_documents');
            $db->table('fse_documents')->insert(['id' => 1, 'report_text_enc' => 'synthetic-encrypted-placeholder']);
            $migration = new \App\Database\Migrations\AddFseServiceDescription($forge);
            $migration->up();
            $migration->up();
            $this->assertTrue($db->fieldExists('service_description_enc', 'fse_documents'));
            $row = $db->table('fse_documents')->get()->getRowArray();
            $this->assertSame('synthetic-encrypted-placeholder', $row['report_text_enc']);
            $this->assertNull($row['service_description_enc']);
        } finally {
            $db->close();
        }
    }

    public function testInstallsOnlyFseTablesOnTenantConnection(): void
    {
        $databasePath = WRITEPATH . 'fse-schema-test-' . bin2hex(random_bytes(6)) . '.sqlite';
        $connectionConfig = [
            'DSN' => '',
            'hostname' => '',
            'username' => '',
            'password' => '',
            'database' => $databasePath,
            'DBDriver' => 'SQLite3',
            'DBPrefix' => '',
            'pConnect' => false,
            'DBDebug' => true,
            'charset' => 'utf8',
            'DBCollat' => 'utf8_general_ci',
            'foreignKeys' => true,
        ];
        $db = Database::connect($connectionConfig, false);

        $catalog = $this->getMockBuilder(TenantCatalogService::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getTenantById'])
            ->getMock();
        $catalog->expects($this->once())->method('getTenantById')->with(42)->willReturn([
            'id_tenant' => 42,
            'tenant_name' => 'Tenant test FSE',
        ]);

        $connector = $this->getMockBuilder(TenantDatabaseConnector::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['connect', 'buildConnectionConfig'])
            ->getMock();
        $connector->expects($this->exactly(2))->method('connect')->willReturn($db);
        $connector->expects($this->once())->method('buildConnectionConfig')->willReturn($connectionConfig);

        try {
            $result = (new FseTenantSchemaService($catalog, $connector))->ensureTenantSchemaReady(42, true);

            $this->assertTrue($result['ready'], json_encode($result));
            $this->assertTrue($result['applied']);
            $this->assertSame('ok', $result['status']);
            $this->assertTrue($db->tableExists('fse_documents'));
            $this->assertTrue($db->tableExists('fse_document_events'));
            $this->assertContains('patient_birth_date_enc', $db->getFieldNames('fse_documents'));
            $this->assertContains('service_description_enc', $db->getFieldNames('fse_documents'));
            $this->assertFalse($db->tableExists('platform_tenant_fse_profiles'));
        } finally {
            $db->close();
            unset($db);
            @unlink($databasePath);
        }
    }
}
