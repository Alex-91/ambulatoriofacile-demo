<?php

namespace Tests\Unit;

use App\Services\AppointmentReminderPriorityService;
use App\Services\NotificationRateLimiterService;
use App\Services\TenantNotificationPolicyService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;

final class AppointmentReminderPriorityServiceTest extends CIUnitTestCase
{
    private string $stateDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stateDir = sys_get_temp_dir() . '/af-priority-' . bin2hex(random_bytes(6));
        mkdir($this->stateDir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->stateDir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->stateDir);
        parent::tearDown();
    }

    public function testCampaignReservesMorningCapacityForItsOwnTenantBeforeEight(): void
    {
        $seen = [];
        $service = $this->service(static function (array $options) use (&$seen): array {
            $seen[] = $options;
            return ['tenants' => [['pending' => $options['tenant_id'] === 42 ? 3 : 0]]];
        });
        $this->assertTrue($service->hasPending(42, $this->now('07:30')));
        $this->assertFalse($service->hasPending(43, $this->now('07:30')));
        $this->assertSame(false, $seen[0]['send']);
        $this->assertSame(true, $seen[0]['pending_only']);
        $this->assertSame('2026-09-22', $seen[0]['reference_date']);
    }

    public function testCompletedDayIsRecheckedInCollectionWindowButNotAfterwards(): void
    {
        $this->state('2026-09-22', 'completed');
        $calls = 0;
        $service = $this->service(static function () use (&$calls): array {
            $calls++;
            return ['tenants' => [['pending' => 1]]];
        });
        $this->assertTrue($service->hasPending(42, $this->now('08:55')));
        $this->assertFalse($service->hasPending(42, $this->now('09:00')));
        $this->assertSame(1, $calls);
    }

    public function testHistoricalRetryStillHasPriorityAfterMorningWindow(): void
    {
        $this->state('2026-09-21', 'retry_required');
        $this->state('2026-09-22', 'completed');
        $seen = [];
        $service = $this->service(static function (array $options) use (&$seen): array {
            $seen[] = $options['reference_date'];
            return ['tenants' => [['pending' => 1]]];
        });
        $this->assertTrue($service->hasPending(42, $this->now('14:00')));
        $this->assertSame(['2026-09-21'], $seen);
    }

    public function testCampaignResumesAsSoonAsActualPendingRemindersAreGone(): void
    {
        $this->state('2026-09-22', 'running');
        $pending = 1;
        $service = $this->service(static function () use (&$pending): array {
            return ['tenants' => [['pending' => $pending]]];
        });
        $this->assertTrue($service->hasPending(42, $this->now('10:00')));
        $pending = 0;
        $this->assertFalse($service->hasPending(42, $this->now('10:01')));
    }

    public function testMissingTodaysRunDoesNotLetCampaignConsumeCapacity(): void
    {
        $service = $this->service(static fn(): array => ['tenants' => [['pending' => 2]]]);
        $this->assertTrue($service->hasPending(42, $this->now('10:00')));
    }

    public function testCampaignDenialDoesNotConsumeQuotaOrStartATransaction(): void
    {
        $db = $this->createMock(BaseConnection::class);
        $db->expects($this->never())->method('query');
        $db->expects($this->never())->method('table');
        $db->expects($this->never())->method('tableExists');
        $db->expects($this->never())->method('transBegin');
        $policies = $this->createMock(TenantNotificationPolicyService::class);
        $priority = $this->service(static fn(): array => ['tenants' => [['pending' => 1]]]);
        $limiter = new NotificationRateLimiterService($db, $policies, $priority);
        $result = $limiter->claim(42, 'wa', [], false, true);
        $this->assertFalse($result['allowed']);
        $this->assertSame('reminder_priority', $result['reason']);
        $this->assertFalse($result['tracked']);
    }

    public function testReminderSendsDoNotEnterCampaignPriorityGate(): void
    {
        $db = $this->createMock(BaseConnection::class);
        $db->method('tableExists')->willReturn(false);
        $policies = $this->createMock(TenantNotificationPolicyService::class);
        $priority = $this->service(static function (): array {
            throw new \LogicException('A reminder must not block itself.');
        });
        $limiter = new NotificationRateLimiterService($db, $policies, $priority);
        $this->assertTrue($limiter->claim(42, 'wa', [])['allowed']);
    }

    public function testUnavailableTenantPausesCampaignWithoutConsumingQuota(): void
    {
        $db = $this->createMock(BaseConnection::class);
        $db->expects($this->never())->method('query');
        $priority = $this->service(static fn(): array => ['tenants' => [['error' => 'Unavailable']]]);
        $limiter = new NotificationRateLimiterService($db, $this->createMock(TenantNotificationPolicyService::class), $priority);
        $result = $limiter->claim(42, 'wa', [], true, true);
        $this->assertFalse($result['allowed']);
        $this->assertSame('reminder_priority_unavailable', $result['reason']);
    }

    public function testCorruptSchedulerStatePausesCampaign(): void
    {
        file_put_contents($this->stateDir . '/appointment_reminder_scheduler_2026-09-22.json', '{');
        $this->expectException(\RuntimeException::class);
        $this->service(static fn(): array => [])->hasPending(42, $this->now('10:00'));
    }

    public function testExpiredDatesAndTimesAreRejectedIncludingExactBoundary(): void
    {
        $now = $this->now('10:30');
        $this->assertFalse(AppointmentReminderPriorityService::isFutureAppointment('2026-09-21', '23:59', $now));
        $this->assertFalse(AppointmentReminderPriorityService::isFutureAppointment('2026-09-22', '10:29', $now));
        $this->assertFalse(AppointmentReminderPriorityService::isFutureAppointment('2026-09-22', '10:30', $now));
        $this->assertTrue(AppointmentReminderPriorityService::isFutureAppointment('2026-09-22', '10:31:00', $now));
        $this->assertTrue(AppointmentReminderPriorityService::isFutureAppointment('2026-09-23', '08:00', $now));
        $this->assertFalse(AppointmentReminderPriorityService::isFutureAppointment('2026-09-31', '10:30', $now));
        $this->assertFalse(AppointmentReminderPriorityService::isFutureAppointment('2026-09-22', '', $now));
    }

    public function testAppointmentComparisonUsesRomeEvenWhenRuntimeIsUtc(): void
    {
        $now = new \DateTimeImmutable('2026-09-22 08:30:00', new \DateTimeZone('UTC'));
        $this->assertFalse(AppointmentReminderPriorityService::isFutureAppointment('2026-09-22', '10:00', $now));
        $this->assertTrue(AppointmentReminderPriorityService::isFutureAppointment('2026-09-22', '11:00', $now));
    }

    private function service(\Closure $probe): AppointmentReminderPriorityService
    {
        return new AppointmentReminderPriorityService($probe, $this->stateDir);
    }

    private function now(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-09-22 ' . $time . ':00', new \DateTimeZone('Europe/Rome'));
    }

    private function state(string $date, string $status): void
    {
        file_put_contents($this->stateDir . '/appointment_reminder_scheduler_' . $date . '.json', json_encode(['status' => $status]));
    }
}
