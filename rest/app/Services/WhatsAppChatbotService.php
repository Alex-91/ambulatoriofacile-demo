<?php

namespace App\Services;

use App\Libraries\WhatsappAppointmentNote;
use App\Models\AgendaAppointmentModel;
use App\Models\LegacyWhatsappAppointmentsModel;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

class WhatsAppChatbotService
{
    private const CHATBOTS_TABLE = 'platform_whatsapp_chatbots';
    private const INTERACTIONS_TABLE = 'platform_whatsapp_chatbot_interactions';
    private const MESSAGES_TABLE = 'platform_whatsapp_chatbot_messages';

    private BaseConnection $platformDb;
    private WhatsAppChatbotRuleEngine $ruleEngine;
    private TenantCatalogService $tenantCatalog;
    private TenantDatabaseConnector $tenantDbConnector;
    private ?WhatsAppGatewayClient $gatewayClient;

    public function __construct(
        ?BaseConnection $platformDb = null,
        ?WhatsAppChatbotRuleEngine $ruleEngine = null,
        ?TenantCatalogService $tenantCatalog = null,
        ?TenantDatabaseConnector $tenantDbConnector = null,
        ?WhatsAppGatewayClient $gatewayClient = null
    ) {
        $this->platformDb = $platformDb ?? Database::connect('platform');
        $this->ruleEngine = $ruleEngine ?? new WhatsAppChatbotRuleEngine();
        $this->tenantCatalog = $tenantCatalog ?? new TenantCatalogService();
        $this->tenantDbConnector = $tenantDbConnector ?? new TenantDatabaseConnector();
        $this->gatewayClient = $gatewayClient;
    }

