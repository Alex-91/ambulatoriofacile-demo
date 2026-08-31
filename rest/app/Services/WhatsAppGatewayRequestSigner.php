<?php

namespace App\Services;

class WhatsAppGatewayRequestSigner
{
    private string $keyId;
    private string $secret;

    public function __construct(string $keyId, string $secret)
    {
        $this->keyId = trim($keyId);
        $this->secret = trim($secret);

        if ($this->keyId === '') {
            throw new \InvalidArgumentException('WHATSAPP_GATEWAY_API_KEY_ID non configurato.');
        }
        if (strlen($this->secret) < 32) {
            throw new \InvalidArgumentException('WHATSAPP_GATEWAY_API_SECRET deve contenere almeno 32 caratteri.');
        }
    }

    /**
     * @return array<string, string>
     */
    public function headers(
        string $method,
        string $requestTarget,
        int $tenantId,
        string $body,
        ?int $timestamp = null,
        ?string $requestId = null
    ): array {
        if ($tenantId <= 0) {
            throw new \InvalidArgumentException('Tenant WhatsApp non valido.');
        }

        $timestamp ??= time();
        $requestId = trim((string) ($requestId ?? ''));
        if ($requestId === '') {
            $requestId = 'req-' . bin2hex(random_bytes(16));
        }

        $canonical = self::canonical($method, $requestTarget, $tenantId, $timestamp, $requestId, $body);

        return [
            'X-AmbulatorioFacile-Key-ID' => $this->keyId,
            'X-AmbulatorioFacile-Tenant-ID' => (string) $tenantId,
            'X-AmbulatorioFacile-Timestamp' => (string) $timestamp,
            'X-AmbulatorioFacile-Request-ID' => $requestId,
            'X-AmbulatorioFacile-Signature' => hash_hmac('sha256', $canonical, $this->secret),
        ];
    }

    public static function canonical(
        string $method,
        string $requestTarget,
        int $tenantId,
        int $timestamp,
        string $requestId,
        string $body
    ): string {
        return implode("\n", [
            strtoupper(trim($method)),
            $requestTarget,
            (string) $tenantId,
            (string) $timestamp,
            $requestId,
            hash('sha256', $body),
        ]);
    }
}
