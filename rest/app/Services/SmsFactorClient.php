<?php

namespace App\Services;

class SmsFactorClient
{
    public const PROVIDER_LABEL = 'SMSFactor';
    public const DEFAULT_BASE_URL = 'https://api.smsfactor.com';

    private string $apiToken;
    private string $baseUrl;
    private int $timeoutSeconds;

    /** @var callable|null */
    private $requester;

    public function __construct(
        ?string $apiToken = null,
        ?string $baseUrl = null,
        ?int $timeoutSeconds = null,
        ?callable $requester = null
    ) {
        $this->apiToken = trim((string) ($apiToken ?? env('SMSFACTOR_API_TOKEN', '')));
        $this->baseUrl = rtrim(trim((string) ($baseUrl ?? env('SMSFACTOR_BASE_URL', self::DEFAULT_BASE_URL))), '/');
        $this->timeoutSeconds = max(5, min(120, (int) ($timeoutSeconds ?? env('SMSFACTOR_TIMEOUT_SECONDS', 30))));
        $this->requester = $requester;

        $this->assertValidBaseUrl();
    }

    public static function isConfigured(?string $apiToken = null): bool
    {
        return trim((string) ($apiToken ?? env('SMSFACTOR_API_TOKEN', ''))) !== '';
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function send(string $recipient, string $message, string $sender = '', array $options = []): array
    {
        return $this->submit('/send', $recipient, $message, $sender, $options, false);
    }

    /**
     * Verifica formato, filtri e costo senza creare una campagna o consumare crediti.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function simulate(string $recipient, string $message, string $sender = '', array $options = []): array
    {
        return $this->submit('/send/simulate', $recipient, $message, $sender, $options, true);
    }

    /**
     * @return array<string, mixed>
     */
    public function credits(): array
    {
        if ($this->apiToken === '') {
            return $this->configurationError('SMSFACTOR_API_TOKEN non configurato.');
        }

        $response = $this->request('GET', '/credits');
        $decoded = $this->decodeJson((string) ($response['body'] ?? ''));
        $ok = $this->isSuccessfulResponse($response, $decoded);

        return [
            'ok' => $ok,
            'provider' => self::PROVIDER_LABEL,
            'credits' => isset($decoded['credits']) ? (int) $decoded['credits'] : null,
            'response' => $decoded !== [] ? $decoded : (string) ($response['body'] ?? ''),
            'error' => $ok ? null : $this->responseError($response, $decoded, 'Saldo SMSFactor non disponibile.'),
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function submit(
        string $path,
        string $recipient,
        string $message,
        string $sender,
        array $options,
        bool $simulation
    ): array {
        if ($this->apiToken === '') {
            return $this->configurationError('SMSFACTOR_API_TOKEN non configurato.', $recipient);
        }

        $destination = $this->normalizeDestination($recipient);
        if ($destination === '') {
            return $this->configurationError('Destinatario SMSFactor non valido.', $recipient);
        }

        $message = trim($message);
        if ($message === '') {
            return $this->configurationError('Il testo SMS non può essere vuoto.', $recipient);
        }

        $sender = trim($sender);
        if ($sender !== '' && preg_match('/^[A-Za-z0-9]{1,11}$/', $sender) !== 1) {
            return $this->configurationError(
                'Il mittente SMSFactor deve contenere da 1 a 11 caratteri alfanumerici.',
                $recipient
            );
        }
        $pushType = strtolower(trim((string) ($options['push_type'] ?? env('SMSFACTOR_PUSH_TYPE', 'alert'))));
        if (!in_array($pushType, ['alert', 'marketing'], true)) {
            $pushType = 'alert';
        }

        $tenantId = max(0, (int) ($options['tenant_id'] ?? 0));
        $clientMessageId = $this->normalizeClientMessageId((string) ($options['client_message_id'] ?? ''));
        if ($clientMessageId === '') {
            $clientMessageId = $this->createClientMessageId($tenantId);
        }

        $messagePayload = [
            'text' => $message,
            'pushtype' => $pushType,
            'unicode' => array_key_exists('unicode', $options)
                ? (!empty($options['unicode']) ? 1 : 0)
                : (self::requiresUnicode($message) ? 1 : 0),
        ];
        if ($sender !== '') {
            $messagePayload['sender'] = $sender;
        }

        $payload = [
            'sms' => [
                'message' => $messagePayload,
                'recipients' => [
                    'gsm' => [[
                        'gsmsmsid' => $clientMessageId,
                        'value' => $destination,
                    ]],
                ],
            ],
        ];

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($body)) {
            return $this->configurationError('Impossibile serializzare la richiesta SMSFactor.', $recipient);
        }

        $response = $this->request('POST', $path, $body);
        $decoded = $this->decodeJson((string) ($response['body'] ?? ''));
        $requestStatus = isset($decoded['status']) ? (int) $decoded['status'] : null;
        $accepted = $this->isSuccessfulResponse($response, $decoded)
            && ($requestStatus === -8 || !isset($decoded['sent']) || (int) $decoded['sent'] > 0);
        $providerId = trim((string) ($decoded['ticket'] ?? ''));

        return [
            'ok' => $accepted,
            'channel' => AppointmentNotificationSettingsService::CHANNEL_SMS,
            'recipient' => '+' . $destination,
            'provider' => self::PROVIDER_LABEL,
            'provider_id' => $providerId !== '' ? $providerId : $clientMessageId,
            'client_message_id' => $clientMessageId,
            'campaign_id' => $providerId,
            'moderated' => $requestStatus === -8,
            'simulated' => $simulation,
            'credits' => isset($decoded['credits']) ? (int) $decoded['credits'] : null,
            'cost' => isset($decoded['cost']) ? (int) $decoded['cost'] : null,
            'response' => $decoded !== [] ? array_merge($decoded, [
                'client_message_id' => $clientMessageId,
                'simulated' => $simulation,
            ]) : (string) ($response['body'] ?? ''),
            'error' => $accepted
                ? null
                : $this->responseError($response, $decoded, 'Invio SMSFactor non riuscito.'),
        ];
    }

    private function assertValidBaseUrl(): void
    {
        if ($this->baseUrl === '' || filter_var($this->baseUrl, FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException('SMSFACTOR_BASE_URL non configurato o non valido.');
        }

        $parts = parse_url($this->baseUrl);
        if (
            !is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host'])
            || !empty($parts['user'])
            || !empty($parts['pass'])
            || !empty($parts['query'])
            || !empty($parts['fragment'])
        ) {
            throw new \InvalidArgumentException('SMSFACTOR_BASE_URL deve indicare un endpoint HTTPS senza credenziali o query string.');
        }
    }

    private function normalizeDestination(string $recipient): string
    {
        $digits = preg_replace('/\D+/', '', trim($recipient)) ?? '';
        return preg_match('/^[1-9][0-9]{7,14}$/', $digits) === 1 ? $digits : '';
    }

    private function normalizeClientMessageId(string $value): string
    {
        $value = preg_replace('/[^A-Za-z0-9._-]/', '', trim($value)) ?? '';
        return substr($value, 0, 64);
    }

    private function createClientMessageId(int $tenantId): string
    {
        return sprintf('af-%d-%s', $tenantId, bin2hex(random_bytes(10)));
    }

    public static function requiresUnicode(string $message): bool
    {
        return preg_match('/[^\x0A\x0D\x20-\x7E£¥èéùìòÇØøÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉÄÖÑÜ§¿¡äöñüà¤€]/u', $message) === 1;
    }

    /**
     * @param array<string, mixed> $response
     * @param array<string, mixed> $decoded
     */
    private function isSuccessfulResponse(array $response, array $decoded): bool
    {
        $httpStatus = (int) ($response['status'] ?? 0);
        $requestStatus = isset($decoded['status']) ? (int) $decoded['status'] : null;

        return $httpStatus >= 200
            && $httpStatus < 300
            && in_array($requestStatus, [1, -8], true);
    }

    /**
     * @param array<string, mixed> $response
     * @param array<string, mixed> $decoded
     */
    private function responseError(array $response, array $decoded, string $fallback): string
    {
        if ((int) ($decoded['status'] ?? 0) === 1 && isset($decoded['sent']) && (int) $decoded['sent'] === 0) {
            $filtered = [];
            foreach (['blacklisted', 'duplicated', 'npai', 'invalid', 'not_allowed', 'flood', 'country_limit'] as $key) {
                if ((int) ($decoded[$key] ?? 0) > 0) {
                    $filtered[] = $key . ': ' . (int) $decoded[$key];
                }
            }

            return 'Nessun SMS accettato da SMSFactor'
                . ($filtered !== [] ? ' (' . implode(', ', $filtered) . ')' : '')
                . '.';
        }

        $message = $decoded['message'] ?? $decoded['error'] ?? $response['error'] ?? null;
        if (is_scalar($message) && trim((string) $message) !== '') {
            return trim((string) $message);
        }

        $status = (int) ($response['status'] ?? 0);
        return $status > 0 ? ($fallback . ' HTTP ' . $status . '.') : $fallback;
    }

    /**
     * @return array<string, mixed>
     */
    private function configurationError(string $message, string $recipient = ''): array
    {
        return [
            'ok' => false,
            'channel' => AppointmentNotificationSettingsService::CHANNEL_SMS,
            'recipient' => $recipient,
            'provider' => self::PROVIDER_LABEL,
            'provider_id' => '',
            'response' => null,
            'error' => $message,
        ];
    }

    /**
     * @return array{status:int, body:string, error:?string}
     */
    private function request(string $method, string $path, string $body = ''): array
    {
        $headers = [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $this->apiToken,
        ];
        if ($body !== '') {
            $headers['Content-Type'] = 'application/json';
        }

        if ($this->requester !== null) {
            try {
                $response = ($this->requester)($method, $this->baseUrl . $path, $body, $headers);
                return is_array($response)
                    ? [
                        'status' => (int) ($response['status'] ?? 0),
                        'body' => (string) ($response['body'] ?? ''),
                        'error' => isset($response['error']) ? (string) $response['error'] : null,
                    ]
                    : ['status' => 0, 'body' => '', 'error' => 'Risposta HTTP SMSFactor non valida.'];
            } catch (\Throwable $e) {
                return ['status' => 0, 'body' => '', 'error' => $e->getMessage()];
            }
        }

        if (!function_exists('curl_init')) {
            return ['status' => 0, 'body' => '', 'error' => 'Estensione cURL non disponibile.'];
        }

        $curl = curl_init($this->baseUrl . $path);
        if ($curl === false) {
            return ['status' => 0, 'body' => '', 'error' => 'Impossibile inizializzare cURL.'];
        }

        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $curlOptions = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSL_VERIFYPEER => true,
        ];
        if ($body !== '') {
            $curlOptions[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($curl, $curlOptions);

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

    /** @return array<string, mixed> */
    private function decodeJson(string $json): array
    {
        if (trim($json) === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }
}
