<?php

namespace App\Services;

class AppointmentNotificationLogExplorerService
{
    private const PROBLEM_STATUSES = ['failed', 'error', 'rejected', 'undelivered', 'bounced', 'expired'];
    private const DELIVERED_STATUSES = ['delivered', 'read'];
    private const ACCEPTED_STATUSES = ['sent', 'accepted', 'success'];
    /**
     * @param array<int, array<string, mixed>> $entries
     * @param array<string, mixed> $rawFilters
     * @return array<string, mixed>
     */
    public function build(array $entries, array $rawFilters = [], int $defaultLimit = 100): array
    {
        $filters = $this->normalizeFilters($rawFilters, $defaultLimit);
        $rows = [];

        foreach ($entries as $entry) {
            $decorated = $this->decorate($entry);
            if ($this->matches($decorated, $filters)) {
                $rows[] = $decorated;
            }
        }

        usort($rows, static function (array $left, array $right): int {
            return strcmp((string) ($right['created_at'] ?? ''), (string) ($left['created_at'] ?? ''));
        });

        $summary = [
            'total' => count($rows),
            'accepted' => 0,
            'delivered' => 0,
            'problems' => 0,
            'pending' => 0,
            'skipped' => 0,
            'email' => 0,
            'wa' => 0,
            'sms' => 0,
            'otp' => 0,
        ];
        foreach ($rows as $row) {
            $group = (string) ($row['status_group'] ?? 'pending');
            $summaryKey = $group === 'problem' ? 'problems' : $group;
            if (array_key_exists($summaryKey, $summary)) {
                $summary[$summaryKey]++;
            }
            $channel = (string) ($row['channel'] ?? '');
            if (array_key_exists($channel, $summary)) {
                $summary[$channel]++;
            }
        }

        $limit = (int) $filters['limit'];

        return [
            'rows' => array_slice($rows, 0, $limit),
            'summary' => $summary,
            'filters' => $filters,
            'total_matching' => count($rows),
            'truncated' => count($rows) > $limit,
        ];
    }

    /** @param array<string, mixed> $entry @return array<string, mixed> */
    private function decorate(array $entry): array
    {
        $channel = strtolower(trim((string) ($entry['channel'] ?? '')));
        $sendStatus = strtolower(trim((string) ($entry['status'] ?? '')));
        $deliveryStatus = strtolower(trim((string) ($entry['delivery_status'] ?? '')));
        $effectiveStatus = $deliveryStatus !== '' ? $deliveryStatus : ($sendStatus !== '' ? $sendStatus : 'pending');
        $statusGroup = $this->statusGroup($effectiveStatus);
        $error = trim((string) ($entry['error'] ?? ''));
        $response = $entry['response'] ?? null;

        $entry['channel'] = $channel;
        $entry['status'] = $sendStatus;
        $entry['effective_status'] = $effectiveStatus;
        $entry['status_group'] = $statusGroup;
        $entry['diagnostic_message'] = $this->diagnosticMessage($channel, $effectiveStatus, $statusGroup, $error, $response);
        $entry['response_preview'] = $this->responsePreview($response);

        return $entry;
    }

    private function diagnosticMessage(string $channel, string $status, string $group, string $error, $response): string
    {
        if ($error !== '') {
            return $error;
        }
        if ($group === 'problem') {
            return 'Il provider ha rifiutato o non ha consegnato la notifica; apri la risposta tecnica per i dettagli.';
        }
        if ($group === 'skipped') {
            return 'Invio non eseguito perché il flusso o il canale non erano applicabili.';
        }
        if ($group === 'pending') {
            return $status === 'deferred'
                ? 'Invio rinviato per rispettare i limiti di frequenza dello spazio.'
                : 'Invio ancora in attesa di un esito definitivo.';
        }
        if ($channel === AppointmentNotificationSettingsService::CHANNEL_EMAIL && $group === 'accepted') {
            if (is_array($response) && strtolower(trim((string) ($response['protocol'] ?? ''))) === 'mail') {
                return 'Il server ha accettato l’invio tramite la funzione mail di sistema, senza conferma di consegna. Per una diagnosi più affidabile configura un SMTP dedicato e controlla spam/quarantena.';
            }
            return 'Il trasporto email ha accettato il messaggio, ma la consegna nella casella non è confermata. Controlla spam, quarantena e configurazione SMTP.';
        }
        if ($channel === AppointmentNotificationSettingsService::CHANNEL_SMS && $group === 'accepted') {
            return 'SMS accettato dal provider; la conferma finale arriverà tramite ricevuta DLR.';
        }
        if ($channel === AppointmentNotificationSettingsService::CHANNEL_WHATSAPP && $group === 'accepted') {
            return 'Messaggio accettato dal provider WhatsApp; l’esito finale dipende dalla ricevuta di consegna.';
        }
        if ($group === 'delivered') {
            return $status === 'read' ? 'Notifica letta dal destinatario.' : 'Consegna confermata dal provider.';
        }

        return 'Invio accettato dal canale configurato.';
    }

