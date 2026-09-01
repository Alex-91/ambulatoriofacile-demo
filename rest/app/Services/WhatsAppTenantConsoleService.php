<?php

namespace App\Services;

class WhatsAppTenantConsoleService
{
    private ?WhatsAppGatewayClient $client;

    public function __construct(?WhatsAppGatewayClient $client = null)
    {
        $this->client = $client;
    }

    /**
     * @return array<string, mixed>
     */
    public function build(int $tenantId): array
    {
        $payload = $this->emptyPayload($tenantId);
        $payload['gateway_configured'] = WhatsAppGatewayClient::isConfigured();
        $payload['tenant_routed'] = WhatsAppGatewayClient::isRoutedToGateway($tenantId);
        $payload['gateway_available'] = $payload['gateway_configured'] && $payload['tenant_routed'];

        if (!$payload['gateway_available']) {
            $payload['setup_message'] = $payload['gateway_configured']
                ? 'Il canale WhatsApp è attivo, ma il collegamento del dispositivo non è ancora stato predisposto dalla piattaforma per questo spazio.'
                : 'Il canale WhatsApp è attivo, ma il collegamento del dispositivo non è ancora disponibile in questo ambiente.';
            return $payload;
        }

        $client = $this->client();
        $status = $client->accountStatus($tenantId);
        if (empty($status['ok'])) {
            if (in_array((string) ($status['error_code'] ?? ''), ['account_not_found', 'account_not_paired'], true)) {
                return $payload;
            }

            throw new \RuntimeException((string) ($status['error'] ?? 'Stato WhatsApp non disponibile.'));
        }

        $account = is_array($status['account'] ?? null) ? $status['account'] : [];
        if ((string) ($account['state'] ?? '') === 'pairing' && empty($account['qr_code'])) {
            $qr = $client->pairingQrCode($tenantId);
            if (!empty($qr['ok']) && is_array($qr['account'] ?? null)) {
                $account = array_replace($account, $qr['account']);
            }
        }

        $payload['account'] = $this->normalizeAccount($account);
        $payload['account_exists'] = true;

        if (!empty($account['logged_in'])) {
            $timeline = $client->messages($tenantId, 100);
            if (empty($timeline['ok'])) {
                throw new \RuntimeException((string) ($timeline['error'] ?? 'Registro invii WhatsApp non disponibile.'));
            }

            $payload['delivery_log'] = $this->normalizeDeliveryLog(
                is_array($timeline['messages'] ?? null) ? $timeline['messages'] : []
            );
            $payload['delivery_summary'] = $this->buildDeliverySummary($payload['delivery_log']);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function startPairing(int $tenantId, string $displayName): array
    {
        $result = $this->client()->startPairing($tenantId, $displayName);
        $this->assertOperationSucceeded($result, 'Avvio del collegamento WhatsApp non riuscito.');
        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function reconnect(int $tenantId): array
    {
        $result = $this->client()->connectAccount($tenantId);
        $this->assertOperationSucceeded($result, 'Riconnessione WhatsApp non riuscita.');
        return $result;
    }

    public function disconnect(int $tenantId): void
    {
        $result = $this->client()->logoutAccount($tenantId);
        $this->assertOperationSucceeded($result, 'Disconnessione del dispositivo WhatsApp non riuscita.');
    }

    /**
     * @return array<string, mixed>
     */
    public function changeDevice(int $tenantId, string $displayName): array
    {
        $logout = $this->client()->logoutAccount($tenantId);
        if (
            empty($logout['ok'])
            && !in_array((string) ($logout['error_code'] ?? ''), ['account_not_found', 'account_not_paired'], true)
        ) {
            $this->assertOperationSucceeded($logout, 'Disconnessione del dispositivo WhatsApp non riuscita.');
        }

        return $this->startPairing($tenantId, $displayName);
    }

    /**
     * @return array<string, mixed>
     */
    public function emptyPayload(int $tenantId): array
    {
        return [
            'tenant_id' => $tenantId,
            'feature_enabled' => true,
            'gateway_configured' => false,
            'tenant_routed' => false,
            'gateway_available' => false,
            'account_exists' => false,
            'setup_message' => '',
            'load_error' => '',
            'account' => $this->normalizeAccount([]),
            'delivery_summary' => [
                'total' => 0,
                'sent' => 0,
                'delivered' => 0,
                'read' => 0,
                'failed' => 0,
                'last_sent_at' => '',
            ],
            'delivery_log' => [],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @return array<int, array<string, mixed>>
     */
    public function normalizeDeliveryLog(array $messages): array
    {
        $rows = [];
        foreach ($messages as $message) {
            if (!is_array($message) || strtolower((string) ($message['direction'] ?? '')) !== 'outgoing') {
                continue;
            }

            $status = strtolower(trim((string) ($message['delivery_status'] ?? 'sent')));
            if (!in_array($status, ['sent', 'delivered', 'read', 'failed'], true)) {
                $status = 'sent';
            }

            $text = trim((string) ($message['text'] ?? ''));
            if ($text === '') {
                $text = $this->messageTypeLabel((string) ($message['message_type'] ?? ''));
            }
            $preview = function_exists('mb_substr') ? mb_substr($text, 0, 180) : substr($text, 0, 180);

            $rows[] = [
                'message_id' => trim((string) ($message['message_id'] ?? '')),
                'recipient' => trim((string) ($message['to'] ?? $message['peer'] ?? '')),
                'text' => $preview,
                'message_type' => trim((string) ($message['message_type'] ?? 'text')),
                'status' => $status,
                'status_label' => $this->deliveryStatusLabel($status),
                'sent_at' => trim((string) ($message['received_at'] ?? $message['stored_at'] ?? '')),
                'delivered_at' => trim((string) ($message['delivered_at'] ?? '')),
                'read_at' => trim((string) ($message['read_at'] ?? '')),
            ];
        }

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    public function buildDeliverySummary(array $rows): array
    {
        $summary = [
            'total' => count($rows),
            'sent' => 0,
            'delivered' => 0,
            'read' => 0,
            'failed' => 0,
            'last_sent_at' => trim((string) ($rows[0]['sent_at'] ?? '')),
        ];

        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? 'sent');
            if (array_key_exists($status, $summary)) {
                $summary[$status]++;
            }
        }

        return $summary;
    }

    /**
     * @param array<string, mixed> $account
     * @return array<string, mixed>
     */
    public function normalizeAccount(array $account): array
    {
        $state = strtolower(trim((string) ($account['state'] ?? 'not_configured')));
        $connected = !empty($account['connected']);
        $loggedIn = !empty($account['logged_in']);
        if ($connected && $loggedIn) {
            $state = 'connected';
        } elseif ($state === 'connected') {
            $state = 'connecting';
        }

        return [
            'state' => $state,
            'state_label' => $this->accountStateLabel($state),
            'connected' => $connected,
            'logged_in' => $loggedIn,
            'display_name' => trim((string) ($account['display_name'] ?? '')),
            'device' => $this->formatDevice((string) ($account['device_jid'] ?? '')),
            'qr_code' => trim((string) ($account['qr_code'] ?? '')),
            'qr_expires_at' => trim((string) ($account['qr_expires_at'] ?? '')),
            'updated_at' => trim((string) ($account['updated_at'] ?? '')),
        ];
    }

    private function client(): WhatsAppGatewayClient
    {
        if ($this->client === null) {
            $this->client = new WhatsAppGatewayClient();
        }
        return $this->client;
    }

    /**
     * @param array<string, mixed> $result
     */
    private function assertOperationSucceeded(array $result, string $fallback): void
    {
        if (!empty($result['ok'])) {
            return;
        }

        $message = trim((string) ($result['error'] ?? ''));
        throw new \RuntimeException($message !== '' ? $message : $fallback);
    }

    private function deliveryStatusLabel(string $status): string
    {
        return match ($status) {
            'read' => 'Letto',
            'delivered' => 'Consegnato',
            'failed' => 'Non inviato',
            default => 'Inviato',
        };
    }

    private function accountStateLabel(string $state): string
    {
        return match ($state) {
            'connected' => 'Collegato',
            'connecting' => 'Connessione in corso',
            'pairing' => 'In attesa del QR',
            'paired' => 'Associato',
            'disconnected' => 'Disconnesso',
            'logged_out' => 'Scollegato',
            'error' => 'Richiede attenzione',
            default => 'Non collegato',
        };
    }

    private function messageTypeLabel(string $messageType): string
    {
        return match (strtolower(trim($messageType))) {
            'image' => 'Immagine',
            'video' => 'Video',
            'audio', 'voice' => 'Messaggio audio',
            'document' => 'Documento',
            'sticker' => 'Sticker',
            default => 'Messaggio WhatsApp',
        };
    }

    private function formatDevice(string $deviceJid): string
    {
        $device = trim($deviceJid);
        if ($device === '') {
            return '';
        }

        $device = explode('@', $device, 2)[0];
        $device = explode(':', $device, 2)[0];
        if (preg_match('/^\d{8,15}$/', $device) !== 1) {
            return '';
        }

        return '+' . $device;
    }
}
