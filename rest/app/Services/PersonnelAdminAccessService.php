<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

class PersonnelAdminAccessService
{
    public const TIPO_DOTTORE = 1;
    public const TIPO_SEGRETERIA = 3;
    public const USER_TYPE_ADMIN = 1;
    public const USER_TYPE_STAFF = 2;

    private ?BaseConnection $platformDb;

    public function __construct(?BaseConnection $platformDb = null)
    {
        $this->platformDb = $platformDb;
    }

    public static function isEligiblePersonnelType(int $personnelType): bool
    {
        return in_array($personnelType, [self::TIPO_DOTTORE, self::TIPO_SEGRETERIA], true);
    }

    public static function resolveLocalUserType(int $personnelType, bool $adminEnabled): int
    {
        return self::isEligiblePersonnelType($personnelType) && $adminEnabled
            ? self::USER_TYPE_ADMIN
            : self::USER_TYPE_STAFF;
    }

    public static function isLegacyAdminProfile(int $personnelType, int $userType): bool
    {
        return self::isEligiblePersonnelType($personnelType)
            && $userType === self::USER_TYPE_ADMIN;
    }

    public function findCurrentTenantMembershipFlag(int $appUserId): ?bool
    {
        $tenantId = $this->currentTenantId();
        if ($tenantId <= 0 || $appUserId <= 0) {
            return null;
        }

        $db = $this->platformDatabase();
        if (
            !$db->tableExists('platform_user_tenants')
            || !$db->fieldExists('is_app_admin', 'platform_user_tenants')
        ) {
            return null;
        }

        $row = $db->table('platform_user_tenants')
            ->select('is_app_admin')
            ->where('id_tenant', $tenantId)
            ->where('app_user_id', $appUserId)
            ->get(1)
            ->getRowArray();

        return $row === null ? null : (int) ($row['is_app_admin'] ?? 0) === 1;
    }

    public function updateCurrentTenantMembershipFlag(int $appUserId, bool $adminEnabled): bool
    {
        $tenantId = $this->currentTenantId();
        if ($tenantId <= 0 || $appUserId <= 0) {
            return false;
        }

        $db = $this->platformDatabase();
        if (
            !$db->tableExists('platform_user_tenants')
            || !$db->fieldExists('is_app_admin', 'platform_user_tenants')
        ) {
            return false;
        }

        return (bool) $db->table('platform_user_tenants')
            ->where('id_tenant', $tenantId)
            ->where('app_user_id', $appUserId)
            ->update(['is_app_admin' => $adminEnabled ? 1 : 0]);
    }

    private function currentTenantId(): int
    {
        $context = session()->get(TenantContextService::SESSION_KEY);
        return is_array($context) ? (int) ($context['tenant_id'] ?? 0) : 0;
    }

    private function platformDatabase(): BaseConnection
    {
        if ($this->platformDb === null) {
            $this->platformDb = Database::connect('platform');
        }

        return $this->platformDb;
    }
}
