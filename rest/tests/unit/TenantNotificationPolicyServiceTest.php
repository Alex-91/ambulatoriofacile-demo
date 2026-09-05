<?php

namespace Tests\Unit;

use App\Services\AppointmentNotificationSettingsService;
use App\Services\TenantNotificationSecretsService;
use App\Services\TenantNotificationPolicyService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

final class TenantNotificationPolicyServiceTest extends CIUnitTestCase
{
    public function testDefaultsAreConservativeAndUseNoReplyDomain(): void
    {
        $service = new TenantNotificationPolicyService();
        $policy = $service->defaults('Studio Prova');

        $this->assertSame('noreply@ambulatoriofacile.it', $policy['email']['from_address']);
        $this->assertSame('noreply@ambulatoriofacile.it', $policy['email']['reply_to']);
        $this->assertSame(10, $policy['email']['messages_per_interval']);
        $this->assertSame(5, $policy['email']['interval_minutes']);
        $this->assertFalse($policy['email']['smtp_enabled']);
        $this->assertFalse($policy['email']['smtp_password_configured']);
        $this->assertSame(1, $policy['whatsapp']['messages_per_interval']);
        $this->assertSame(5, $policy['whatsapp']['interval_minutes']);
        $this->assertTrue($policy['whatsapp']['sms_fallback_enabled']);
        $this->assertTrue($service->sanitize([], 'Studio Prova')['whatsapp']['sms_fallback_enabled']);
    }

    public function testRejectsSenderOutsideAmbulatorioFacileDomain(): void
    {
        $service = new TenantNotificationPolicyService();

        $this->expectException(\InvalidArgumentException::class);
        $service->sanitize([
            'email' => ['from_address' => 'pazienti@example.com'],
        ], 'Studio Prova', true);
    }

    public function testComputesEvenSpacingFromConfiguredWindow(): void
    {
        $service = new TenantNotificationPolicyService();
        $policy = $service->sanitize([
            'whatsapp' => [
                'messages_per_interval' => 5,
                'interval_minutes' => 10,
                'daily_limit' => 100,
                'sms_fallback_enabled' => true,
                'fallback_after_minutes' => 30,
            ],
        ], 'Studio Prova');

        $this->assertSame(
            120,
            $service->minimumSpacingSeconds($policy, AppointmentNotificationSettingsService::CHANNEL_WHATSAPP)
        );
    }

    public function testRejectsSmsSenderLongerThanElevenCharacters(): void
    {
        $service = new TenantNotificationPolicyService();

        $this->expectException(\InvalidArgumentException::class);
        $service->sanitize([
            'sms' => ['sender' => 'AmbulatorioFacile'],
        ], 'Studio Prova', true);
    }

    public function testSanitizesCompleteTenantSmtpConfiguration(): void
    {
        $service = new TenantNotificationPolicyService();
        $policy = $service->sanitize([
            'email' => [
                'smtp_enabled' => '1',
                'smtp_host' => 'SMTPS.ARUBA.IT',
                'smtp_port' => '465',
                'smtp_crypto' => 'none',
                'smtp_username' => 'studio@ambulatoriofacile.it',
                'smtp_password' => 'non-deve-entrare-nel-json',
                'smtp_timeout_seconds' => '15',
            ],
        ], 'Studio Prova', true);

        $this->assertTrue($policy['email']['smtp_enabled']);
        $this->assertSame('smtps.aruba.it', $policy['email']['smtp_host']);
        $this->assertSame(465, $policy['email']['smtp_port']);
        $this->assertSame('', $policy['email']['smtp_crypto']);
        $this->assertSame('studio@ambulatoriofacile.it', $policy['email']['smtp_username']);
        $this->assertSame(15, $policy['email']['smtp_timeout_seconds']);
        $this->assertArrayNotHasKey('smtp_password', $policy['email']);
    }

    public function testRejectsIncompleteEnabledTenantSmtpConfiguration(): void
    {
        $service = new TenantNotificationPolicyService();

        $this->expectException(\InvalidArgumentException::class);
        $service->sanitize([
            'email' => [
                'smtp_enabled' => '1',
                'smtp_host' => '',
                'smtp_username' => '',
            ],
        ], 'Studio Prova', true);
    }

    public function testBuildsCodeIgniterSmtpTransportWithoutChangingTheSecret(): void
    {
        $service = new TenantNotificationPolicyService();
        $transport = $service->buildEmailTransportConfig([
            'smtp_host' => 'smtps.aruba.it',
            'smtp_port' => 465,
            'smtp_crypto' => '',
            'smtp_username' => 'studio@ambulatoriofacile.it',
            'smtp_timeout_seconds' => 15,
        ], 'password con spazi');

        $this->assertSame('smtp', $transport['protocol']);
        $this->assertSame('smtps.aruba.it', $transport['SMTPHost']);
        $this->assertSame(465, $transport['SMTPPort']);
        $this->assertSame('', $transport['SMTPCrypto']);
        $this->assertSame('studio@ambulatoriofacile.it', $transport['SMTPUser']);
        $this->assertSame('password con spazi', $transport['SMTPPass']);
    }

    public function testSaveEncryptsAndPreservesTenantSmtpPassword(): void
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
        $db->query(
            'CREATE TABLE platform_tenant_notification_policies (
                id_tenant_notification_policy INTEGER PRIMARY KEY AUTOINCREMENT,
                id_tenant INTEGER NOT NULL UNIQUE,
                config_json TEXT NOT NULL,
                smtp_password_encrypted TEXT NULL,
                updated_by_platform_user_id INTEGER NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );

        $service = new TenantNotificationPolicyService(
            $db,
            new TenantNotificationSecretsService('unit-test-notification-key')
        );
        $payload = [
            'email' => [
                'smtp_enabled' => '1',
                'smtp_host' => 'smtps.aruba.it',
                'smtp_port' => '465',
                'smtp_crypto' => 'none',
                'smtp_username' => 'studio@ambulatoriofacile.it',
                'smtp_password' => 'segreto-per-tenant',
                'smtp_timeout_seconds' => '15',
            ],
        ];

        $saved = $service->save(7, $payload, 0, 'Studio Prova');
        $row = $db->table(TenantNotificationPolicyService::TABLE)->where('id_tenant', 7)->get()->getRowArray();

        $this->assertTrue($saved['email']['smtp_password_configured']);
        $this->assertArrayNotHasKey('smtp_password', $saved['email']);
        $this->assertStringStartsWith('notifsec:v1:', (string) $row['smtp_password_encrypted']);
        $this->assertStringNotContainsString('segreto-per-tenant', (string) $row['config_json']);

        $transport = $service->resolveEmailTransport(7, $saved);
        $this->assertSame('segreto-per-tenant', $transport['SMTPPass']);

        $encryptedBefore = (string) $row['smtp_password_encrypted'];
        $payload['email']['smtp_password'] = '';
        $payload['email']['smtp_username'] = 'nuovo@ambulatoriofacile.it';
        $service->save(7, $payload, 0, 'Studio Prova');
        $updated = $db->table(TenantNotificationPolicyService::TABLE)->where('id_tenant', 7)->get()->getRowArray();

        $this->assertSame($encryptedBefore, (string) $updated['smtp_password_encrypted']);
    }
}
