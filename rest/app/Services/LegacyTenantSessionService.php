<?php

namespace App\Services;

use App\Libraries\TenantContext;
use Config\Database;

class LegacyTenantSessionService
{
    public const SESSION_KEY_PENDING_SELECTION = 'legacy_pending_tenant_selection';
    public const SESSION_KEY_PENDING_RUNTIME = 'legacy_pending_tenant_runtime';
    private const PENDING_SELECTION_TTL_SECONDS = 600;

    private \CodeIgniter\Database\BaseConnection $platformDb;
    private TenantCatalogService $catalog;
    private TenantContextService $tenantContext;
    private TenantDatabaseConnector $tenantDbConnector;
    private TenantRuntimeBindingService $runtimeBinder;

    public function __construct()
    {
        $this->platformDb = Database::connect('platform');
        $this->catalog = new TenantCatalogService();
        $this->tenantContext = new TenantContextService($this->catalog);
        $this->tenantDbConnector = new TenantDatabaseConnector();
        $this->runtimeBinder = new TenantRuntimeBindingService();
    }

    /**
     * @param array<int, array<string, mixed>> $matches
     */
    public function storePendingSelection(array $matches): void
    {
        $rows = [];

        foreach ($matches as $match) {
            $tenantId = (int) ($match['id_tenant'] ?? 0);
            if ($tenantId <= 0) {
                continue;
            }

            $rows[] = [
                'id_tenant' => $tenantId,
                'tenant_key' => (string) ($match['tenant_key'] ?? ''),
                'tenant_name' => (string) ($match['tenant_name'] ?? ''),
                'package_code' => (string) ($match['package_code'] ?? ''),
                'package_name' => (string) ($match['package_name'] ?? ''),
                'login_hint' => (string) ($match['login_hint'] ?? ''),
                'app_user_id' => (int) ($match['app_user_id'] ?? 0),
                'user_type' => (int) ($match['user_type'] ?? 0),
                'username' => (string) ($match['username'] ?? ''),
            ];
        }

        if ($rows === []) {
            $this->clearPendingSelection();
            return;
        }

        session()->set(self::SESSION_KEY_PENDING_SELECTION, [
            'created_at' => time(),
            'matches' => $rows,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function consumePendingSelection(int $tenantId): ?array
    {
        $pending = session()->get(self::SESSION_KEY_PENDING_SELECTION);
        if (!is_array($pending)) {
            return null;
        }

        $createdAt = (int) ($pending['created_at'] ?? 0);
        if ($createdAt <= 0 || (time() - $createdAt) > self::PENDING_SELECTION_TTL_SECONDS) {
            $this->clearPendingSelection();
            return null;
        }

        foreach ((array) ($pending['matches'] ?? []) as $match) {
            if (!is_array($match)) {
                continue;
            }

            if ((int) ($match['id_tenant'] ?? 0) !== $tenantId) {
                continue;
            }

            $this->clearPendingSelection();
            return $match;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $tenant
     */
    public function queuePendingRuntime(array $tenant, int $appUserId, int $userType, string $loginSource = 'legacy_tenant'): void
    {
        $tenantId = (int) ($tenant['id_tenant'] ?? 0);
        if ($tenantId <= 0) {
            $this->clearPendingRuntime();
            return;
        }

        session()->set(self::SESSION_KEY_PENDING_RUNTIME, [
            'tenant_id' => $tenantId,
            'tenant_key' => (string) ($tenant['tenant_key'] ?? ''),
            'tenant_name' => (string) ($tenant['tenant_name'] ?? ''),
            'tenant_status' => (string) ($tenant['status'] ?? ''),
            'onboarding_status' => (string) ($tenant['onboarding_status'] ?? ''),
            'storage_key' => (string) ($tenant['storage_key'] ?? ''),
            'feature_profile' => (string) ($tenant['feature_profile'] ?? ''),
            'package_code' => (string) ($tenant['package_code'] ?? ''),
            'package_name' => (string) ($tenant['package_name'] ?? ''),
            'app_user_id' => $appUserId,
            'user_type' => $userType,
            'tenant_role' => $this->inferTenantRole($userType),
            'login_source' => $loginSource,
        ]);
    }

    public function bindPendingRuntimeIfAvailable(): bool
    {
        $tenant = $this->loadPendingRuntimeTenant();
        if ($tenant === null) {
            return false;
        }

        try {
            $config = $this->tenantDbConnector->buildConnectionConfig($tenant);
            $this->runtimeBinder->bindConnectionConfig($config);
        } catch (\Throwable $e) {
            log_message('warning', 'LegacyTenantSessionService::bindPendingRuntimeIfAvailable failed: ' . $e->getMessage(), [
                'tenant_id' => (int) ($tenant['id_tenant'] ?? 0),
                'tenant_key' => (string) ($tenant['tenant_key'] ?? ''),
            ]);
            $this->clearPendingRuntime();
            return false;
        }

        return true;
    }

    public function activatePendingRuntime(): ?TenantContext
    {
        $payload = session()->get(self::SESSION_KEY_PENDING_RUNTIME);
        if (!is_array($payload)) {
            return null;
        }

        $tenant = $this->loadPendingRuntimeTenant();
        if ($tenant === null) {
            $this->clearPendingRuntime();
            return null;
        }

        $context = new TenantContext(
            (int) ($tenant['id_tenant'] ?? 0),
            trim((string) ($tenant['tenant_key'] ?? '')),
            trim((string) ($tenant['tenant_name'] ?? '')),
            trim((string) ($tenant['status'] ?? '')),
            trim((string) ($tenant['onboarding_status'] ?? '')),
            trim((string) ($tenant['package_code'] ?? '')),
            trim((string) ($tenant['package_name'] ?? '')),
            trim((string) ($payload['tenant_role'] ?? '')) ?: $this->inferTenantRole((int) ($payload['user_type'] ?? 0)),
            0,
            (int) ($payload['app_user_id'] ?? 0),
            trim((string) ($tenant['storage_key'] ?? '')),
            trim((string) ($tenant['feature_profile'] ?? '')),
            $this->catalog->resolveFeatureMapForTenant((int) ($tenant['id_tenant'] ?? 0))
        );

        if (!$context->isValid()) {
            $this->clearPendingRuntime();
            return null;
        }

        $this->tenantContext->setCurrentTenant($context);
        session()->set('loginSource', (string) ($payload['login_source'] ?? 'legacy_tenant'));
        $this->clearPendingRuntime();

        return $context;
    }

    public function queueCurrentRuntimeTenantIfAvailable(int $appUserId, int $userType, bool $activateNow = false): bool
    {
        if ($appUserId <= 0 || $userType <= 0) {
            return false;
        }

        $currentTenant = $this->catalog->resolveCurrentRuntimeTenant();
        if (!is_array($currentTenant) || (int) ($currentTenant['id_tenant'] ?? 0) <= 0) {
            return false;
        }

        $tenant = $this->loadTenantDescriptorById((int) $currentTenant['id_tenant']);
        if ($tenant === null) {
            return false;
        }

        $this->queuePendingRuntime($tenant, $appUserId, $userType);
        if ($activateNow) {
            $this->activatePendingRuntime();
        }

        return true;
    }

    public function clearPendingSelection(): void
    {
        session()->remove(self::SESSION_KEY_PENDING_SELECTION);
    }

    public function clearPendingRuntime(): void
    {
        session()->remove(self::SESSION_KEY_PENDING_RUNTIME);
    }

    public function clearAllPending(): void
    {
        $this->clearPendingSelection();
        $this->clearPendingRuntime();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadPendingRuntimeTenant(): ?array
    {
        $payload = session()->get(self::SESSION_KEY_PENDING_RUNTIME);
        if (!is_array($payload)) {
            return null;
        }

        $tenantId = (int) ($payload['tenant_id'] ?? 0);
        if ($tenantId <= 0) {
            return null;
        }

        $tenant = $this->loadTenantDescriptorById($tenantId);
        if ($tenant === null || !$this->tenantAllowsLogin($tenant)) {
            return null;
        }

        return $tenant;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadTenantDescriptorById(int $tenantId): ?array
    {
        if ($tenantId <= 0) {
            return null;
        }

        return $this->platformDb->table('platform_tenants t')
            ->select('t.*, p.package_code, p.package_name')
            ->join('platform_packages p', 'p.id_package = t.id_package', 'left')
            ->where('t.id_tenant', $tenantId)
            ->get(1)
            ->getRowArray() ?: null;
    }

    /**
     * @param array<string, mixed> $tenant
     */
    private function tenantAllowsLogin(array $tenant): bool
    {
        if ((int) ($tenant['is_active'] ?? 0) !== 1) {
            return false;
        }

        $status = strtolower(trim((string) ($tenant['status'] ?? 'active')));
        return !in_array($status, ['archived', 'suspended'], true);
    }

    private function inferTenantRole(int $userType): string
    {
        if ($userType === 1) {
            return 'tenant_admin';
        }

        return 'tenant_staff';
    }
}
