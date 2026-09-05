<?php

namespace Tests\Unit;

use App\Services\TenantNotificationSchemaService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

final class TenantNotificationSchemaServiceTest extends CIUnitTestCase
{
    public function testForeignKeyNamesFitMysqlIdentifierLimit(): void
    {
        foreach ([
            TenantNotificationSchemaService::FK_POLICY_TENANT,
            TenantNotificationSchemaService::FK_POLICY_USER,
            TenantNotificationSchemaService::FK_RATE_LIMIT_TENANT,
            TenantNotificationSchemaService::FK_FALLBACK_TENANT,
        ] as $foreignKeyName) {
            $this->assertLessThanOrEqual(64, strlen($foreignKeyName));
        }
    }

    public function testRepairsMissingNotificationTablesAndSmtpColumnIdempotently(): void
    {
        $db = Database::connect([
            'DBDriver' => 'SQLite3',
            'database' => ':memory:',
            'DBPrefix' => '',
            'pConnect' => false,
            'DBDebug' => true,
            'charset' => 'utf8',
            'DBCollat' => 'utf8_general_ci',
            'swapPre' => '',
            'encrypt' => false,
            'compress' => false,
            'strictOn' => false,
            'failover' => [],
            'port' => 0,
            'foreignKeys' => true,
            'busyTimeout' => 1000,
            'dateFormat' => ['date' => 'Y-m-d', 'datetime' => 'Y-m-d H:i:s', 'time' => 'H:i:s'],
        ], false);
        $db->query('CREATE TABLE platform_tenants (id_tenant INTEGER PRIMARY KEY AUTOINCREMENT)');
        $db->query('CREATE TABLE platform_users (id_platform_user INTEGER PRIMARY KEY AUTOINCREMENT)');
        $db->query(
            'CREATE TABLE platform_tenant_notification_policies (
                id_tenant_notification_policy INTEGER PRIMARY KEY AUTOINCREMENT,
                id_tenant INTEGER NOT NULL UNIQUE,
                config_json TEXT NOT NULL,
                updated_by_platform_user_id INTEGER NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );

        $service = new TenantNotificationSchemaService($db);
        $this->assertFalse($service->isReady());

        $service->ensureReady();
        $service->ensureReady();

        $this->assertTrue($service->isReady());
        $this->assertTrue($db->fieldExists('smtp_password_encrypted', TenantNotificationSchemaService::POLICY_TABLE));
        $this->assertTrue($db->tableExists(TenantNotificationSchemaService::RATE_LIMIT_TABLE));
        $this->assertTrue($db->tableExists(TenantNotificationSchemaService::FALLBACK_TABLE));
    }
}
