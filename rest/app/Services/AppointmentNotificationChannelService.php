<?php

namespace App\Services;

use App\Libraries\Crypto_helper;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Config\Services;

class AppointmentNotificationChannelService
{
    private const DEFAULT_ULTRAMSG_URL = 'https://api.ultramsg.com/instance123914/messages/chat';
    private const DEFAULT_ARUBA_BASEURL = 'https://adminsms.aruba.it/API/v1.0/REST/';
    private const DEFAULT_ARUBA_SENDER = 'AmbRIMAGGIO';

    /** @var array<int, string>|null */
    private ?array $arubaSession = null;

    /**
     * @param array<string, mixed>|string $recipient
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function send(string $channel, $recipient, string $message, array $options = []): array
    {
        $channel = strtolower(trim($channel));
        $recipientContext = $this->normalizeRecipientContext($recipient);

        return match ($channel) {
            AppointmentNotificationSettingsService::CHANNEL_SMS => $this->sendSmsChannel($recipientContext, $message),
            AppointmentNotificationSettingsService::CHANNEL_WHATSAPP => $this->sendWhatsappChannel($recipientContext, $message),
            AppointmentNotificationSettingsService::CHANNEL_EMAIL => $this->sendEmailChannel($recipientContext, $message, $options),
            AppointmentNotificationSettingsService::CHANNEL_OTP => $this->sendOtpChannel($recipientContext, $message, $options),
            default => [
                'ok' => false,
                'channel' => $channel,
                'recipient' => '',
                'provider' => $this->providerLabel($channel),
                'error' => 'Canale non supportato.',
            ],
        };
    }

    public function providerLabel(string $channel): string
    {
        return match (strtolower(trim($channel))) {
            AppointmentNotificationSettingsService::CHANNEL_SMS => 'Aruba SMS',
            AppointmentNotificationSettingsService::CHANNEL_WHATSAPP => 'UltraMsg',
            AppointmentNotificationSettingsService::CHANNEL_EMAIL => 'Email',
            AppointmentNotificationSettingsService::CHANNEL_OTP => 'OTP',
            default => strtoupper(trim($channel)),
        };
    }

    public function channelLabel(string $channel): string
    {
        return match (strtolower(trim($channel))) {
            AppointmentNotificationSettingsService::CHANNEL_SMS => 'SMS',
            AppointmentNotificationSettingsService::CHANNEL_WHATSAPP => 'WhatsApp',
            AppointmentNotificationSettingsService::CHANNEL_EMAIL => 'Email',
            AppointmentNotificationSettingsService::CHANNEL_OTP => 'OTP',
            default => strtoupper(trim($channel)),
        };
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

    public function normalizeEmail(string $email): string
    {
        $email = trim(strtolower($email));
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    /**
     * @param array<string, mixed>|string $recipient
     * @return array<string, mixed>
     */
    public function normalizeRecipientContext($recipient): array
    {
        if (!is_array($recipient)) {
            $raw = trim((string) $recipient);
            return [
                'mobile' => $this->normalizeRecipient($raw) ?? '',
                'phone' => $this->normalizeRecipient($raw) ?? '',
                'email' => $this->normalizeEmail($raw),
                'label' => '',
                'user_id' => 0,
                'otp_identity' => '',
            ];
        }

        $mobile = $this->normalizeRecipient((string) ($recipient['mobile'] ?? $recipient['cellulare'] ?? '')) ?? '';
        $phone = $this->normalizeRecipient((string) ($recipient['phone'] ?? $recipient['telefono'] ?? '')) ?? '';
        $email = $this->normalizeEmail((string) ($recipient['email'] ?? ''));

        if ($mobile === '' && $phone !== '') {
            $mobile = $phone;
        }

        return [
            'mobile' => $mobile,
            'phone' => $phone,
            'email' => $email,
            'label' => trim((string) ($recipient['label'] ?? '')),
            'user_id' => max(0, (int) ($recipient['user_id'] ?? 0)),
            'otp_identity' => trim((string) ($recipient['otp_identity'] ?? '')),
        ];
    }

