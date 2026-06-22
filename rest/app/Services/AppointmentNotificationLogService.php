<?php

namespace App\Services;

use Config\Database;

class AppointmentNotificationLogService
{
    private TenantStoragePathService $paths;
    private \CodeIgniter\Database\BaseConnection $platformDb;

    public function __construct()
    {
        $this->paths = new TenantStoragePathService();
        $this->platformDb = Database::connect('platform');
    }

    /**
     * @param array<string, mixed> $tenant
     * @param array<string, mixed> $entry
     */
    public function append(array $tenant, array $entry): void
    {
        $dir = $this->paths->notificationsDir($tenant, true);
        $createdAt = trim((string) ($entry['created_at'] ?? '')) ?: date('c');
        $file = $dir . DIRECTORY_SEPARATOR . 'appointment_notifications_' . date('Y-m', strtotime($createdAt)) . '.jsonl';

        $payload = [
            'event_id' => trim((string) ($entry['event_id'] ?? '')) !== ''
                ? trim((string) ($entry['event_id'] ?? ''))
                : ('evt_' . bin2hex(random_bytes(8))),
            'tenant_id' => (int) ($entry['tenant_id'] ?? ($tenant['id_tenant'] ?? 0)),
            'tenant_key' => (string) ($entry['tenant_key'] ?? ($tenant['tenant_key'] ?? '')),
            'tenant_name' => (string) ($entry['tenant_name'] ?? ($tenant['tenant_name'] ?? '')),
            'message_type' => (string) ($entry['message_type'] ?? ''),
            'status' => (string) ($entry['status'] ?? 'sent'),
            'channel' => (string) ($entry['channel'] ?? ''),
            'provider' => (string) ($entry['provider'] ?? ''),
            'provider_id' => (string) ($entry['provider_id'] ?? ''),
            'recipient' => (string) ($entry['recipient'] ?? ''),
            'recipient_role' => (string) ($entry['recipient_role'] ?? ''),
            'appointment_id' => (int) ($entry['appointment_id'] ?? 0),
            'doctor_id' => (int) ($entry['doctor_id'] ?? 0),
            'doctor_label' => (string) ($entry['doctor_label'] ?? ''),
            'actor_user_id' => (int) ($entry['actor_user_id'] ?? 0),
            'actor_label' => (string) ($entry['actor_label'] ?? ''),
            'patient_label' => (string) ($entry['patient_label'] ?? ''),
            'scheduled_for' => (string) ($entry['scheduled_for'] ?? ''),
            'notes' => (string) ($entry['notes'] ?? ''),
            'source' => (string) ($entry['source'] ?? 'runtime'),
            'error' => (string) ($entry['error'] ?? ''),
            'response' => $entry['response'] ?? null,
            'created_at' => $createdAt,
        ];

        file_put_contents(
            $file,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }

    /**
     * @param array<string, mixed> $tenant
     * @return array<int, array<string, mixed>>
     */
    public function listEntriesForTenant(array $tenant, int $days = 30, int $limit = 200): array
    {
        $entries = array_merge(
            $this->readUnifiedEntries($tenant, $days),
            $this->readLegacyReminderEntries($tenant, $days)
        );

        $entries = $this->deduplicateEntries($entries);

        usort($entries, static function (array $left, array $right): int {
            return strcmp((string) ($right['created_at'] ?? ''), (string) ($left['created_at'] ?? ''));
        });

        return $limit > 0 ? array_slice($entries, 0, $limit) : $entries;
    }

    /**
     * @param array<string, mixed> $tenant
     * @return array<int, array<string, mixed>>
     */
    private function readUnifiedEntries(array $tenant, int $days): array
    {
        $dir = $this->paths->notificationsDir($tenant, false);
        if (!is_dir($dir)) {
            return [];
        }

        $cutoff = strtotime('-' . max(1, $days) . ' day');
        $files = glob($dir . DIRECTORY_SEPARATOR . 'appointment_notifications_*.jsonl') ?: [];
        rsort($files, SORT_STRING);

        $rows = [];
        foreach ($files as $file) {
            $handle = @fopen($file, 'rb');
            if ($handle === false) {
                continue;
            }

            while (($line = fgets($handle)) !== false) {
                $decoded = json_decode(trim($line), true);
                if (!is_array($decoded)) {
                    continue;
                }

                $createdAt = strtotime((string) ($decoded['created_at'] ?? ''));
                if ($createdAt !== false && $createdAt < $cutoff) {
                    continue;
                }

                $rows[] = $decoded;
            }

            fclose($handle);
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $tenant
     * @return array<int, array<string, mixed>>
     */
    private function readLegacyReminderEntries(array $tenant, int $days): array
    {
        $dirs = [$this->paths->reminderStateDir($tenant, false)];
        if ($this->canUseGlobalReminderFallback()) {
            $dirs[] = $this->paths->globalReminderStateDir();
        }

        $cutoff = strtotime('-' . max(1, $days) . ' day');
        $rows = [];

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }

            $files = glob($dir . DIRECTORY_SEPARATOR . 'appointment_reminders_*_*.json') ?: [];
            foreach ($files as $file) {
                $basename = basename($file);
                if (!preg_match('/^appointment_reminders_(sms|wa)_(\d{4}-\d{2}-\d{2})\.json$/', $basename, $matches)) {
                    continue;
                }

                $channel = (string) $matches[1];
                $targetDate = (string) $matches[2];
                $json = file_get_contents($file);
                if ($json === false || trim($json) === '') {
                    continue;
                }

                $decoded = json_decode($json, true);
                $sentRows = is_array($decoded['sent'] ?? null) ? $decoded['sent'] : [];
                foreach ($sentRows as $appointmentId => $payload) {
                    if (!is_array($payload)) {
                        continue;
                    }

                    $createdAt = strtotime((string) ($payload['sent_at'] ?? ''));
                    if ($createdAt !== false && $createdAt < $cutoff) {
                        continue;
                    }

                    $rows[] = [
                        'event_id' => 'legacy_' . md5($basename . '|' . (string) $appointmentId . '|' . (string) ($payload['sent_at'] ?? '')),
                        'tenant_id' => (int) ($tenant['id_tenant'] ?? 0),
                        'tenant_key' => (string) ($tenant['tenant_key'] ?? ''),
                        'tenant_name' => (string) ($tenant['tenant_name'] ?? ''),
                        'message_type' => AppointmentNotificationSettingsService::TYPE_REMINDER,
                        'status' => 'sent',
                        'channel' => $channel,
                        'provider' => $channel === 'sms' ? 'Aruba SMS' : 'UltraMsg',
                        'provider_id' => (string) ($payload['provider_id'] ?? ''),
                        'recipient' => (string) ($payload['recipient'] ?? ''),
                        'recipient_role' => 'patient',
                        'appointment_id' => (int) $appointmentId,
                        'doctor_id' => 0,
                        'doctor_label' => '',
                        'actor_user_id' => 0,
                        'actor_label' => '',
                        'patient_label' => '',
                        'scheduled_for' => $targetDate,
                        'notes' => '',
                        'source' => 'legacy_reminder_state',
                        'error' => '',
                        'response' => $payload['response'] ?? null,
                        'created_at' => (string) ($payload['sent_at'] ?? ''),
                    ];
                }
            }
        }

        return $rows;
    }

    private function canUseGlobalReminderFallback(): bool
    {
        try {
            if (!$this->platformDb->tableExists('platform_tenants')) {
                return true;
            }

            $count = (int) $this->platformDb->table('platform_tenants')
                ->where('is_active', 1)
                ->countAllResults();

            return $count <= 1;
        } catch (\Throwable $e) {
            log_message('warning', 'AppointmentNotificationLogService global fallback check failed: {message}', [
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @param array<int, array<string, mixed>> $entries
     * @return array<int, array<string, mixed>>
     */
    private function deduplicateEntries(array $entries): array
    {
        $unique = [];
        $keys = [];

        foreach ($entries as $entry) {
            $key = implode('|', [
                (string) ($entry['message_type'] ?? ''),
                (string) ($entry['channel'] ?? ''),
                (string) ($entry['appointment_id'] ?? ''),
                (string) ($entry['recipient'] ?? ''),
                substr((string) ($entry['created_at'] ?? ''), 0, 16),
            ]);

            if (isset($keys[$key])) {
                continue;
            }

            $keys[$key] = true;
            $unique[] = $entry;
        }

        return $unique;
    }
}
