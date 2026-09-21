<?php

namespace App\Services;

use App\Libraries\DatabaseConfig;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

class WhatsAppCampaignService
{
    private const CAMPAIGNS = 'platform_whatsapp_campaigns';
    private const RECIPIENTS = 'platform_whatsapp_campaign_recipients';
    private const RATE_LIMITS = 'platform_whatsapp_campaign_rate_limits';

    private BaseConnection $platformDb;
    private TenantCatalogService $tenantCatalog;
    private TenantDatabaseConnector $tenantDbConnector;
    private DatabaseConfig $databaseConfig;
    private ?WhatsAppGatewayClient $gatewayClient;
    private TenantNotificationPolicyService $notificationPolicies;
    private NotificationRateLimiterService $rateLimiter;
    private WhatsAppSmsFallbackService $smsFallbacks;
    private WhatsAppCampaignPlan $planner;

    public function __construct(
        ?BaseConnection $platformDb = null,
        ?TenantCatalogService $tenantCatalog = null,
        ?TenantDatabaseConnector $tenantDbConnector = null,
        ?DatabaseConfig $databaseConfig = null,
        ?WhatsAppGatewayClient $gatewayClient = null,
        ?TenantNotificationPolicyService $notificationPolicies = null,
        ?NotificationRateLimiterService $rateLimiter = null,
        ?WhatsAppSmsFallbackService $smsFallbacks = null,
        ?WhatsAppCampaignPlan $planner = null
    ) {
        $this->platformDb = $platformDb ?? Database::connect('platform');
        $this->tenantCatalog = $tenantCatalog ?? new TenantCatalogService();
        $this->tenantDbConnector = $tenantDbConnector ?? new TenantDatabaseConnector();
        $this->databaseConfig = $databaseConfig ?? new DatabaseConfig();
        $this->gatewayClient = $gatewayClient;
        $this->notificationPolicies = $notificationPolicies ?? new TenantNotificationPolicyService($this->platformDb);
        $this->rateLimiter = $rateLimiter ?? new NotificationRateLimiterService($this->platformDb, $this->notificationPolicies);
        $this->smsFallbacks = $smsFallbacks ?? new WhatsAppSmsFallbackService($this->platformDb, $this->notificationPolicies);
        $this->planner = $planner ?? new WhatsAppCampaignPlan();
    }