    /**
     * @param array<string, mixed> $recipient
     */
    public function describeRecipientForChannel(string $channel, array $recipient): string
    {
        $recipient = $this->normalizeRecipientContext($recipient);
        $channel = strtolower(trim($channel));

        return match ($channel) {
            AppointmentNotificationSettingsService::CHANNEL_EMAIL => (string) ($recipient['email'] ?? ''),
            AppointmentNotificationSettingsService::CHANNEL_OTP => (string) (($recipient['email'] ?? '') ?: ($recipient['mobile'] ?? '') ?: ($recipient['phone'] ?? '')),
            default => (string) (($recipient['mobile'] ?? '') ?: ($recipient['phone'] ?? '')),
        };
    }

    /**
     * @param array<string, mixed> $recipient
     * @return array<string, mixed>
     */
    private function sendSmsChannel(array $recipient, string $message): array
    {
        $target = (string) (($recipient['mobile'] ?? '') ?: ($recipient['phone'] ?? ''));
        if ($target === '') {
            return $this->invalidRecipientResult(AppointmentNotificationSettingsService::CHANNEL_SMS);
        }

        return $this->sendSms($target, $message);
    }

    /**
     * @param array<string, mixed> $recipient
     * @return array<string, mixed>
     */
    private function sendWhatsappChannel(array $recipient, string $message): array
    {
        $target = (string) (($recipient['mobile'] ?? '') ?: ($recipient['phone'] ?? ''));
        if ($target === '') {
            return $this->invalidRecipientResult(AppointmentNotificationSettingsService::CHANNEL_WHATSAPP);
        }

        return $this->sendWhatsapp($target, $message);
    }

