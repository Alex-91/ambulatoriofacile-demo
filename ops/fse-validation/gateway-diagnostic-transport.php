<?php
// Restricted to CLI test fixtures. Never used by the application or its audit logger.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

final class FseGatewayDiagnosticTransport extends \App\Services\FseGatewayTransport
{
    public function send(string $url, array $options): array
    {
        if ($url !== 'https://modipa-val.fse.salute.gov.it/govway/rest/in/FSE/gateway/v1/documents/validation'
            || ($options[CURLOPT_CUSTOMREQUEST] ?? '') !== 'POST') throw new RuntimeException('Diagnostica limitata alla verifica nazionale di test.');
        $body = $options[CURLOPT_POSTFIELDS]['requestBody'] ?? null;
        $metadata = json_decode($body instanceof CURLStringFile ? $body->data : (string) $body, true);
        if (($metadata['activity'] ?? '') !== 'VERIFICA') throw new RuntimeException('Diagnostica limitata a VERIFICA.');
        $response = new \App\Services\FseGatewayResponse();
        $raw = '';
        $ch = curl_init($url);
        try {
            $options[CURLOPT_FOLLOWLOCATION] = false;
            $options[CURLOPT_SSL_VERIFYPEER] = true;
            $options[CURLOPT_SSL_VERIFYHOST] = 2;
            $options[CURLOPT_HEADERFUNCTION] = static fn($handle, string $line): int => $response->captureHeader($line);
            $options[CURLOPT_WRITEFUNCTION] = static function ($handle, string $data) use (&$raw): int {
                if (strlen($raw) + strlen($data) > 1048576) return 0;
                $raw .= $data; return strlen($data);
            };
            curl_setopt_array($ch, $options);
            $ok = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $result = $response->result($ok === false ? false : $raw, $status, curl_errno($ch));
            $decoded = json_decode($raw, true);
            $diagnostics = [];
            foreach (['type', 'title', 'detail', 'errorCode', 'code'] as $field) {
                if (!is_string($decoded[$field] ?? null)) continue;
                $text = $decoded[$field];
                $text = preg_replace('/-----BEGIN .*?-----.*?-----END .*?-----/s', '[REDACTED_PEM]', $text);
                $text = preg_replace('/[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}/', '[REDACTED_JWT]', $text);
                $text = preg_replace('/Bearer\s+\S+/i', 'Bearer [REDACTED]', $text);
                $text = preg_replace('/\b[A-Z]{6}[0-9LMNPQRSTUV]{2}[A-Z][0-9LMNPQRSTUV]{2}[A-Z][0-9LMNPQRSTUV]{3}[A-Z]\b/', '[ID_TEST]', $text);
                $diagnostics[$field] = substr(preg_replace('/[\x00-\x1F\x7F]/', ' ', $text), 0, 2000);
            }
            return $result + ['curl_error_code' => curl_errno($ch),
                'tls_verify_result' => (int) curl_getinfo($ch, CURLINFO_SSL_VERIFYRESULT),
                'test_only_diagnostics' => $diagnostics];
        } finally { curl_close($ch); }
    }
}
