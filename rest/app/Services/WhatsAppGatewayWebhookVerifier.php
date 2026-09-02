<?php

namespace App\Services;

final class WhatsAppGatewayWebhookVerifier
{
    public function __construct(
        private readonly string $keyId,
        private readonly string $secret,
        private readonly int $allowedClockSkewSeconds = 300
    ) {
    }

    /**
     * @param array<string, string> $headers
     * @return array{tenant_id:int,request_id:string}
     */
    public function verify(string $method, string $requestTarget, string $body, array $headers, ?int $now = null): array
    {
        $normalized = [];
        foreach ($headers as $key => $value) {
            $normalized[strtolower(trim((string) $key))] = trim((string) $value);
        }

        $providedKey = $normalized['x-ambulatoriofacile-key-id'] ?? '';
        $tenantId = (int) ($normalized['x-ambulatoriofacile-tenant-id'] ?? 0);
        $timestamp = (int) ($normalized['x-ambulatoriofacile-timestamp'] ?? 0);
        $requestId = $normalized['x-ambulatoriofacile-request-id'] ?? '';
        $signature = strtolower($normalized['x-ambulatoriofacile-signature'] ?? '');
        $now ??= time();

        if ($this->keyId === '' || !hash_equals($this->keyId, $providedKey)) {
            throw new \RuntimeException('Credenziali webhook non valide.');
        }
        if (strlen($this->secret) < 32 || $tenantId <= 0) {
            throw new \RuntimeException('Contesto webhook non valido.');
        }
        if ($timestamp <= 0 || abs($now - $timestamp) > max(30, min(900, $this->allowedClockSkewSeconds))) {
            throw new \RuntimeException('Timestamp webhook non valido.');
        }
        if (!preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $requestId)) {
            throw new \RuntimeException('Identificativo webhook non valido.');
        }
        if (!preg_match('/^[a-f0-9]{64}$/', $signature)) {
            throw new \RuntimeException('Firma webhook non valida.');
        }

        $expected = hash_hmac(
            'sha256',
            WhatsAppGatewayRequestSigner::canonical($method, $requestTarget, $tenantId, $timestamp, $requestId, $body),
            $this->secret
        );
        if (!hash_equals($expected, $signature)) {
            throw new \RuntimeException('Firma webhook non valida.');
        }

        return ['tenant_id' => $tenantId, 'request_id' => $requestId];
    }
}