    /**
     * @return array<string, mixed>
     */
    public function configForTenant(int $tenantId): array
    {
        $defaults = $this->ruleEngine->defaultConfig();
        if ($tenantId <= 0 || !$this->tablesReady()) {
            return $defaults;
        }

        $row = $this->platformDb->table(self::CHATBOTS_TABLE)
            ->where('id_tenant', $tenantId)
            ->get(1)
            ->getRowArray();
        if (!$row) {
            return $defaults;
        }

        return $this->ruleEngine->sanitizeConfig([
            'enabled' => (int) ($row['is_enabled'] ?? 0) === 1,
            'response_window_hours' => (int) ($row['response_window_hours'] ?? 168),
            'prompt_text' => (string) ($row['prompt_text'] ?? ''),
            'fallback_reply' => (string) ($row['fallback_reply'] ?? ''),
            'open_on' => $this->decodeJsonList((string) ($row['open_on_json'] ?? '')),
            'rules' => $this->decodeJsonList((string) ($row['rules_json'] ?? '')),
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function saveConfig(int $tenantId, array $payload, int $platformUserId): array
    {
        if ($tenantId <= 0 || !$this->tablesReady()) {
            throw new \RuntimeException('Il database del chatbot WhatsApp non è ancora aggiornato.');
        }

        $config = $this->ruleEngine->sanitizeConfig($payload);
        $now = date('Y-m-d H:i:s');
        $data = [
            'id_tenant' => $tenantId,
            'is_enabled' => !empty($config['enabled']) ? 1 : 0,
            'response_window_hours' => (int) $config['response_window_hours'],
            'prompt_text' => (string) $config['prompt_text'],
            'fallback_reply' => (string) $config['fallback_reply'],
            'open_on_json' => json_encode($config['open_on'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'rules_json' => json_encode($config['rules'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_by_platform_user_id' => $platformUserId > 0 ? $platformUserId : null,
            'updated_at' => $now,
        ];

        $existing = $this->platformDb->table(self::CHATBOTS_TABLE)
            ->select('id_whatsapp_chatbot')
            ->where('id_tenant', $tenantId)
            ->get(1)
            ->getRowArray();
        if ($existing) {
            $this->platformDb->table(self::CHATBOTS_TABLE)
                ->where('id_whatsapp_chatbot', (int) $existing['id_whatsapp_chatbot'])
                ->update($data);
        } else {
            $data['created_at'] = $now;
            $this->platformDb->table(self::CHATBOTS_TABLE)->insert($data);
        }

        return $config;
    }

    public function instructionsForTenant(int $tenantId, ?string $messageType = null): string
    {
        $config = $this->configForTenant($tenantId);
        if (empty($config['enabled'])) {
            return '';
        }
        if ($messageType !== null && !in_array($messageType, (array) ($config['open_on'] ?? []), true)) {
            return '';
        }

        return trim((string) ($config['prompt_text'] ?? ''));
    }

    public function registerAppointmentPrompt(
        int $tenantId,
        int $appointmentId,
        string $phone,
        string $messageType,
        string $outboundMessageId = ''
    ): bool {
        if ($tenantId <= 0 || $appointmentId <= 0 || !$this->tablesReady()) {
            return false;
        }

        $phoneKey = self::phoneKey($phone);
        if ($phoneKey === '') {
            return false;
        }

        $config = $this->configForTenant($tenantId);
        if (empty($config['enabled']) || !in_array($messageType, (array) ($config['open_on'] ?? []), true)) {
            return false;
        }

        $now = date('Y-m-d H:i:s');
        $expiresAt = date('Y-m-d H:i:s', time() + ((int) $config['response_window_hours'] * 3600));

        $this->platformDb->transStart();
        $this->platformDb->table(self::INTERACTIONS_TABLE)
            ->where('id_tenant', $tenantId)
            ->where('phone_key', $phoneKey)
            ->where('status', 'pending')
            ->update([
                'status' => 'superseded',
                'resolved_at' => $now,
                'updated_at' => $now,
            ]);
        $this->platformDb->table(self::INTERACTIONS_TABLE)->insert([
            'id_tenant' => $tenantId,
            'appointment_id' => $appointmentId,
            'phone_key' => $phoneKey,
            'message_type' => $messageType,
            'outbound_message_id' => trim($outboundMessageId) !== '' ? trim($outboundMessageId) : null,
            'status' => 'pending',
            'expires_at' => $expiresAt,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->platformDb->transComplete();

        return (bool) $this->platformDb->transStatus();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function processIncoming(int $tenantId, array $payload): array
    {
        if ($tenantId <= 0 || !$this->tablesReady()) {
            throw new \RuntimeException('Chatbot WhatsApp non disponibile.');
        }

        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
        $messageId = trim((string) ($data['message_id'] ?? $data['id'] ?? ''));
        $accountId = trim((string) ($payload['account_id'] ?? $data['account_id'] ?? 'primary'));
        $from = trim((string) ($data['from'] ?? ''));
        $phoneKey = self::phoneKey($from);
        $text = trim((string) ($data['text'] ?? $data['body'] ?? ''));
        $messageType = strtolower(trim((string) ($data['message_type'] ?? $data['type'] ?? 'text')));
        $isGroup = !empty($data['is_group']);

        if ($messageId === '' || $accountId === '' || $phoneKey === '') {
            throw new \InvalidArgumentException('Messaggio WhatsApp incompleto.');
        }

        $messageKey = hash('sha256', $accountId . '|' . $messageId);
        $claim = $this->claimInboundMessage($tenantId, $messageKey, [
            'provider_message_id' => $messageId,
            'account_id' => substr($accountId, 0, 63),
            'phone_key' => $phoneKey,
            'sender_name' => trim((string) ($data['sender_name'] ?? '')),
            'message_text' => $text,
            'received_at' => $this->normalizeDateTime((string) ($data['received_at'] ?? '')),
        ]);

        if (!empty($claim['duplicate'])) {
            return ['ok' => true, 'duplicate' => true, 'status' => (string) ($claim['status'] ?? 'processed')];
        }

        $messageRowId = (int) ($claim['id'] ?? 0);
        if ((string) ($claim['status'] ?? '') === 'reply_failed') {
            return $this->retryReply($messageRowId, $tenantId, $from, $claim);
        }

        $claimedInteractionId = 0;
        $appointmentActionApplied = false;
        $claimedAction = '';

        try {
            $config = $this->configForTenant($tenantId);
            if (empty($config['enabled']) || $isGroup || $messageType !== 'text' || $text === '') {
                $this->finishMessage($messageRowId, ['status' => 'ignored']);
                return ['ok' => true, 'status' => 'ignored'];
            }

            $rule = $this->ruleEngine->match($config, $text);
            if ($rule === null) {
                $fallback = trim((string) ($config['fallback_reply'] ?? ''));
                if ($fallback === '') {
                    $this->finishMessage($messageRowId, ['status' => 'unmatched']);
                    return ['ok' => true, 'status' => 'unmatched'];
                }

                $this->sendReplyOrFail($messageRowId, $tenantId, $from, $fallback, [
                    'status' => 'replied',
                    'action_name' => WhatsAppChatbotRuleEngine::ACTION_REPLY,
                ]);
                return ['ok' => true, 'status' => 'replied'];
            }

            $action = (string) ($rule['action'] ?? WhatsAppChatbotRuleEngine::ACTION_REPLY);
            $interaction = null;
            $appointment = null;
            $appointmentId = 0;

            if (in_array($action, [WhatsAppChatbotRuleEngine::ACTION_CONFIRM, WhatsAppChatbotRuleEngine::ACTION_CANCEL], true)) {
                $interaction = $this->findPendingInteraction($tenantId, $phoneKey);
                if ($interaction === null) {
                    $reply = trim((string) ($config['fallback_reply'] ?? ''));
                    if ($reply === '') {
                        $reply = 'Non trovo una richiesta di conferma ancora valida per questo numero. Contatta lo studio per assistenza.';
                    }
                    $this->sendReplyOrFail($messageRowId, $tenantId, $from, $reply, [
                        'status' => 'no_context',
                        'matched_rule_id' => (string) ($rule['id'] ?? ''),
                        'action_name' => $action,
                    ]);
                    return ['ok' => true, 'status' => 'no_context'];
                }

                $claimedInteractionId = (int) ($interaction['id_whatsapp_chatbot_interaction'] ?? 0);
                $claimedAction = $action;
                $appointmentId = (int) ($interaction['appointment_id'] ?? 0);
                [$tenantDb, $appointment] = $this->applyAppointmentAction($tenantId, $appointmentId, $action);
                $appointmentActionApplied = true;
                $this->resolveInteraction($claimedInteractionId, $action, $messageId);
                $claimedInteractionId = 0;
                unset($tenantDb);
            }

            $reply = $this->renderReply((string) ($rule['reply'] ?? ''), $appointment, $tenantId);
            $finish = [
                'status' => 'processed',
                'matched_rule_id' => (string) ($rule['id'] ?? ''),
                'action_name' => $action,
                'appointment_id' => $appointmentId > 0 ? $appointmentId : null,
            ];
            if ($reply !== '') {
                $this->sendReplyOrFail($messageRowId, $tenantId, $from, $reply, $finish);
            } else {
                $this->finishMessage($messageRowId, $finish);
            }

            return [
                'ok' => true,
                'status' => 'processed',
                'action' => $action,
                'appointment_id' => $appointmentId,
            ];
        } catch (\Throwable $e) {
            if ($claimedInteractionId > 0) {
                $this->finishInteractionAfterError(
                    $claimedInteractionId,
                    $claimedAction,
                    $messageId,
                    $appointmentActionApplied
                );
            }
            $this->markMessageFailed($messageRowId, $e->getMessage());
            throw $e;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboardForTenant(int $tenantId, int $limit = 50): array
    {
        if ($tenantId <= 0 || !$this->tablesReady()) {
            return ['ready' => false, 'pending' => 0, 'processed_30_days' => 0, 'failed_30_days' => 0, 'messages' => []];
        }

        $cutoff = date('Y-m-d H:i:s', strtotime('-30 days'));
        $pending = (int) $this->platformDb->table(self::INTERACTIONS_TABLE)
            ->where('id_tenant', $tenantId)
            ->where('status', 'pending')
            ->where('expires_at >=', date('Y-m-d H:i:s'))
            ->countAllResults();
        $processed = (int) $this->platformDb->table(self::MESSAGES_TABLE)
            ->where('id_tenant', $tenantId)
            ->whereIn('status', ['processed', 'replied'])
            ->where('created_at >=', $cutoff)
            ->countAllResults();
        $failed = (int) $this->platformDb->table(self::MESSAGES_TABLE)
            ->where('id_tenant', $tenantId)
            ->whereIn('status', ['failed', 'reply_failed'])
            ->where('created_at >=', $cutoff)
            ->countAllResults();
        $messages = $this->platformDb->table(self::MESSAGES_TABLE)
            ->where('id_tenant', $tenantId)
            ->orderBy('id_whatsapp_chatbot_message', 'DESC')
            ->get(max(1, min(100, $limit)))
            ->getResultArray();

        return [
            'ready' => true,
            'pending' => $pending,
            'processed_30_days' => $processed,
            'failed_30_days' => $failed,
            'messages' => $messages,
        ];
    }

    public static function phoneKey(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', trim($phone)) ?? '';
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        if ($digits !== '' && !str_starts_with($digits, '39') && preg_match('/^3\d{8,9}$/', $digits)) {
            $digits = '39' . $digits;
        }

        return strlen($digits) >= 8 && strlen($digits) <= 15 ? $digits : '';
    }

    private function tablesReady(): bool
    {
        return $this->platformDb->tableExists(self::CHATBOTS_TABLE)
            && $this->platformDb->tableExists(self::INTERACTIONS_TABLE)
            && $this->platformDb->tableExists(self::MESSAGES_TABLE);
    }

    /**
     * @return list<mixed>
     */
    private function decodeJsonList(string $json): array
    {
        if (trim($json) === '') {
            return [];
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? array_values($decoded) : [];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function claimInboundMessage(int $tenantId, string $messageKey, array $data): array
    {
        $existing = $this->platformDb->table(self::MESSAGES_TABLE)
            ->where('id_tenant', $tenantId)
            ->where('message_key', $messageKey)
            ->get(1)
            ->getRowArray();
        if ($existing) {
            $status = (string) ($existing['status'] ?? 'processed');
            if (!in_array($status, ['failed', 'reply_failed'], true)) {
                return ['duplicate' => true, 'status' => $status, 'id' => (int) $existing['id_whatsapp_chatbot_message']];
            }
            if ($status === 'reply_failed') {
                return ['duplicate' => false, 'status' => $status, 'id' => (int) $existing['id_whatsapp_chatbot_message']] + $existing;
            }

            $this->platformDb->table(self::MESSAGES_TABLE)
                ->where('id_whatsapp_chatbot_message', (int) $existing['id_whatsapp_chatbot_message'])
                ->update(['status' => 'processing', 'error_text' => null, 'updated_at' => date('Y-m-d H:i:s')]);
            return ['duplicate' => false, 'status' => 'processing', 'id' => (int) $existing['id_whatsapp_chatbot_message']];
        }

        $now = date('Y-m-d H:i:s');
        $this->platformDb->table(self::MESSAGES_TABLE)->insert([
            'id_tenant' => $tenantId,
            'message_key' => $messageKey,
            'provider_message_id' => (string) $data['provider_message_id'],
            'account_id' => (string) $data['account_id'],
            'phone_key' => (string) $data['phone_key'],
            'sender_name' => (string) $data['sender_name'],
            'message_text' => (string) $data['message_text'],
            'status' => 'processing',
            'received_at' => (string) $data['received_at'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return ['duplicate' => false, 'status' => 'processing', 'id' => (int) $this->platformDb->insertID()];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findPendingInteraction(int $tenantId, string $phoneKey): ?array
    {
        $now = date('Y-m-d H:i:s');
        $this->platformDb->table(self::INTERACTIONS_TABLE)
            ->where('id_tenant', $tenantId)
            ->where('phone_key', $phoneKey)
            ->where('status', 'pending')
            ->where('expires_at <', $now)
            ->update(['status' => 'expired', 'resolved_at' => $now, 'updated_at' => $now]);

        $row = $this->platformDb->table(self::INTERACTIONS_TABLE)
            ->where('id_tenant', $tenantId)
            ->where('phone_key', $phoneKey)
            ->where('status', 'pending')
            ->where('expires_at >=', $now)
            ->orderBy('id_whatsapp_chatbot_interaction', 'DESC')
            ->get(1)
            ->getRowArray();

        if (!$row) {
            return null;
        }

        $interactionId = (int) ($row['id_whatsapp_chatbot_interaction'] ?? 0);
        $this->platformDb->table(self::INTERACTIONS_TABLE)
            ->where('id_whatsapp_chatbot_interaction', $interactionId)
            ->where('status', 'pending')
            ->update(['status' => 'processing', 'updated_at' => $now]);

        return $this->platformDb->affectedRows() === 1 ? $row : null;
    }

    /**
     * @return array{0:BaseConnection,1:array<string,mixed>}
     */
    private function applyAppointmentAction(int $tenantId, int $appointmentId, string $action): array
    {
        $tenant = $this->tenantCatalog->getTenantById($tenantId);
        if (!$tenant || (int) ($tenant['is_active'] ?? 0) !== 1) {
            throw new \RuntimeException('Spazio cliente non disponibile.');
        }

        $tenantDb = $this->tenantDbConnector->connect($tenant);
        $legacyAppointments = new LegacyWhatsappAppointmentsModel($tenantDb);
        $appointment = $legacyAppointments->findPendingAppointmentById($appointmentId, false);
        if (!$appointment) {
            throw new \RuntimeException('Appuntamento non trovato oppure già gestito.');
        }

        $occurredAt = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Rome'));
        if ($action === WhatsAppChatbotRuleEngine::ACTION_CONFIRM) {
            if (!$legacyAppointments->markAppointmentConfirmed($appointmentId, WhatsappAppointmentNote::buildConfirmationNote($occurredAt))) {
                throw new \RuntimeException('Non è stato possibile confermare l’appuntamento.');
            }
        } elseif ($action === WhatsAppChatbotRuleEngine::ACTION_CANCEL) {
            (new AgendaAppointmentModel($tenantDb))->deleteAppointment($appointmentId, 0);
            $legacyAppointments->appendOutcomeNote($appointmentId, WhatsappAppointmentNote::buildCancellationNote($occurredAt));
        }

        return [$tenantDb, $appointment];
    }

    private function resolveInteraction(int $interactionId, string $action, string $messageId): void
    {
        $status = $action === WhatsAppChatbotRuleEngine::ACTION_CANCEL ? 'cancelled' : 'confirmed';
        $now = date('Y-m-d H:i:s');
        $this->platformDb->table(self::INTERACTIONS_TABLE)
            ->where('id_whatsapp_chatbot_interaction', $interactionId)
            ->where('status', 'processing')
            ->update([
                'status' => $status,
                'inbound_message_id' => $messageId,
                'resolved_at' => $now,
                'updated_at' => $now,
            ]);
    }

    private function finishInteractionAfterError(
        int $interactionId,
        string $action,
        string $messageId,
        bool $appointmentActionApplied
    ): void {
        try {
            if ($appointmentActionApplied) {
                $this->resolveInteraction($interactionId, $action, $messageId);
                return;
            }

            $this->platformDb->table(self::INTERACTIONS_TABLE)
                ->where('id_whatsapp_chatbot_interaction', $interactionId)
                ->where('status', 'processing')
                ->update(['status' => 'pending', 'updated_at' => date('Y-m-d H:i:s')]);
        } catch (\Throwable $cleanupError) {
            log_message('error', 'WhatsApp chatbot interaction cleanup failed: {message}', [
                'message' => $cleanupError->getMessage(),
                'interaction_id' => $interactionId,
            ]);
        }
    }

    /**
     * @param array<string, mixed>|null $appointment
     */
    private function renderReply(string $template, ?array $appointment, int $tenantId): string
    {
        $tenant = $this->tenantCatalog->getTenantById($tenantId) ?? [];
        $scheduled = '';
        if (!empty($appointment['appointment_date'])) {
            try {
                $scheduled = (new \DateTimeImmutable((string) $appointment['appointment_date'], new \DateTimeZone('Europe/Rome')))->format('d/m/Y H:i');
            } catch (\Throwable $e) {
                $scheduled = trim((string) $appointment['appointment_date']);
            }
        }
        $patient = trim(implode(' ', array_filter([
            trim((string) ($appointment['patient_name'] ?? $appointment['appointment_name'] ?? '')),
            trim((string) ($appointment['patient_surname'] ?? $appointment['appointment_surname'] ?? '')),
        ])));
        $doctor = trim(implode(' ', array_filter([
            trim((string) ($appointment['doctor_title'] ?? '')),
            trim((string) ($appointment['doctor_name'] ?? '')),
            trim((string) ($appointment['doctor_surname'] ?? '')),
        ])));

        return trim(strtr($template, [
            '{{paziente}}' => $patient !== '' ? $patient : 'paziente',
            '{{data_ora}}' => $scheduled,
            '{{dottore}}' => $doctor,
            '{{nome_spazio}}' => trim((string) ($tenant['tenant_name'] ?? 'AmbulatorioFacile')),
        ]));
    }

    /**
     * @param array<string, mixed> $finish
     */
    private function sendReplyOrFail(int $messageRowId, int $tenantId, string $recipient, string $reply, array $finish): void
    {
        $result = $this->gatewayClient()->sendText($tenantId, $recipient, $reply);
        $finish['reply_text'] = $reply;
        if (empty($result['ok'])) {
            $finish['status'] = 'reply_failed';
            $finish['error_text'] = (string) ($result['error'] ?? 'Risposta WhatsApp non inviata.');
            $this->finishMessage($messageRowId, $finish, false);
            throw new \RuntimeException((string) $finish['error_text']);
        }

        $this->finishMessage($messageRowId, $finish);
    }

    /**
     * @param array<string, mixed> $claim
     * @return array<string, mixed>
     */
    private function retryReply(int $messageRowId, int $tenantId, string $recipient, array $claim): array
    {
        $reply = trim((string) ($claim['reply_text'] ?? ''));
        if ($reply === '') {
            $this->finishMessage($messageRowId, ['status' => 'failed', 'error_text' => 'Testo risposta non disponibile.']);
            throw new \RuntimeException('Testo risposta non disponibile.');
        }

        $result = $this->gatewayClient()->sendText($tenantId, $recipient, $reply);
        if (empty($result['ok'])) {
            $this->finishMessage($messageRowId, [
                'status' => 'reply_failed',
                'reply_text' => $reply,
                'error_text' => (string) ($result['error'] ?? 'Risposta WhatsApp non inviata.'),
            ], false);
            throw new \RuntimeException((string) ($result['error'] ?? 'Risposta WhatsApp non inviata.'));
        }

        $this->finishMessage($messageRowId, ['status' => 'processed', 'reply_text' => $reply]);
        return ['ok' => true, 'status' => 'processed', 'reply_retried' => true];
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function finishMessage(int $messageRowId, array $fields, bool $processed = true): void
    {
        $fields['updated_at'] = date('Y-m-d H:i:s');
        if ($processed) {
            $fields['processed_at'] = date('Y-m-d H:i:s');
            $fields['error_text'] = $fields['error_text'] ?? null;
        }
        $this->platformDb->table(self::MESSAGES_TABLE)
            ->where('id_whatsapp_chatbot_message', $messageRowId)
            ->update($fields);
    }

    private function markMessageFailed(int $messageRowId, string $error): void
    {
        $row = $this->platformDb->table(self::MESSAGES_TABLE)
            ->select('status')
            ->where('id_whatsapp_chatbot_message', $messageRowId)
            ->get(1)
            ->getRowArray();
        if ((string) ($row['status'] ?? '') === 'reply_failed') {
            return;
        }
        $this->finishMessage($messageRowId, [
            'status' => 'failed',
            'error_text' => mb_substr(trim($error), 0, 2000),
        ], false);
    }

    private function normalizeDateTime(string $value): string
    {
        if (trim($value) === '') {
            return date('Y-m-d H:i:s');
        }
        try {
            return (new \DateTimeImmutable($value))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return date('Y-m-d H:i:s');
        }
    }

    private function gatewayClient(): WhatsAppGatewayClient
    {
        return $this->gatewayClient ??= new WhatsAppGatewayClient();
    }
}
