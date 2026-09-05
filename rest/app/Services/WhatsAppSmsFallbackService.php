<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

class WhatsAppSmsFallbackService
{
    public const TABLE = 'platform_notification_fallbacks';

    private BaseConnection $platformDb;
    private TenantNotificationPolicyService $policies;
    private NotificationRateLimiterService $rateLimiter;
    private AppointmentNotificationChannelService $channels;
    private AppointmentNotificationSettingsService $settings;
    private ?WhatsAppGatewayClient $gateway;

    public function __construct(
        ?BaseConnection $platformDb = null,
        ?TenantNotificationPolicyService $policies = null,
        ?NotificationRateLimiterService $rateLimiter = null,
        ?AppointmentNotificationChannelService $channels = null,
        ?AppointmentNotificationSettingsService $settings = null,
        ?WhatsAppGatewayClient $gateway = null
    ) {
        $this->platformDb = $platformDb ?? Database::connect('platform');
        $this->policies = $policies ?? new TenantNotificationPolicyService($this->platformDb);
        $this->rateLimiter = $rateLimiter ?? new NotificationRateLimiterService($this->platformDb, $this->policies);
        $this->channels = $channels ?? new AppointmentNotificationChannelService();
        $this->settings = $settings ?? new AppointmentNotificationSettingsService();
        $this->gateway = $gateway;
    }

