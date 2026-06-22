<?php

namespace App\Services;

class TenantStoragePathService
{
    /**
     * @param array<string, mixed> $tenant
     */
    public function writableRoot(array $tenant): string
    {
        $storageKey = $this->resolveStorageKey($tenant);
        return rtrim(WRITEPATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'tenants' . DIRECTORY_SEPARATOR . $storageKey;
    }

    /**
     * @param array<string, mixed> $tenant
     */
    public function notificationsDir(array $tenant, bool $ensure = false): string
    {
        return $this->resolvePath($this->writableRoot($tenant) . DIRECTORY_SEPARATOR . 'notifications', $ensure);
    }

    /**
     * @param array<string, mixed> $tenant
     */
    public function reminderStateDir(array $tenant, bool $ensure = false): string
    {
        return $this->resolvePath($this->writableRoot($tenant) . DIRECTORY_SEPARATOR . 'reminder_state', $ensure);
    }

    /**
     * @param array<string, mixed> $tenant
     */
    public function logsDir(array $tenant, bool $ensure = false): string
    {
        return $this->resolvePath($this->writableRoot($tenant) . DIRECTORY_SEPARATOR . 'logs', $ensure);
    }

    public function globalReminderStateDir(): string
    {
        return rtrim(WRITEPATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'reminder_state';
    }

    /**
     * @param array<string, mixed> $tenant
     */
    private function resolveStorageKey(array $tenant): string
    {
        $storageKey = trim((string) ($tenant['storage_key'] ?? ''));
        if ($storageKey !== '') {
            return $storageKey;
        }

        $tenantKey = trim((string) ($tenant['tenant_key'] ?? ''));
        if ($tenantKey !== '') {
            return $tenantKey;
        }

        $tenantId = (int) ($tenant['id_tenant'] ?? 0);
        return $tenantId > 0 ? ('tenant-' . $tenantId) : 'tenant-default';
    }

    private function resolvePath(string $path, bool $ensure): string
    {
        if ($ensure && !is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new \RuntimeException('Impossibile creare la cartella tenant: ' . $path);
        }

        return $path;
    }
}
