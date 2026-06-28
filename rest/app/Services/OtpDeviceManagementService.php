<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;

class OtpDeviceManagementService
{
    private TenantCatalogService $tenantCatalog;
    private TenantDatabaseConnector $tenantDatabaseConnector;
    private TenantProvisioningService $tenantProvisioning;

    public function __construct(
        ?TenantCatalogService $tenantCatalog = null,
        ?TenantDatabaseConnector $tenantDatabaseConnector = null,
        ?TenantProvisioningService $tenantProvisioning = null
    ) {
        $this->tenantCatalog = $tenantCatalog ?? new TenantCatalogService();
        $this->tenantDatabaseConnector = $tenantDatabaseConnector ?? new TenantDatabaseConnector();
        $this->tenantProvisioning = $tenantProvisioning ?? new TenantProvisioningService();
    }

    /**
     * @return array<string, mixed>
     */
    public function buildTenantDashboard(int $tenantId): array
    {
        $tenant = $this->requireTenant($tenantId);
        $memberships = $this->tenantProvisioning->listTenantMembers($tenantId);
        $tenantDb = $this->tenantDatabaseConnector->connect($tenant);

        $appUserIds = [];
        foreach ($memberships as $membership) {
            $appUserId = (int) ($membership['app_user_id'] ?? 0);
            if ($appUserId > 0) {
                $appUserIds[$appUserId] = $appUserId;
            }
        }

        $usernamesById = [];
        $devicesByUserId = [];
        $runtimeWarning = null;

        if ($appUserIds !== []) {
            $usernamesById = $this->loadRuntimeUsernames($tenantDb, array_values($appUserIds));

            if ($tenantDb->tableExists('push_subscriptions')) {
                $devicesByUserId = $this->loadLatestActiveDevices($tenantDb, array_values($appUserIds));
            } else {
                $runtimeWarning = 'Archivio dispositivi OTP non disponibile in questo spazio.';
            }
        }

        $accounts = [];
        $mappedAccounts = 0;
        $activeDevices = 0;

        foreach ($memberships as $membership) {
            $appUserId = (int) ($membership['app_user_id'] ?? 0);
            $hasAppUser = $appUserId > 0;
            $device = $hasAppUser ? ($devicesByUserId[$appUserId] ?? null) : null;
            $hasActiveDevice = is_array($device);

            if ($hasAppUser) {
                $mappedAccounts++;
            }

            if ($hasActiveDevice) {
                $activeDevices++;
            }

            $fullName = trim((string) ($membership['first_name'] ?? '') . ' ' . (string) ($membership['last_name'] ?? ''));
            if ($fullName === '') {
                $fullName = trim((string) ($membership['email'] ?? ''));
            }

            $accounts[] = [
                'membership_id' => (int) ($membership['id_platform_user_tenant'] ?? 0),
                'platform_user_id' => (int) ($membership['id_platform_user'] ?? 0),
                'email' => trim((string) ($membership['email'] ?? '')),
                'full_name' => $fullName,
                'tenant_role' => trim((string) ($membership['tenant_role'] ?? 'tenant_staff')),
                'app_user_id' => $appUserId,
                'app_username' => $hasAppUser ? trim((string) ($usernamesById[$appUserId] ?? '')) : '',
                'is_owner' => (int) ($membership['is_owner'] ?? 0) === 1,
                'is_default' => (int) ($membership['is_default'] ?? 0) === 1,
                'is_app_admin' => (int) ($membership['is_app_admin'] ?? 0) === 1,
                'platform_user_status' => trim((string) ($membership['platform_user_status'] ?? '')),
                'invitation_status' => trim((string) ($membership['invitation_status'] ?? '')),
                'has_active_device' => $hasActiveDevice,
                'device_label' => $hasActiveDevice ? trim((string) ($device['device_label'] ?? $device['device_name'] ?? 'Dispositivo mobile')) : '',
                'device_name' => $hasActiveDevice ? trim((string) ($device['device_name'] ?? '')) : '',
                'device_os' => $hasActiveDevice ? trim((string) ($device['device_os'] ?? '')) : '',
                'device_type' => $hasActiveDevice ? trim((string) ($device['device_type'] ?? '')) : '',
                'last_seen' => $hasActiveDevice ? trim((string) ($device['last_seen'] ?? '')) : '',
            ];
        }

        usort($accounts, static function (array $left, array $right): int {
            $leftWeight = ($left['has_active_device'] ? 10 : 0) + ($left['is_owner'] ? 5 : 0);
            $rightWeight = ($right['has_active_device'] ? 10 : 0) + ($right['is_owner'] ? 5 : 0);

            if ($leftWeight !== $rightWeight) {
                return $rightWeight <=> $leftWeight;
            }

            return strcasecmp((string) ($left['email'] ?? ''), (string) ($right['email'] ?? ''));
        });

        return [
            'tenant' => $tenant,
            'accounts' => $accounts,
            'summary' => [
                'total_accounts' => count($accounts),
                'mapped_accounts' => $mappedAccounts,
                'active_devices' => $activeDevices,
            ],
            'runtime_warning' => $runtimeWarning,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function disconnectTenantMemberDevice(int $tenantId, int $membershipId): array
    {
        $tenant = $this->requireTenant($tenantId);
        $membership = $this->findTenantMembership($tenantId, $membershipId);

        if ($membership === null) {
            throw new \RuntimeException('Account dello spazio non trovato.');
        }

        $appUserId = (int) ($membership['app_user_id'] ?? 0);
        if ($appUserId <= 0) {
            throw new \RuntimeException('Questo account non e collegato a un utente agenda.');
        }

        $tenantDb = $this->tenantDatabaseConnector->connect($tenant);
        if (!$tenantDb->tableExists('push_subscriptions')) {
            throw new \RuntimeException('Archivio dispositivi OTP non disponibile in questo spazio.');
        }

        $hasActiveDevice = $tenantDb->table('push_subscriptions')
            ->where('user_id', $appUserId)
            ->where('is_active', 1)
            ->countAllResults() > 0;

        if (!$hasActiveDevice) {
            return [
                'tenant' => $tenant,
                'membership' => $membership,
                'disconnected' => false,
                'message' => 'Nessun dispositivo attivo da disassociare.',
            ];
        }

        $updatePayload = [
            'is_active' => 0,
        ];

        if ($tenantDb->fieldExists('updated_at', 'push_subscriptions')) {
            $updatePayload['updated_at'] = date('Y-m-d H:i:s');
        }

        $tenantDb->table('push_subscriptions')
            ->where('user_id', $appUserId)
            ->where('is_active', 1)
            ->update($updatePayload);

        return [
            'tenant' => $tenant,
            'membership' => $membership,
            'disconnected' => true,
            'message' => 'Dispositivo disassociato con successo.',
        ];
    }

    /**
     * @param array<int, int> $userIds
     * @return array<int, string>
     */
    private function loadRuntimeUsernames(BaseConnection $tenantDb, array $userIds): array
    {
        if ($userIds === [] || !$tenantDb->tableExists('dap01_users')) {
            return [];
        }

        $rows = $tenantDb->table('dap01_users')
            ->select('id_user, username')
            ->whereIn('id_user', $userIds)
            ->get()
            ->getResultArray();

        $usernames = [];
        foreach ($rows as $row) {
            $userId = (int) ($row['id_user'] ?? 0);
            if ($userId <= 0) {
                continue;
            }

            $usernames[$userId] = trim((string) ($row['username'] ?? ''));
        }

        return $usernames;
    }

    /**
     * @param array<int, int> $userIds
     * @return array<int, array<string, mixed>>
     */
    private function loadLatestActiveDevices(BaseConnection $tenantDb, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $rows = $tenantDb->table('push_subscriptions')
            ->select('user_id, device_name, device_label, device_os, device_type, last_seen, id')
            ->whereIn('user_id', $userIds)
            ->where('is_active', 1)
            ->where('is_mobile', 1)
            ->orderBy('user_id', 'ASC')
            ->orderBy('last_seen', 'DESC')
            ->orderBy('id', 'DESC')
            ->get()
            ->getResultArray();

        $devices = [];
        foreach ($rows as $row) {
            $userId = (int) ($row['user_id'] ?? 0);
            if ($userId <= 0 || isset($devices[$userId])) {
                continue;
            }

            $devices[$userId] = $row;
        }

        return $devices;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findTenantMembership(int $tenantId, int $membershipId): ?array
    {
        if ($tenantId <= 0 || $membershipId <= 0) {
            return null;
        }

        foreach ($this->tenantProvisioning->listTenantMembers($tenantId) as $membership) {
            if ((int) ($membership['id_platform_user_tenant'] ?? 0) === $membershipId) {
                return $membership;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function requireTenant(int $tenantId): array
    {
        if ($tenantId <= 0) {
            throw new \InvalidArgumentException('Tenant non valido.');
        }

        $tenant = $this->tenantCatalog->getTenantById($tenantId);
        if (!is_array($tenant)) {
            throw new \RuntimeException('Spazio cliente non trovato.');
        }

        return $tenant;
    }
}