    /**
     * @return array<string, mixed>
     */
    public function registerPending(
        int $tenantId,
        string $sourceType,
        int $sourceId,
        string $messageType,
        string $recipientPhone,
        string $message,
        string $whatsAppProviderId,
        bool $smsChannelSelected,
        bool $immediate = false
    ): array {
        if (!$this->platformDb->tableExists(self::TABLE)) {
            return ['registered' => false, 'reason' => 'schema_missing'];
        }

        $tenant = $this->tenant($tenantId);
        $policy = $this->policies->resolve($tenantId, (string) ($tenant['tenant_name'] ?? ''));
        if (!$smsChannelSelected || empty($policy['whatsapp']['sms_fallback_enabled'])) {
            return ['registered' => false, 'reason' => 'fallback_disabled'];
        }
        if (!WhatsAppGatewayClient::isRoutedToGateway($tenantId)) {
            return ['registered' => false, 'reason' => 'not_gateway_routed'];
        }

        $phone = $this->channels->normalizeRecipient($recipientPhone) ?? '';
        $message = trim($message);
        if ($phone === '' || $message === '') {
            return ['registered' => false, 'reason' => 'invalid_payload'];
        }

        $sourceType = preg_replace('/[^a-z0-9_\-]/', '', strtolower(trim($sourceType))) ?: 'notification';
        $messageType = preg_replace('/[^a-z0-9_\-]/', '', strtolower(trim($messageType))) ?: 'notification';
        $fallbackKey = hash('sha256', implode('|', [
            $tenantId,
            $sourceType,
            max(0, $sourceId),
            trim($whatsAppProviderId),
            $phone,
            hash('sha256', $message),
        ]));
        $existing = $this->platformDb->table(self::TABLE)
            ->where('fallback_key', $fallbackKey)
            ->get(1)
            ->getRowArray();
        if ($existing) {
            return ['registered' => true, 'existing' => true, 'row' => $existing];
        }

        $now = date('Y-m-d H:i:s');
        $dueAt = $immediate
            ? $now
            : date(
                'Y-m-d H:i:s',
                time() + (max(5, (int) ($policy['whatsapp']['fallback_after_minutes'] ?? 30)) * 60)
            );
        $ok = $this->platformDb->table(self::TABLE)->insert([
            'id_tenant' => $tenantId,
            'fallback_key' => $fallbackKey,
            'source_type' => $sourceType,
            'source_id' => $sourceId > 0 ? $sourceId : null,
            'message_type' => $messageType,
            'recipient_phone' => $phone,
            'message_text' => $message,
            'whatsapp_provider_id' => trim($whatsAppProviderId) ?: null,
            'whatsapp_status' => $immediate ? 'failed' : 'sent',
            'status' => 'pending',
            'due_at' => $dueAt,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [
            'registered' => (bool) $ok,
            'existing' => false,
            'fallback_id' => $ok ? (int) $this->platformDb->insertID() : 0,
            'due_at' => $dueAt,
        ];
    }

    /**
     * Processes fallbacks only after the configured delivery deadline. A message
     * already marked delivered/read is closed without sending an SMS.
     *
     * @return array<string, mixed>
     */
    public function reconcile(int $limit = 50): array
    {
        $summary = ['checked' => 0, 'delivered' => 0, 'sms_sent' => 0, 'sms_failed' => 0, 'deferred' => 0, 'errors' => []];
        if (!$this->platformDb->tableExists(self::TABLE)) {
            $summary['errors'][] = 'schema_missing';
            return $summary;
        }

        $rows = $this->platformDb->table(self::TABLE . ' f')
            ->select('f.*, t.tenant_name')
            ->join('platform_tenants t', 't.id_tenant = f.id_tenant')
            ->where('f.status', 'pending')
            ->where('f.due_at <=', date('Y-m-d H:i:s'))
            ->orderBy('f.due_at', 'ASC')
            ->get(max(1, min(200, $limit)))
            ->getResultArray();
        if ($rows === []) {
            return $summary;
        }

        $byTenant = [];
        foreach ($rows as $row) {
            $byTenant[(int) ($row['id_tenant'] ?? 0)][] = $row;
        }

        foreach ($byTenant as $tenantId => $tenantRows) {
            if ($tenantId <= 0) {
                continue;
            }
            try {
                $statusById = [];
                $timelineError = null;
                $needsTimeline = false;
                foreach ($tenantRows as $tenantRow) {
                    if ((string) ($tenantRow['whatsapp_status'] ?? '') !== 'failed') {
                        $needsTimeline = true;
                        break;
                    }
                }
                if ($needsTimeline) {
                    try {
                        $timeline = $this->gateway()->messages($tenantId, 100);
                        if (empty($timeline['ok'])) {
                            throw new \RuntimeException((string) ($timeline['error'] ?? 'Stato WhatsApp non disponibile.'));
                        }
                        foreach ((array) ($timeline['messages'] ?? []) as $messageRow) {
                            if (!is_array($messageRow)) {
                                continue;
                            }
                            $messageId = trim((string) ($messageRow['message_id'] ?? ''));
                            if ($messageId !== '') {
                                $statusById[$messageId] = strtolower(trim((string) ($messageRow['delivery_status'] ?? 'sent')));
                            }
                        }
                    } catch (\Throwable $timelineFailure) {
                        $timelineError = $timelineFailure->getMessage();
                        $summary['errors'][] = 'Tenant #' . $tenantId . ': ' . $timelineError;
                    }
                }
                $tenantName = (string) ($tenantRows[0]['tenant_name'] ?? '');
                $policy = $this->policies->resolve($tenantId, $tenantName);
                $settings = $this->settings->resolveTenantSettings($tenantId);
                $smsAvailable = !empty($settings['available_channels'][AppointmentNotificationSettingsService::CHANNEL_SMS]);

                foreach ($tenantRows as $row) {
                    $summary['checked']++;
                    $messageId = trim((string) ($row['whatsapp_provider_id'] ?? ''));
                    $knownFailure = (string) ($row['whatsapp_status'] ?? '') === 'failed';
                    if (!$knownFailure && $timelineError !== null) {
                        $this->update((int) $row['id_notification_fallback'], [
                            'checked_at' => date('Y-m-d H:i:s'),
                            'error_text' => 'Verifica consegna WhatsApp non disponibile: ' . $timelineError,
                        ]);
                        $summary['deferred']++;
                        continue;
                    }
                    $whatsAppStatus = $knownFailure
                        ? 'failed'
                        : ($messageId !== '' ? ($statusById[$messageId] ?? 'unknown') : 'unknown');
                    if (in_array($whatsAppStatus, ['delivered', 'read'], true)) {
                        $this->update((int) $row['id_notification_fallback'], [
                            'status' => 'not_required',
                            'whatsapp_status' => $whatsAppStatus,
                            'checked_at' => date('Y-m-d H:i:s'),
                            'error_text' => null,
                        ]);
                        $summary['delivered']++;
                        continue;
                    }
                    if (!$smsAvailable || empty($policy['whatsapp']['sms_fallback_enabled'])) {
                        $this->update((int) $row['id_notification_fallback'], [
                            'status' => 'cancelled',
                            'whatsapp_status' => $whatsAppStatus,
                            'checked_at' => date('Y-m-d H:i:s'),
                            'error_text' => 'Fallback SMS non più disponibile per lo spazio.',
                        ]);
                        continue;
                    }

                    $rate = $this->rateLimiter->claim($tenantId, AppointmentNotificationSettingsService::CHANNEL_SMS, $policy);
                    if (empty($rate['allowed'])) {
                        $this->update((int) $row['id_notification_fallback'], [
                            'whatsapp_status' => $whatsAppStatus,
                            'checked_at' => date('Y-m-d H:i:s'),
                            'due_at' => (string) (($rate['next_allowed_at'] ?? '') ?: date('Y-m-d H:i:s', time() + 60)),
                            'error_text' => 'Fallback differito dal limite SMS dello spazio.',
                        ]);
                        $summary['deferred']++;
                        continue;
                    }

                    $id = (int) ($row['id_notification_fallback'] ?? 0);
                    $claimed = $this->platformDb->table(self::TABLE)
                        ->where('id_notification_fallback', $id)
                        ->where('status', 'pending')
                        ->update(['status' => 'processing', 'checked_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
                    if (!$claimed) {
                        continue;
                    }
                    $send = $this->channels->send(
                        AppointmentNotificationSettingsService::CHANNEL_SMS,
                        (string) ($row['recipient_phone'] ?? ''),
                        (string) ($row['message_text'] ?? ''),
                        [
                            'tenant_id' => $tenantId,
                        ]
                    );
                    $ok = !empty($send['ok']);
                    $this->update($id, [
                        'status' => $ok ? 'sms_sent' : 'sms_failed',
                        'whatsapp_status' => $whatsAppStatus,
                        'sms_provider_id' => trim((string) ($send['provider_id'] ?? '')) ?: null,
                        'sms_sent_at' => $ok ? date('Y-m-d H:i:s') : null,
                        'error_text' => $ok ? null : mb_substr((string) ($send['error'] ?? 'Invio SMS non riuscito.'), 0, 2000),
                    ]);
                    $this->updateCampaignSource($row, $send);
                    $this->appendLog($tenantId, $tenantName, $row, $send);
                    $summary[$ok ? 'sms_sent' : 'sms_failed']++;
                }
            } catch (\Throwable $e) {
                $summary['errors'][] = 'Tenant #' . $tenantId . ': ' . $e->getMessage();
            }
        }

        return $summary;
    }

    /**
     * @param array<int, int> $sourceIds
     * @return array<int, array<string, mixed>>
     */
    public function rowsForSources(int $tenantId, string $sourceType, array $sourceIds): array
    {
        $sourceIds = array_values(array_unique(array_filter(array_map('intval', $sourceIds), static fn(int $id): bool => $id > 0)));
        if ($tenantId <= 0 || $sourceIds === [] || !$this->platformDb->tableExists(self::TABLE)) {
            return [];
        }
        $rows = $this->platformDb->table(self::TABLE)
            ->where('id_tenant', $tenantId)
            ->where('source_type', $sourceType)
            ->whereIn('source_id', $sourceIds)
            ->get()
            ->getResultArray();
        $map = [];
        foreach ($rows as $row) {
            $map[(int) ($row['source_id'] ?? 0)] = $row;
        }
        return $map;
    }

    private function gateway(): WhatsAppGatewayClient
    {
        return $this->gateway ??= new WhatsAppGatewayClient();
    }

    /** @return array<string, mixed> */
    private function tenant(int $tenantId): array
    {
        if ($tenantId <= 0 || !$this->platformDb->tableExists('platform_tenants')) {
            return [];
        }
        return $this->platformDb->table('platform_tenants')->where('id_tenant', $tenantId)->get(1)->getRowArray() ?: [];
    }

    /** @param array<string, mixed> $payload */
    private function update(int $id, array $payload): void
    {
        if ($id <= 0) {
            return;
        }
        $payload['updated_at'] = date('Y-m-d H:i:s');
        $this->platformDb->table(self::TABLE)->where('id_notification_fallback', $id)->update($payload);
    }

    /** @param array<string, mixed> $row @param array<string, mixed> $send */
    private function appendLog(int $tenantId, string $tenantName, array $row, array $send): void
    {
        $tenant = $this->tenant($tenantId);
        if ($tenant === []) {
            return;
        }
        try {
            (new AppointmentNotificationLogService())->append($tenant, [
                'tenant_id' => $tenantId,
                'tenant_key' => (string) ($tenant['tenant_key'] ?? ''),
                'tenant_name' => $tenantName,
                'message_type' => (string) ($row['message_type'] ?? 'notification'),
                'channel' => AppointmentNotificationSettingsService::CHANNEL_SMS,
                'provider' => (string) ($send['provider'] ?? (new AppointmentNotificationChannelService())
                    ->providerLabel(AppointmentNotificationSettingsService::CHANNEL_SMS, $tenantId)),
                'provider_id' => (string) ($send['provider_id'] ?? ''),
                'recipient' => (string) ($send['recipient'] ?? $row['recipient_phone'] ?? ''),
                'recipient_role' => 'patient',
                'appointment_id' => (string) ($row['source_type'] ?? '') === 'appointment' ? (int) ($row['source_id'] ?? 0) : 0,
                'status' => !empty($send['ok']) ? 'sent' : 'failed',
                'source' => 'whatsapp_sms_fallback',
                'error' => (string) ($send['error'] ?? ''),
                'response' => $send['response'] ?? null,
                'created_at' => date('c'),
            ]);
        } catch (\Throwable $e) {
            log_message('warning', 'Registrazione fallback SMS non riuscita: {message}', ['message' => $e->getMessage()]);
        }
    }

    /** @param array<string, mixed> $row @param array<string, mixed> $send */
    private function updateCampaignSource(array $row, array $send): void
    {
        if (
            (string) ($row['source_type'] ?? '') !== 'whatsapp_campaign_recipient'
            || (int) ($row['source_id'] ?? 0) <= 0
            || !$this->platformDb->tableExists('platform_whatsapp_campaign_recipients')
        ) {
            return;
        }

        $recipientId = (int) $row['source_id'];
        $recipient = $this->platformDb->table('platform_whatsapp_campaign_recipients')
            ->where('id_whatsapp_campaign_recipient', $recipientId)
            ->get(1)
            ->getRowArray();
        if (!$recipient) {
            return;
        }

        $ok = !empty($send['ok']);
        $this->platformDb->table('platform_whatsapp_campaign_recipients')
            ->where('id_whatsapp_campaign_recipient', $recipientId)
            ->update([
                'status' => $ok ? 'sent' : (string) ($recipient['status'] ?? 'failed'),
                'provider_message_id' => $ok
                    ? (trim((string) ($send['provider_id'] ?? '')) ?: ($recipient['provider_message_id'] ?? null))
                    : ($recipient['provider_message_id'] ?? null),
                'error_text' => $ok
                    ? 'WhatsApp non consegnato entro il termine: fallback SMS inviato.'
                    : mb_substr((string) ($send['error'] ?? 'Fallback SMS non riuscito.'), 0, 2000),
                'sent_at' => $ok ? date('Y-m-d H:i:s') : ($recipient['sent_at'] ?? null),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        $campaignId = (int) ($recipient['id_whatsapp_campaign'] ?? 0);
        if ($campaignId <= 0 || !$this->platformDb->tableExists('platform_whatsapp_campaigns')) {
            return;
        }
        $counts = $this->platformDb->query(
            "SELECT COUNT(*) AS total, SUM(status = 'pending') AS pending, SUM(status = 'sent') AS sent, SUM(status = 'failed') AS failed, SUM(status = 'processing') AS processing FROM platform_whatsapp_campaign_recipients WHERE id_whatsapp_campaign = ?",
            [$campaignId]
        )->getRowArray() ?: [];
        $pending = (int) ($counts['pending'] ?? 0) + (int) ($counts['processing'] ?? 0);
        $this->platformDb->table('platform_whatsapp_campaigns')
            ->where('id_whatsapp_campaign', $campaignId)
            ->update([
                'total_recipients' => (int) ($counts['total'] ?? 0),
                'pending_recipients' => $pending,
                'sent_recipients' => (int) ($counts['sent'] ?? 0),
                'failed_recipients' => (int) ($counts['failed'] ?? 0),
                'status' => $pending > 0 ? 'running' : 'completed',
                'completed_at' => $pending > 0 ? null : date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }
}
