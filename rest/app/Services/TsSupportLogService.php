<?php

namespace App\Services;

class TsSupportLogService
{
    private TsStorageService $storage;
    private TenantCatalogService $tenants;

    public function __construct(
        ?TsStorageService $storage = null,
        ?TenantCatalogService $tenants = null
    ) {
        $this->storage = $storage ?? new TsStorageService();
        $this->tenants = $tenants ?? new TenantCatalogService();
    }

    /**
     * @param array<string, mixed> $context
     */
    public function startOperation(int $tenantId, string $operation, array $context = []): TsSupportLogSession
    {
        if ($tenantId <= 0) {
            throw new \InvalidArgumentException('Tenant non valido per il support log TS.');
        }

        $traceId = 'ts-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4));
        $tenant = $this->tenants->getTenantById($tenantId) ?? [];
        $baseContext = [
            'trace_id' => $traceId,
            'operation' => trim($operation),
            'started_at' => date('c'),
            'tenant' => [
                'id_tenant' => $tenantId,
                'tenant_key' => trim((string) ($tenant['tenant_key'] ?? '')),
                'tenant_name' => trim((string) ($tenant['tenant_name'] ?? '')),
                'storage_key' => trim((string) ($tenant['storage_key'] ?? '')),
            ],
            'runtime' => [
                'php_version' => PHP_VERSION,
                'soap_loaded' => extension_loaded('soap'),
                'app_env' => defined('ENVIRONMENT') ? ENVIRONMENT : 'unknown',
                'recorded_at' => date('Y-m-d H:i:s'),
            ],
            'context' => $this->sanitizeValue($context),
        ];

        return new TsSupportLogSession($this, $tenantId, $baseContext);
    }

    /**
     * @param array<string, mixed> $entry
     */
    public function appendTimelineEntry(int $tenantId, array $entry): string
    {
        $directory = $this->storage->ensureTenantDirectory(
            $tenantId,
            'logs' . DIRECTORY_SEPARATOR . date('Y') . DIRECTORY_SEPARATOR . date('m')
        );

        $path = $directory . DIRECTORY_SEPARATOR . 'ts-support-' . date('Y-m') . '.jsonl';
        $encoded = json_encode($this->sanitizeValue($entry), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded) || $encoded === '') {
            throw new \RuntimeException('Serializzazione timeline support log TS non riuscita.');
        }

        if (file_put_contents($path, $encoded . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException('Scrittura timeline support log TS non riuscita: ' . $path);
        }

        return $path;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function storeTraceSummary(int $tenantId, string $traceId, array $payload): string
    {
        return $this->storage->saveJsonArtifact(
            $tenantId,
            'logs',
            $traceId . '-trace',
            $this->sanitizeValue($payload)
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function buildReference(string $traceId, string $summaryPath = '', string $timelinePath = ''): array
    {
        return [
            'trace_id' => trim($traceId),
            'summary_path' => trim($summaryPath),
            'timeline_path' => trim($timelinePath),
        ];
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    public function sanitizeValue($value, string $key = '')
    {
        if (is_array($value)) {
            $sanitized = [];
            foreach ($value as $childKey => $childValue) {
                $childKey = is_string($childKey) ? $childKey : (string) $childKey;
                $sanitized[$childKey] = $this->sanitizeValue($childValue, $childKey);
            }

            return $sanitized;
        }

        if ($value instanceof \Throwable) {
            return $this->sanitizeException($value);
        }

        if (is_object($value)) {
            return $this->sanitizeValue(get_object_vars($value), $key);
        }

        $normalizedKey = strtolower(trim($key));

        if (is_string($value)) {
            if (in_array($normalizedKey, ['last_request', 'last_response', 'last_request_headers', 'last_response_headers'], true)) {
                return $this->sanitizeSoapText($value, $normalizedKey);
            }

            if ($this->isBinaryPayloadKey($normalizedKey)) {
                return '[binary payload omitted: ' . strlen($value) . ' bytes]';
            }

            if ($this->isSensitiveKey($normalizedKey)) {
                return $this->maskSensitiveString($value, $normalizedKey);
            }

            return $this->truncateString($value);
        }

        if (is_scalar($value) || $value === null) {
            if ($this->isSensitiveKey($normalizedKey)) {
                return $this->maskSensitiveString((string) $value, $normalizedKey);
            }

            return $value;
        }

        return '[unsupported]';
    }

    /**
     * @return array<string, mixed>
     */
    public function sanitizeException(\Throwable $e): array
    {
        return [
            'type' => get_class($e),
            'message' => $this->truncateString($e->getMessage(), 4000),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ];
    }

    private function isSensitiveKey(string $key): bool
    {
        if ($key === '') {
            return false;
        }

        foreach ([
            'password',
            'pincode',
            'authorization',
            'cf',
            '_cf',
            'hash',
            '_enc',
            'patient_label',
            'label_plain',
        ] as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function isBinaryPayloadKey(string $key): bool
    {
        if ($key === '') {
            return false;
        }

        foreach ([
            'pdf',
            'base64',
            'binary',
            'file_contents',
            'raw_contents',
        ] as $needle) {
            if (str_contains($key, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function maskSensitiveString(string $value, string $key = ''): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (str_contains($key, 'label')) {
            return '[present:' . strlen($value) . ' chars]';
        }

        if (strlen($value) <= 4) {
            return str_repeat('*', strlen($value));
        }

        return str_repeat('*', max(0, strlen($value) - 4)) . substr($value, -4);
    }

    private function sanitizeSoapText(string $value, string $key = ''): string
    {
        $patterns = [
            '#(<(?:\w+:)?pincode>)(.*?)(</(?:\w+:)?pincode>)#si',
            '#(<(?:\w+:)?cfProprietario>)(.*?)(</(?:\w+:)?cfProprietario>)#si',
            '#(<(?:\w+:)?cfCittadino>)(.*?)(</(?:\w+:)?cfCittadino>)#si',
            '#(<(?:\w+:)?pIva>)(.*?)(</(?:\w+:)?pIva>)#si',
            '#(<(?:\w+:)?numDocumento>)(.*?)(</(?:\w+:)?numDocumento>)#si',
            '#(<(?:\w+:)?document_number>)(.*?)(</(?:\w+:)?document_number>)#si',
            '#(<(?:\w+:)?pdf>)(.*?)(</(?:\w+:)?pdf>)#si',
            '#(<(?:\w+:)?base64Binary>)(.*?)(</(?:\w+:)?base64Binary>)#si',
        ];

        foreach ($patterns as $pattern) {
            $value = preg_replace($pattern, '$1[REDACTED]$3', $value) ?? $value;
        }

        $value = preg_replace('/(Authorization:\s*Basic\s+)[A-Za-z0-9+\/=]+/i', '$1[REDACTED]', $value) ?? $value;

        if ($key === 'last_request_headers' || $key === 'last_response_headers') {
            $value = preg_replace('/(Cookie:\s*)(.+)/i', '$1[REDACTED]', $value) ?? $value;
            $value = preg_replace('/(Set-Cookie:\s*)(.+)/i', '$1[REDACTED]', $value) ?? $value;
        }

        return $this->truncateString($value, 30000);
    }

    private function truncateString(string $value, int $limit = 8000): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        return mb_substr($value, 0, $limit) . '...[truncated]';
    }
}
