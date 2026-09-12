<?php

namespace App\Services;

/** Isolated transport, replaceable by a fake in offline contract tests. No retries. */
class FseGatewayTransport
{
    /** @param array<int,mixed> $options @return array<string,mixed> */
    public function send(string $url, array $options): array
    {
        $response = new FseGatewayResponse();
        $ch = curl_init($url);
        if ($ch === false) throw new \RuntimeException('Impossibile inizializzare il trasporto FSE.');
        try {
            $options[CURLOPT_HEADERFUNCTION] = static fn($handle, string $line): int => $response->captureHeader($line);
            $options[CURLOPT_WRITEFUNCTION] = static fn($handle, string $chunk): int => $response->captureBody($chunk);
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
            $options[CURLOPT_SSL_VERIFYPEER] = true;
            $options[CURLOPT_SSL_VERIFYHOST] = 2;
            $options[CURLOPT_FOLLOWLOCATION] = false;
            if (!curl_setopt_array($ch, $options)) throw new \RuntimeException('Configurazione trasporto FSE non valida.');
            $raw = curl_exec($ch);
            return $response->result($raw === false ? false : $response->body(), (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), curl_errno($ch)) + [
                'curl_error_code' => curl_errno($ch),
                'tls_verify_result' => (int) curl_getinfo($ch, CURLINFO_SSL_VERIFYRESULT),
            ];
        } finally {
            curl_close($ch);
        }
    }
}
