<?php

namespace Tests\Unit;

use App\Services\AppointmentNotificationChannelService;
use App\Services\AppointmentNotificationSettingsService;
use App\Services\AppointmentReminderDispatchService;
use App\Services\TenantDatabaseConnector;
use App\Services\TenantNotificationPolicyService;
use App\Services\TenantStoragePathService;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\BaseResult;
use CodeIgniter\Test\CIUnitTestCase;

final class AppointmentReminderPendingDispatchTest extends CIUnitTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/af-pending-' . bin2hex(random_bytes(6));
        mkdir($this->root);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->root);
        parent::tearDown();
    }

    public function testRealPendingProbeCountsOnlyUnsentValidChannelsAndDoesNotSend(): void
    {
        $date = '2099-01-01';
        $this->sentState($date, [1]);
        $service = $this->dispatcher([
            $this->row(1), // Already sent on WhatsApp: its fallback must not block campaigns.
            $this->row(2), // New reminder.
            array_merge($this->row(3), ['cellulare' => '', 'telefono' => '', 'patient_email' => '']),
        ]);
        $before = file_get_contents($this->root . '/appointment_reminders_wa_' . $date . '.json');
        $result = $service->run(['send' => false, 'pending_only' => true, 'tenant_id' => 42, 'target_date' => $date]);
        $tenant = $result['tenants'][0];
        $this->assertArrayNotHasKey('error', $tenant);
        $this->assertSame(2, $tenant['pending']); // WA and possible immediate SMS fallback for appointment 2.
        $this->assertSame(1, $tenant['invalid_recipient']);
        $this->assertSame([], $tenant['preview']);
        $this->assertSame($before, file_get_contents($this->root . '/appointment_reminders_wa_' . $date . '.json'));
        $this->assertFileDoesNotExist($this->root . '/appointment_reminders_sms_' . $date . '.json');
    }

    public function testExpiredUnsentAppointmentDoesNotBlockCampaign(): void
    {
        $result = $this->dispatcher([$this->row(2)])->run([
            'send' => false, 'pending_only' => true, 'tenant_id' => 42, 'target_date' => '2000-01-01',
        ]);
        $this->assertArrayNotHasKey('error', $result['tenants'][0]);
        $this->assertSame(0, $result['tenants'][0]['pending']);
        $this->assertSame(1, $result['tenants'][0]['expired']);
    }

    public function testSmsFallbackAlreadySentStopsWhatsAppRetryAndCampaignBlock(): void
    {
        file_put_contents($this->root . '/appointment_reminders_sms_2099-01-01.json', json_encode(['sent' => ['1' => ['sent_at' => '2098-12-30']]]));
        $result = $this->dispatcher([$this->row(1)])->run(['pending_only' => true, 'target_date' => '2099-01-01']);
        $this->assertSame(0, $result['tenants'][0]['pending']);
    }

    public function testPoisonRecipientBacksOffWhileOtherRecipientsKeepPriority(): void
    {
        foreach (['wa', 'sms'] as $channel) {
            file_put_contents($this->root . '/appointment_reminders_' . $channel . '_2099-01-01.json', json_encode([
                'failures' => ['1' => ['attempts' => 1, 'retry_at' => time() + 900]],
            ]));
        }
        $result = $this->dispatcher([$this->row(1), $this->row(2)])->run(['pending_only' => true, 'target_date' => '2099-01-01']);
        $this->assertSame(2, $result['tenants'][0]['pending']);
        $result = $this->dispatcher([$this->row(1)])->run(['pending_only' => true, 'target_date' => '2099-01-01']);
        $this->assertSame(0, $result['tenants'][0]['pending']);
    }

    public function testRepeatedProviderFailureHasBoundedRetries(): void
    {
        $failure = [];
        $now = 1000;
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->assertTrue(\App\Services\AppointmentReminderRetry::ready($failure, $now));
            $failure = \App\Services\AppointmentReminderRetry::failed($failure, $now);
            $this->assertFalse(\App\Services\AppointmentReminderRetry::ready($failure, $now + 300));
            $now = $failure['retry_at'];
        }
        $this->assertTrue(\App\Services\AppointmentReminderRetry::exhausted($failure));
        $this->assertFalse(\App\Services\AppointmentReminderRetry::ready($failure, $now + 86400));
    }

    private function row(int $id): array
    {
        return ['id_appuntamento' => $id, 'ora_label' => '10:00', 'cellulare' => '+393331234567', 'appointment_reminder_sms_enabled' => 1];
    }

    private function sentState(string $date, array $ids): void
    {
        file_put_contents($this->root . '/appointment_reminders_wa_' . $date . '.json', json_encode(['sent' => array_fill_keys($ids, ['sent_at' => '2098-12-30'])]));
    }

    private function dispatcher(array $rows): AppointmentReminderDispatchService
    {
        $tenantResult = $this->createMock(BaseResult::class);
        $tenantResult->method('getResultArray')->willReturn([['id_tenant' => 42, 'tenant_name' => 'Test']]);
        $builder = $this->createMock(BaseBuilder::class);
        foreach (['select', 'where', 'orderBy'] as $method) {
            $builder->method($method)->willReturnSelf();
        }
        $builder->method('get')->willReturn($tenantResult);
        $platform = $this->createMock(BaseConnection::class);
        $platform->method('table')->willReturn($builder);

        $appointments = $this->createMock(BaseResult::class);
        $appointments->method('getResultArray')->willReturn($rows);
        $db = $this->createMock(BaseConnection::class);
        $db->method('query')->willReturn($appointments);
        $connector = $this->createMock(TenantDatabaseConnector::class);
        $connector->method('connect')->willReturn($db);

        $settings = $this->createMock(AppointmentNotificationSettingsService::class);
        $settings->method('resolveTenantSettings')->willReturn(['module' => ['available' => true]]);
        $settings->method('resolveDispatchPlan')->willReturn(['enabled' => true, 'channels' => ['wa', 'sms'], 'lead_days' => 2]);
        $settings->method('channelDefinitions')->willReturn(['wa' => [], 'sms' => []]);
        $policies = $this->createMock(TenantNotificationPolicyService::class);
        $policies->method('resolve')->willReturn(['whatsapp' => ['sms_fallback_enabled' => true]]);
        $storage = $this->createMock(TenantStoragePathService::class);
        $storage->method('reminderStateDir')->with($this->anything(), false)->willReturn($this->root);
        $channels = $this->getMockBuilder(AppointmentNotificationChannelService::class)->onlyMethods(['send'])->getMock();
        $channels->expects($this->never())->method('send');

        $reflection = new \ReflectionClass(AppointmentReminderDispatchService::class);
        $service = $reflection->newInstanceWithoutConstructor();
        foreach ([
            'platformDb' => $platform, 'tenantDbConnector' => $connector, 'settingsService' => $settings,
            'notificationPolicies' => $policies, 'storagePaths' => $storage, 'channelService' => $channels,
        ] as $name => $value) {
            $reflection->getProperty($name)->setValue($service, $value);
        }
        return $service;
    }
}
