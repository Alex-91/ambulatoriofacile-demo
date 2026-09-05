<?php

namespace Tests\Unit;

use App\Services\AppointmentNotificationLogService;
use App\Services\TenantStoragePathService;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

final class AppointmentNotificationLogServiceTest extends CIUnitTestCase
{
    private BaseConnection $platformDb;
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->temporaryDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'af-notification-log-' . bin2hex(random_bytes(6));
        mkdir($this->temporaryDirectory, 0775, true);

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
        $this->platformDb->query('CREATE TABLE platform_tenants (id_tenant INTEGER PRIMARY KEY, is_active INTEGER NOT NULL)');
        $this->platformDb->query('INSERT INTO platform_tenants (id_tenant, is_active) VALUES (7, 1)');
        $this->platformDb->query(
            'CREATE TABLE platform_appointment_notification_logs (
                id_notification_log INTEGER PRIMARY KEY AUTOINCREMENT,
                event_id TEXT NOT NULL UNIQUE,
                id_tenant INTEGER NULL,
                tenant_key TEXT NULL,
                tenant_name TEXT NULL,
                message_type TEXT NOT NULL,
                status TEXT NOT NULL,
                channel TEXT NOT NULL,
                provider TEXT NULL,
                provider_id TEXT NULL,
                recipient TEXT NULL,
                recipient_role TEXT NULL,
                appointment_id INTEGER NULL,
                doctor_id INTEGER NULL,
                doctor_label TEXT NULL,
                actor_user_id INTEGER NULL,
                actor_label TEXT NULL,
                patient_label TEXT NULL,
                scheduled_for TEXT NULL,
                notes TEXT NULL,
                source TEXT NOT NULL,
                error_message TEXT NULL,
                response_json TEXT NULL,
                created_at TEXT NOT NULL
            )'
        );
    }

    protected function tearDown(): void
    {
        foreach (glob($this->temporaryDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if (is_dir($this->temporaryDirectory)) {
            rmdir($this->temporaryDirectory);
        }
        parent::tearDown();
    }

    public function testAppendPersistsCentralLogAndDeduplicatesFileFallback(): void
    {
        $directory = $this->temporaryDirectory;
        $paths = new class($directory) extends TenantStoragePathService {
            public function __construct(private string $directory)
            {
            }

            public function notificationsDir(array $tenant, bool $ensure = false): string
            {
                return $this->directory;
            }

            public function reminderStateDir(array $tenant, bool $ensure = false): string
            {
                return $this->directory . DIRECTORY_SEPARATOR . 'missing-reminder-state';
            }

            public function globalReminderStateDir(): string
            {
                return $this->directory . DIRECTORY_SEPARATOR . 'missing-global-state';
            }
        };
        $service = new AppointmentNotificationLogService($paths, $this->platformDb);
        $tenant = ['id_tenant' => 7, 'tenant_key' => 'studio-test', 'tenant_name' => 'Studio Test'];

        $this->assertTrue($service->centralStorageReady());

        $service->append($tenant, [
            'event_id' => 'evt_test_email',
            'message_type' => 'patient_booking_confirmation',
            'status' => 'failed',
            'channel' => 'email',
            'provider' => 'Email',
            'recipient' => 'patient@example.test',
            'error' => 'Autenticazione SMTP non riuscita',
            'response' => ['authorization' => 'Bearer hidden-value', 'code' => 535],
            'created_at' => '2026-09-05T12:00:00+00:00',
        ]);

        $stored = $this->platformDb->table(AppointmentNotificationLogService::PLATFORM_TABLE)->get()->getRowArray();
        $entries = $service->listEntriesForTenant($tenant, 365, 20);

        $this->assertSame('evt_test_email', $stored['event_id']);
        $this->assertStringContainsString('[redacted]', (string) $stored['response_json']);
        $this->assertStringNotContainsString('hidden-value', (string) $stored['response_json']);
        $this->assertCount(1, $entries);
        $this->assertSame('Autenticazione SMTP non riuscita', $entries[0]['error']);
    }
}
