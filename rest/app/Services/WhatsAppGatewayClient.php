<?php

namespace App\Services;

class WhatsAppGatewayClient
{
    private string $baseUrl;
    private string $accountId;
    private int $timeoutSeconds;
    private WhatsAppGatewayRequestSigner $signer;

    public static function isRoutedToGateway(
        int $tenantId,
        ?string $provider = null,
        ?string $gatewayTenantIds = null
    ): bool {
        if ($tenantId <= 0) {
            return false;
        }

        $provider = strtolower(trim((string) ($provider ?? env('WHATSAPP_PROVIDER', 'ultramsg'))));
        if ($provider === 'gateway') {
            return true;
        }
        if ($provider !== 'hybrid') {
            return false;
        }

        $tenantIds = array_values(array_filter(array_map(
            static fn(string $value): int => max(0, (int) trim($value)),
            explode(',', (string) ($gatewayTenantIds ?? env('WHATSAPP_GATEWAY_TENANT_IDS', '')))
        )));

        return in_array($tenantId, $tenantIds, true);
    }

    public static function isAvailableForTenant(int $tenantId): bool
    {
        return self::isRoutedToGateway($tenantId)
            && trim((string) env('WHATSAPP_GATEWAY_BASE_URL', '')) !== ''
            && trim((string) env('WHATSAPP_GATEWAY_API_SECRET', '')) !== '';
    }

