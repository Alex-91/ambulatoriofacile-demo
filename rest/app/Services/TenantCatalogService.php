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

    public function __construct()
    {
        $this->db = Database::connect('platform');
        $this->tenantsModel = new PlatformTenantsModel();
        $this->usersModel = new PlatformUsersModel();
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

    /**
     * @return array<string, bool>
     */
    public function resolveFeatureMapForTenant(int $tenantId): array
    {
        if ($tenantId <= 0) {
            return [];
        }

        $sql = "
            SELECT
                f.feature_key,
                COALESCE(tf.is_enabled, pf.is_enabled, f.default_enabled, 0) AS is_enabled
            FROM platform_features f
            LEFT JOIN platform_tenants t
                ON t.id_tenant = ?
            LEFT JOIN platform_package_features pf
                ON pf.id_feature = f.id_feature
               AND pf.id_package = t.id_package
            LEFT JOIN platform_tenant_features tf
                ON tf.id_feature = f.id_feature
               AND tf.id_tenant = t.id_tenant
            ORDER BY f.feature_key ASC
        ";

        $rows = $this->db->query($sql, [$tenantId])->getResultArray();
        $featureMap = [];

        foreach ($rows as $row) {
            $featureMap[(string) ($row['feature_key'] ?? '')] = (int) ($row['is_enabled'] ?? 0) === 1;
        }

        return $featureMap;
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
