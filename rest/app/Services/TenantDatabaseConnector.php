<?php

namespace App\Services;

use Config\Database;

class TenantDatabaseConnector
{
    /**
     * @return array<string, mixed>
     */
    public function resolveDatabaseSettings(array $tenant): array
    {
        $databaseConfig = new \Config\Database();
        $platform = $databaseConfig->platform;
        $dbName = trim((string) ($tenant['db_name'] ?? ''));

        if ($dbName === '') {
            $dbName = $this->defaultTenantDatabaseName((string) ($tenant['tenant_key'] ?? ''));
        }

        return [
            'db_host' => trim((string) ($tenant['db_host'] ?? '')) !== ''
                ? trim((string) ($tenant['db_host'] ?? ''))
                : $this->envValue('tenant.provisioning.runtimeHost', 'TENANT_PROVISIONING_RUNTIME_HOST', (string) ($platform['hostname'] ?? 'localhost')),
            'db_port' => (int) ($tenant['db_port'] ?? 0) > 0
                ? (int) ($tenant['db_port'] ?? 3306)
                : (int) $this->envValue('tenant.provisioning.runtimePort', 'TENANT_PROVISIONING_RUNTIME_PORT', (string) ($platform['port'] ?? 3306)),
            'db_name' => $dbName,
            'db_username' => trim((string) ($tenant['db_username'] ?? '')) !== ''
                ? trim((string) ($tenant['db_username'] ?? ''))
                : $this->envValue('tenant.provisioning.runtimeUsername', 'TENANT_PROVISIONING_RUNTIME_USERNAME', (string) ($platform['username'] ?? '')),
            'db_password_ref' => trim((string) ($tenant['db_password_ref'] ?? '')) !== ''
                ? trim((string) ($tenant['db_password_ref'] ?? ''))
                : $this->envValue('tenant.provisioning.runtimePasswordRef', 'TENANT_PROVISIONING_RUNTIME_PASSWORD_REF', ''),
            'db_driver' => trim((string) ($tenant['db_driver'] ?? '')) !== ''
                ? trim((string) ($tenant['db_driver'] ?? ''))
                : $this->envValue('tenant.provisioning.runtimeDriver', 'TENANT_PROVISIONING_RUNTIME_DRIVER', (string) ($platform['DBDriver'] ?? 'MySQLi')),
            'db_prefix' => (string) ($tenant['db_prefix'] ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildConnectionConfig(array $tenant): array
    {
        $resolvedTenant = array_merge($tenant, $this->resolveDatabaseSettings($tenant));
        $dbHost = trim((string) ($resolvedTenant['db_host'] ?? ''));
        $dbName = trim((string) ($resolvedTenant['db_name'] ?? ''));
        $dbUser = trim((string) ($resolvedTenant['db_username'] ?? ''));
        $dbPasswordRef = trim((string) ($resolvedTenant['db_password_ref'] ?? ''));

        if ($dbHost === '' || $dbName === '' || $dbUser === '' || $dbPasswordRef === '') {
            throw new \RuntimeException('Configurazione database tenant incompleta.');
        }

        $password = $this->resolvePasswordValue($dbPasswordRef);
        if ($password === '') {
            throw new \RuntimeException('Variabile ambiente tenant non trovata: ' . $dbPasswordRef);
        }

        $databaseConfig = new \Config\Database();
        $base = $databaseConfig->platform;

        $base['hostname'] = $dbHost;
        $base['database'] = $dbName;
        $base['username'] = $dbUser;
        $base['password'] = $password;
        $base['port'] = (int) ($resolvedTenant['db_port'] ?? 3306);
        $base['DBDriver'] = trim((string) ($resolvedTenant['db_driver'] ?? '')) !== ''
            ? trim((string) ($resolvedTenant['db_driver'] ?? ''))
            : ($base['DBDriver'] ?? 'MySQLi');
        $base['DBPrefix'] = (string) ($resolvedTenant['db_prefix'] ?? '');

        return $base;
    }

    public function connect(array $tenant): \CodeIgniter\Database\BaseConnection
    {
        return Database::connect($this->buildConnectionConfig($tenant), false);
    }

    public function resolvePasswordValue(string $reference): string
    {
        $reference = trim($reference);
        if ($reference === '') {
            return '';
        }

        $candidates = array_values(array_unique(array_filter(array_merge(
            [$reference],
            $this->passwordReferenceAliases($reference)
        ))));

        foreach ($candidates as $candidate) {
            $value = env($candidate);
            if ($value !== null && $value !== '') {
                return trim((string) $value);
            }
        }

        return '';
    }

    private function defaultTenantDatabaseName(string $tenantKey): string
    {
        $tenantKey = strtolower(trim($tenantKey));
        $tenantKey = preg_replace('/[^a-z0-9_]+/', '_', $tenantKey) ?? '';
        $tenantKey = trim($tenantKey, '_');

        return $tenantKey !== '' ? 'af_' . $tenantKey : '';
    }

    /**
     * @return array<int, string>
     */
    private function passwordReferenceAliases(string $reference): array
    {
        $map = [
            'DB_PASSWORD' => ['database.default.password'],
            'database.default.password' => ['DB_PASSWORD'],
            'PLATFORM_DB_PASSWORD' => ['database.platform.password'],
            'database.platform.password' => ['PLATFORM_DB_PASSWORD'],
        ];

        return $map[$reference] ?? [];
    }

    private function envValue(string $primaryKey, string $fallbackKey, string $default = ''): string
    {
        $value = env($primaryKey);
        if ($value !== null && $value !== '') {
            return trim((string) $value);
        }

        $value = env($fallbackKey);
        if ($value !== null && $value !== '') {
            return trim((string) $value);
        }

        return $default;
    }
}