    public function __construct(
        ?string $baseUrl = null,
        ?string $accountId = null,
        ?int $timeoutSeconds = null,
        ?WhatsAppGatewayRequestSigner $signer = null
    ) {
        $this->baseUrl = rtrim(trim((string) ($baseUrl ?? env('WHATSAPP_GATEWAY_BASE_URL', ''))), '/');
        $this->accountId = trim((string) ($accountId ?? env('WHATSAPP_GATEWAY_ACCOUNT_ID', 'primary')));
        $this->timeoutSeconds = max(5, min(120, (int) ($timeoutSeconds ?? env('WHATSAPP_GATEWAY_TIMEOUT_SECONDS', 90))));
        $this->signer = $signer ?? new WhatsAppGatewayRequestSigner(
            (string) env('WHATSAPP_GATEWAY_API_KEY_ID', 'ambulatoriofacile-app'),
            (string) env('WHATSAPP_GATEWAY_API_SECRET', '')
        );

        if ($this->baseUrl === '' || filter_var($this->baseUrl, FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException('WHATSAPP_GATEWAY_BASE_URL non configurato o non valido.');
        }
        $parts = parse_url($this->baseUrl);
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            throw new \InvalidArgumentException('WHATSAPP_GATEWAY_BASE_URL deve utilizzare HTTPS.');
        }
        if (
            !empty($parts['user'])
            || !empty($parts['pass'])
            || !empty($parts['query'])
            || !empty($parts['fragment'])
            || !in_array((string) ($parts['path'] ?? ''), ['', '/'], true)
        ) {
            throw new \InvalidArgumentException('WHATSAPP_GATEWAY_BASE_URL deve contenere solo schema e host.');
        }
        if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,62}$/', $this->accountId)) {
            throw new \InvalidArgumentException('WHATSAPP_GATEWAY_ACCOUNT_ID non valido.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function sendText(int $tenantId, string $recipient, string $message): array
    {
        $requestTarget = '/v1/accounts/' . rawurlencode($this->accountId) . '/messages/text';
        $body = json_encode([
            'to' => $recipient,
            'text' => $message,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $headers = $this->signer->headers('POST', $requestTarget, $tenantId, $body);
        $headers['Accept'] = 'application/json';
        $headers['Content-Type'] = 'application/json';

        $response = $this->request('POST', $requestTarget, $body, $headers);
        $decoded = json_decode((string) ($response['body'] ?? ''), true);
        $decoded = is_array($decoded) ? $decoded : [];
        $ok = (int) ($response['status'] ?? 0) >= 200
            && (int) ($response['status'] ?? 0) < 300
            && !empty($decoded['ok']);
        $errorMessage = $decoded['message'] ?? $decoded['error'] ?? $response['error'] ?? null;
        if (!is_scalar($errorMessage) || trim((string) $errorMessage) === '') {
            $errorMessage = 'Invio WhatsApp tramite gateway non riuscito.';
        }

        return [
            'ok' => $ok,
            'channel' => AppointmentNotificationSettingsService::CHANNEL_WHATSAPP,
            'recipient' => $recipient,
            'provider' => 'AmbulatorioFacile WhatsApp Gateway',
            'provider_id' => (string) ($decoded['message']['message_id'] ?? ''),
            'response' => $decoded !== [] ? $decoded : (string) ($response['body'] ?? ''),
            'error' => $ok
                ? null
                : (string) $errorMessage,
        ];
    }

    /**
     * @return array{ok:bool, count:int, messages:array<int, array<string, mixed>>, error:?string}
     */
    public function incomingMessages(int $tenantId, int $limit = 50): array
    {
        $limit = max(1, min(100, $limit));
        $requestTarget = '/v1/accounts/' . rawurlencode($this->accountId) . '/messages?limit=' . $limit;
        $headers = $this->signer->headers('GET', $requestTarget, $tenantId, '');
        $headers['Accept'] = 'application/json';

        $response = $this->request('GET', $requestTarget, '', $headers);
        $decoded = json_decode((string) ($response['body'] ?? ''), true);
        $decoded = is_array($decoded) ? $decoded : [];
        $ok = (int) ($response['status'] ?? 0) >= 200
            && (int) ($response['status'] ?? 0) < 300
            && !empty($decoded['ok']);
        $messages = isset($decoded['messages']) && is_array($decoded['messages'])
            ? array_values($decoded['messages'])
            : [];
        $errorMessage = $decoded['message'] ?? $decoded['error'] ?? $response['error'] ?? null;
        if (!$ok && (!is_scalar($errorMessage) || trim((string) $errorMessage) === '')) {
            $errorMessage = 'Lettura dei messaggi WhatsApp non riuscita.';
        }

        return [
            'ok' => $ok,
            'count' => count($messages),
            'messages' => $messages,
            'error' => $ok ? null : (string) $errorMessage,
        ];
    }

    /**
     * @return array{ok:bool, account:array<string, mixed>, error:?string}
     */
    public function accountStatus(int $tenantId): array
    {
        $requestTarget = '/v1/accounts/' . rawurlencode($this->accountId);
        $headers = $this->signer->headers('GET', $requestTarget, $tenantId, '');
        $headers['Accept'] = 'application/json';

        $response = $this->request('GET', $requestTarget, '', $headers);
        $decoded = json_decode((string) ($response['body'] ?? ''), true);
        $decoded = is_array($decoded) ? $decoded : [];
        $ok = (int) ($response['status'] ?? 0) >= 200
            && (int) ($response['status'] ?? 0) < 300
            && !empty($decoded['ok']);
        $account = isset($decoded['account']) && is_array($decoded['account'])
            ? $decoded['account']
            : [];
        $errorMessage = $decoded['message'] ?? $decoded['error'] ?? $response['error'] ?? null;
        if (!$ok && (!is_scalar($errorMessage) || trim((string) $errorMessage) === '')) {
            $errorMessage = 'Stato dell\'account WhatsApp non disponibile.';
        }

        return [
            'ok' => $ok,
            'account' => $account,
            'error' => $ok ? null : (string) $errorMessage,
        ];
    }

    /**
     * @return array{ok:bool, count:int, messages:array<int, array<string, mixed>>, error:?string}
     */
    public function messages(int $tenantId, int $limit = 100): array
    {
        $limit = max(1, min(100, $limit));
        $requestTarget = '/v1/accounts/' . rawurlencode($this->accountId)
            . '/messages?limit=' . $limit . '&direction=all';
        $headers = $this->signer->headers('GET', $requestTarget, $tenantId, '');
        $headers['Accept'] = 'application/json';

        $response = $this->request('GET', $requestTarget, '', $headers);
        $decoded = json_decode((string) ($response['body'] ?? ''), true);
        $decoded = is_array($decoded) ? $decoded : [];
        $ok = (int) ($response['status'] ?? 0) >= 200
            && (int) ($response['status'] ?? 0) < 300
            && !empty($decoded['ok']);
        $messages = isset($decoded['messages']) && is_array($decoded['messages'])
            ? array_values($decoded['messages'])
            : [];
        $errorMessage = $decoded['message'] ?? $decoded['error'] ?? $response['error'] ?? null;
        if (!$ok && (!is_scalar($errorMessage) || trim((string) $errorMessage) === '')) {
            $errorMessage = 'Lettura delle conversazioni WhatsApp non riuscita.';
        }

        return [
            'ok' => $ok,
            'count' => count($messages),
            'messages' => $messages,
            'error' => $ok ? null : (string) $errorMessage,
        ];
    }

    /**
     * @param array<string, string> $headers
     * @return array{status:int, body:string, error:?string}
     */
    private function request(string $method, string $requestTarget, string $body, array $headers): array
    {
        if (!function_exists('curl_init')) {
            return ['status' => 0, 'body' => '', 'error' => 'Estensione cURL non disponibile.'];
        }

        $curl = curl_init($this->baseUrl . $requestTarget);
        if ($curl === false) {
            return ['status' => 0, 'body' => '', 'error' => 'Impossibile inizializzare cURL.'];
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSL_VERIFYPEER => true,
        ];
        if ($body !== '') {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($curl, $options);

        $responseBody = curl_exec($curl);
        $error = curl_errno($curl) !== 0 ? curl_error($curl) : null;
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        return [
            'status' => $status,
            'body' => is_string($responseBody) ? $responseBody : '',
            'error' => $error,
        ];
    }
}
