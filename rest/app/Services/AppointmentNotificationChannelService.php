<?php

namespace App\Services;

class AppointmentNotificationChannelService
{
    private const DEFAULT_ULTRAMSG_URL = 'https://api.ultramsg.com/instance123914/messages/chat';
    private const DEFAULT_ARUBA_BASEURL = 'https://adminsms.aruba.it/API/v1.0/REST/';
    private const DEFAULT_ARUBA_SENDER = 'AmbRIMAGGIO';

    /** @var array<int, string>|null */
    private ?array $arubaSession = null;

    /**
     * @return array<string, mixed>
     */
    public function send(string $channel, string $recipient, string $message): array
    {
        $channel = strtolower(trim($channel));
        $recipient = (string) ($this->normalizeRecipient($recipient) ?? '');

        if ($recipient === '') {
            return [
                'ok' => false,
                'channel' => $channel,
                'recipient' => $recipient,
                'provider' => $this->providerLabel($channel),
                'error' => 'Destinatario non valido.',
            ];
        }

        return $channel === 'sms'
            ? $this->sendSms($recipient, $message)
            : $this->sendWhatsapp($recipient, $message);
    }

    public function providerLabel(string $channel): string
    {
        return strtolower(trim($channel)) === 'sms' ? 'Aruba SMS' : 'UltraMsg';
    }

    public function channelLabel(string $channel): string
    {
        return strtolower(trim($channel)) === 'sms' ? 'SMS' : 'WhatsApp';
    }

    public function normalizeRecipient(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '' || $raw === '.' || $raw === '0') {
            return null;
        }

        $digits = preg_replace('/[^0-9+]/', '', $raw);
        if ($digits === null || $digits === '') {
            return null;
        }

        if (str_starts_with($digits, '00')) {
            $digits = '+' . substr($digits, 2);
        }

        if (str_starts_with($digits, '+39')) {
            $local = substr($digits, 3);
        } elseif (str_starts_with($digits, '39')) {
            $local = substr($digits, 2);
        } else {
            $local = ltrim($digits, '+');
        }

        if (!preg_match('/^3[0-9]{8,9}$/', $local)) {
            return null;
        }

