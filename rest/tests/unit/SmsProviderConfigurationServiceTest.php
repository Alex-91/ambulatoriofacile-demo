<?php

namespace Tests\Unit;

use App\Services\SmsProviderConfigurationService;
use App\Services\SmsProviderSecretsService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

final class SmsProviderConfigurationServiceTest extends CIUnitTestCase
{
    private BaseConnection $platformDb;
    private SmsProviderConfigurationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->platformDb = Database::connect([
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
        $this->platformDb->query('CREATE TABLE platform_tenants (id_tenant INTEGER PRIMARY KEY, tenant_name TEXT NOT NULL)');
        $this->platformDb->query("INSERT INTO platform_tenants (id_tenant, tenant_name) VALUES (7, 'Studio Test')");
        $this->platformDb->query(
            'CREATE TABLE platform_sms_provider_settings (
                id_sms_provider_setting INTEGER PRIMARY KEY AUTOINCREMENT,
                scope_key TEXT NOT NULL UNIQUE,
                id_tenant INTEGER NULL,
                inherit_global INTEGER NOT NULL DEFAULT 1,
                provider TEXT NOT NULL,
                default_sender TEXT NOT NULL,
                smsfactor_base_url TEXT NOT NULL,
                smsfactor_timeout_seconds INTEGER NOT NULL,
                smsfactor_push_type TEXT NOT NULL,
                smsfactor_api_token_encrypted TEXT NULL,
                smsfactor_webhook_signature_encrypted TEXT NULL,
                aruba_username_encrypted TEXT NULL,
                aruba_password_encrypted TEXT NULL,
                updated_by_platform_user_id INTEGER NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );
        $this->service = new SmsProviderConfigurationService(
            $this->platformDb,
            new SmsProviderSecretsService('unit-test-sms-key')
        );
    }

    public function testGlobalCredentialsAreEncryptedAndNeverReturnedForDisplay(): void
    {
        $display = $this->service->saveGlobal([
            'provider' => 'smsfactor',
            'sender' => 'AmbFacile',
            'smsfactor_base_url' => 'https://api.smsfactor.com',
            'smsfactor_timeout_seconds' => 20,
            'smsfactor_push_type' => 'alert',
            'smsfactor_api_token' => 'global-token-secret',
            'smsfactor_webhook_signature' => 'global-webhook-secret',
        ]);
        $row = $this->platformDb->table(SmsProviderConfigurationService::TABLE)->where('scope_key', 'global')->get()->getRowArray();
        $runtime = $this->service->resolveRuntime();

        $this->assertTrue($display['configured']);
        $this->assertTrue($display['smsfactor_api_token_configured']);
        $this->assertTrue($display['smsfactor_api_token_stored']);
        $this->assertTrue($display['smsfactor_webhook_signature_stored']);
        $this->assertArrayNotHasKey('api_token', $display);
        $this->assertStringStartsWith('smssec:v1:', (string) $row['smsfactor_api_token_encrypted']);
        $this->assertStringNotContainsString('global-token-secret', json_encode($display));
        $this->assertSame('global-token-secret', $runtime['smsfactor']['api_token']);
        $this->assertSame('global-webhook-secret', $runtime['smsfactor']['webhook_signature']);
    }

    public function testTenantCanInheritGlobalCredentialsWithItsOwnSender(): void
    {
        $this->saveGlobalSmsFactor();
        $this->service->saveTenant(7, [
            'mode' => 'inherit',
            'provider' => 'smsfactor',
            'sender' => 'Studio7',
            'smsfactor_base_url' => 'https://api.smsfactor.com',
            'smsfactor_timeout_seconds' => 30,
            'smsfactor_push_type' => 'alert',
        ]);

        $runtime = $this->service->resolveRuntime(7);

        $this->assertTrue($runtime['inherited']);
        $this->assertSame('global-token', $runtime['smsfactor']['api_token']);
        $this->assertSame('Studio7', $runtime['sender']);
        $this->assertSame('Studio7', $runtime['tenant_sender_override']);
    }

    public function testTenantCanUseDedicatedSmsFactorAccount(): void
    {
        $this->saveGlobalSmsFactor();
        $this->service->saveTenant(7, [
            'mode' => 'custom',
            'provider' => 'smsfactor',
            'sender' => 'Studio7',
            'smsfactor_base_url' => 'https://api.smsfactor.com',
            'smsfactor_timeout_seconds' => 45,
            'smsfactor_push_type' => 'alert',
            'smsfactor_api_token' => 'tenant-token',
            'smsfactor_webhook_signature' => 'tenant-webhook',
        ]);

        $runtime = $this->service->resolveRuntime(7);

        $this->assertFalse($runtime['inherited']);
        $this->assertSame('tenant_database', $runtime['source']);
        $this->assertSame('tenant-token', $runtime['smsfactor']['api_token']);
        $this->assertSame('tenant-webhook', $runtime['smsfactor']['webhook_signature']);
        $this->assertSame(45, $runtime['smsfactor']['timeout_seconds']);
    }

    public function testBlankSecretPreservesExistingDedicatedCredential(): void
    {
        $this->service->saveTenant(7, [
            'mode' => 'custom',
            'provider' => 'smsfactor',
            'sender' => 'Studio7',
            'smsfactor_base_url' => 'https://api.smsfactor.com',
            'smsfactor_timeout_seconds' => 30,
            'smsfactor_push_type' => 'alert',
            'smsfactor_api_token' => 'tenant-token',
        ]);
        $this->service->saveTenant(7, [
            'mode' => 'custom',
            'provider' => 'smsfactor',
            'sender' => 'Nuovo7',
            'smsfactor_base_url' => 'https://api.smsfactor.com',
            'smsfactor_timeout_seconds' => 30,
            'smsfactor_push_type' => 'alert',
            'smsfactor_api_token' => '',
        ]);

        $runtime = $this->service->resolveRuntime(7);
        $this->assertSame('tenant-token', $runtime['smsfactor']['api_token']);
        $this->assertSame('Nuovo7', $runtime['sender']);
    }

    public function testDedicatedProviderRequiresItsOwnCredentials(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->saveTenant(7, [
            'mode' => 'custom',
            'provider' => 'smsfactor',
            'sender' => 'Studio7',
            'smsfactor_base_url' => 'https://api.smsfactor.com',
            'smsfactor_timeout_seconds' => 30,
            'smsfactor_push_type' => 'alert',
        ]);
    }

    private function saveGlobalSmsFactor(): void
    {
        $this->service->saveGlobal([
            'provider' => 'smsfactor',
            'sender' => 'AmbFacile',
            'smsfactor_base_url' => 'https://api.smsfactor.com',
            'smsfactor_timeout_seconds' => 30,
            'smsfactor_push_type' => 'alert',
            'smsfactor_api_token' => 'global-token',
            'smsfactor_webhook_signature' => 'global-webhook',
        ]);
    }
}
