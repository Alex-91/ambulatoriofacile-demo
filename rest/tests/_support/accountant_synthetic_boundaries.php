<?php
namespace App\Services;

/** Only test infrastructure boundaries are replaced; policies, queries and controllers remain real. */
class TenantDatabaseConnector
{
    public static array $databases = [];
    public function connect(array $tenant): \CodeIgniter\Database\BaseConnection
    {
        return self::$databases[(int) $tenant['id_tenant']] ?? throw new \RuntimeException('Unknown synthetic tenant');
    }
}
class BillingTenantSchemaService
{
    public function ensureTenantSchemaReady(int $tenantId = 0, bool $attemptRepair = true): array
    {
        return ['ready' => isset(TenantDatabaseConnector::$databases[$tenantId]), 'message' => 'Synthetic SQLite: no MySQL schema repair'];
    }
}