        return '+39' . $local;
    }

    /**
     * @return array<string, mixed>
     */
    private function sendWhatsapp(string $recipient, string $message): array
    {
        $url = trim((string) (env('SMS_ULTRAMSG_URL') ?: self::DEFAULT_ULTRAMSG_URL));
        $token = trim((string) (env('SMS_API_TOKEN') ?: ''));

        if ($token === '') {
            return [
                'ok' => false,
                'channel' => 'wa',
                'recipient' => $recipient,
                'provider' => 'UltraMsg',
                'error' => 'SMS_API_TOKEN non configurato.',
            ];
        }

        $response = $this->httpPostForm($url, [
            'token' => $token,
            'to' => $recipient,
            'body' => $message,
        ]);

        $decoded = $this->decodeJson((string) ($response['body'] ?? ''));
        $providerId = $this->extractProviderId($decoded);

        return [
            'ok' => !empty($response['ok']),
            'channel' => 'wa',
            'recipient' => $recipient,
            'provider' => 'UltraMsg',
            'provider_id' => $providerId,
            'response' => $decoded ?: (string) ($response['body'] ?? ''),
            'error' => $response['ok'] ? null : (string) ($response['error'] ?? 'Invio WhatsApp non riuscito.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sendSms(string $recipient, string $message): array
    {
        try {
            if ($this->arubaSession === null) {
                $this->arubaSession = $this->loginAruba();
            }
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'channel' => 'sms',
                'recipient' => $recipient,
                'provider' => 'Aruba SMS',
                'error' => $e->getMessage(),
            ];
        }

        $sender = trim((string) (env('SMS_SENDER') ?: self::DEFAULT_ARUBA_SENDER));
        $response = $this->httpPostJson(
            rtrim(self::DEFAULT_ARUBA_BASEURL, '/') . '/sms',
            [
                'message' => $message,
                'message_type' => 'N',
                'returnCredits' => false,
                'recipient' => [$recipient],
                'sender' => $sender !== '' ? $sender : self::DEFAULT_ARUBA_SENDER,
            ],
            [
                'Content-type: application/json',
                'user_key: ' . (string) ($this->arubaSession[0] ?? ''),
                'Session_key: ' . (string) ($this->arubaSession[1] ?? ''),
            ]
        );

        $decoded = $this->decodeJson((string) ($response['body'] ?? ''));
        $providerId = $this->extractProviderId($decoded);

        return [
            'ok' => !empty($response['ok']),
            'channel' => 'sms',
            'recipient' => $recipient,
            'provider' => 'Aruba SMS',
            'provider_id' => $providerId,
            'response' => $decoded ?: (string) ($response['body'] ?? ''),
            'error' => $response['ok'] ? null : (string) ($response['error'] ?? 'Invio SMS non riuscito.'),
        ];
    }

    /**
     * @return array<int, string>
     */
    private function loginAruba(): array
    {
        $username = trim((string) (env('SMS_USERNAME') ?: ''));
        $password = trim((string) (env('SMS_PASSWORD') ?: ''));

        if ($username === '' || $password === '') {
            throw new \RuntimeException('SMS_USERNAME o SMS_PASSWORD mancanti per il canale SMS.');
        }

        $url = rtrim(self::DEFAULT_ARUBA_BASEURL, '/') . '/login?username=' . rawurlencode($username) . '&password=' . rawurlencode($password);
        $response = $this->httpGet($url);
        if (empty($response['ok'])) {
            throw new \RuntimeException((string) ($response['error'] ?? 'Login Aruba SMS non riuscito.'));
        }

        $parts = array_values(array_filter(array_map('trim', explode(';', (string) ($response['body'] ?? '')))));
        if (count($parts) < 2) {
            throw new \RuntimeException('Sessione Aruba SMS non valida.');
        }

        return [$parts[0], $parts[1]];
    }

    /**
     * @param array<string, scalar|null> $payload
     * @return array<string, mixed>
     */
    private function httpPostForm(string $url, array $payload): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'error' => 'cURL non disponibile.'];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($payload),
            CURLOPT_HTTPHEADER => ['content-type: application/x-www-form-urlencoded'],
        ]);

        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'ok' => $body !== false && $status >= 200 && $status < 300,
            'status' => $status,
            'body' => $body === false ? '' : $body,
            'error' => $body === false ? $error : ($status >= 200 && $status < 300 ? null : ('HTTP ' . $status)),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $headers
     * @return array<string, mixed>
     */
    private function httpPostJson(string $url, array $payload, array $headers = []): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'error' => 'cURL non disponibile.'];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => $headers,
        ]);

        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'ok' => $body !== false && $status >= 200 && $status < 300,
            'status' => $status,
            'body' => $body === false ? '' : $body,
            'error' => $body === false ? $error : ($status >= 200 && $status < 300 ? null : ('HTTP ' . $status)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function httpGet(string $url): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'error' => 'cURL non disponibile.'];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        $body = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'ok' => $body !== false && $status >= 200 && $status < 300,
            'status' => $status,
            'body' => $body === false ? '' : $body,
            'error' => $body === false ? $error : ($status >= 200 && $status < 300 ? null : ('HTTP ' . $status)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $json): array
    {
        $json = trim($json);
        if ($json === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractProviderId(array $payload): string
    {
        foreach (['id', 'message_id', 'messageId', 'smsId', 'transactionId'] as $key) {
            if (!empty($payload[$key]) && is_scalar($payload[$key])) {
                return (string) $payload[$key];
            }
        }

        foreach (['data', 'result'] as $bucket) {
            if (!empty($payload[$bucket]) && is_array($payload[$bucket])) {
                $nested = $this->extractProviderId($payload[$bucket]);
                if ($nested !== '') {
                    return $nested;
                }
            }
        }

        return '';
    }
}
