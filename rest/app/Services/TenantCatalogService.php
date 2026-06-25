<?php

namespace App\Services;

use App\Libraries\TenantContext;
use App\Models\PlatformTenantsModel;
use App\Models\PlatformUsersModel;
use Config\Database;

class TenantCatalogService
{
    private \CodeIgniter\Database\BaseConnection $db;
    private PlatformTenantsModel $tenantsModel;
    private PlatformUsersModel $usersModel;
    private TenantFeatureService $tenantFeatures;
    private bool $runtimeTenantResolved = false;
    private ?array $runtimeTenantCache = null;

    public function __construct()
    {
        $this->db = Database::connect('platform');
        $this->tenantsModel = new PlatformTenantsModel();
        $this->usersModel = new PlatformUsersModel();
        $this->tenantFeatures = new TenantFeatureService();
    }

    public function getTenantById(int $tenantId): ?array
    {
        if ($tenantId <= 0) {
            return null;
        }

        return $this->tenantsModel->find($tenantId);
    }

    public function getTenantByKey(string $tenantKey): ?array
    {
        return $this->tenantsModel->findByTenantKey($tenantKey);
    }

    public function getPlatformUserByEmail(string $email): ?array
    {
        return $this->usersModel->findByEmailInsensitive($email);
    }

    public function getTenantMembership(int $platformUserId, int $tenantId): ?array
    {
        if ($platformUserId <= 0 || $tenantId <= 0) {
            return null;
        }

        return $this->db->table('platform_user_tenants put')
            ->select('put.*, t.tenant_key, t.tenant_name, t.status AS tenant_status, t.onboarding_status, t.storage_key, t.feature_profile, t.login_hint, t.is_active AS tenant_is_active, p.package_code, p.package_name')
            ->join('platform_tenants t', 't.id_tenant = put.id_tenant')
            ->join('platform_packages p', 'p.id_package = t.id_package', 'left')
            ->where('put.id_platform_user', $platformUserId)
            ->where('put.id_tenant', $tenantId)
            ->get(1)
            ->getRowArray() ?: null;
    }

    public function listTenantsForPlatformUser(int $platformUserId): array
    {
        if ($platformUserId <= 0) {
            return [];
        }

        return $this->db->table('platform_user_tenants put')
            ->select('put.*, t.tenant_key, t.tenant_name, t.status AS tenant_status, t.onboarding_status, t.storage_key, t.feature_profile, t.login_hint, t.is_active AS tenant_is_active, p.package_code, p.package_name')
            ->join('platform_tenants t', 't.id_tenant = put.id_tenant')
            ->join('platform_packages p', 'p.id_package = t.id_package', 'left')
            ->where('put.id_platform_user', $platformUserId)
            ->orderBy('put.is_default', 'DESC')
            ->orderBy('t.tenant_name', 'ASC')
            ->get()
            ->getResultArray();
    }

    public function findTenantMembershipByAppUser(int $platformUserId, int $appUserId): ?array
    {
        if ($platformUserId <= 0 || $appUserId <= 0) {
            return null;
        }

        return $this->db->table('platform_user_tenants put')
            ->select('put.*, t.tenant_key, t.tenant_name, t.status AS tenant_status, t.onboarding_status, t.storage_key, t.feature_profile, t.login_hint, t.is_active AS tenant_is_active, p.package_code, p.package_name')
            ->join('platform_tenants t', 't.id_tenant = put.id_tenant')
            ->join('platform_packages p', 'p.id_package = t.id_package', 'left')
            ->where('put.id_platform_user', $platformUserId)
            ->where('put.app_user_id', $appUserId)
            ->orderBy('put.is_default', 'DESC')
            ->orderBy('put.id_platform_user_tenant', 'ASC')
            ->get(1)
            ->getRowArray() ?: null;
    }

    /**
     * @return array<string, bool>
     */
    public function resolveFeatureMapForTenant(int $tenantId): array
    {
        if ($tenantId <= 0) {
            return [];
        }

        return $this->tenantFeatures->resolveEffectiveFeatureMapForTenant($tenantId);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function resolveCurrentRuntimeTenant(): ?array
    {
        if ($this->runtimeTenantResolved) {
            return $this->runtimeTenantCache;
        }

        $this->runtimeTenantResolved = true;

        $dbConfig = config(\Config\Database::class);
        $runtimeConfig = is_array($dbConfig->default ?? null) ? $dbConfig->default : [];

        $dbName = strtolower(trim((string) ($runtimeConfig['database'] ?? '')));
        if ($dbName === '') {
            return $this->runtimeTenantCache = null;
        }

        $rows = $this->db->table('platform_tenants')
            ->where('LOWER(db_name)', $dbName)
            ->orderBy('is_active', 'DESC')
            ->orderBy('id_tenant', 'ASC')
            ->get()
            ->getResultArray();

        if ($rows === []) {
            return $this->runtimeTenantCache = null;
        }

        if (count($rows) === 1) {
            return $this->runtimeTenantCache = $rows[0];
        }

        $dbHost = strtolower(trim((string) ($runtimeConfig['hostname'] ?? '')));
        $dbUser = strtolower(trim((string) ($runtimeConfig['username'] ?? '')));
        $dbPort = (int) ($runtimeConfig['port'] ?? 0);

        $rows = array_values(array_filter($rows, static function (array $row) use ($dbHost, $dbUser, $dbPort): bool {
            $hostMatches = $dbHost === ''
                || strtolower(trim((string) ($row['db_host'] ?? ''))) === $dbHost;
            $userMatches = $dbUser === ''
                || strtolower(trim((string) ($row['db_username'] ?? ''))) === $dbUser;
            $portMatches = $dbPort <= 0
                || (int) ($row['db_port'] ?? 0) === $dbPort;

            return $hostMatches && $userMatches && $portMatches;
        }));

        if ($rows === []) {
            return $this->runtimeTenantCache = null;
        }

        return $this->runtimeTenantCache = $rows[0];
    }

    /**
     * @return array<string, bool>
     */
    public function resolveFeatureMapForCurrentRuntimeTenant(): array
    {
        $tenant = $this->resolveCurrentRuntimeTenant();
        if (!is_array($tenant)) {
            return [];
        }

        return $this->resolveFeatureMapForTenant((int) ($tenant['id_tenant'] ?? 0));
    }

    public function buildTenantContext(array $membership): TenantContext
    {
        $tenantId = (int) ($membership['id_tenant'] ?? 0);

        return new TenantContext(
            $tenantId,
            trim((string) ($membership['tenant_key'] ?? '')),
            trim((string) ($membership['tenant_name'] ?? '')),
            trim((string) ($membership['tenant_status'] ?? '')),
            trim((string) ($membership['onboarding_status'] ?? '')),
            trim((string) ($membership['package_code'] ?? '')),
            trim((string) ($membership['package_name'] ?? '')),
            trim((string) ($membership['tenant_role'] ?? '')),
            (int) ($membership['id_platform_user'] ?? 0),
            (int) ($membership['app_user_id'] ?? 0),
            trim((string) ($membership['storage_key'] ?? '')),
            trim((string) ($membership['feature_profile'] ?? '')),
            $this->resolveFeatureMapForTenant($tenantId)
        );
    }
}
