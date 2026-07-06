<?php

namespace App\Services;

use App\Config\TsBilling;

class TsStorageService
{
    private TenantCatalogService $tenants;
    private TsBilling $config;

    public function __construct(
        ?TenantCatalogService $tenants = null,
        ?TsBilling $config = null
    ) {
        $this->tenants = $tenants ?? new TenantCatalogService();
        $this->config = $config ?? config(TsBilling::class);
    }

    public function ensureTenantDirectory(int $tenantId, string $relativePath = ''): string
    {
        if ($tenantId <= 0) {
            throw new \InvalidArgumentException('Tenant TS non valido per lo storage.');
        }

        $basePath = rtrim($this->config->tenantStorageRoot, '\\/')
            . DIRECTORY_SEPARATOR
            . $this->sanitizeSegment($this->resolveStorageKey($tenantId))
            . DIRECTORY_SEPARATOR
            . $this->sanitizeSegment($this->config->tenantStorageSegment);

        $relativePath = trim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath), DIRECTORY_SEPARATOR);
        if ($relativePath !== '') {
            $segments = array_filter(array_map([$this, 'sanitizeSegment'], explode(DIRECTORY_SEPARATOR, $relativePath)));
            if ($segments !== []) {
                $basePath .= DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments);
            }
        }

        if (!is_dir($basePath) && !mkdir($basePath, 0775, true) && !is_dir($basePath)) {
            throw new \RuntimeException('Impossibile creare la directory storage TS: ' . $basePath);
        }

        return $basePath;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function saveJsonArtifact(int $tenantId, string $category, string $baseName, array $payload): string
    {
        $directory = $this->ensureTenantDirectory(
            $tenantId,
            trim($category, '/\\') . DIRECTORY_SEPARATOR . date('Y') . DIRECTORY_SEPARATOR . date('m')
        );

        $fileName = $this->sanitizeSegment($baseName);
        if ($fileName === '') {
            $fileName = 'artifact';
        }

        $path = $directory
            . DIRECTORY_SEPARATOR
            . date('Ymd-His')
            . '-'
            . $fileName
            . '.json';

        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded) || $encoded === '') {
            throw new \RuntimeException('Serializzazione artifact TS non riuscita.');
        }

        if (file_put_contents($path, $encoded) === false) {
            throw new \RuntimeException('Scrittura artifact TS non riuscita: ' . $path);
        }

        return $path;
    }

    /**
     * @return array{path:string,file_size:int,checksum_sha256:string}
     */
    public function saveBinaryArtifact(int $tenantId, string $category, string $baseName, string $extension, string $contents): array
    {
        $directory = $this->ensureTenantDirectory(
            $tenantId,
            trim($category, '/\\') . DIRECTORY_SEPARATOR . date('Y') . DIRECTORY_SEPARATOR . date('m')
        );

        $fileName = $this->sanitizeSegment($baseName);
        if ($fileName === '') {
            $fileName = 'artifact';
        }

        $extension = trim(strtolower($extension), '. ');
        if ($extension === '') {
            $extension = 'bin';
        }

        $path = $directory
            . DIRECTORY_SEPARATOR
            . date('Ymd-His')
            . '-'
            . $fileName
            . '.'
            . $extension;

        if (file_put_contents($path, $contents) === false) {
            throw new \RuntimeException('Scrittura artifact binario TS non riuscita: ' . $path);
        }

        return [
            'path' => $path,
            'file_size' => (int) filesize($path),
            'checksum_sha256' => hash_file('sha256', $path) ?: hash('sha256', $contents),
        ];
    }

    private function resolveStorageKey(int $tenantId): string
    {
        $tenant = $this->tenants->getTenantById($tenantId);
        $storageKey = trim((string) ($tenant['storage_key'] ?? ''));

        return $storageKey !== '' ? $storageKey : 'tenant-' . $tenantId;
    }

    private function sanitizeSegment(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/[^A-Za-z0-9._-]+/', '-', $value) ?? $value;

        return trim($value, '-.');
    }
}
