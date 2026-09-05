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

        $membershipAccess = $this->resolveMembershipAccess($tenantId, $appUserId, $userType);

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
            'tenant_role' => (string) $membershipAccess['tenant_role'],
            'is_app_admin' => (bool) $membershipAccess['is_app_admin'],
            'platform_user_id' => (int) $membershipAccess['platform_user_id'],
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
            (int) ($payload['platform_user_id'] ?? 0),
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
        $this->applyMembershipAdministrativeAccess($tenant, $payload);
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

    /**
     * @return array{tenant_role: string, is_app_admin: bool, platform_user_id: int}
     */
    private function resolveMembershipAccess(int $tenantId, int $appUserId, int $userType): array
    {
        $fallback = [
            'tenant_role' => $this->inferTenantRole($userType),
            'is_app_admin' => $userType === PersonnelAdminAccessService::USER_TYPE_ADMIN,
            'platform_user_id' => $userType === PersonnelAdminAccessService::USER_TYPE_ADMIN
                ? $this->resolveTenantMasterPlatformUserId($tenantId)
                : 0,
        ];

        if (
            $tenantId <= 0
            || $appUserId <= 0
            || !$this->platformDb->tableExists('platform_user_tenants')
            || !$this->platformDb->fieldExists('app_user_id', 'platform_user_tenants')
        ) {
            return $fallback;
        }

        $selectFields = [];
        if ($this->platformDb->fieldExists('tenant_role', 'platform_user_tenants')) {
            $selectFields[] = 'tenant_role';
        }
        if ($this->platformDb->fieldExists('is_app_admin', 'platform_user_tenants')) {
            $selectFields[] = 'is_app_admin';
        }
        if ($this->platformDb->fieldExists('id_platform_user', 'platform_user_tenants')) {
            $selectFields[] = 'id_platform_user';
        }

        if ($selectFields === []) {
            return $fallback;
        }

        $builder = $this->platformDb->table('platform_user_tenants')
            ->select(implode(', ', $selectFields))
            ->where('id_tenant', $tenantId)
            ->where('app_user_id', $appUserId);

        if ($this->platformDb->fieldExists('is_owner', 'platform_user_tenants')) {
            $builder->orderBy('is_owner', 'DESC');
        }
        if ($this->platformDb->fieldExists('id_platform_user_tenant', 'platform_user_tenants')) {
            $builder->orderBy('id_platform_user_tenant', 'ASC');
        }

        $membership = $builder->get(1)->getRowArray();
        if (!is_array($membership)) {
            return $fallback;
        }

        $tenantRole = strtolower(trim((string) ($membership['tenant_role'] ?? '')));
        if ($tenantRole === '') {
            $tenantRole = $fallback['tenant_role'];
        }

        return [
            'tenant_role' => $tenantRole,
            'is_app_admin' => $userType === PersonnelAdminAccessService::USER_TYPE_ADMIN
                || (int) ($membership['is_app_admin'] ?? 0) === 1,
            'platform_user_id' => (int) ($membership['id_platform_user'] ?? 0),
        ];
    }

    private function resolveTenantMasterPlatformUserId(int $tenantId): int
    {
        if (
            $tenantId <= 0
            || !$this->platformDb->tableExists('platform_user_tenants')
            || !$this->platformDb->fieldExists('id_platform_user', 'platform_user_tenants')
        ) {
            return 0;
        }

        if ($this->platformDb->fieldExists('tenant_role', 'platform_user_tenants')) {
            $master = $this->platformDb->table('platform_user_tenants')
                ->select('id_platform_user')
                ->where('id_tenant', $tenantId)
                ->where('tenant_role', 'tenant_master')
                ->orderBy('id_platform_user_tenant', 'ASC')
                ->get(1)
                ->getRowArray();

            $platformUserId = (int) ($master['id_platform_user'] ?? 0);
            if ($platformUserId > 0) {
                return $platformUserId;
            }
        }

        if ($this->platformDb->fieldExists('is_owner', 'platform_user_tenants')) {
            $owner = $this->platformDb->table('platform_user_tenants')
                ->select('id_platform_user')
                ->where('id_tenant', $tenantId)
                ->where('is_owner', 1)
                ->orderBy('id_platform_user_tenant', 'ASC')
                ->get(1)
                ->getRowArray();

            return (int) ($owner['id_platform_user'] ?? 0);
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $tenant
     * @param array<string, mixed> $payload
     */
    private function applyMembershipAdministrativeAccess(array $tenant, array $payload): void
    {
        $tenantRole = strtolower(trim((string) ($payload['tenant_role'] ?? '')));
        $isTenantMaster = $tenantRole === 'tenant_master';
        $isAppAdmin = (bool) ($payload['is_app_admin'] ?? false);

        if (!$isTenantMaster && (!$isAppAdmin || !$this->canCurrentUserReceiveAppAdminAccess())) {
            return;
        }

        $tenantDb = $this->tenantDbConnector->connect($tenant);
        $menuAdmin = (new TenantAdminMenuService())->ensureDefaultMenuIfEmpty($tenantDb);

        session()->set([
            'admin' => 1,
            'is_admin' => true,
            'menuDataAdmin' => ['result' => $menuAdmin],
            'tenant_app_admin' => true,
        ]);

        $this->applyLinkedPlatformIdentity((int) ($payload['platform_user_id'] ?? 0));
    }

    private function canCurrentUserReceiveAppAdminAccess(): bool
    {
        $currentUser = session()->get('utente_sess');
        if (!is_object($currentUser) || (int) ($currentUser->id_personale ?? 0) <= 0) {
            return false;
        }

        return in_array((int) ($currentUser->tipo_pers ?? 0), [1, 2, 3], true);
    }

    private function applyLinkedPlatformIdentity(int $platformUserId): void
    {
        if (
            $platformUserId <= 0
            || !$this->platformDb->tableExists('platform_users')
            || !$this->platformDb->fieldExists('id_platform_user', 'platform_users')
        ) {
            return;
        }

        $platformUser = $this->platformDb->table('platform_users')
            ->where('id_platform_user', $platformUserId)
            ->get(1)
            ->getRowArray();

        if (!is_array($platformUser)) {
            return;
        }

        $status = strtolower(trim((string) ($platformUser['status'] ?? 'active')));
        if (in_array($status, ['suspended', 'blocked'], true)) {
            return;
        }

        $memberships = $this->catalog->listTenantsForPlatformUser($platformUserId);
        $selectableTenants = (new PlatformAuthService())->buildSelectableTenants($memberships);

        session()->set([
            'platform_user_id' => $platformUserId,
            'platform_user_email' => (string) ($platformUser['email'] ?? ''),
            'platform_is_admin' => (new PlatformAdminAccessService())->isPlatformAdmin($platformUser),
            TenantAppSessionBootstrapService::PLATFORM_SELECTABLE_TENANTS_SESSION_KEY => $selectableTenants,
        ]);
    }
}