    /** @return array<string,mixed> */
    public function createCampaign(int $tenantId, array $payload, int $platformUserId): array
    {
        $this->assertTablesReady();
        $tenant = $this->tenantCatalog->getTenantById($tenantId);
        if (!$tenant || (int) ($tenant['is_active'] ?? 0) !== 1) {
            throw new \RuntimeException('Spazio cliente non disponibile.');
        }
        $settings = (new AppointmentNotificationSettingsService())->resolveTenantSettings($tenantId);
        if (empty($settings['available_channels'][AppointmentNotificationSettingsService::CHANNEL_WHATSAPP]) || !WhatsAppGatewayClient::isAvailableForTenant($tenantId)) {
            throw new \RuntimeException('WhatsApp non è attivo e pronto per questo spazio.');
        }

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

        if (!WhatsAppCampaignPrioritySchema::ready($this->platformDb)) {
            throw new \RuntimeException('Aggiornamento della priorità campagne in corso. Riprova tra poco.');
        }
        $recipientPlan = $this->loadRecipientPlan($tenant, $audience, $appointmentDate);
        $recipients = $recipientPlan['recipients'];
        if ($recipients === []) {
            throw new \RuntimeException('Non ci sono pazienti con un numero mobile WhatsApp valido per i destinatari selezionati.');
        }

        $now = date('Y-m-d H:i:s');
        $this->platformDb->transStart();
        $this->platformDb->table(self::CAMPAIGNS)->insert([
            'id_tenant' => $tenantId,
            'audience_type' => $audience,
            'appointment_date' => $appointmentDate !== '' ? $appointmentDate : null,
            'message_text' => $message,
            'status' => 'queued',
            'total_recipients' => count($recipients),
            'pending_recipients' => count($recipients),
            'sent_recipients' => 0,
            'failed_recipients' => 0,
            'priority_plan_json' => $recipientPlan['summary'] === null ? null : json_encode($recipientPlan['summary'], JSON_THROW_ON_ERROR),
            'created_by_platform_user_id' => $platformUserId > 0 ? $platformUserId : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $campaignId = (int) $this->platformDb->insertID();
        foreach ($recipients as $recipient) {
            $this->platformDb->table(self::RECIPIENTS)->insert([
                'id_whatsapp_campaign' => $campaignId,
                'id_tenant' => $tenantId,
                'id_client' => (int) ($recipient['id_client'] ?? 0) ?: null,
                'id_appointment' => (int) ($recipient['id_appointment'] ?? 0) ?: null,
                'patient_name' => (string) ($recipient['patient_name'] ?? ''),
                'recipient_phone' => (string) $recipient['phone'],
                'status' => 'pending',
                'send_order' => (int) ($recipient['send_order'] ?? 0),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $this->platformDb->transComplete();
        if (!$this->platformDb->transStatus()) {
            throw new \RuntimeException('Non è stato possibile accodare la campagna WhatsApp.');
        }
        return $this->campaignById($tenantId, $campaignId) ?? [];
    }

    /** @return array<string,mixed> */
    public function dashboard(int $tenantId, int $campaignId = 0): array
    {
        if (!$this->tablesReady()) {
            return ['schema_ready' => false, 'campaigns' => [], 'selected_campaign' => null, 'recipients' => [], 'summary' => []];
        }
        $campaigns = $this->platformDb->table(self::CAMPAIGNS)->where('id_tenant', $tenantId)->orderBy('id_whatsapp_campaign', 'DESC')->get(40)->getResultArray();
        $selected = $campaignId > 0 ? $this->campaignById($tenantId, $campaignId) : ($campaigns[0] ?? null);
        $recipients = [];
        if (is_array($selected)) {
            $selected['remaining_completion_at'] = $this->estimateRemainingCompletion($tenantId, (int) $selected['id_whatsapp_campaign']);
            $selected['priority_plan'] = json_decode((string) ($selected['priority_plan_json'] ?? ''), true) ?: null;
            $recipientQuery = $this->platformDb->table(self::RECIPIENTS)
                ->where('id_whatsapp_campaign', (int) $selected['id_whatsapp_campaign']);
            $hasPriority = $selected['priority_plan'] !== null && WhatsAppCampaignPrioritySchema::ready($this->platformDb);
            if ($hasPriority) {
                $recipientQuery->orderBy("CASE WHEN status = 'pending' THEN 0 ELSE 1 END", 'ASC', false)->orderBy('send_order', 'ASC');
            }
            $recipients = $recipientQuery->orderBy('id_whatsapp_campaign_recipient', $hasPriority ? 'ASC' : 'DESC')->get(200)->getResultArray();
            $fallbackRows = $this->smsFallbacks->rowsForSources(
                $tenantId,
                'whatsapp_campaign_recipient',
                array_map(static fn(array $row): int => (int) ($row['id_whatsapp_campaign_recipient'] ?? 0), $recipients)
            );
            foreach ($recipients as &$recipient) {
                $recipient['sms_fallback'] = $fallbackRows[(int) ($recipient['id_whatsapp_campaign_recipient'] ?? 0)] ?? null;
            }
            unset($recipient);
        }
        return [
            'schema_ready' => true,
            'campaigns' => $campaigns,
            'selected_campaign' => $selected,
            'recipients' => $recipients,
            'summary' => $this->summary($campaigns),
        ];
    }

    /** @return array<string,mixed> */
    public function runOne(): array
    {
        if (!$this->tablesReady()) {
            return ['ok' => false, 'status' => 'schema_missing'];
        }
        $schedule = $this->planner;
        if (!$schedule->isOpen($schedule->now())) {
            return ['ok' => true, 'status' => 'outside_window'];
        }
        $this->releaseStaleClaims();
        $now = date('Y-m-d H:i:s');
        $this->platformDb->transStart();
        $recipientOrder = WhatsAppCampaignPrioritySchema::ready($this->platformDb) ? 'r.send_order ASC, ' : '';
        $candidate = $this->platformDb->query(
            'SELECT r.*, c.message_text, c.id_tenant AS campaign_tenant_id FROM ' . self::RECIPIENTS . ' r INNER JOIN ' . self::CAMPAIGNS . ' c ON c.id_whatsapp_campaign = r.id_whatsapp_campaign LEFT JOIN ' . self::RATE_LIMITS . " rl ON rl.id_tenant = c.id_tenant WHERE r.status = 'pending' AND c.status IN ('queued', 'running') AND (rl.next_allowed_at IS NULL OR rl.next_allowed_at <= ?) ORDER BY c.id_whatsapp_campaign ASC, " . $recipientOrder . 'r.id_whatsapp_campaign_recipient ASC LIMIT 1 FOR UPDATE',
            [$now]
        )->getRowArray();
        if (!$candidate) {
            $this->platformDb->transComplete();
            return ['ok' => true, 'status' => 'idle'];
        }
        $tenantId = (int) $candidate['campaign_tenant_id'];
        $limit = $this->platformDb->table(self::RATE_LIMITS)->where('id_tenant', $tenantId)->get(1)->getRowArray();
        $tenant = $this->tenantCatalog->getTenantById($tenantId) ?? [];
        $policy = $this->notificationPolicies->resolve($tenantId, (string) ($tenant['tenant_name'] ?? ''));
        $rate = $this->rateLimiter->claim(
            $tenantId,
            AppointmentNotificationSettingsService::CHANNEL_WHATSAPP,
            $policy,
            false
        );
        if (empty($rate['allowed'])) {
            $nextAt = (string) (($rate['next_allowed_at'] ?? '') ?: date('Y-m-d H:i:s', time() + 60));
            $rateData = ['next_allowed_at' => $nextAt, 'updated_at' => $now];
            if ($limit) {
                $this->platformDb->table(self::RATE_LIMITS)->where('id_tenant', $tenantId)->update($rateData);
            } else {
                $this->platformDb->table(self::RATE_LIMITS)->insert(['id_tenant' => $tenantId] + $rateData);
            }
            $this->platformDb->transComplete();
            return ['ok' => true, 'status' => (string) ($rate['reason'] ?? 'throttled'), 'tenant_id' => $tenantId, 'next_allowed_at' => $nextAt];
        }
        $recipientId = (int) $candidate['id_whatsapp_campaign_recipient'];
        $claimed = $this->platformDb->table(self::RECIPIENTS)->where('id_whatsapp_campaign_recipient', $recipientId)->where('status', 'pending')->update([
            'status' => 'processing', 'attempt_count' => ((int) $candidate['attempt_count']) + 1, 'updated_at' => $now,
        ]);
        if (!$claimed) {
            $this->platformDb->transComplete();
            return ['ok' => true, 'status' => 'contended'];
        }
        // Reuse the shared reservation so campaign and reminder queues follow
        // the same tenant policy without introducing another spacing timer.
        $nextAt = (string) ($rate['next_allowed_at'] ?? date(
            'Y-m-d H:i:s',
            time() + $this->notificationPolicies->minimumSpacingSeconds(
                $policy,
                AppointmentNotificationSettingsService::CHANNEL_WHATSAPP
            )
        ));
        $rateData = ['next_allowed_at' => $nextAt, 'updated_at' => $now];
        if ($limit) {
            $this->platformDb->table(self::RATE_LIMITS)->where('id_tenant', $tenantId)->update($rateData);
        } else {
            $this->platformDb->table(self::RATE_LIMITS)->insert(['id_tenant' => $tenantId] + $rateData);
        }
        $this->platformDb->table(self::CAMPAIGNS)->where('id_whatsapp_campaign', (int) $candidate['id_whatsapp_campaign'])->where('status', 'queued')->update(['status' => 'running', 'started_at' => $now, 'updated_at' => $now]);
        $this->platformDb->transComplete();
        if (!$this->platformDb->transStatus()) {
            return ['ok' => false, 'status' => 'claim_failed'];
        }
        try {
            // Recheck after waiting for DB locks: a claim made just before the
            // closing time must not cause a new gateway request after 22:30.
            if (!$schedule->isOpen($schedule->now())) {
                $this->platformDb->table(self::RECIPIENTS)->where('id_whatsapp_campaign_recipient', $recipientId)
                    ->where('status', 'processing')->update(['status' => 'pending', 'attempt_count' => (int) $candidate['attempt_count'], 'updated_at' => date('Y-m-d H:i:s')]);
                return ['ok' => true, 'status' => 'outside_window'];
            }
            $result = $this->gateway()->sendText($tenantId, (string) $candidate['recipient_phone'], (string) $candidate['message_text']);
            $this->finishRecipient($candidate, $result);
            $settings = (new AppointmentNotificationSettingsService())->resolveTenantSettings($tenantId);
            try {
                $this->smsFallbacks->registerPending(
                    $tenantId,
                    'whatsapp_campaign_recipient',
                    $recipientId,
                    'mass_campaign',
                    (string) $candidate['recipient_phone'],
                    (string) $candidate['message_text'],
                    (string) ($result['provider_id'] ?? ''),
                    !empty($settings['available_channels'][AppointmentNotificationSettingsService::CHANNEL_SMS]),
                    empty($result['ok'])
                );
            } catch (\Throwable $fallbackError) {
                log_message('warning', 'Registrazione fallback campagna WhatsApp/SMS non riuscita: {message}', [
                    'message' => $fallbackError->getMessage(),
                    'tenant_id' => $tenantId,
                    'recipient_id' => $recipientId,
                ]);
            }
            return ['ok' => !empty($result['ok']), 'status' => !empty($result['ok']) ? 'sent' : 'failed', 'tenant_id' => $tenantId, 'recipient_id' => $recipientId];
        } catch (\Throwable $e) {
            $this->finishRecipient($candidate, ['ok' => false, 'error' => $e->getMessage()]);
            try {
                $settings = (new AppointmentNotificationSettingsService())->resolveTenantSettings($tenantId);
                $this->smsFallbacks->registerPending(
                    $tenantId,
                    'whatsapp_campaign_recipient',
                    $recipientId,
                    'mass_campaign',
                    (string) $candidate['recipient_phone'],
                    (string) $candidate['message_text'],
                    '',
                    !empty($settings['available_channels'][AppointmentNotificationSettingsService::CHANNEL_SMS]),
                    true
                );
            } catch (\Throwable $fallbackError) {
                log_message('warning', 'Registrazione fallback campagna WhatsApp/SMS non riuscita dopo errore gateway: {message}', [
                    'message' => $fallbackError->getMessage(),
                    'tenant_id' => $tenantId,
                    'recipient_id' => $recipientId,
                ]);
            }
            return ['ok' => false, 'status' => 'failed', 'tenant_id' => $tenantId, 'recipient_id' => $recipientId];
        }
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
            . ' c ON c.id_whatsapp_campaign = r.id_whatsapp_campaign LEFT JOIN '
            . self::RATE_LIMITS
            . " rl ON rl.id_tenant = c.id_tenant WHERE r.status = 'pending' AND c.status IN ('queued', 'running')",
            [$now]
        )->getRowArray();
        $nextDueAt = trim((string) ($row['next_due_at'] ?? ''));

        return $nextDueAt !== '' ? $nextDueAt : null;
    }

    private function loadRecipientPlan(array $tenant, string $audience, string $appointmentDate): array
    {
        $now = $this->planner->now();
        $db = $this->tenantDbConnector->connect($tenant);
        $this->databaseConfig->setEncryptionConfig($db);
        if ($audience === 'appointments_on_date') {
            $rows = $this->appointmentRecipients($db, $appointmentDate);
        } else {
            $rows = $this->allPatientRecipients($db, $now);
        }
        $channel = new AppointmentNotificationChannelService();
        $normalized = [];
        foreach ($rows as $row) {
            $phone = $channel->normalizeRecipient((string) (($row['cellulare'] ?? '') ?: ($row['telefono'] ?? '')));
            if ($phone === null) {
                continue;
            }
            $normalized[] = [
                'phone' => $phone,
                'id_client' => (int) ($row['id_client'] ?? 0),
                'id_appointment' => (int) ($row['id_appointment'] ?? 0),
                'patient_name' => trim((string) ($row['patient_name'] ?? '')),
                'sort_surname' => (string) ($row['sort_surname'] ?? ''),
                'sort_name' => (string) ($row['sort_name'] ?? ''),
                'next_appointment_at' => $row['next_appointment_at'] ?? null,
            ];
        }
        if ($audience === 'all_patients') {
            return $this->buildPlan($tenant, $normalized, $now);
        }
        $unique = [];
        foreach ($normalized as $row) { $unique[$row['phone']] ??= $row; }
        return ['recipients' => array_values($unique), 'summary' => null];
    }

    /** @return array<int,array<string,mixed>> */
    protected function allPatientRecipients(BaseConnection $db, \DateTimeImmutable $now): array
    {
        if (!$db->tableExists('dap02_clients')) { return []; }
        $name = $this->decryptExpr('c.nome'); $surname = $this->decryptExpr('c.cognome');
        $mobile = $this->decryptExpr('c.cellulare'); $phone = $this->decryptExpr('c.telefono');
        $appointmentSelect = 'NULL';
        $appointmentJoin = '';
        $params = [];
        if ($db->tableExists('dap12_agenda_appuntamenti') && $db->tableExists('dap11_agenda_slot')) {
            $clientRef = $db->fieldExists('id_client', 'dap12_agenda_appuntamenti')
                ? 'COALESCE(NULLIF(a.id_client, 0), NULLIF(a.id_paziente, 0))' : 'NULLIF(a.id_paziente, 0)';
            $start = $db->fieldExists('ora_inizio_appuntamento', 'dap12_agenda_appuntamenti')
                ? 'COALESCE(a.ora_inizio_appuntamento, s.ora_inizio)' : 's.ora_inizio';
            $appointmentSelect = 'upcoming.next_appointment_at';
            $appointmentJoin = " LEFT JOIN (SELECT {$clientRef} AS client_id, MIN(TIMESTAMP(s.data_slot, {$start})) AS next_appointment_at FROM dap12_agenda_appuntamenti a INNER JOIN dap11_agenda_slot s ON s.id_slot = a.id_slot WHERE a.stato <> 'ANNULLATO' AND s.data_slot >= ? AND TIMESTAMP(s.data_slot, {$start}) >= ? GROUP BY {$clientRef}) upcoming ON upcoming.client_id = c.id_client";
            $params = [$now->format('Y-m-d'), $now->format('Y-m-d H:i:s')];
        }
        return $db->query("SELECT c.id_client, {$mobile} AS cellulare, {$phone} AS telefono, {$surname} AS sort_surname, {$name} AS sort_name, TRIM(CONCAT_WS(' ', {$name}, {$surname})) AS patient_name, {$appointmentSelect} AS next_appointment_at FROM dap02_clients c {$appointmentJoin} ORDER BY c.id_client ASC", $params)->getResultArray();
    }

    public function estimateRemainingCompletion(int $tenantId, int $campaignId): ?string
    {
        $campaign = $this->campaignById($tenantId, $campaignId);
        if (!$campaign || !in_array($campaign['status'], ['queued', 'running'], true)) {
            return null;
        }
        $pending = $this->platformDb->table(self::RECIPIENTS)
            ->where('id_tenant', $tenantId)->where('id_whatsapp_campaign', $campaignId)
            ->where('status', 'pending')->countAllResults();
        if ($pending === 0) { return null; }
        $tenant = $this->tenantCatalog->getTenantById($tenantId) ?? ['id_tenant' => $tenantId];
        $now = $this->planner->now();
        [$earliest, $spacing, $dailyLimit, $usedToday, $ahead] = $this->planningLimits($tenant, $now, $campaignId);
        return $this->planner->estimateCompletion($pending + $ahead, $earliest, $spacing, $dailyLimit, $usedToday, $now)->format(DATE_ATOM);
    }

    private function buildPlan(array $tenant, array $rows, \DateTimeImmutable $now, int $campaignId = 0): array
    {
        [$earliest, $spacing, $dailyLimit, $usedToday, $ahead] = $this->planningLimits($tenant, $now, $campaignId);
        return $this->planner->build($rows, $now, $earliest, $spacing, $dailyLimit, $usedToday, $ahead);
    }

    private function planningLimits(array $tenant, \DateTimeImmutable $now, int $campaignId): array
    {
        $tenantId = (int) $tenant['id_tenant'];
        $policy = $this->notificationPolicies->resolve($tenantId, (string) ($tenant['tenant_name'] ?? ''));
        $spacing = $this->notificationPolicies->minimumSpacingSeconds($policy, AppointmentNotificationSettingsService::CHANNEL_WHATSAPP);
        $dailyLimit = $this->notificationPolicies->dailyLimit($policy, AppointmentNotificationSettingsService::CHANNEL_WHATSAPP);
        $rate = $this->platformDb->table(NotificationRateLimiterService::TABLE)->where('id_tenant', $tenantId)->where('channel', 'wa')->get(1)->getRowArray() ?: [];
        $campaignRate = $this->platformDb->table(self::RATE_LIMITS)->where('id_tenant', $tenantId)->get(1)->getRowArray() ?: [];
        $earliest = $now;
        foreach ([$rate, $campaignRate] as $limit) {
            if (!empty($limit['next_allowed_at'])) {
                $due = new \DateTimeImmutable($limit['next_allowed_at'], new \DateTimeZone(date_default_timezone_get()));
                if ($due > $earliest) { $earliest = $due; }
            }
        }
        $query = $this->platformDb->table(self::RECIPIENTS . ' r')
            ->join(self::CAMPAIGNS . ' c', 'c.id_whatsapp_campaign = r.id_whatsapp_campaign')
            ->where('c.id_tenant', $tenantId)->where('r.id_tenant', $tenantId)
            ->where('r.status', 'pending')->whereIn('c.status', ['queued', 'running']);
        if ($campaignId > 0) { $query->where('c.id_whatsapp_campaign <', $campaignId); }
        $ahead = $query->countAllResults();
        $usedToday = ($rate['counter_date'] ?? '') === $now->setTimezone(new \DateTimeZone(date_default_timezone_get()))->format('Y-m-d') ? (int) ($rate['sent_today'] ?? 0) : 0;
        return [$earliest, $spacing, $dailyLimit, $usedToday, $ahead];
    }

    /** Preview or atomically reorder only pending recipients. Existing sends,
     * addresses, identities, texts, outcomes and provider IDs are never rewritten.
     * Applying twice returns the original fixed plan instead of moving its cutoff.
     */
    public function prioritizePending(int $tenantId, int $campaignId, bool $apply = false): array
    {
        $planner = $this->planner;
        $now = $planner->now();
        if ($apply && $planner->isOpen($now)) {
            throw new \RuntimeException('Il riordino si applica soltanto nella pausa 22:30-07:30 Europe/Rome.');
        }
        if ($apply && !WhatsAppCampaignPrioritySchema::ready($this->platformDb)) {
            throw new \RuntimeException('Installare prima la migration della priorità campagne.');
        }
        if ($apply && !$this->platformDb->transBegin()) { throw new \RuntimeException('Impossibile aprire la transazione di riordino.'); }
        try {
            $campaign = $this->platformDb->query('SELECT * FROM ' . self::CAMPAIGNS . ' WHERE id_tenant = ? AND id_whatsapp_campaign = ?' . ($apply ? ' FOR UPDATE' : ''), [$tenantId, $campaignId])->getRowArray();
            if (!$campaign || $campaign['audience_type'] !== 'all_patients') { throw new \RuntimeException('Campagna tutti i pazienti non trovata nello spazio richiesto.'); }
            if (!empty($campaign['priority_plan_json'])) {
                if ($apply) { $this->platformDb->transCommit(); }
                return ['status' => 'already_planned', 'campaign_id' => $campaignId, 'plan' => json_decode($campaign['priority_plan_json'], true)];
            }
            if (!in_array($campaign['status'], ['queued', 'running'], true)) { throw new \RuntimeException('La campagna non è attiva.'); }
            $pending = $this->platformDb->query('SELECT * FROM ' . self::RECIPIENTS . " WHERE id_tenant = ? AND id_whatsapp_campaign = ? AND status IN ('pending', 'processing') ORDER BY id_whatsapp_campaign_recipient" . ($apply ? ' FOR UPDATE' : ''), [$tenantId, $campaignId])->getResultArray();
            foreach ($pending as $recipient) {
                if ($recipient['status'] === 'processing') { throw new \RuntimeException('Un invio è ancora in corso: attendere il completamento prima del riordino.'); }
            }
            if ($pending === []) { throw new \RuntimeException('Nessun destinatario in attesa.'); }
            $tenant = $this->tenantCatalog->getTenantById($tenantId);
            if (!$tenant) { throw new \RuntimeException('Spazio non trovato.'); }
            $db = $this->tenantDbConnector->connect($tenant);
            $this->databaseConfig->setEncryptionConfig($db);
            $hints = $this->allPatientRecipients($db, $now);
            $channel = new AppointmentNotificationChannelService();
            $byClient = []; $byPhone = [];
            foreach ($hints as $hint) {
                $byClient[(int) $hint['id_client']] = $hint;
                $phone = $channel->normalizeRecipient((string) (($hint['cellulare'] ?? '') ?: ($hint['telefono'] ?? '')));
                if ($phone !== null && (!isset($byPhone[$phone]) || (!empty($hint['next_appointment_at']) && (empty($byPhone[$phone]['next_appointment_at']) || $hint['next_appointment_at'] < $byPhone[$phone]['next_appointment_at'])))) {
                    $byPhone[$phone] = $hint;
                }
            }
            foreach ($pending as &$recipient) {
                $recipient['phone'] = $recipient['recipient_phone'];
                $hint = $byClient[(int) ($recipient['id_client'] ?? 0)] ?? $byPhone[$recipient['phone']] ?? [];
                $recipient['sort_surname'] = $hint['sort_surname'] ?? $recipient['patient_name'];
                $recipient['sort_name'] = $hint['sort_name'] ?? '';
                $dates = array_filter([$hint['next_appointment_at'] ?? null, $byPhone[$recipient['phone']]['next_appointment_at'] ?? null]);
                $recipient['next_appointment_at'] = $dates === [] ? null : min($dates);
            }
            unset($recipient);
            $plan = $this->buildPlan($tenant, $pending, $now, $campaignId);
            if (count($plan['recipients']) !== count($pending)) { throw new \RuntimeException('Il riordino cambierebbe il numero dei destinatari.'); }
            if ($apply) {
                if ($planner->isOpen($planner->now())) { throw new \RuntimeException('La finestra notturna è terminata: riordino annullato.'); }
                foreach (array_chunk($plan['recipients'], 100) as $chunk) {
                    $updates = array_map(static fn(array $r): array => ['id_whatsapp_campaign_recipient' => $r['id_whatsapp_campaign_recipient'], 'send_order' => $r['send_order']], $chunk);
                    $ok = $this->platformDb->table(self::RECIPIENTS)->where('id_tenant', $tenantId)->where('id_whatsapp_campaign', $campaignId)->where('status', 'pending')
                        ->updateBatch($updates, 'id_whatsapp_campaign_recipient');
                    if ($ok === false) { throw new \RuntimeException('Riordino destinatari non riuscito.'); }
                }
                $this->platformDb->table(self::CAMPAIGNS)->where('id_tenant', $tenantId)->where('id_whatsapp_campaign', $campaignId)
                    ->update(['priority_plan_json' => json_encode($plan['summary'], JSON_THROW_ON_ERROR)]);
                if (!$this->platformDb->transStatus()) { throw new \RuntimeException('Riordino non riuscito.'); }
                $this->platformDb->transCommit();
            }
            return ['status' => $apply ? 'applied' : 'preview', 'tenant_id' => $tenantId, 'campaign_id' => $campaignId, 'plan' => $plan['summary']];
        } catch (\Throwable $e) {
            if ($apply) { $this->platformDb->transRollback(); }
            throw $e;
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function appointmentRecipients(BaseConnection $db, string $date): array
    {
        if (!$db->tableExists('dap12_agenda_appuntamenti') || !$db->tableExists('dap11_agenda_slot')) { return []; }
        $hasClient = $db->tableExists('dap02_clients') && $db->fieldExists('id_client', 'dap12_agenda_appuntamenti');
        $join = $hasClient ? 'LEFT JOIN dap02_clients c ON c.id_client = COALESCE(NULLIF(a.id_client, 0), NULLIF(a.id_paziente, 0))' : 'LEFT JOIN (SELECT NULL AS id_client, NULL AS cellulare, NULL AS telefono, NULL AS nome, NULL AS cognome, NULL AS vector_id) c ON 1 = 0';
        $mobile = $hasClient ? $this->decryptExpr('c.cellulare') : "''"; $phone = $hasClient ? $this->decryptExpr('c.telefono') : "''";
        return $db->query("SELECT a.id_appuntamento AS id_appointment, COALESCE(c.id_client, 0) AS id_client, COALESCE(NULLIF(a.cellulare, ''), {$mobile}) AS cellulare, COALESCE(NULLIF(a.telefono, ''), {$phone}) AS telefono, TRIM(CONCAT_WS(' ', a.nome, a.cognome)) AS patient_name FROM dap12_agenda_appuntamenti a INNER JOIN dap11_agenda_slot s ON s.id_slot = a.id_slot {$join} WHERE s.data_slot = ? AND a.stato <> 'ANNULLATO' ORDER BY a.id_appuntamento ASC", [$date])->getResultArray();
    }

    private function finishRecipient(array $candidate, array $result): void
    {
        $now = date('Y-m-d H:i:s'); $ok = !empty($result['ok']);
        $this->platformDb->table(self::RECIPIENTS)->where('id_whatsapp_campaign_recipient', (int) $candidate['id_whatsapp_campaign_recipient'])->where('status', 'processing')->update([
            'status' => $ok ? 'sent' : 'failed', 'provider_message_id' => trim((string) ($result['provider_id'] ?? '')) ?: null,
            'error_text' => $ok ? null : mb_substr(trim((string) ($result['error'] ?? 'Invio WhatsApp non riuscito.')), 0, 2000),
            'sent_at' => $ok ? $now : null, 'updated_at' => $now,
        ]);
        $this->refreshCampaign((int) $candidate['id_whatsapp_campaign']);
    }

    private function refreshCampaign(int $campaignId): void
    {
        $counts = $this->platformDb->query("SELECT COUNT(*) AS total, SUM(status = 'pending') AS pending, SUM(status = 'sent') AS sent, SUM(status = 'failed') AS failed, SUM(status = 'processing') AS processing FROM " . self::RECIPIENTS . ' WHERE id_whatsapp_campaign = ?', [$campaignId])->getRowArray() ?: [];
        $pending = (int) ($counts['pending'] ?? 0); $processing = (int) ($counts['processing'] ?? 0);
        $this->platformDb->table(self::CAMPAIGNS)->where('id_whatsapp_campaign', $campaignId)->update([
            'total_recipients' => (int) ($counts['total'] ?? 0), 'pending_recipients' => $pending + $processing,
            'sent_recipients' => (int) ($counts['sent'] ?? 0), 'failed_recipients' => (int) ($counts['failed'] ?? 0),
            'status' => ($pending + $processing) > 0 ? 'running' : 'completed',
            'completed_at' => ($pending + $processing) > 0 ? null : date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function releaseStaleClaims(): void
    {
        $before = date('Y-m-d H:i:s', time() - 900);
        $this->platformDb->table(self::RECIPIENTS)->where('status', 'processing')->where('updated_at <', $before)->update(['status' => 'pending', 'updated_at' => date('Y-m-d H:i:s')]);
    }
    private function campaignById(int $tenantId, int $campaignId): ?array { $row = $this->platformDb->table(self::CAMPAIGNS)->where('id_tenant', $tenantId)->where('id_whatsapp_campaign', $campaignId)->get(1)->getRowArray(); return $row ?: null; }
    private function gateway(): WhatsAppGatewayClient { return $this->gatewayClient ??= new WhatsAppGatewayClient(); }
    private function tablesReady(): bool { return $this->platformDb->tableExists(self::CAMPAIGNS) && $this->platformDb->tableExists(self::RECIPIENTS) && $this->platformDb->tableExists(self::RATE_LIMITS); }
    private function assertTablesReady(): void { if (!$this->tablesReady()) { throw new \RuntimeException('Il database campagne WhatsApp non è ancora aggiornato.'); } }
    private function isDate(string $value): bool { $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value); return $date && $date->format('Y-m-d') === $value; }
    private function decryptExpr(string $field): string { $dot = strrpos($field, '.'); $vector = $dot === false ? 'vector_id' : substr($field, 0, $dot + 1) . 'vector_id'; return "CONVERT(CAST(AES_DECRYPT(UNHEX({$field}), @key_str, {$vector}) AS CHAR CHARACTER SET latin1) USING utf8mb4)"; }
    /** @param array<int,array<string,mixed>> $campaigns */
    private function summary(array $campaigns): array { $summary = ['total' => count($campaigns), 'queued' => 0, 'sent' => 0, 'failed' => 0]; foreach ($campaigns as $row) { $summary['queued'] += (int) ($row['pending_recipients'] ?? 0); $summary['sent'] += (int) ($row['sent_recipients'] ?? 0); $summary['failed'] += (int) ($row['failed_recipients'] ?? 0); } return $summary; }
}
