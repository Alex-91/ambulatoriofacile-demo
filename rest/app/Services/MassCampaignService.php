<?php

namespace App\Services;

use App\Libraries\DatabaseConfig;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

class MassCampaignService
{
    private const CAMPAIGNS = 'platform_mass_campaigns';
    private const RECIPIENTS = 'platform_mass_campaign_recipients';
    private const RATE_LIMITS = 'platform_mass_campaign_rate_limits';

    private BaseConnection $platformDb;
    private TenantCatalogService $tenantCatalog;
    private TenantDatabaseConnector $tenantDbConnector;
    private DatabaseConfig $databaseConfig;
    private MassCampaignDeliveryService $delivery;

    public function __construct(
        ?BaseConnection $platformDb = null,
        ?TenantCatalogService $tenantCatalog = null,
        ?TenantDatabaseConnector $tenantDbConnector = null,
        ?DatabaseConfig $databaseConfig = null,
        ?MassCampaignDeliveryService $delivery = null
    ) {
        $this->platformDb = $platformDb ?? Database::connect('platform');
        $this->tenantCatalog = $tenantCatalog ?? new TenantCatalogService();
        $this->tenantDbConnector = $tenantDbConnector ?? new TenantDatabaseConnector();
        $this->databaseConfig = $databaseConfig ?? new DatabaseConfig();
        $this->delivery = $delivery ?? new MassCampaignDeliveryService();
    }
    /** @return array<string,mixed> */
    public function createCampaign(int $tenantId, array $payload, int $platformUserId): array
    {
        $this->assertTablesReady();
        $tenant = $this->tenantCatalog->getTenantById($tenantId);
        if (!$tenant || (int) ($tenant['is_active'] ?? 0) !== 1) {
            throw new \RuntimeException('Spazio cliente non disponibile.');
        }
        $channels = $this->delivery->channelOrder($payload, $this->delivery->smsEnabled($tenantId));
        $audience = trim((string) ($payload['audience_type'] ?? ''));
        if (!in_array($audience, ['all_patients', 'appointments_on_date'], true)) {
            throw new \RuntimeException('Destinatari non validi.');
        }
        $appointmentDate = trim((string) ($payload['appointment_date'] ?? ''));
        if ($audience === 'appointments_on_date' && !$this->isDate($appointmentDate)) {
            throw new \RuntimeException('Seleziona una data valida per gli appuntamenti.');
        }
        if ($audience !== 'appointments_on_date') {
            $appointmentDate = '';
        }
        $message = trim((string) ($payload['message_text'] ?? ''));
        if ($message === '' || mb_strlen($message) > 2000) {
            throw new \RuntimeException('Il messaggio deve contenere da 1 a 2000 caratteri.');
        }

        $recipients = $this->loadRecipients($tenant, $audience, $appointmentDate, $channels);
        if ($recipients === []) {
            throw new \RuntimeException('Non ci sono pazienti con recapiti validi per i canali selezionati.');
        }

        $now = date('Y-m-d H:i:s');
        $this->platformDb->transStart();
        $this->platformDb->table(self::CAMPAIGNS)->insert([
            'id_tenant' => $tenantId,
            'audience_type' => $audience,
            'appointment_date' => $appointmentDate !== '' ? $appointmentDate : null,
            'message_text' => $message,
            'channels_json' => json_encode($channels),
            'status' => 'queued',
            'total_recipients' => count($recipients),
            'pending_recipients' => count($recipients),
            'sent_recipients' => 0,
            'failed_recipients' => 0,
            'created_by_platform_user_id' => $platformUserId > 0 ? $platformUserId : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $campaignId = (int) $this->platformDb->insertID();
        foreach ($recipients as $recipient) {
            $this->platformDb->table(self::RECIPIENTS)->insert([
                'id_mass_campaign' => $campaignId,
                'id_tenant' => $tenantId,
                'id_client' => (int) ($recipient['id_client'] ?? 0) ?: null,
                'id_appointment' => (int) ($recipient['id_appointment'] ?? 0) ?: null,
                'patient_name' => (string) ($recipient['patient_name'] ?? ''),
                'recipient_phone' => (string) $recipient['phone'],
                'recipient_email' => (string) $recipient['email'],
                'status' => 'pending',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $this->platformDb->query(
            'INSERT INTO ' . self::RATE_LIMITS . ' (id_tenant, next_allowed_at, updated_at) VALUES (?, NULL, ?) ON DUPLICATE KEY UPDATE id_tenant = VALUES(id_tenant)',
            [$tenantId, $now]
        );
        $this->platformDb->transComplete();
        if (!$this->platformDb->transStatus()) {
            throw new \RuntimeException('Non è stato possibile accodare la campagna.');
        }
        return $this->campaignById($tenantId, $campaignId) ?? [];
    }

    /** @return array<string,mixed> */
    public function dashboard(int $tenantId, int $campaignId = 0): array
    {
        if (!$this->tablesReady()) {
            return ['schema_ready' => false, 'campaigns' => [], 'selected_campaign' => null, 'recipients' => [], 'summary' => []];
        }
        $campaigns = $this->platformDb->table(self::CAMPAIGNS)->where('id_tenant', $tenantId)->orderBy('id_mass_campaign', 'DESC')->get(40)->getResultArray();
        $selected = $campaignId > 0 ? $this->campaignById($tenantId, $campaignId) : ($campaigns[0] ?? null);
        $recipients = [];
        if (is_array($selected)) {
            $recipients = $this->platformDb->table(self::RECIPIENTS)
                ->where('id_mass_campaign', (int) $selected['id_mass_campaign'])
                ->orderBy('id_mass_campaign_recipient', 'DESC')->get(200)->getResultArray();
        }
        return [
            'schema_ready' => true,
            'campaigns' => $campaigns,
            'selected_campaign' => $selected,
            'recipients' => $recipients,
            'summary' => $this->summary($campaigns),
        ];
    }

    public function setPaused(int $tenantId, int $campaignId, bool $paused): void
    {
        $this->assertTablesReady();
        $builder = $this->platformDb->table(self::CAMPAIGNS)
            ->where('id_tenant', $tenantId)->where('id_mass_campaign', $campaignId);
        if ($paused) {
            $builder->whereIn('status', ['queued', 'running'])->set('status', 'paused');
        } else {
            $builder->where('status', 'paused')
                ->set('status', "CASE WHEN started_at IS NULL THEN 'queued' ELSE 'running' END", false);
        }
        if (!$builder->update(['updated_at' => date('Y-m-d H:i:s')])) {
            throw new \RuntimeException('Non è stato possibile aggiornare la campagna.');
        }
        if ($this->platformDb->affectedRows() > 0) { return; }
        $campaign = $this->campaignById($tenantId, $campaignId);
        $expected = $paused ? ['paused'] : ['queued', 'running'];
        if (!$campaign || !in_array($campaign['status'], $expected, true)) {
            throw new \RuntimeException('Campagna non disponibile o già completata.');
        }
    }

    /** @return array<string,mixed> */
    public function runOne(): array
    {
        if (!$this->tablesReady()) {
            return ['ok' => false, 'status' => 'schema_missing'];
        }
        $this->releaseStaleClaims();
        $now = date('Y-m-d H:i:s');
        $this->platformDb->transBegin();
        try {
            // Lock the space first: concurrent workers/campaigns share this single minute slot.
            $limit = $this->platformDb->query(
                'SELECT rl.* FROM ' . self::RATE_LIMITS . ' rl WHERE (rl.next_allowed_at IS NULL OR rl.next_allowed_at <= ?)'
                . ' AND EXISTS (SELECT 1 FROM ' . self::RECIPIENTS . ' r INNER JOIN ' . self::CAMPAIGNS
                . " c ON c.id_mass_campaign = r.id_mass_campaign WHERE r.id_tenant = rl.id_tenant AND r.status = 'pending' AND c.status IN ('queued', 'running'))"
                . ' ORDER BY COALESCE(rl.next_allowed_at, \'1970-01-01\'), rl.id_tenant LIMIT 1 FOR UPDATE',
                [$now]
            )->getRowArray();
            if (!$limit) {
                $this->platformDb->transCommit();
                return ['ok' => true, 'status' => 'idle'];
            }
            $tenantId = (int) $limit['id_tenant'];
            $candidate = $this->platformDb->query(
                'SELECT r.*, c.message_text, c.channels_json FROM ' . self::RECIPIENTS . ' r INNER JOIN ' . self::CAMPAIGNS
                . " c ON c.id_mass_campaign = r.id_mass_campaign WHERE r.id_tenant = ? AND r.status = 'pending' AND c.status IN ('queued', 'running')"
                . ' ORDER BY c.id_mass_campaign, r.id_mass_campaign_recipient LIMIT 1 FOR UPDATE',
                [$tenantId]
            )->getRowArray();
            if (!$candidate) {
                $this->platformDb->transCommit();
                return ['ok' => true, 'status' => 'idle'];
            }
            $recipientId = (int) $candidate['id_mass_campaign_recipient'];
            $this->platformDb->table(self::RECIPIENTS)->where('id_mass_campaign_recipient', $recipientId)->update([
                'status' => 'processing', 'attempt_count' => ((int) $candidate['attempt_count']) + 1, 'updated_at' => $now,
            ]);
            $this->platformDb->table(self::RATE_LIMITS)->where('id_tenant', $tenantId)->update([
                'next_allowed_at' => date('Y-m-d H:i:s', time() + 60), 'updated_at' => $now,
            ]);
            $this->platformDb->table(self::CAMPAIGNS)->where('id_mass_campaign', (int) $candidate['id_mass_campaign'])->where('status', 'queued')->update([
                'status' => 'running', 'started_at' => $now, 'updated_at' => $now,
            ]);
            if (!$this->platformDb->transStatus()) {
                throw new \RuntimeException('Impossibile prenotare lo slot di invio.');
            }
            $this->platformDb->transCommit();
        } catch (\Throwable $e) {
            $this->platformDb->transRollback();
            log_message('error', 'Mass campaign claim failed: ' . $e->getMessage());
            return ['ok' => false, 'status' => 'claim_failed'];
        }

        $channels = json_decode((string) $candidate['channels_json'], true);
        $channels = is_array($channels) ? $channels : [];
        $index = (int) ($candidate['channel_index'] ?? 0);
        $channel = (string) ($channels[$index] ?? '');
        try {
            $tenant = $this->tenantCatalog->getTenantById($tenantId);
            $result = !empty($tenant['is_active'])
                ? $this->delivery->send($tenantId, $channel, $candidate, (string) $candidate['message_text'])
                : ['ok' => false, 'error' => 'Spazio non attivo.'];
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'error' => $e->getMessage()];
        }
        // A successful first channel is terminal. Never send the second as a duplicate.
        $fallback = empty($result['ok']) && !empty($tenant['is_active'])
            && isset($channels[$index + 1]) && in_array($channels[$index + 1], ['email', 'sms'], true);
        $this->finishRecipient($candidate, $result + ['channel' => $channel], $fallback);
        return ['ok' => !empty($result['ok']) || $fallback, 'status' => $fallback ? 'fallback_pending' : (!empty($result['ok']) ? 'sent' : 'failed'), 'tenant_id' => $tenantId, 'recipient_id' => $recipientId];
    }
    public function nextPendingDueAt(): ?string
    {
        if (!$this->tablesReady()) {
            return null;
        }

        $now = date('Y-m-d H:i:s');
        $row = $this->platformDb->query(
            'SELECT MIN(COALESCE(rl.next_allowed_at, ?)) AS next_due_at FROM '
            . self::RECIPIENTS . ' r INNER JOIN ' . self::CAMPAIGNS
            . ' c ON c.id_mass_campaign = r.id_mass_campaign LEFT JOIN '
            . self::RATE_LIMITS
            . " rl ON rl.id_tenant = c.id_tenant WHERE r.status = 'pending' AND c.status IN ('queued', 'running')",
            [$now]
        )->getRowArray();
        $nextDueAt = trim((string) ($row['next_due_at'] ?? ''));

        return $nextDueAt !== '' ? $nextDueAt : null;
    }

    /** @return array<int,array<string,mixed>> */
    private function loadRecipients(array $tenant, string $audience, string $appointmentDate, array $channels): array
    {
        $db = $this->tenantDbConnector->connect($tenant);
        $this->databaseConfig->setEncryptionConfig($db);
        if ($audience === 'appointments_on_date') {
            $rows = $this->appointmentRecipients($db, $appointmentDate);
        } else {
            $rows = $this->allPatientRecipients($db);
        }
        $channel = new AppointmentNotificationChannelService();
        $unique = [];
        foreach ($rows as $row) {
            $phone = $channel->normalizeRecipient((string) (($row['cellulare'] ?? '') ?: ($row['telefono'] ?? '')));
            $email = $channel->normalizeEmail((string) ($row['email'] ?? ''));
            if ((!in_array('sms', $channels, true) || $phone === null)
                && (!in_array('email', $channels, true) || $email === '')) {
                continue;
            }
            $key = (int) ($row['id_client'] ?? 0) > 0 ? 'client:' . $row['id_client'] : 'contact:' . $phone . ':' . $email;
            if (isset($unique[$key])) { continue; }
            $unique[$key] = [
                'phone' => $phone ?? '',
                'email' => $email,
                'id_client' => (int) ($row['id_client'] ?? 0),
                'id_appointment' => (int) ($row['id_appointment'] ?? 0),
                'patient_name' => trim((string) ($row['patient_name'] ?? '')),
            ];
        }
        return array_values($unique);
    }

    /** @return array<int,array<string,mixed>> */
    private function allPatientRecipients(BaseConnection $db): array
    {
        if (!$db->tableExists('dap02_clients')) { return []; }
        $name = $this->decryptExpr('c.nome'); $surname = $this->decryptExpr('c.cognome');
        $email = $this->decryptExpr('c.email');
        $mobile = $this->decryptExpr('c.cellulare'); $phone = $this->decryptExpr('c.telefono');
        return $db->query("SELECT c.id_client, {$email} AS email, {$mobile} AS cellulare, {$phone} AS telefono, TRIM(CONCAT_WS(' ', {$name}, {$surname})) AS patient_name FROM dap02_clients c ORDER BY c.id_client ASC")->getResultArray();
    }

    /** @return array<int,array<string,mixed>> */
    private function appointmentRecipients(BaseConnection $db, string $date): array
    {
        if (!$db->tableExists('dap12_agenda_appuntamenti') || !$db->tableExists('dap11_agenda_slot')) { return []; }
        $hasClient = $db->tableExists('dap02_clients') && $db->fieldExists('id_client', 'dap12_agenda_appuntamenti');
        $join = $hasClient ? 'LEFT JOIN dap02_clients c ON c.id_client = COALESCE(NULLIF(a.id_client, 0), NULLIF(a.id_paziente, 0))' : 'LEFT JOIN (SELECT NULL AS id_client, NULL AS cellulare, NULL AS telefono, NULL AS nome, NULL AS cognome, NULL AS vector_id) c ON 1 = 0';
        $email = $hasClient ? $this->decryptExpr('c.email') : "''";
        $mobile = $hasClient ? $this->decryptExpr('c.cellulare') : "''"; $phone = $hasClient ? $this->decryptExpr('c.telefono') : "''";
        return $db->query("SELECT COALESCE(NULLIF(a.email, ''), {$email}) AS email, a.id_appuntamento AS id_appointment, COALESCE(c.id_client, 0) AS id_client, COALESCE(NULLIF(a.cellulare, ''), {$mobile}) AS cellulare, COALESCE(NULLIF(a.telefono, ''), {$phone}) AS telefono, TRIM(CONCAT_WS(' ', a.nome, a.cognome)) AS patient_name FROM dap12_agenda_appuntamenti a INNER JOIN dap11_agenda_slot s ON s.id_slot = a.id_slot {$join} WHERE s.data_slot = ? AND a.stato <> 'ANNULLATO' ORDER BY a.id_appuntamento ASC", [$date])->getResultArray();
    }

    private function finishRecipient(array $candidate, array $result, bool $fallback = false): void
    {
        $now = date('Y-m-d H:i:s'); $ok = !empty($result['ok']);
        $this->platformDb->table(self::RECIPIENTS)->where('id_mass_campaign_recipient', (int) $candidate['id_mass_campaign_recipient'])->where('status', 'processing')->update([
            'status' => $ok ? 'sent' : ($fallback ? 'pending' : 'failed'),
            'channel_index' => (int) ($candidate['channel_index'] ?? 0) + ($fallback ? 1 : 0),
            'last_channel' => (string) ($result['channel'] ?? ''),
            'attempts_json' => json_encode(array_merge((array) json_decode((string) ($candidate['attempts_json'] ?? '[]'), true), [['channel' => $result['channel'] ?? '', 'ok' => $ok, 'error' => $result['error'] ?? null, 'at' => $now]])), 'provider_message_id' => trim((string) ($result['provider_id'] ?? '')) ?: null,
            'error_text' => $ok ? null : mb_substr(trim((string) ($result['error'] ?? 'Invio non riuscito.')), 0, 2000),
            'sent_at' => $ok ? $now : null, 'updated_at' => $now,
        ]);
        $this->refreshCampaign((int) $candidate['id_mass_campaign']);
    }

    private function refreshCampaign(int $campaignId): void
    {
        $counts = $this->platformDb->query("SELECT COUNT(*) AS total, SUM(status = 'pending') AS pending, SUM(status = 'sent') AS sent, SUM(status = 'failed') AS failed, SUM(status = 'processing') AS processing FROM " . self::RECIPIENTS . ' WHERE id_mass_campaign = ?', [$campaignId])->getRowArray() ?: [];
        $pending = (int) ($counts['pending'] ?? 0); $processing = (int) ($counts['processing'] ?? 0);
        $this->platformDb->table(self::CAMPAIGNS)->where('id_mass_campaign', $campaignId)
            // Preserve a concurrent pause while the already claimed recipient finishes.
            ->set('status', ($pending + $processing) > 0
                ? "CASE WHEN status = 'paused' THEN 'paused' ELSE 'running' END"
                : "'completed'", false)->update([
            'total_recipients' => (int) ($counts['total'] ?? 0), 'pending_recipients' => $pending + $processing,
            'sent_recipients' => (int) ($counts['sent'] ?? 0), 'failed_recipients' => (int) ($counts['failed'] ?? 0),
            'completed_at' => ($pending + $processing) > 0 ? null : date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function releaseStaleClaims(): void
    {
        $before = date('Y-m-d H:i:s', time() - 900);
        $rows = $this->platformDb->table(self::RECIPIENTS)->where('status', 'processing')->where('updated_at <', $before)->get()->getResultArray();
        foreach ($rows as $row) {
            // Delivery may have succeeded before a crash: do not automatically duplicate it.
            $this->platformDb->table(self::RECIPIENTS)->where('id_mass_campaign_recipient', $row['id_mass_campaign_recipient'])
                ->where('status', 'processing')->where('updated_at <', $before)->update([
                    'status' => 'failed', 'error_text' => 'Esito incerto dopo interruzione: verificare prima di ripetere.', 'updated_at' => date('Y-m-d H:i:s'),
                ]);
            $this->refreshCampaign((int) $row['id_mass_campaign']);
        }
    }
    private function campaignById(int $tenantId, int $campaignId): ?array { $row = $this->platformDb->table(self::CAMPAIGNS)->where('id_tenant', $tenantId)->where('id_mass_campaign', $campaignId)->get(1)->getRowArray(); return $row ?: null; }
    private function tablesReady(): bool { return $this->platformDb->tableExists(self::CAMPAIGNS) && $this->platformDb->tableExists(self::RECIPIENTS) && $this->platformDb->tableExists(self::RATE_LIMITS); }
    private function assertTablesReady(): void { if (!$this->tablesReady()) { throw new \RuntimeException('Il database campagne non è ancora aggiornato.'); } }
    private function isDate(string $value): bool { $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value); return $date && $date->format('Y-m-d') === $value; }
    private function decryptExpr(string $field): string { $dot = strrpos($field, '.'); $vector = $dot === false ? 'vector_id' : substr($field, 0, $dot + 1) . 'vector_id'; return "CONVERT(CAST(AES_DECRYPT(UNHEX({$field}), @key_str, {$vector}) AS CHAR CHARACTER SET latin1) USING utf8mb4)"; }
    /** @param array<int,array<string,mixed>> $campaigns */
    private function summary(array $campaigns): array { $summary = ['total' => count($campaigns), 'queued' => 0, 'sent' => 0, 'failed' => 0]; foreach ($campaigns as $row) { $summary['queued'] += (int) ($row['pending_recipients'] ?? 0); $summary['sent'] += (int) ($row['sent_recipients'] ?? 0); $summary['failed'] += (int) ($row['failed_recipients'] ?? 0); } return $summary; }
}
