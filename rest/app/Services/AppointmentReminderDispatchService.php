<?php

namespace App\Services;

use Config\Database;

class AppointmentReminderDispatchService
{
    private \CodeIgniter\Database\BaseConnection $platformDb;
    private TenantDatabaseConnector $tenantDbConnector;
    private AppointmentNotificationSettingsService $settingsService;
    private AppointmentNotificationChannelService $channelService;
    private AppointmentNotificationLogService $logService;
    private TenantStoragePathService $storagePaths;

    public function __construct()
    {
        $this->platformDb = Database::connect('platform');
        $this->tenantDbConnector = new TenantDatabaseConnector();
        $this->settingsService = new AppointmentNotificationSettingsService();
        $this->channelService = new AppointmentNotificationChannelService();
        $this->logService = new AppointmentNotificationLogService();
        $this->storagePaths = new TenantStoragePathService();
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function run(array $options = []): array
    {
        $sendMode = !empty($options['send']);
        $tenantFilterId = max(0, (int) ($options['tenant_id'] ?? 0));
        $forcedChannel = strtolower(trim((string) ($options['channel'] ?? 'auto')));
        $forceRecipient = $this->channelService->normalizeRecipientContext((string) ($options['force_recipient'] ?? ''));
        $hasForcedRecipient = $this->hasAnyRecipientTarget($forceRecipient);
        $delayMs = max(0, (int) ($options['delay_ms'] ?? (int) (env('SMS_BATCH_DELAY_MS') ?: 0)));
        $limit = max(0, (int) ($options['limit'] ?? 0));
        $doctorFilter = $this->normalizeDoctorFilter((string) ($options['doctor'] ?? ''));
        $targetDateOverride = trim((string) ($options['target_date'] ?? ''));

        $tenants = $this->platformDb->table('platform_tenants')
            ->select('id_tenant, tenant_key, tenant_name, storage_key, db_host, db_port, db_name, db_username, db_password_ref, db_driver, db_prefix')
            ->where('is_active', 1);

        if ($tenantFilterId > 0) {
            $tenants->where('id_tenant', $tenantFilterId);
        }

        $tenantRows = $tenants->orderBy('tenant_name', 'ASC')->get()->getResultArray();
        $summary = [
            'mode' => $sendMode ? 'send' : 'dry-run',
            'tenant_count' => count($tenantRows),
            'processed_tenants' => 0,
            'candidates' => 0,
            'sent' => 0,
            'failed' => 0,
            'already_sent' => 0,
            'invalid_recipient' => 0,
            'tenants' => [],
        ];

        foreach ($tenantRows as $tenant) {
            $tenantId = (int) ($tenant['id_tenant'] ?? 0);
            if ($tenantId <= 0) {
                continue;
            }

            $settings = $this->settingsService->resolveTenantSettings($tenantId);
            $plan = $this->settingsService->resolveDispatchPlan($tenantId, AppointmentNotificationSettingsService::TYPE_REMINDER);
            if (empty($settings['module']['available']) || empty($plan['enabled']) || empty($plan['channels'])) {
                continue;
            }

            $channels = $this->resolveChannels((array) $plan['channels'], $forcedChannel);
            if ($channels === []) {
                continue;
            }

            $targetDate = $targetDateOverride !== ''
                ? $targetDateOverride
                : (new \DateTimeImmutable('today', new \DateTimeZone('Europe/Rome')))
                    ->modify('+' . max(0, (int) ($plan['lead_days'] ?? 2)) . ' day')
                    ->format('Y-m-d');

            $tenantSummary = [
                'tenant_id' => $tenantId,
                'tenant_name' => (string) ($tenant['tenant_name'] ?? ''),
                'target_date' => $targetDate,
                'channels' => $channels,
                'candidates' => 0,
                'sent' => 0,
                'failed' => 0,
                'already_sent' => 0,
                'invalid_recipient' => 0,
                'preview' => [],
            ];

            try {
                $tenantDb = $this->tenantDbConnector->connect($tenant);
                $rows = $this->fetchReminderCandidates($tenantDb, $targetDate, $doctorFilter, $limit);
                $tenantSummary['candidates'] = count($rows);
                $summary['processed_tenants']++;

                $stateDir = $this->storagePaths->reminderStateDir($tenant, true);
                $states = [];
                foreach ($channels as $channel) {
                    $states[$channel] = $this->loadState($stateDir . DIRECTORY_SEPARATOR . 'appointment_reminders_' . $channel . '_' . $targetDate . '.json');
                }

                foreach ($rows as $row) {
                    $appointmentId = (int) ($row['id_appuntamento'] ?? 0);
                    $patientLabel = trim((string) ($row['patient_cognome'] ?? '') . ' ' . (string) ($row['patient_nome'] ?? ''));
                    $recipient = $hasForcedRecipient ? $forceRecipient : $this->buildRecipientContext($row, $patientLabel);

                    if (!$this->hasAnyRecipientTarget($recipient)) {
                        $tenantSummary['invalid_recipient']++;
                        $summary['invalid_recipient']++;
                        continue;
                    }

                    $message = $this->buildReminderMessage($row, $targetDate);
                    $subject = 'Reminder appuntamento AmbulatorioFacile';
                    $otpSubject = 'Codice OTP e reminder appuntamento';

                    if (!$sendMode) {
                        $tenantSummary['preview'][] = [
                            'appointment_id' => $appointmentId,
                            'patient_label' => $patientLabel,
                            'recipient' => $this->describeRecipients($recipient, $channels),
                            'channels' => $channels,
                            'scheduled_for' => $targetDate . ' ' . (string) ($row['ora_label'] ?? ''),
                        ];
                        continue;
                    }

                    foreach ($channels as $channel) {
                        if (isset($states[$channel]['sent'][(string) $appointmentId])) {
                            $tenantSummary['already_sent']++;
                            $summary['already_sent']++;
                            continue;
                        }

                        $resolvedRecipient = $this->channelService->describeRecipientForChannel($channel, $recipient);
                        if ($resolvedRecipient === '') {
                            $tenantSummary['invalid_recipient']++;
                            $summary['invalid_recipient']++;
                            continue;
                        }

                        $sendResult = $this->channelService->send($channel, $recipient, $message, [
                            'db' => $tenantDb,
                            'subject' => $subject,
                            'otp_subject' => $otpSubject,
                        ]);
                        $logEntry = [
                            'tenant_id' => $tenantId,
                            'tenant_key' => (string) ($tenant['tenant_key'] ?? ''),
                            'tenant_name' => (string) ($tenant['tenant_name'] ?? ''),
                            'message_type' => AppointmentNotificationSettingsService::TYPE_REMINDER,
                            'channel' => $channel,
                            'provider' => (string) ($sendResult['provider'] ?? $this->channelService->providerLabel($channel)),
                            'provider_id' => (string) ($sendResult['provider_id'] ?? ''),
                            'recipient' => (string) ($sendResult['recipient'] ?? $resolvedRecipient),
                            'recipient_role' => 'patient',
                            'appointment_id' => $appointmentId,
                            'patient_label' => $patientLabel,
                            'doctor_id' => (int) ($row['id_dot'] ?? 0),
                            'doctor_label' => trim((string) ($row['doc_qualifica'] ?? '') . ' ' . (string) ($row['doc_cognome'] ?? '') . ' ' . (string) ($row['doc_nome'] ?? '')),
                            'scheduled_for' => $targetDate . ' ' . (string) ($row['ora_label'] ?? ''),
                            'status' => !empty($sendResult['ok']) ? 'sent' : 'failed',
                            'source' => 'appointment_reminder_runner',
                            'error' => (string) ($sendResult['error'] ?? ''),
                            'response' => $sendResult['response'] ?? null,
                            'created_at' => date('c'),
                        ];
                        $this->logService->append($tenant, $logEntry);

                        if (!empty($sendResult['ok'])) {
                            $states[$channel]['sent'][(string) $appointmentId] = [
                                'recipient' => (string) ($sendResult['recipient'] ?? $resolvedRecipient),
                                'sent_at' => date('c'),
                                'channel' => $channel,
                                'provider_id' => (string) ($sendResult['provider_id'] ?? ''),
                                'response' => $sendResult['response'] ?? null,
                            ];
                            $tenantSummary['sent']++;
                            $summary['sent']++;
                            $this->saveState(
                                $stateDir . DIRECTORY_SEPARATOR . 'appointment_reminders_' . $channel . '_' . $targetDate . '.json',
                                $states[$channel]
                            );
                        } else {
                            $tenantSummary['failed']++;
                            $summary['failed']++;
                        }

                        if ($delayMs > 0) {
                            usleep($delayMs * 1000);
                        }
                    }
                }
            } catch (\Throwable $e) {
                $tenantSummary['error'] = $e->getMessage();
            }

            $summary['candidates'] += (int) $tenantSummary['candidates'];
            $summary['tenants'][] = $tenantSummary;
        }

        return $summary;
    }

    /**
     * @param array<int, string> $channels
     * @return array<int, string>
     */
    private function resolveChannels(array $channels, string $forcedChannel): array
    {
        $supported = array_keys($this->settingsService->channelDefinitions());
        $channels = array_values(array_unique(array_filter(array_map(
            static fn($channel): string => strtolower(trim((string) $channel)),
            $channels
        ))));

        if (!in_array($forcedChannel, $supported, true)) {
            return array_values(array_intersect($channels, $supported));
        }

        return in_array($forcedChannel, $channels, true) ? [$forcedChannel] : [];
    }

    /**
     * @return array<int, int>
     */
    private function normalizeDoctorFilter(string $raw): array
    {
        $parts = preg_split('/[\s,;]+/', trim($raw)) ?: [];
        $ids = [];

        foreach ($parts as $part) {
            $id = (int) $part;
            if ($id > 0 && !in_array($id, $ids, true)) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @param array<int, int> $doctorFilter
     * @return array<int, array<string, mixed>>
     */
    private function fetchReminderCandidates(\CodeIgniter\Database\BaseConnection $db, string $targetDate, array $doctorFilter, int $limit): array
    {
        $doctorSql = '';
        if ($doctorFilter !== []) {
            $doctorSql = ' AND s.id_dot IN (' . implode(',', array_map('intval', $doctorFilter)) . ')';
        }

        $limitSql = $limit > 0 ? (' LIMIT ' . $limit) : '';
        $hasConfirmTable = $db->tableExists('dap39_sms_dot');
        $joinConfirm = $hasConfirmTable
            ? "LEFT JOIN dap39_sms_dot conf ON conf.id_dot = s.id_dot"
            : "LEFT JOIN (SELECT NULL AS id_dot, 0 AS conferma) conf ON 1 = 0";
        $hasIdClientColumn = $db->fieldExists('id_client', 'dap12_agenda_appuntamenti');
        $clientJoinOn = $hasIdClientColumn
            ? 'c.id_client = COALESCE(NULLIF(a.id_client, 0), NULLIF(a.id_paziente, 0))'
            : 'c.id_client = NULLIF(a.id_paziente, 0)';
        $patientEmailExpr = $this->decryptExpr('c.email', 'c.vector_id');

        $sql = "
            SELECT
                a.id_appuntamento,
                a.id_slot,
                a.id_dot,
                a.id_paziente,
                a.id_client,
                a.cognome AS patient_cognome,
                a.nome AS patient_nome,
                a.cellulare,
                a.telefono,
                a.email AS appointment_email,
                a.stato,
                s.data_slot,
                DATE_FORMAT(s.ora_inizio, '%H:%i') AS ora_label,
                s.id_amb_legacy,
                s.ambulatorio,
                s.stanza,
                COALESCE(conf.conferma, 0) AS conferma,
                COALESCE(amb.nome, s.ambulatorio, '') AS ambulatorio_label,
                COALESCE(amb.indirizzo, '') AS indirizzo,
                COALESCE(amb.citta, '') AS citta,
                COALESCE(amb.telefono, '') AS amb_tel,
                COALESCE(NULLIF(TRIM(a.email), ''), NULLIF(TRIM(" . $patientEmailExpr . "), ''), '') AS patient_email,
                " . $this->decryptExpr('p.qualifica', 'p.vector_id') . " AS doc_qualifica,
                " . $this->decryptExpr('p.nome', 'p.vector_id') . " AS doc_nome,
                " . $this->decryptExpr('p.cognome', 'p.vector_id') . " AS doc_cognome
            FROM dap12_agenda_appuntamenti a
            INNER JOIN dap11_agenda_slot s
                ON s.id_slot = a.id_slot
            {$joinConfirm}
            LEFT JOIN dap03_personale p
                ON p.legacy_id_dot = s.id_dot
               AND p.tipo IN (1, 2)
            LEFT JOIN dap02_clients c
                ON {$clientJoinOn}
            LEFT JOIN dap42_ambulatori amb
                ON amb.id_amb_legacy = s.id_amb_legacy
            WHERE s.data_slot = ?
              AND a.stato <> 'ANNULLATO'
              {$doctorSql}
            ORDER BY s.id_dot ASC, s.ora_inizio ASC, a.id_appuntamento ASC
            {$limitSql}
        ";

        return $db->query($sql, [$targetDate])->getResultArray();
    }

    private function decryptExpr(string $fieldExpr, string $vectorExpr): string
    {
        return "CONVERT(CAST(AES_DECRYPT(UNHEX({$fieldExpr}), @key_str, {$vectorExpr}) AS CHAR CHARACTER SET latin1) USING utf8mb4)";
    }

    /**
     * @param array<string, mixed> $row
     */
    private function buildRecipientContext(array $row, string $patientLabel): array
    {
        return [
            'mobile' => (string) ($row['cellulare'] ?? ''),
            'phone' => (string) ($row['telefono'] ?? ''),
            'email' => (string) (($row['patient_email'] ?? '') ?: ($row['appointment_email'] ?? '')),
            'label' => $patientLabel,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function buildReminderMessage(array $row, string $targetDate): string
    {
        $date = new \DateTimeImmutable($targetDate, new \DateTimeZone('Europe/Rome'));
        $doctorLabel = trim(implode(' ', array_filter([
            trim((string) ($row['doc_qualifica'] ?? '')),
            trim((string) ($row['doc_cognome'] ?? '')),
            trim((string) ($row['doc_nome'] ?? '')),
        ], static fn(string $value): bool => $value !== '')));

        $place = trim((string) ($row['ambulatorio_label'] ?? ''));
        $lines = [
            'Promemoria appuntamento',
            'Data: ' . $date->format('d/m/Y') . ' ore ' . trim((string) ($row['ora_label'] ?? '')),
            'Dottore: ' . ($doctorLabel !== '' ? $doctorLabel : 'AmbulatorioFacile'),
        ];

        if ($place !== '') {
            $lines[] = 'Sede: ' . $place;
        }

        if ((int) ($row['conferma'] ?? 0) === 1) {
            $lines[] = 'Rispondi 1 per confermare o 2 per annullare.';
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, mixed> $recipient
     * @param array<int, string> $channels
     */
    private function describeRecipients(array $recipient, array $channels): string
    {
        $labels = [];

        foreach ($channels as $channel) {
            $resolved = $this->channelService->describeRecipientForChannel($channel, $recipient);
            if ($resolved === '') {
                continue;
            }

            $labels[] = $this->channelService->channelLabel($channel) . ': ' . $resolved;
        }

        return $labels !== [] ? implode(' | ', $labels) : '';
    }

    /**
     * @param array<string, mixed> $recipient
     */
    private function hasAnyRecipientTarget(array $recipient): bool
    {
        return trim((string) (($recipient['mobile'] ?? '') ?: ($recipient['phone'] ?? '') ?: ($recipient['email'] ?? ''))) !== '';
    }

    /**
     * @return array<string, mixed>
     */
    private function loadState(string $stateFile): array
    {
        if (!is_file($stateFile)) {
            return ['sent' => []];
        }

        $json = file_get_contents($stateFile);
        if ($json === false || trim($json) === '') {
            return ['sent' => []];
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return ['sent' => []];
        }

        if (!isset($decoded['sent']) || !is_array($decoded['sent'])) {
            $decoded['sent'] = [];
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $state
     */
    private function saveState(string $stateFile, array $state): void
    {
        file_put_contents(
            $stateFile,
            json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }
}