    private function statusGroup(string $status): string
    {
        if (in_array($status, self::DELIVERED_STATUSES, true)) {
            return 'delivered';
        }
        if (in_array($status, self::ACCEPTED_STATUSES, true)) {
            return 'accepted';
        }
        if (in_array($status, self::PROBLEM_STATUSES, true)) {
            return 'problem';
        }
        if ($status === 'skipped') {
            return 'skipped';
        }

        return 'pending';
    }

    /** @param array<string, mixed> $entry @param array<string, mixed> $filters */
    private function matches(array $entry, array $filters): bool
    {
        if ((int) $filters['tenant_id'] > 0 && (int) ($entry['tenant_id'] ?? 0) !== (int) $filters['tenant_id']) {
            return false;
        }
        if ((string) $filters['channel'] !== '' && (string) ($entry['channel'] ?? '') !== (string) $filters['channel']) {
            return false;
        }
        if ((string) $filters['status'] !== '' && (string) ($entry['status_group'] ?? '') !== (string) $filters['status']) {
            return false;
        }
        if ((string) $filters['message_type'] !== '' && (string) ($entry['message_type'] ?? '') !== (string) $filters['message_type']) {
            return false;
        }

        $query = (string) $filters['query'];
        if ($query === '') {
            return true;
        }

        $haystack = mb_strtolower(implode(' ', [
            (string) ($entry['tenant_name'] ?? ''),
            (string) ($entry['patient_label'] ?? ''),
            (string) ($entry['doctor_label'] ?? ''),
            (string) ($entry['recipient'] ?? ''),
            (string) ($entry['provider'] ?? ''),
            (string) ($entry['provider_id'] ?? ''),
            (string) ($entry['diagnostic_message'] ?? ''),
            (string) ($entry['event_id'] ?? ''),
        ]));

        return str_contains($haystack, mb_strtolower($query));
    }

    /** @param array<string, mixed> $rawFilters @return array<string, mixed> */
    private function normalizeFilters(array $rawFilters, int $defaultLimit): array
    {
        $channel = strtolower(trim((string) ($rawFilters['channel'] ?? '')));
        if (!in_array($channel, ['', 'email', 'wa', 'sms', 'otp'], true)) {
            $channel = '';
        }
        $status = strtolower(trim((string) ($rawFilters['status'] ?? '')));
        if (!in_array($status, ['', 'problem', 'accepted', 'delivered', 'pending', 'skipped'], true)) {
            $status = '';
        }
        $messageType = trim((string) ($rawFilters['message_type'] ?? ''));
        if (!in_array($messageType, [
            '',
            AppointmentNotificationSettingsService::TYPE_PATIENT_BOOKING,
            AppointmentNotificationSettingsService::TYPE_DOCTOR_CROSS_BOOKING,
            AppointmentNotificationSettingsService::TYPE_REMINDER,
        ], true)) {
            $messageType = '';
        }
        $limit = (int) ($rawFilters['limit'] ?? $defaultLimit);
        if (!in_array($limit, [50, 100, 250, 500], true)) {
            $limit = max(50, min(500, $defaultLimit));
        }

        return [
            'tenant_id' => max(0, (int) ($rawFilters['tenant_id'] ?? 0)),
            'channel' => $channel,
            'status' => $status,
            'message_type' => $messageType,
            'query' => mb_substr(trim((string) ($rawFilters['query'] ?? '')), 0, 120),
            'limit' => $limit,
        ];
    }

    private function responsePreview($response): string
    {
        if ($response === null || $response === '' || $response === []) {
            return '';
        }

        $sanitized = $this->redactSensitiveData($response);
        if (is_string($sanitized)) {
            return mb_substr($sanitized, 0, 6000);
        }
        $encoded = json_encode($sanitized, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? mb_substr($encoded, 0, 6000) : '';
    }

    private function redactSensitiveData($value)
    {
        if (!is_array($value)) {
            return $value;
        }

        $result = [];
        foreach ($value as $key => $item) {
            $keyText = strtolower((string) $key);
            if (preg_match('/token|password|secret|authorization|api[_-]?key|session[_-]?key|user[_-]?key/', $keyText) === 1) {
                $result[$key] = '[redacted]';
            } else {
                $result[$key] = $this->redactSensitiveData($item);
            }
        }

        return $result;
    }
}
