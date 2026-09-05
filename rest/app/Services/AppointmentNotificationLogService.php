<?php

namespace App\Services;

use Config\Database;

class AppointmentNotificationLogService
{
    public const PLATFORM_TABLE = 'platform_appointment_notification_logs';

    private TenantStoragePathService $paths;
    private \CodeIgniter\Database\BaseConnection $platformDb;
    private ?bool $platformTableReady = null;

    public function __construct(
        ?TenantStoragePathService $paths = null,
        ?\CodeIgniter\Database\BaseConnection $platformDb = null
    )
    {
        $this->paths = $paths ?? new TenantStoragePathService();
        $this->platformDb = $platformDb ?? Database::connect('platform');
    }

    public function centralStorageReady(): bool
    {
        if ($this->platformTableReady !== null) {
            return $this->platformTableReady;
        }

        try {
            return $this->platformTableReady = $this->platformDb->tableExists(self::PLATFORM_TABLE);
        } catch (\Throwable $e) {
            return $this->platformTableReady = false;
        }
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
            'response' => $this->redactSensitiveData($entry['response'] ?? null),
            'created_at' => $createdAt,
        ];

        $this->appendToPlatformDatabase($payload);

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
            $this->readPlatformEntries($tenant, $days),
            $this->readUnifiedEntries($tenant, $days),
            $this->readLegacyReminderEntries($tenant, $days)
        );
        $entries = $this->applySmsFactorDeliveryReceipts($tenant, $entries);

        $entries = $this->deduplicateEntries($entries);

        usort($entries, static function (array $left, array $right): int {
            return strcmp((string) ($right['created_at'] ?? ''), (string) ($left['created_at'] ?? ''));
        });

        return $limit > 0 ? array_slice($entries, 0, $limit) : $entries;
    }

    /** @param array<string, mixed> $payload */
    private function appendToPlatformDatabase(array $payload): void
    {
        try {
            if (!$this->centralStorageReady()) {
                return;
            }

            $responseJson = null;
            if (($payload['response'] ?? null) !== null) {
                $encoded = json_encode($payload['response'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $responseJson = is_string($encoded) ? $encoded : null;
            }

            $this->platformDb->table(self::PLATFORM_TABLE)->insert([
                'event_id' => mb_substr((string) ($payload['event_id'] ?? ''), 0, 64),
                'id_tenant' => (int) ($payload['tenant_id'] ?? 0) > 0 ? (int) $payload['tenant_id'] : null,
                'tenant_key' => $this->nullableString($payload['tenant_key'] ?? '', 100),
                'tenant_name' => $this->nullableString($payload['tenant_name'] ?? '', 190),
                'message_type' => mb_substr((string) ($payload['message_type'] ?? 'notification'), 0, 64),
                'status' => mb_substr((string) ($payload['status'] ?? 'sent'), 0, 32),
                'channel' => mb_substr((string) ($payload['channel'] ?? ''), 0, 24),
                'provider' => $this->nullableString($payload['provider'] ?? '', 120),
                'provider_id' => $this->nullableString($payload['provider_id'] ?? '', 191),
                'recipient' => $this->nullableString($payload['recipient'] ?? '', 255),
                'recipient_role' => $this->nullableString($payload['recipient_role'] ?? '', 32),
                'appointment_id' => (int) ($payload['appointment_id'] ?? 0) > 0 ? (int) $payload['appointment_id'] : null,
                'doctor_id' => (int) ($payload['doctor_id'] ?? 0) > 0 ? (int) $payload['doctor_id'] : null,
                'doctor_label' => $this->nullableString($payload['doctor_label'] ?? '', 190),
                'actor_user_id' => (int) ($payload['actor_user_id'] ?? 0) > 0 ? (int) $payload['actor_user_id'] : null,
                'actor_label' => $this->nullableString($payload['actor_label'] ?? '', 190),
                'patient_label' => $this->nullableString($payload['patient_label'] ?? '', 190),
                'scheduled_for' => $this->nullableString($payload['scheduled_for'] ?? '', 64),
                'notes' => $this->nullableString($payload['notes'] ?? '', 65535),
                'source' => mb_substr((string) ($payload['source'] ?? 'runtime'), 0, 80),
                'error_message' => $this->nullableString($payload['error'] ?? '', 20000),
                'response_json' => $responseJson,
                'created_at' => date('Y-m-d H:i:s', strtotime((string) ($payload['created_at'] ?? 'now')) ?: time()),
            ]);
        } catch (\Throwable $e) {
            log_message('warning', 'Scrittura log notifiche nel database piattaforma fallita: {message}', [
                'message' => $e->getMessage(),
                'tenant_id' => (int) ($payload['tenant_id'] ?? 0),
                'event_id' => (string) ($payload['event_id'] ?? ''),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $tenant
     * @return array<int, array<string, mixed>>
     */
    private function readPlatformEntries(array $tenant, int $days): array
    {
        $tenantId = max(0, (int) ($tenant['id_tenant'] ?? 0));
        if ($tenantId <= 0) {
            return [];
        }

        try {
            if (!$this->centralStorageReady()) {
                return [];
            }

            $rows = $this->platformDb->table(self::PLATFORM_TABLE)
                ->where('id_tenant', $tenantId)
                ->where('created_at >=', date('Y-m-d H:i:s', strtotime('-' . max(1, $days) . ' day')))
                ->orderBy('created_at', 'DESC')
                ->limit(5000)
                ->get()
                ->getResultArray();
        } catch (\Throwable $e) {
            log_message('warning', 'Lettura log notifiche dal database piattaforma fallita: {message}', [
                'message' => $e->getMessage(),
                'tenant_id' => $tenantId,
            ]);
            return [];
        }

        return array_map(static function (array $row): array {
            $response = null;
            $responseJson = trim((string) ($row['response_json'] ?? ''));
            if ($responseJson !== '') {
                $decoded = json_decode($responseJson, true);
                $response = json_last_error() === JSON_ERROR_NONE ? $decoded : $responseJson;
            }

            return [
                'event_id' => (string) ($row['event_id'] ?? ''),
                'tenant_id' => (int) ($row['id_tenant'] ?? 0),
                'tenant_key' => (string) ($row['tenant_key'] ?? ''),
                'tenant_name' => (string) ($row['tenant_name'] ?? ''),
                'message_type' => (string) ($row['message_type'] ?? ''),
                'status' => (string) ($row['status'] ?? ''),
                'channel' => (string) ($row['channel'] ?? ''),
                'provider' => (string) ($row['provider'] ?? ''),
                'provider_id' => (string) ($row['provider_id'] ?? ''),
                'recipient' => (string) ($row['recipient'] ?? ''),
                'recipient_role' => (string) ($row['recipient_role'] ?? ''),
                'appointment_id' => (int) ($row['appointment_id'] ?? 0),
                'doctor_id' => (int) ($row['doctor_id'] ?? 0),
                'doctor_label' => (string) ($row['doctor_label'] ?? ''),
                'actor_user_id' => (int) ($row['actor_user_id'] ?? 0),
                'actor_label' => (string) ($row['actor_label'] ?? ''),
                'patient_label' => (string) ($row['patient_label'] ?? ''),
                'scheduled_for' => (string) ($row['scheduled_for'] ?? ''),
                'notes' => (string) ($row['notes'] ?? ''),
                'source' => (string) ($row['source'] ?? 'platform_database'),
                'error' => (string) ($row['error_message'] ?? ''),
                'response' => $response,
                'created_at' => (string) ($row['created_at'] ?? ''),
            ];
        }, $rows);
    }

    /**
     * @param array<string, mixed> $tenant
     * @param array<int, array<string, mixed>> $entries
     * @return array<int, array<string, mixed>>
     */
    private function applySmsFactorDeliveryReceipts(array $tenant, array $entries): array
    {
        $tenantId = max(0, (int) ($tenant['id_tenant'] ?? 0));
        if ($tenantId <= 0 || $entries === []) {
            return $entries;
        }

        $providerIds = [];
        foreach ($entries as $entry) {
            if (
                strtolower(trim((string) ($entry['channel'] ?? ''))) !== AppointmentNotificationSettingsService::CHANNEL_SMS
                || strtolower(trim((string) ($entry['provider'] ?? ''))) !== 'smsfactor'
            ) {
                continue;
            }

            $providerId = trim((string) ($entry['provider_id'] ?? ''));
            if ($providerId !== '') {
                $providerIds[] = $providerId;
            }
        }
        $providerIds = array_values(array_unique($providerIds));
        if ($providerIds === []) {
            return $entries;
        }

        try {
            if (!$this->platformDb->tableExists('platform_sms_delivery_receipts')) {
                return $entries;
            }

            $rows = $this->platformDb->table('platform_sms_delivery_receipts')
                ->where('id_tenant', $tenantId)
                ->groupStart()
                    ->whereIn('campaign_id', $providerIds)
                    ->orWhereIn('client_message_id', $providerIds)
                ->groupEnd()
                ->orderBy('updated_at', 'DESC')
                ->get()
                ->getResultArray();
        } catch (\Throwable $e) {
            log_message('warning', 'Lettura ricevute SMSFactor fallita: {message}', [
                'message' => $e->getMessage(),
                'tenant_id' => $tenantId,
            ]);
            return $entries;
        }

        $receiptByProviderId = [];
        foreach ($rows as $row) {
            foreach (['campaign_id', 'client_message_id'] as $field) {
                $key = trim((string) ($row[$field] ?? ''));
                if ($key !== '' && !isset($receiptByProviderId[$key])) {
                    $receiptByProviderId[$key] = $row;
                }
            }
        }

        foreach ($entries as &$entry) {
            $providerId = trim((string) ($entry['provider_id'] ?? ''));
            $receipt = $providerId !== '' ? ($receiptByProviderId[$providerId] ?? null) : null;
            if (!is_array($receipt)) {
                continue;
            }

            $entry['delivery_status'] = (string) ($receipt['delivery_status'] ?? '');
            $entry['delivery_status_code'] = (int) ($receipt['status_code'] ?? 0);
            $entry['delivery_received_at'] = (string) ($receipt['updated_at'] ?? '');
            $entry['delivered_at'] = (string) ($receipt['occurred_at'] ?? '');
        }
        unset($entry);

        return $entries;
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
            $eventId = trim((string) ($entry['event_id'] ?? ''));
            $key = $eventId !== ''
                ? ('event:' . $eventId)
                : implode('|', [
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

    private function nullableString($value, int $maxLength): ?string
    {
        $value = trim((string) $value);
        return $value !== '' ? mb_substr($value, 0, $maxLength) : null;
    }

    private function redactSensitiveData($value)
    {
        if (!is_array($value)) {
            return $value;
        }

        $redacted = [];
        foreach ($value as $key => $item) {
            $keyText = strtolower((string) $key);
            if (preg_match('/token|password|secret|authorization|api[_-]?key|session[_-]?key|user[_-]?key/', $keyText) === 1) {
                $redacted[$key] = '[redacted]';
                continue;
            }
            $redacted[$key] = $this->redactSensitiveData($item);
        }

        return $redacted;
    }
}
