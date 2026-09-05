<?php

namespace Tests\Unit;

use App\Services\AppointmentReminderScheduledRunService;
use CodeIgniter\Test\CIUnitTestCase;

final class AppointmentReminderScheduledRunServiceTest extends CIUnitTestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'af-reminder-scheduler-' . bin2hex(random_bytes(6));
        mkdir($this->root, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
        parent::tearDown();
    }

    public function testDoesNotStartBeforeEightInRome(): void
    {
        $calls = 0;
        $service = $this->service(static function () use (&$calls): array {
            $calls++;
            return [];
        });

        $result = $service->run([
            'now' => new \DateTimeImmutable('2026-09-05 07:59:00', new \DateTimeZone('Europe/Rome')),
        ]);

        $this->assertSame('not_due', $result['status']);
        $this->assertSame(0, $calls);
    }

    public function testStartsAtEightAndPassesTheReferenceDate(): void
    {
        $received = [];
        $service = $this->service(static function (array $options) use (&$received): array {
            $received = $options;
            return [
                'failed' => 0,
                'deferred' => 0,
                'sent' => 3,
                'tenants' => [],
            ];
        });

        $result = $service->run([
            'now' => new \DateTimeImmutable('2026-09-05 08:00:00', new \DateTimeZone('Europe/Rome')),
        ]);

        $this->assertSame('completed', $result['status']);
        $this->assertTrue($received['send']);
        $this->assertSame('2026-09-05', $received['reference_date']);
    }

    public function testDoesNotOpenANewDailyBatchLaterInTheDay(): void
    {
        $calls = 0;
        $service = $this->service(static function () use (&$calls): array {
            $calls++;

            return [];
        });

        $result = $service->run([
            'now' => new \DateTimeImmutable('2026-09-05 20:30:00', new \DateTimeZone('Europe/Rome')),
        ]);

        $this->assertSame('not_due', $result['status']);
        $this->assertSame('2026-09-06 08:00:00', $result['next_run_at']);
        $this->assertSame(0, $calls);
    }

    public function testCompletedDayIsNotRunTwice(): void
    {
        $calls = 0;
        $service = $this->service(static function () use (&$calls): array {
            $calls++;
            return ['failed' => 0, 'deferred' => 0, 'tenants' => []];
        });
        $now = new \DateTimeImmutable('2026-09-05 08:00:00', new \DateTimeZone('Europe/Rome'));

        $first = $service->run(['now' => $now]);
        $second = $service->run(['now' => $now->modify('+5 minutes')]);

        $this->assertSame('completed', $first['status']);
        $this->assertSame('already_completed', $second['status']);
        $this->assertSame(1, $calls);
    }

    public function testDeferredBatchIsRetriedInsteadOfBeingMarkedCompleted(): void
    {
        $references = [];
        $service = $this->service(static function (array $options) use (&$references): array {
            $references[] = $options['reference_date'];
            $deferred = count($references) === 1 ? 2 : 0;
            return ['failed' => 0, 'deferred' => $deferred, 'tenants' => []];
        });
        $now = new \DateTimeImmutable('2026-09-05 08:00:00', new \DateTimeZone('Europe/Rome'));

        $first = $service->run(['now' => $now]);
        $second = $service->run(['now' => $now->modify('+5 minutes')]);

        $this->assertSame('retry_required', $first['status']);
        $this->assertSame('completed', $second['status']);
        $this->assertSame(['2026-09-05', '2026-09-05'], $references);
    }

    public function testUnfinishedPreviousDayIsResumedWithItsOriginalReferenceDate(): void
    {
        $stateDir = $this->root . DIRECTORY_SEPARATOR . 'state';
        mkdir($stateDir, 0775, true);
        file_put_contents(
            $stateDir . DIRECTORY_SEPARATOR . 'appointment_reminder_scheduler_2026-09-04.json',
            json_encode(['status' => 'retry_required', 'reference_date' => '2026-09-04'])
        );
        $received = [];
        $service = $this->service(static function (array $options) use (&$received): array {
            $received = $options;
            return ['failed' => 0, 'deferred' => 0, 'tenants' => []];
        }, $stateDir);

        $result = $service->run([
            'now' => new \DateTimeImmutable('2026-09-05 07:00:00', new \DateTimeZone('Europe/Rome')),
        ]);

        $this->assertSame('completed', $result['status']);
        $this->assertSame('2026-09-04', $received['reference_date']);
    }

    private function service(\Closure $dispatcher, ?string $stateDir = null): AppointmentReminderScheduledRunService
    {
        return new AppointmentReminderScheduledRunService(
            $dispatcher,
            $stateDir ?? ($this->root . DIRECTORY_SEPARATOR . 'state'),
            $this->root . DIRECTORY_SEPARATOR . 'locks'
        );
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($child)) {
                $this->removeDirectory($child);
            } else {
                unlink($child);
            }
        }
        rmdir($path);
    }
}
