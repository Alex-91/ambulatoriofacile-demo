<?php

namespace App\Services;

/** Configurable HTTPS POST adapter. Templates are expanded before JSON encoding. */
class GenericSmsClient
{
    public static function validate(array $config): void
    {
        $url = parse_url((string) ($config['url'] ?? ''));
        if (!$url || ($url['scheme'] ?? '') !== 'https' || empty($url['host'])
            || isset($url['user']) || isset($url['pass']) || isset($url['fragment'])
            || isset($url['query']) || (isset($url['port']) && $url['port'] !== 443)) {
            throw new \InvalidArgumentException('Provider HTTP: specifica un endpoint HTTPS pubblico senza credenziali o query.');
        }
        if (!in_array($config['format'] ?? 'json', ['json', 'form'], true)
            || !is_array($config['body'] ?? null)) {
            throw new \InvalidArgumentException('Provider HTTP: formato json/form e body sono obbligatori.');
        }
        $body = json_encode($config['body']);
        if (!str_contains($body, '{recipient}') || !str_contains($body, '{message}')) {
            throw new \InvalidArgumentException('Il body deve contenere {recipient} e {message}.');
        }
        if (!is_array($config['headers'] ?? [])) {
            throw new \InvalidArgumentException('Gli header devono essere un oggetto JSON.');
        }
        foreach (($config['headers'] ?? []) as $name => $value) {
            if (!preg_match('/^[A-Za-z0-9-]+$/', (string) $name) || !is_string($value)
                || preg_match('/[\r\n]/', $value)
                || in_array(strtolower((string) $name), ['host', 'content-length', 'transfer-encoding'], true)) {
                throw new \InvalidArgumentException('Header HTTP non valido.');
            }
        }
        if (empty($config['success_path']) || !array_key_exists('success_value', $config)) {
            throw new \InvalidArgumentException('Specifica success_path e success_value per verificare la risposta JSON del fornitore.');
        }
    }

    public static function payload(array $body, array $values): array
    {
        foreach ($body as &$value) {
            if (is_array($value)) {
                $value = self::payload($value, $values);
            } elseif (is_string($value)) {
                $value = strtr($value, $values);
            }
        }
        return $body;
    }

    public static function responseValue(array $response, string $path)
    {
        $value = $response;
        foreach (explode('.', $path) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }
        return $value;
    }

    public function send(array $config, string $recipient, string $message, string $sender): array
    {
        $result = ['ok' => false, 'channel' => 'sms', 'recipient' => $recipient,
            'provider' => 'HTTP personalizzato', 'provider_id' => '', 'response' => null];
        try {
            self::validate($config);
            $host = parse_url($config['url'], PHP_URL_HOST);
            $addresses = gethostbynamel($host) ?: [];
            if (!$addresses) {
                throw new \RuntimeException('Endpoint SMS non risolvibile.');
            }
            foreach ($addresses as $address) {
                if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    throw new \RuntimeException('Il provider SMS deve usare un indirizzo pubblico.');
                }
            }
            $payload = self::payload($config['body'], [
                '{recipient}' => $recipient, '{message}' => $message, '{sender}' => $sender,
            ]);
            $json = ($config['format'] ?? 'json') === 'json';
            $headers = ['Content-Type: ' . ($json ? 'application/json' : 'application/x-www-form-urlencoded')];
            foreach (($config['headers'] ?? []) as $name => $value) {
                $headers[] = $name . ': ' . $value;
            }
            $curl = curl_init($config['url']);
            curl_setopt_array($curl, [
                CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POSTFIELDS => $json ? json_encode($payload, JSON_THROW_ON_ERROR) : http_build_query($payload),
                CURLOPT_HTTPHEADER => $headers, CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_PROXY => '', CURLOPT_RESOLVE => [$host . ':443:' . $addresses[0]],
            ]);
            $raw = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            $ok = $status >= 200 && $status < 300 && is_array($decoded)
                && self::responseValue($decoded, (string) $config['success_path']) === $config['success_value'];
            $result['ok'] = $ok;
            $id = is_array($decoded) ? self::responseValue($decoded, (string) ($config['id_path'] ?? 'id')) : null;
            $result['provider_id'] = $ok && is_scalar($id) ? (string) $id : '';
            $result['error'] = $ok ? null : 'Il provider HTTP non ha confermato l’invio SMS (HTTP ' . $status . ').';
        } catch (\Throwable $e) {
            // Never expose headers, credentials, message content or provider response bodies.
            $result['error'] = 'Invio tramite provider HTTP non riuscito. Verifica configurazione e disponibilità del servizio.';
        }
        return $result;
    }
}
