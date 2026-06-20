<?php

namespace App\Services;

use App\Models\PlatformUsersModel;

class PlatformAuthService
{
    private PlatformUsersModel $usersModel;
    private TenantCatalogService $catalog;

    public function __construct(?PlatformUsersModel $usersModel = null, ?TenantCatalogService $catalog = null)
    {
        $this->usersModel = $usersModel ?? new PlatformUsersModel();
        $this->catalog = $catalog ?? new TenantCatalogService();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function authenticate(string $email, string $password): ?array
    {
        $email = $this->normalizeEmail($email);
        if ($email === '' || $password === '') {
            return null;
        }

        $platformUser = $this->usersModel->findByEmailInsensitive($email);
        if (!$platformUser) {
            return null;
        }

        $status = strtolower(trim((string) ($platformUser['status'] ?? 'active')));
        if ($status === 'suspended' || $status === 'blocked') {
            return null;
        }

        $hash = (string) ($platformUser['password_hash'] ?? '');
        if ($hash === '' || !password_verify($password, $hash)) {
            return null;
        }

        $memberships = $this->catalog->listTenantsForPlatformUser((int) ($platformUser['id_platform_user'] ?? 0));
        $selectableTenants = $this->buildSelectableTenants($memberships);

        return [
            'platform_user' => $platformUser,
            'memberships' => $memberships,
            'selectable_tenants' => $selectableTenants,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $memberships
     * @return array<int, array<string, mixed>>
     */
    public function buildSelectableTenants(array $memberships): array
    {
        $rows = [];

        foreach ($memberships as $membership) {
            if (!$this->membershipAllowsLogin($membership)) {
                continue;
            }

            $rows[] = [
                'id_tenant' => (int) ($membership['id_tenant'] ?? 0),
                'tenant_key' => (string) ($membership['tenant_key'] ?? ''),
                'tenant_name' => (string) ($membership['tenant_name'] ?? ''),
                'package_code' => (string) ($membership['package_code'] ?? ''),
                'package_name' => (string) ($membership['package_name'] ?? ''),
                'tenant_role' => (string) ($membership['tenant_role'] ?? ''),
                'login_hint' => (string) ($membership['login_hint'] ?? ''),
                'is_default' => (int) ($membership['is_default'] ?? 0) === 1,
                'onboarding_status' => (string) ($membership['onboarding_status'] ?? ''),
                'tenant_status' => (string) ($membership['tenant_status'] ?? ''),
            ];
        }

        usort($rows, static function (array $left, array $right): int {
            $leftDefault = !empty($left['is_default']) ? 1 : 0;
            $rightDefault = !empty($right['is_default']) ? 1 : 0;

            if ($leftDefault !== $rightDefault) {
                return $rightDefault <=> $leftDefault;
            }

            return strcasecmp((string) ($left['tenant_name'] ?? ''), (string) ($right['tenant_name'] ?? ''));
        });

        return $rows;
    }

    /**
     * @param array<string, mixed> $membership
     */
    private function membershipAllowsLogin(array $membership): bool
    {
        if ((int) ($membership['tenant_is_active'] ?? 0) !== 1) {
            return false;
        }

        $status = strtolower(trim((string) ($membership['tenant_status'] ?? '')));
        return !in_array($status, ['archived', 'suspended'], true);
    }

    private function normalizeEmail(string $email): string
    {
        $email = strtolower(trim($email));
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }
}
