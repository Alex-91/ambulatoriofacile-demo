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
        ?string $gatewayTenantIds = null,
        ?callable $tenantRoutingResolver = null
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

        if (in_array($tenantId, self::configuredTenantIds($gatewayTenantIds), true)) {
            return true;
        }

        // An explicit list keeps this helper deterministic for callers and tests
        // that intentionally want to evaluate only the legacy environment allowlist.
        if ($gatewayTenantIds !== null && $tenantRoutingResolver === null) {
            return false;
        }

        $tenantRoutingResolver = $tenantRoutingResolver
            ?? static fn(int $resolvedTenantId): bool => (new WhatsAppGatewayTenantRoutingService())
                ->isEnabledForTenant($resolvedTenantId);

        try {
            return (bool) $tenantRoutingResolver($tenantId);
        } catch (\Throwable $e) {
            log_message('error', 'WhatsApp gateway tenant routing lookup failed: ' . $e->getMessage(), [
                'tenant_id' => $tenantId,
            ]);
            return false;
        }
    }

    /**
     * @return array<int, int>
     */
    public static function configuredTenantIds(?string $gatewayTenantIds = null): array
    {
        $values = preg_split(
            '/[\s,;]+/',
            trim((string) ($gatewayTenantIds ?? env('WHATSAPP_GATEWAY_TENANT_IDS', '')))
        ) ?: [];
        $tenantIds = [];

        foreach ($values as $value) {
            $tenantId = max(0, (int) trim((string) $value));
            if ($tenantId > 0 && !in_array($tenantId, $tenantIds, true)) {
                $tenantIds[] = $tenantId;
            }
        }

        sort($tenantIds, SORT_NUMERIC);
        return $tenantIds;
    }

    public static function isConfigured(): bool
    {
        return trim((string) env('WHATSAPP_GATEWAY_BASE_URL', '')) !== ''
            && trim((string) env('WHATSAPP_GATEWAY_API_SECRET', '')) !== '';
    }

    public static function isAvailableForTenant(int $tenantId): bool
    {
        return self::isRoutedToGateway($tenantId) && self::isConfigured();
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
     * Avvia una nuova associazione WhatsApp multi-device per lo spazio.
     *
     * @return array{ok:bool, account:array<string, mixed>, error:?string, error_code:?string, http_status:int}
     */
    public function startPairing(int $tenantId, string $displayName = ''): array
    {
        $requestTarget = '/v1/accounts/' . rawurlencode($this->accountId) . '/pair';
        $body = json_encode([
            'display_name' => trim($displayName),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return $this->accountOperation('POST', $requestTarget, $tenantId, $body);
    }

    /**
     * Legge il QR corrente durante l'associazione.
     *
     * @return array{ok:bool, account:array<string, mixed>, qr_pending:bool, error:?string, error_code:?string, http_status:int}
     */
    public function pairingQrCode(int $tenantId): array
    {
        $requestTarget = '/v1/accounts/' . rawurlencode($this->accountId) . '/qr';
        $result = $this->accountOperation('GET', $requestTarget, $tenantId);

        return array_merge($result, [
            'qr_pending' => !empty($result['response']['qr_pending']),
        ]);
    }

    /**
     * Ricollega una sessione WhatsApp gia associata.
     *
     * @return array{ok:bool, account:array<string, mixed>, error:?string, error_code:?string, http_status:int}
     */
    public function connectAccount(int $tenantId): array
    {
        $requestTarget = '/v1/accounts/' . rawurlencode($this->accountId) . '/connect';
        return $this->accountOperation('POST', $requestTarget, $tenantId);
    }

    /**
     * Revoca la sessione WhatsApp e scollega il dispositivo corrente.
     *
     * @return array{ok:bool, account:array<string, mixed>, error:?string, error_code:?string, http_status:int}
     */
    public function logoutAccount(int $tenantId): array
    {
        $requestTarget = '/v1/accounts/' . rawurlencode($this->accountId) . '/session';
        return $this->accountOperation('DELETE', $requestTarget, $tenantId);
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
     * @return array{ok:bool, account:array<string, mixed>, error:?string, error_code:?string, http_status:int}
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
            'error_code' => $ok ? null : trim((string) ($decoded['error'] ?? '')),
            'http_status' => (int) ($response['status'] ?? 0),
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
     * @return array{ok:bool, account:array<string, mixed>, response:array<string, mixed>, error:?string, error_code:?string, http_status:int}
     */
    private function accountOperation(
        string $method,
        string $requestTarget,
        int $tenantId,
        string $body = ''
    ): array {
        $headers = $this->signer->headers($method, $requestTarget, $tenantId, $body);
        $headers['Accept'] = 'application/json';
        if ($body !== '') {
            $headers['Content-Type'] = 'application/json';
        }

        $response = $this->request($method, $requestTarget, $body, $headers);
        $decoded = json_decode((string) ($response['body'] ?? ''), true);
        $decoded = is_array($decoded) ? $decoded : [];
        $status = (int) ($response['status'] ?? 0);
        $ok = $status >= 200 && $status < 300 && !empty($decoded['ok']);
        $account = isset($decoded['account']) && is_array($decoded['account'])
            ? $decoded['account']
            : [];
        $errorMessage = $decoded['message'] ?? $decoded['error'] ?? $response['error'] ?? null;
        if (!$ok && (!is_scalar($errorMessage) || trim((string) $errorMessage) === '')) {
            $errorMessage = 'Operazione sull\'account WhatsApp non riuscita.';
        }

        return [
            'ok' => $ok,
            'account' => $account,
            'response' => $decoded,
            'error' => $ok ? null : (string) $errorMessage,
            'error_code' => $ok ? null : trim((string) ($decoded['error'] ?? '')),
            'http_status' => $status,
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
