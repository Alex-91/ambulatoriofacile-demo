<?php

namespace Tests\Unit;

use App\Services\SmsMonthlyQuotaService;
use App\Services\TenantNotificationPolicyService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

final class SmsMonthlyQuotaServiceTest extends CIUnitTestCase
{
    public function testManualPeriodSurvivesMonthChangeAndKeepsBlocking(): void
    {
        $db = Database::connect(['DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => '', 'DBDebug' => true], false);
        $db->query('CREATE TABLE platform_sms_monthly_usage (id_tenant INTEGER, month_key TEXT, attempts INTEGER DEFAULT 0, PRIMARY KEY (id_tenant, month_key))');
        $service = new SmsMonthlyQuotaService($db);
        $policy = ['sms' => ['monthly_limit_enabled' => true, 'monthly_limit' => 2,
            'monthly_auto_renew' => false, 'monthly_period' => '2026-09']];
        $this->assertSame('2026-09', SmsMonthlyQuotaService::periodKey($policy, new \DateTimeImmutable('2027-01-01')));
        $this->assertSame('2026-10', SmsMonthlyQuotaService::periodKey([], new \DateTimeImmutable('2026-09-30T22:00:00Z')));
        $this->assertTrue($service->claim(7, $policy));
        $this->assertTrue($service->claim(7, $policy));
        $this->assertFalse($service->claim(7, $policy));
        $this->assertSame(2, $service->usage(7, $policy));
        $policy['sms']['monthly_period'] = 'R123abc';
        $this->assertSame(0, $service->usage(7, $policy));
        $this->assertTrue($service->claim(7, $policy));
        $this->assertSame(2, (int) $db->table(SmsMonthlyQuotaService::TABLE)->where('month_key', '2026-09')->get()->getRowArray()['attempts']);
        $db->close();
    }

    public function testSavingManualPolicyPreservesConsumptionUntilExplicitRenewal(): void
    {
        $db = Database::connect(['DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => '', 'DBDebug' => true], false);
        $db->query('CREATE TABLE platform_sms_monthly_usage (id_tenant INTEGER, month_key TEXT, attempts INTEGER DEFAULT 0, PRIMARY KEY (id_tenant, month_key))');
        $db->query('CREATE TABLE platform_tenant_notification_policies (
            id_tenant_notification_policy INTEGER PRIMARY KEY AUTOINCREMENT, id_tenant INTEGER UNIQUE,
            config_json TEXT, smtp_password_encrypted TEXT NULL, updated_by_platform_user_id INTEGER NULL,
            created_at TEXT, updated_at TEXT)');
        $policies = new TenantNotificationPolicyService($db);
        $quota = new SmsMonthlyQuotaService($db);
        $raw = ['sms' => ['monthly_limit_enabled' => 1, 'monthly_limit' => 1]];
        $automatic = $policies->save(7, $raw);
        $this->assertTrue($automatic['sms']['monthly_auto_renew']);
        $this->assertTrue($quota->claim(7, $automatic));
        $raw['sms']['monthly_auto_renew'] = '0';
        $manual = $policies->save(7, $raw);
        $this->assertFalse($manual['sms']['monthly_auto_renew']);
        $this->assertSame(SmsMonthlyQuotaService::periodKey([]), $manual['sms']['monthly_period']);
        $this->assertFalse($quota->claim(7, $manual));
        $raw['sms']['monthly_period'] = 'Rffffff'; // Forged form data cannot reset the quota.
        $saved = $policies->save(7, $raw);
        $this->assertSame($manual['sms']['monthly_period'], $saved['sms']['monthly_period']);
        $this->assertFalse($quota->claim(7, $saved));
        $raw['sms']['monthly_limit_enabled'] = 0;
        $policies->save(7, $raw);
        $raw['sms']['monthly_limit_enabled'] = 1;
        $this->assertFalse($quota->claim(7, $policies->save(7, $raw)));
        $raw['sms']['renew_quota'] = 1;
        $renewed = $policies->save(7, $raw);
        $this->assertNotSame($manual['sms']['monthly_period'], $renewed['sms']['monthly_period']);
        $this->assertTrue($quota->claim(7, $renewed));
        $this->assertFalse($quota->claim(7, $renewed));
        unset($raw['sms']['renew_quota']);
        $this->assertFalse($quota->claim(7, $policies->save(7, $raw)));
        $this->assertSame(1, $quota->usage(7, $manual));
        $raw['sms']['monthly_auto_renew'] = 1;
        $raw['sms']['renew_quota'] = 1;
        $this->expectException(\InvalidArgumentException::class);
        $policies->save(7, $raw);
    }

    public function testLimitIsolationDisableReenableAndMonthRollover(): void
    {
        $db = Database::connect(['DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => '', 'DBDebug' => true], false);
        $db->query('CREATE TABLE platform_sms_monthly_usage (id_tenant INTEGER, month_key TEXT, attempts INTEGER DEFAULT 0, PRIMARY KEY (id_tenant, month_key))');
        $service = new SmsMonthlyQuotaService($db);
        $policy = ['sms' => ['monthly_limit_enabled' => true, 'monthly_limit' => 2]];
        $this->assertTrue($service->claim(7, $policy));
        $this->assertTrue($service->claim(7, $policy));
        $this->assertFalse($service->claim(7, $policy));
        $this->assertTrue($service->claim(8, $policy));
        $this->assertTrue($service->claim(7, ['sms' => ['monthly_limit_enabled' => false]]));
        $this->assertFalse($service->claim(7, $policy));
        $this->assertTrue($service->claim(7, ['sms' => ['monthly_limit_enabled' => true, 'monthly_limit' => 4]]));
        $this->assertFalse($service->claim(7, $policy));
        $db->table(SmsMonthlyQuotaService::TABLE)->where('id_tenant', 7)->update(['month_key' => '2000-01']);
        $this->assertTrue($service->claim(7, $policy));
        $this->assertTrue($service->claim(7, $policy));
        $this->assertFalse($service->claim(7, $policy));
        $this->assertSame(3, $db->table(SmsMonthlyQuotaService::TABLE)->countAllResults());
        $db->close();
    }

    public function testMissingCounterFailsClosedWhenEnabled(): void
    {
        $db = Database::connect(['DBDriver' => 'SQLite3', 'database' => ':memory:', 'DBPrefix' => '', 'DBDebug' => true], false);
        $service = new SmsMonthlyQuotaService($db);
        $this->assertTrue($service->claim(7, []));
        $this->expectException(\RuntimeException::class);
        $service->claim(7, ['sms' => ['monthly_limit_enabled' => true, 'monthly_limit' => 2]]);
    }

    public function testPolicyAcceptsOptionalLimitAndRejectsInvalidLimit(): void
    {
        $service = new TenantNotificationPolicyService();
        $this->assertFalse($service->sanitize([])['sms']['monthly_limit_enabled']);
        $policy = $service->sanitize(['sms' => ['monthly_limit_enabled' => '1', 'monthly_limit' => 123]], '', true);
        $this->assertTrue($policy['sms']['monthly_limit_enabled']);
        $this->assertSame(123, $policy['sms']['monthly_limit']);
        $this->expectException(\InvalidArgumentException::class);
        $service->sanitize(['sms' => ['monthly_limit_enabled' => 1, 'monthly_limit' => 0]], '', true);
    }
}