    /**
     * @param array<string, mixed> $recipient
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function sendEmailChannel(array $recipient, string $message, array $options): array
    {
        $email = (string) ($recipient['email'] ?? '');
        if ($email === '') {
            return $this->invalidRecipientResult(AppointmentNotificationSettingsService::CHANNEL_EMAIL);
        }

        $subject = trim((string) ($options['subject'] ?? 'Notifica appuntamento AmbulatorioFacile'));

        try {
            $this->sendEmail($email, $subject, $message);

            return [
                'ok' => true,
                'channel' => AppointmentNotificationSettingsService::CHANNEL_EMAIL,
                'recipient' => $email,
                'provider' => 'Email',
                'provider_id' => '',
                'response' => ['subject' => $subject],
                'error' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'channel' => AppointmentNotificationSettingsService::CHANNEL_EMAIL,
                'recipient' => $email,
                'provider' => 'Email',
                'provider_id' => '',
                'response' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @param array<string, mixed> $recipient
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function sendOtpChannel(array $recipient, string $message, array $options): array
    {
        $deliveryEmail = (string) ($recipient['email'] ?? '');
        $deliveryMobile = (string) (($recipient['mobile'] ?? '') ?: ($recipient['phone'] ?? ''));
        $deliveryChannel = $deliveryEmail !== ''
            ? AppointmentNotificationSettingsService::CHANNEL_EMAIL
            : ($deliveryMobile !== '' ? AppointmentNotificationSettingsService::CHANNEL_SMS : '');

        if ($deliveryChannel === '') {
            return $this->invalidRecipientResult(AppointmentNotificationSettingsService::CHANNEL_OTP);
        }

        $identity = trim((string) ($options['otp_identity'] ?? $recipient['otp_identity'] ?? ''));
        if ($identity === '') {
            $identity = $deliveryEmail !== '' ? $deliveryEmail : $deliveryMobile;
        }

        if ($identity === '') {
            return $this->invalidRecipientResult(AppointmentNotificationSettingsService::CHANNEL_OTP);
        }

        $db = $options['db'] instanceof BaseConnection ? $options['db'] : Database::connect();
        $otp = $this->generateOtpCode();
        if (!$this->storeOtp($db, $identity, $otp)) {
            return [
                'ok' => false,
                'channel' => AppointmentNotificationSettingsService::CHANNEL_OTP,
                'recipient' => $deliveryEmail !== '' ? $deliveryEmail : $deliveryMobile,
                'provider' => 'OTP',
                'provider_id' => '',
                'response' => null,
                'error' => 'Salvataggio OTP non riuscito.',
            ];
        }

        $otpMessage = $this->buildOtpMessage($message, $otp);
        if ($deliveryChannel === AppointmentNotificationSettingsService::CHANNEL_EMAIL) {
            $sendResult = $this->sendEmailChannel(
                ['email' => $deliveryEmail],
                $otpMessage,
                ['subject' => (string) ($options['otp_subject'] ?? 'Codice OTP e notifica appuntamento')]
            );
            $response = ['delivery_channel' => 'email'];
            if (is_array($sendResult['response'] ?? null)) {
                $response += (array) $sendResult['response'];
            } elseif (array_key_exists('response', $sendResult)) {
                $response['provider_response'] = $sendResult['response'];
            }

            return [
                'ok' => !empty($sendResult['ok']),
                'channel' => AppointmentNotificationSettingsService::CHANNEL_OTP,
                'recipient' => (string) ($sendResult['recipient'] ?? $deliveryEmail),
                'provider' => 'OTP Email',
                'provider_id' => (string) ($sendResult['provider_id'] ?? ''),
                'response' => $response,
                'error' => $sendResult['error'] ?? null,
            ];
        }

        $sendResult = $this->sendSms($deliveryMobile, $otpMessage);
        $response = ['delivery_channel' => 'sms'];
        if (is_array($sendResult['response'] ?? null)) {
            $response += (array) $sendResult['response'];
        } elseif (array_key_exists('response', $sendResult)) {
            $response['provider_response'] = $sendResult['response'];
        }

        return [
            'ok' => !empty($sendResult['ok']),
            'channel' => AppointmentNotificationSettingsService::CHANNEL_OTP,
            'recipient' => (string) ($sendResult['recipient'] ?? $deliveryMobile),
            'provider' => 'OTP SMS',
            'provider_id' => (string) ($sendResult['provider_id'] ?? ''),
            'response' => $response,
            'error' => $sendResult['error'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function invalidRecipientResult(string $channel): array
    {
        return [
            'ok' => false,
            'channel' => $channel,
            'recipient' => '',
            'provider' => $this->providerLabel($channel),
            'error' => 'Destinatario non valido.',
        ];
    }

    private function buildOtpMessage(string $message, string $otp): string
    {
        return implode("\n", array_values(array_filter([
            'Codice OTP: ' . $otp,
            trim($message),
            'Il codice rimane valido per circa 2 minuti.',
            'Non condividere il codice con nessuno.',
        ], static fn(string $line): bool => $line !== '')));
    }

    private function generateOtpCode(int $length = 4): string
    {
        $otp = '';

        for ($i = 0; $i < $length; $i++) {
            $otp .= (string) random_int(0, 9);
        }

        return $otp;
    }

    private function storeOtp(BaseConnection $db, string $identity, string $otp): bool
    {
        $crypto = new Crypto_helper();
        $db->query('SET @init_vector = RANDOM_BYTES(16)');

        $sql = "INSERT INTO dap16_auth_code (cellulare, authCode, vector_id)
                VALUES (" . $crypto->encrypt_insert('?') . ", ?, @init_vector)";
        $db->query($sql, [$identity, $otp]);

        return $db->affectedRows() > 0;
    }

    private function sendEmail(string $to, string $subject, string $message): void
    {
        $email = Services::email();
        $email->clear(true);
        $email->setTo($to);
        $email->setSubject($subject);
        $email->setMessage($message);

        if (!$email->send()) {
            $debug = method_exists($email, 'printDebugger')
                ? trim((string) $email->printDebugger(['headers']))
                : '';

            throw new \RuntimeException(
                'Invio email non riuscito.'
                . ($debug !== '' ? ' ' . strip_tags($debug) : '')
            );
        }
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
                'channel' => AppointmentNotificationSettingsService::CHANNEL_WHATSAPP,
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
            'channel' => AppointmentNotificationSettingsService::CHANNEL_WHATSAPP,
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
                'channel' => AppointmentNotificationSettingsService::CHANNEL_SMS,
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
            'channel' => AppointmentNotificationSettingsService::CHANNEL_SMS,
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
