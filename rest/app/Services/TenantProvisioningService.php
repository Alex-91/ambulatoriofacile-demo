<?php

namespace App\Services;

use App\Models\PlatformFeaturesModel;
use App\Models\PlatformPackagesModel;
use App\Models\PlatformTenantFeaturePreferencesModel;
use App\Models\PlatformTenantFeaturesModel;
use App\Models\PlatformTenantsModel;
use App\Models\PlatformUsersModel;
use App\Models\PlatformUserTenantsModel;
use Config\Database as DatabaseConfig;
use Config\Database;
use mysqli;

class TenantProvisioningService
{
    private \CodeIgniter\Database\BaseConnection $db;
    private PlatformPackagesModel $packagesModel;
    private PlatformTenantsModel $tenantsModel;
    private PlatformUsersModel $usersModel;
    private PlatformUserTenantsModel $membershipsModel;
    private PlatformFeaturesModel $featuresModel;
    private PlatformTenantFeaturesModel $tenantFeaturesModel;
    private PlatformTenantFeaturePreferencesModel $tenantFeaturePreferencesModel;

    public function __construct()
    {
        $this->db = Database::connect('platform');
        $this->packagesModel = new PlatformPackagesModel();
        $this->tenantsModel = new PlatformTenantsModel();
        $this->usersModel = new PlatformUsersModel();
        $this->membershipsModel = new PlatformUserTenantsModel();
        $this->featuresModel = new PlatformFeaturesModel();
        $this->tenantFeaturesModel = new PlatformTenantFeaturesModel();
        $this->tenantFeaturePreferencesModel = new PlatformTenantFeaturePreferencesModel();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createTenant(array $payload): array
    {
        return $this->saveTenantInternal(null, $payload);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateTenant(int $tenantId, array $payload): array
    {
        if ($tenantId <= 0) {
            throw new \InvalidArgumentException('Tenant non valido.');
        }

        return $this->saveTenantInternal($tenantId, $payload);
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function deleteTenant(int $tenantId, array $options = []): array
    {
        if ($tenantId <= 0) {
            throw new \InvalidArgumentException('Tenant non valido.');
        }

        $tenant = $this->tenantsModel->find($tenantId);
        if (!$tenant) {
            throw new \RuntimeException('Spazio cliente non trovato.');
        }

        $normalizedOptions = [
            'drop_database' => !empty($options['drop_database']),
            'delete_directories' => !empty($options['delete_directories']),
            'delete_tokens' => array_key_exists('delete_tokens', $options) ? !empty($options['delete_tokens']) : true,
        ];

        $resolvedTenant = (new TenantInfrastructureProvisioningService())->resolveDatabaseDefaults($tenant);
        $cleanup = [
            'database_dropped' => false,
            'deleted_paths' => [],
            'tokens_deleted' => 0,
        ];

        if ($normalizedOptions['drop_database']) {
            $cleanup['database_dropped'] = $this->dropTenantDatabase($resolvedTenant);
        }

        if ($normalizedOptions['delete_directories']) {
            $cleanup['deleted_paths'] = $this->deleteTenantDirectories($resolvedTenant);
        }

        $this->db->transBegin();

        try {
            if ($normalizedOptions['delete_tokens'] && $this->db->tableExists('platform_user_access_tokens')) {
                $this->db->table('platform_user_access_tokens')
                    ->where('id_tenant', $tenantId)
                    ->delete();
                $cleanup['tokens_deleted'] = max(0, (int) $this->db->affectedRows());
            }

            if ($this->db->tableExists('platform_tenant_feature_preferences')) {
                $this->tenantFeaturePreferencesModel
                    ->where('id_tenant', $tenantId)
                    ->delete();
            }

            if ($this->db->tableExists('platform_tenant_features')) {
                $this->tenantFeaturesModel
                    ->where('id_tenant', $tenantId)
                    ->delete();
            }

            if ($this->db->tableExists('platform_user_tenants')) {
                $this->membershipsModel
                    ->where('id_tenant', $tenantId)
                    ->delete();
            }

            if ($this->tenantsModel->delete($tenantId) === false) {
                throw new \RuntimeException('Eliminazione dello spazio cliente non riuscita.');
            }

            if (!$this->db->transStatus()) {
                throw new \RuntimeException('Eliminazione dello spazio cliente non riuscita.');
            }

            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();
            throw $e;
        }

        return [
            'tenant' => $tenant,
            'options' => $normalizedOptions,
            'cleanup' => $cleanup,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function buildRuntimeBlueprint(array $tenant): array
    {
        $resolvedTenant = array_merge($tenant, (new TenantDatabaseConnector())->resolveDatabaseSettings($tenant));
        $tenantKey = trim((string) ($tenant['tenant_key'] ?? ''));
        $storageKey = trim((string) ($tenant['storage_key'] ?? '')) !== ''
            ? trim((string) ($tenant['storage_key'] ?? ''))
            : $tenantKey;

        $projectRoot = $this->projectRoot();
        $uploadPath = $projectRoot . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'tenants' . DIRECTORY_SEPARATOR . $storageKey;
        $writablePath = rtrim(WRITEPATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'tenants' . DIRECTORY_SEPARATOR . $storageKey;

        return [
            'tenant_key' => $tenantKey,
            'storage_key' => $storageKey,
            'db_host' => (string) ($resolvedTenant['db_host'] ?? ''),
            'db_port' => (int) ($resolvedTenant['db_port'] ?? 3306),
            'db_name' => (string) ($resolvedTenant['db_name'] ?? ''),
            'db_username' => (string) ($resolvedTenant['db_username'] ?? ''),
            'db_password_ref' => (string) ($resolvedTenant['db_password_ref'] ?? ''),
            'db_driver' => (string) ($resolvedTenant['db_driver'] ?? 'MySQLi'),
            'db_prefix' => (string) ($resolvedTenant['db_prefix'] ?? ''),
            'upload_path' => $uploadPath,
            'writable_path' => $writablePath,
            'env_password_key' => (string) ($resolvedTenant['db_password_ref'] ?? ''),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function prepareLocalDirectories(string $tenantKey, ?string $storageKey = null): array
    {
        $tenantKey = $this->normalizeTenantKey($tenantKey);
        if ($tenantKey === '') {
            throw new \InvalidArgumentException('tenant_key non valido.');
        }

        $storageKey = $this->normalizeStorageKey((string) $storageKey, $tenantKey);
        $projectRoot = $this->projectRoot();

        $paths = [
            'upload' => $projectRoot . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'tenants' . DIRECTORY_SEPARATOR . $storageKey,
            'writable_root' => rtrim(WRITEPATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'tenants' . DIRECTORY_SEPARATOR . $storageKey,
            'writable_cache' => rtrim(WRITEPATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'tenants' . DIRECTORY_SEPARATOR . $storageKey . DIRECTORY_SEPARATOR . 'cache',
            'writable_logs' => rtrim(WRITEPATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'tenants' . DIRECTORY_SEPARATOR . $storageKey . DIRECTORY_SEPARATOR . 'logs',
            'writable_session' => rtrim(WRITEPATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'tenants' . DIRECTORY_SEPARATOR . $storageKey . DIRECTORY_SEPARATOR . 'session',
            'writable_tmp' => rtrim(WRITEPATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'tenants' . DIRECTORY_SEPARATOR . $storageKey . DIRECTORY_SEPARATOR . 'tmp',
        ];

        foreach ($paths as $path) {
            if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
                throw new \RuntimeException('Impossibile creare la cartella: ' . $path);
            }
        }

        return $paths;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listTenantMembers(int $tenantId): array
    {
        if ($tenantId <= 0) {
            return [];
        }

        return $this->db->table('platform_user_tenants put')
            ->select('put.*, u.email, u.first_name, u.last_name, u.status AS platform_user_status')
            ->join('platform_users u', 'u.id_platform_user = put.id_platform_user')
            ->where('put.id_tenant', $tenantId)
            ->orderBy('put.is_owner', 'DESC')
            ->orderBy('put.tenant_role', 'ASC')
            ->orderBy('u.email', 'ASC')
            ->get()
            ->getResultArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function getTenantUserCapacity(int $tenantId): array
    {
        if ($tenantId <= 0) {
            return [
                'current_users' => 0,
                'max_users' => null,
                'has_capacity' => false,
                'remaining_users' => null,
            ];
        }

        $tenant = $this->db->table('platform_tenants t')
            ->select('t.id_tenant, p.package_code, p.package_name, p.max_users')
            ->join('platform_packages p', 'p.id_package = t.id_package', 'left')
            ->where('t.id_tenant', $tenantId)
            ->get(1)
            ->getRowArray();

        $currentUsers = (int) $this->db->table('platform_user_tenants')
            ->where('id_tenant', $tenantId)
            ->countAllResults();

        $maxUsers = isset($tenant['max_users']) && $tenant['max_users'] !== null
            ? (int) $tenant['max_users']
            : null;

        return [
            'current_users' => $currentUsers,
            'max_users' => $maxUsers,
            'has_capacity' => $maxUsers === null || $currentUsers < $maxUsers,
            'remaining_users' => $maxUsers === null ? null : max(0, $maxUsers - $currentUsers),
            'package_code' => (string) ($tenant['package_code'] ?? ''),
            'package_name' => (string) ($tenant['package_name'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function saveTenantMember(int $tenantId, array $payload): array
    {
        if ($tenantId <= 0) {
            throw new \InvalidArgumentException('Tenant non valido.');
        }

        $tenant = $this->tenantsModel->find($tenantId);
        if (!$tenant) {
            throw new \RuntimeException('Tenant non trovato.');
        }

        $email = $this->normalizeEmail((string) ($payload['email'] ?? ''));
        $firstName = trim((string) ($payload['first_name'] ?? ''));
        $lastName = trim((string) ($payload['last_name'] ?? ''));
        $role = $this->normalizeTenantRole((string) ($payload['tenant_role'] ?? 'tenant_staff'));
        $plainPassword = (string) ($payload['password'] ?? '');
        $requestedMembershipId = (int) ($payload['id_platform_user_tenant'] ?? 0);
        $appUserId = array_key_exists('app_user_id', $payload) && (int) $payload['app_user_id'] > 0
            ? (int) $payload['app_user_id']
            : null;
        $isAppAdmin = array_key_exists('is_app_admin', $payload)
            ? ((int) $payload['is_app_admin'] === 1 ? 1 : 0)
            : null;
        $setDefault = !empty($payload['is_default']);

        if ($email === '') {
            throw new \InvalidArgumentException('Email utente non valida.');
        }

        $this->db->transBegin();

        try {
            $userResult = $this->ensurePlatformUser($email, $firstName, $lastName, $plainPassword);
            $platformUserId = (int) ($userResult['platform_user']['id_platform_user'] ?? 0);
            if ($platformUserId <= 0) {
                throw new \RuntimeException('Platform user non valido.');
            }

            $membership = null;
            if ($requestedMembershipId > 0) {
                $membership = $this->membershipsModel->find($requestedMembershipId);
                if (!$membership || (int) ($membership['id_tenant'] ?? 0) !== $tenantId) {
                    throw new \RuntimeException('Membership tenant non trovata.');
                }
            }

            if (!$membership) {
                $membership = $this->membershipsModel->findMembership($platformUserId, $tenantId);
            }

            if ($membership && (int) ($membership['is_owner'] ?? 0) === 1) {
                throw new \RuntimeException('Il tenant master si gestisce dalla scheda principale dello spazio cliente.');
            }

            $isNewMembership = !$membership;
            if ($isNewMembership) {
                $this->assertTenantUserCapacity($tenantId);
            }

            $hasDefaultElsewhere = $this->db->table('platform_user_tenants')
                ->where('id_platform_user', $platformUserId)
                ->where('is_default', 1)
                ->where('id_tenant !=', $tenantId)
                ->countAllResults() > 0;

            $isDefault = $setDefault || (!$hasDefaultElsewhere && !$membership);
            if ($setDefault) {
                $this->db->table('platform_user_tenants')
                    ->where('id_platform_user', $platformUserId)
                    ->where('id_tenant !=', $tenantId)
                    ->update([
                        'is_default' => 0,
                    ]);
            }

            $membershipPayload = [
                'id_platform_user' => $platformUserId,
                'id_tenant' => $tenantId,
                'tenant_role' => $role,
                'app_user_id' => $appUserId,
                'is_app_admin' => $isAppAdmin ?? (int) ($membership['is_app_admin'] ?? 0),
                'is_default' => $isDefault ? 1 : (int) ($membership['is_default'] ?? 0),
                'is_owner' => 0,
            ];

            if ($membership) {
                $membershipPayload['app_user_id'] = $appUserId ?? ($membership['app_user_id'] ?? null);
                $this->membershipsModel->update((int) $membership['id_platform_user_tenant'], $membershipPayload);
                $membershipId = (int) $membership['id_platform_user_tenant'];
                $mode = 'updated';
            } else {
                $membershipPayload['invitation_status'] = 'pending';
                $membershipPayload['invited_at'] = date('Y-m-d H:i:s');
                $membershipId = (int) $this->membershipsModel->insert($membershipPayload);
                if ($membershipId <= 0) {
                    throw new \RuntimeException('Creazione membership tenant non riuscita.');
                }
                $mode = 'created';
            }

            if (!$this->db->transStatus()) {
                throw new \RuntimeException('Salvataggio utente tenant non riuscito.');
            }

            $this->db->transCommit();
            try {
                $tenantAppSync = (new TenantAppUserProvisioningService())->syncMembership($membershipId, false);
            } catch (\Throwable $syncError) {
                $tenantAppSync = [
                    'status' => 'error',
                    'membership_id' => $membershipId,
                    'message' => $syncError->getMessage(),
                ];
            }
            $syncedMembership = $this->membershipsModel->find($membershipId) ?? [];

            return [
                'mode' => $mode,
                'platform_user' => $userResult['platform_user'],
                'membership' => $syncedMembership,
                'capacity' => $this->getTenantUserCapacity($tenantId),
                'tenant_app_sync' => $tenantAppSync,
            ];
        } catch (\Throwable $e) {
            $this->db->transRollback();
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function saveTenantInternal(?int $tenantId, array $payload): array
    {
        $isUpdate = $tenantId !== null;
        $existingTenant = $isUpdate ? ($this->tenantsModel->find($tenantId) ?? null) : null;

        if ($isUpdate && !$existingTenant) {
            throw new \RuntimeException('Tenant non trovato.');
        }

        $tenantName = trim((string) ($payload['tenant_name'] ?? ($existingTenant['tenant_name'] ?? '')));
        $legalName = $this->nullableString($payload['legal_name'] ?? ($existingTenant['legal_name'] ?? null));
        $tenantKey = $this->resolveTenantKey($tenantId, $payload, $existingTenant, $tenantName, $legalName);
        $masterEmail = $this->normalizeEmail((string) ($payload['master_email'] ?? ''));

        if ($tenantKey === '') {
            throw new \InvalidArgumentException('tenant_key non valido.');
        }
        if ($tenantName === '') {
            throw new \InvalidArgumentException('tenant_name obbligatorio.');
        }
        if ($masterEmail === '') {
            throw new \InvalidArgumentException('master_email non valida.');
        }

        $tenantByKey = $this->tenantsModel->findByTenantKey($tenantKey);
        if ($tenantByKey && (int) ($tenantByKey['id_tenant'] ?? 0) !== (int) ($tenantId ?? 0)) {
            throw new \RuntimeException('Esiste gia un tenant con chiave ' . $tenantKey . '.');
        }

        $packageCode = trim(strtolower((string) ($payload['package_code'] ?? '')));
        if ($packageCode !== '') {
            $package = $this->packagesModel->findByCode($packageCode);
        } elseif ($existingTenant && (int) ($existingTenant['id_package'] ?? 0) > 0) {
            $package = $this->packagesModel->find((int) ($existingTenant['id_package'] ?? 0));
        } else {
            $packageCode = 'base';
            $package = $this->packagesModel->findByCode($packageCode);
        }

        if (!$package) {
            throw new \RuntimeException('Pacchetto non trovato: ' . ($packageCode !== '' ? $packageCode : 'base'));
        }

        $masterFirstName = trim((string) ($payload['master_first_name'] ?? ''));
        $masterLastName = trim((string) ($payload['master_last_name'] ?? ''));
        $status = trim((string) ($payload['status'] ?? ($existingTenant['status'] ?? 'draft'))) ?: 'draft';
        $onboardingStatus = trim((string) ($payload['onboarding_status'] ?? ($existingTenant['onboarding_status'] ?? 'draft'))) ?: 'draft';
        $storageKey = $this->normalizeStorageKey(
            (string) ($payload['storage_key'] ?? ($existingTenant['storage_key'] ?? '')),
            $tenantKey
        );

        $tenantData = [
            'tenant_key' => $tenantKey,
            'tenant_name' => $tenantName,
            'legal_name' => $legalName,
            'status' => $status,
            'id_package' => (int) $package['id_package'],
            'onboarding_status' => $onboardingStatus,
            'login_hint' => $this->nullableString($payload['login_hint'] ?? ($existingTenant['login_hint'] ?? null)),
            'db_host' => $this->nullableString($payload['db_host'] ?? ($existingTenant['db_host'] ?? null)),
            'db_port' => (int) ($payload['db_port'] ?? ($existingTenant['db_port'] ?? 3306)),
            'db_name' => $this->nullableString($payload['db_name'] ?? ($existingTenant['db_name'] ?? null)),
            'db_username' => $this->nullableString($payload['db_username'] ?? ($existingTenant['db_username'] ?? null)),
            'db_password_ref' => $this->nullableString($payload['db_password_ref'] ?? ($existingTenant['db_password_ref'] ?? null)),
            'db_driver' => $this->nullableString($payload['db_driver'] ?? ($existingTenant['db_driver'] ?? 'MySQLi')) ?? 'MySQLi',
            'db_prefix' => (string) ($payload['db_prefix'] ?? ($existingTenant['db_prefix'] ?? '')),
            'storage_key' => $storageKey,
            'feature_profile' => $this->nullableString($payload['feature_profile'] ?? ($existingTenant['feature_profile'] ?? null)),
            'metadata_json' => $this->encodeMetadata($payload['metadata'] ?? ($existingTenant['metadata_json'] ?? null)),
            'is_active' => array_key_exists('is_active', $payload)
                ? (!empty($payload['is_active']) ? 1 : 0)
                : (int) ($existingTenant['is_active'] ?? 1),
        ];

        $enabledFeatures = $this->normalizeFeatureKeys($payload['enabled_features'] ?? []);
        $disabledFeatures = $this->normalizeFeatureKeys($payload['disabled_features'] ?? []);

        $this->db->transBegin();

        try {
            if ($isUpdate) {
                $this->tenantsModel->update((int) $tenantId, $tenantData);
                $resolvedTenantId = (int) $tenantId;
            } else {
                $resolvedTenantId = (int) $this->tenantsModel->insert($tenantData);
            }

            if ($resolvedTenantId <= 0) {
                throw new \RuntimeException('Salvataggio tenant non riuscito.');
            }

            $userResult = $this->ensurePlatformUser(
                $masterEmail,
                $masterFirstName,
                $masterLastName,
                (string) ($payload['master_password'] ?? '')
            );

            $membership = $this->assignOwnerMembership(
                $resolvedTenantId,
                (int) ($userResult['platform_user']['id_platform_user'] ?? 0),
                array_key_exists('app_user_id', $payload) ? (int) $payload['app_user_id'] : null
            );

            $this->replaceFeatureOverrides($resolvedTenantId, $enabledFeatures, $disabledFeatures);

            if (!$this->db->transStatus()) {
                throw new \RuntimeException('Salvataggio tenant non riuscito.');
            }

            $this->db->transCommit();

            $tenant = $this->tenantsModel->find($resolvedTenantId) ?? [];
            $membershipId = (int) ($membership['id_platform_user_tenant'] ?? 0);
            if ($membershipId > 0) {
                try {
                    $tenantAppSync = (new TenantAppUserProvisioningService())->syncMembership($membershipId, false);
                } catch (\Throwable $syncError) {
                    $tenantAppSync = [
                        'status' => 'error',
                        'membership_id' => $membershipId,
                        'message' => $syncError->getMessage(),
                    ];
                }
            } else {
                $tenantAppSync = ['status' => 'skipped', 'message' => 'Membership tenant master non disponibile.'];
            }
            if ($membershipId > 0) {
                $membership = $this->membershipsModel->find($membershipId) ?? $membership;
            }

            return [
                'tenant' => $tenant,
                'package' => $package,
                'platform_user' => $userResult['platform_user'],
                'membership' => $membership,
                'runtime' => $this->buildRuntimeBlueprint($tenant),
                'mode' => $isUpdate ? 'updated' : 'created',
                'tenant_app_sync' => $tenantAppSync,
            ];
        } catch (\Throwable $e) {
            $this->db->transRollback();
            throw $e;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function ensurePlatformUser(
        string $email,
        string $firstName = '',
        string $lastName = '',
        string $plainPassword = ''
    ): array {
        $platformUser = $this->usersModel->findByEmailInsensitive($email);
        $userCreated = false;
        $temporaryPassword = null;

        if (!$platformUser) {
            $hasExplicitPassword = $plainPassword !== '';
            $temporaryPassword = $hasExplicitPassword ? $plainPassword : $this->generateTemporaryPassword();
            $platformUserId = (int) $this->usersModel->insert([
                'email' => $email,
                'password_hash' => password_hash($temporaryPassword, PASSWORD_DEFAULT),
                'first_name' => $firstName !== '' ? $firstName : null,
                'last_name' => $lastName !== '' ? $lastName : null,
                'status' => $hasExplicitPassword ? 'active' : 'invited',
                'must_reset_password' => $hasExplicitPassword ? 0 : 1,
                'email_verified_at' => $hasExplicitPassword ? date('Y-m-d H:i:s') : null,
                'last_login_at' => null,
            ]);

            if ($platformUserId <= 0) {
                throw new \RuntimeException('Creazione platform user non riuscita.');
            }

            $platformUser = $this->usersModel->find($platformUserId);
            $userCreated = true;
        } else {
            $updateData = [];
            if ($firstName !== '') {
                $updateData['first_name'] = $firstName;
            }
            if ($lastName !== '') {
                $updateData['last_name'] = $lastName;
            }
            if ($plainPassword !== '') {
                $updateData['password_hash'] = password_hash($plainPassword, PASSWORD_DEFAULT);
                $updateData['must_reset_password'] = 0;
                if (trim((string) ($platformUser['email_verified_at'] ?? '')) === '') {
                    $updateData['email_verified_at'] = date('Y-m-d H:i:s');
                }

                $currentStatus = strtolower(trim((string) ($platformUser['status'] ?? 'active')));
                if (!in_array($currentStatus, ['suspended', 'blocked'], true)) {
                    $updateData['status'] = 'active';
                }
            }

            if ($updateData !== []) {
                $this->usersModel->update((int) $platformUser['id_platform_user'], $updateData);
                $platformUser = $this->usersModel->find((int) $platformUser['id_platform_user']);
            }
        }

        $platformUserId = (int) ($platformUser['id_platform_user'] ?? 0);
        if ($platformUserId <= 0) {
            throw new \RuntimeException('Platform user non valido.');
        }

        return [
            'platform_user' => [
                'id_platform_user' => $platformUserId,
                'email' => $email,
                'first_name' => (string) ($platformUser['first_name'] ?? $firstName),
                'last_name' => (string) ($platformUser['last_name'] ?? $lastName),
                'was_created' => $userCreated,
                'temporary_password' => $temporaryPassword,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function assignOwnerMembership(int $tenantId, int $platformUserId, ?int $appUserId = null): array
    {
        if ($tenantId <= 0 || $platformUserId <= 0) {
            throw new \RuntimeException('Membership tenant non valida.');
        }

        $this->db->table('platform_user_tenants')
            ->where('id_tenant', $tenantId)
            ->update([
                'is_owner' => 0,
            ]);

        $this->db->table('platform_user_tenants')
            ->where('id_platform_user', $platformUserId)
            ->where('id_tenant !=', $tenantId)
            ->update([
                'is_default' => 0,
            ]);

        $membership = $this->membershipsModel
            ->where('id_platform_user', $platformUserId)
            ->where('id_tenant', $tenantId)
            ->first();

        $payload = [
            'id_platform_user' => $platformUserId,
            'id_tenant' => $tenantId,
            'tenant_role' => 'tenant_master',
            'app_user_id' => $appUserId,
            'is_default' => 1,
            'is_owner' => 1,
            'invitation_status' => 'pending',
            'invited_at' => date('Y-m-d H:i:s'),
        ];

        if ($membership) {
            $updatePayload = [
                'tenant_role' => 'tenant_master',
                'app_user_id' => $appUserId ?? ($membership['app_user_id'] ?? null),
                'is_default' => 1,
                'is_owner' => 1,
            ];

            $this->membershipsModel->update((int) $membership['id_platform_user_tenant'], $updatePayload);
            return $this->membershipsModel->find((int) $membership['id_platform_user_tenant']) ?? [];
        }

        $membershipId = (int) $this->membershipsModel->insert($payload);
        if ($membershipId <= 0) {
            throw new \RuntimeException('Creazione membership tenant non riuscita.');
        }

        return $this->membershipsModel->find($membershipId) ?? [];
    }

    /**
     * @param array<int, string> $enabledFeatureKeys
     * @param array<int, string> $disabledFeatureKeys
     */
    private function replaceFeatureOverrides(int $tenantId, array $enabledFeatureKeys, array $disabledFeatureKeys): void
    {
        if ($tenantId <= 0) {
            return;
        }

        $this->db->table('platform_tenant_features')
            ->where('id_tenant', $tenantId)
            ->delete();

        $featureKeys = array_values(array_unique(array_merge($enabledFeatureKeys, $disabledFeatureKeys)));
        if ($featureKeys === []) {
            return;
        }

        $features = $this->featuresModel->findByKeys($featureKeys);
        foreach ($features as $feature) {
            $featureId = (int) ($feature['id_feature'] ?? 0);
            $featureKey = trim((string) ($feature['feature_key'] ?? ''));

            if ($featureId <= 0 || $featureKey === '') {
                continue;
            }

            if (in_array($featureKey, $enabledFeatureKeys, true)) {
                $this->tenantFeaturesModel->setOverride($tenantId, $featureId, true, null, 'admin_panel');
                continue;
            }

            if (in_array($featureKey, $disabledFeatureKeys, true)) {
                $this->tenantFeaturesModel->setOverride($tenantId, $featureId, false, null, 'admin_panel');
            }
        }
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function normalizeFeatureKeys($value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }

        $normalized = [];
        foreach ((array) $value as $featureKey) {
            $featureKey = trim(strtolower((string) $featureKey));
            if ($featureKey !== '' && !in_array($featureKey, $normalized, true)) {
                $normalized[] = $featureKey;
            }
        }

        return $normalized;
    }

    private function normalizeTenantRole(string $role): string
    {
        $role = trim(strtolower($role));
        $allowed = ['tenant_admin', 'tenant_staff'];

        if (!in_array($role, $allowed, true)) {
            throw new \InvalidArgumentException('Ruolo tenant non valido.');
        }

        return $role;
    }

    private function normalizeTenantKey(string $tenantKey): string
    {
        $tenantKey = strtolower(trim($tenantKey));
        $tenantKey = preg_replace('/[^a-z0-9\-]/', '-', $tenantKey) ?? '';
        $tenantKey = preg_replace('/\-+/', '-', $tenantKey) ?? '';
        return trim($tenantKey, '-');
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed>|null $existingTenant
     */
    private function resolveTenantKey(
        ?int $tenantId,
        array $payload,
        ?array $existingTenant,
        string $tenantName,
        ?string $legalName
    ): string {
        $requestedTenantKey = $this->normalizeTenantKey((string) ($payload['tenant_key'] ?? ''));
        if ($requestedTenantKey !== '') {
            return $requestedTenantKey;
        }

        $currentTenantKey = $this->normalizeTenantKey((string) ($existingTenant['tenant_key'] ?? ''));
        if ($currentTenantKey !== '') {
            return $currentTenantKey;
        }

        $candidate = $this->normalizeTenantKey($tenantName !== '' ? $tenantName : (string) ($legalName ?? ''));
        if ($candidate === '') {
            return '';
        }

        return $this->ensureUniqueTenantKey($candidate, $tenantId);
    }

    private function ensureUniqueTenantKey(string $candidate, ?int $tenantId = null): string
    {
        $candidate = $this->normalizeTenantKey($candidate);
        if ($candidate === '') {
            return '';
        }

        $suffix = 1;
        $tenantKey = $candidate;

        while (true) {
            $tenant = $this->tenantsModel->findByTenantKey($tenantKey);
            if (!$tenant || (int) ($tenant['id_tenant'] ?? 0) === (int) ($tenantId ?? 0)) {
                return $tenantKey;
            }

            $suffix++;
            $tenantKey = $candidate . '-' . $suffix;
        }
    }

    private function normalizeStorageKey(string $storageKey, string $tenantKey): string
    {
        $storageKey = trim($storageKey);
        if ($storageKey === '') {
            return $tenantKey;
        }

        $storageKey = strtolower($storageKey);
        $storageKey = preg_replace('/[^a-z0-9\-_]/', '-', $storageKey) ?? '';
        $storageKey = preg_replace('/\-+/', '-', $storageKey) ?? '';

        return trim($storageKey, '-');
    }

    private function normalizeEmail(string $email): string
    {
        $email = trim(strtolower($email));
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
    }

    /**
     * @param mixed $value
     */
    private function nullableString($value): ?string
    {
        $value = trim((string) $value);
        return $value !== '' ? $value : null;
    }

    /**
     * @param mixed $value
     */
    private function encodeMetadata($value): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }

        if (is_string($value)) {
            return trim($value) !== '' ? trim($value) : null;
        }

        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json !== false ? $json : null;
    }

    private function generateTemporaryPassword(): string
    {
        return substr(bin2hex(random_bytes(12)), 0, 20);
    }

    private function assertTenantUserCapacity(int $tenantId): void
    {
        $capacity = $this->getTenantUserCapacity($tenantId);
        $maxUsers = $capacity['max_users'];
        $currentUsers = (int) ($capacity['current_users'] ?? 0);

        if ($maxUsers !== null && $currentUsers >= (int) $maxUsers) {
            throw new \RuntimeException('Limite utenti raggiunto per il pacchetto selezionato.');
        }
    }

    private function projectRoot(): string
    {
        return dirname(rtrim(APPPATH, DIRECTORY_SEPARATOR), 2);
    }

    /**
     * @param array<string, mixed> $tenant
     * @return array<int, string>
     */
    private function deleteTenantDirectories(array $tenant): array
    {
        $runtime = $this->buildRuntimeBlueprint($tenant);
        $storageKey = trim((string) ($runtime['storage_key'] ?? ''));
        if ($storageKey === '') {
            throw new \RuntimeException('Storage key tenant non valido per la pulizia cartelle.');
        }

        $paths = [
            (string) ($runtime['upload_path'] ?? ''),
            (string) ($runtime['writable_path'] ?? ''),
        ];

        $deleted = [];
        foreach ($paths as $path) {
            $path = trim($path);
            if ($path === '' || !file_exists($path)) {
                continue;
            }

            $this->assertSafeTenantPath($path, $storageKey);
            $this->removeDirectoryTree($path);
            $deleted[] = $path;
        }

        return $deleted;
    }

    /**
     * @param array<string, mixed> $tenant
     */
    private function dropTenantDatabase(array $tenant): bool
    {
        $databaseName = trim((string) ($tenant['db_name'] ?? ''));
        if ($databaseName === '') {
            return false;
        }

        $mysqli = $this->connectAdminMysql($tenant);
        try {
            $exists = $this->queryMysqlValue($mysqli, "
                SELECT SCHEMA_NAME
                FROM information_schema.SCHEMATA
                WHERE SCHEMA_NAME = '" . $mysqli->real_escape_string($databaseName) . "'
                LIMIT 1
            ");

            if ($exists === null) {
                return false;
            }

            if (!$mysqli->query('DROP DATABASE ' . $this->escapeMysqlIdentifier($databaseName))) {
                throw new \RuntimeException('Drop database tenant non riuscito: ' . $mysqli->error);
            }

            return true;
        } finally {
            $mysqli->close();
        }
    }

    /**
     * @param array<string, mixed> $tenant
     */
    private function connectAdminMysql(array $tenant): mysqli
    {
        $databaseConfig = new DatabaseConfig();
        $platform = $databaseConfig->platform;

        $host = $this->envValue('tenant.provisioning.adminHost', 'TENANT_PROVISIONING_ADMIN_HOST', (string) ($tenant['db_host'] ?? $platform['hostname'] ?? 'localhost'));
        $port = (int) $this->envValue('tenant.provisioning.adminPort', 'TENANT_PROVISIONING_ADMIN_PORT', (string) ($tenant['db_port'] ?? $platform['port'] ?? 3306));
        $user = $this->envValue('tenant.provisioning.adminUsername', 'TENANT_PROVISIONING_ADMIN_USERNAME', (string) ($platform['username'] ?? ''));
        $password = $this->envValue('tenant.provisioning.adminPassword', 'TENANT_PROVISIONING_ADMIN_PASSWORD', (string) ($platform['password'] ?? ''));

        if ($host === '' || $user === '') {
            throw new \RuntimeException('Configurazione admin MySQL mancante per il reset del tenant.');
        }

        $mysqli = new mysqli($host, $user, $password, '', $port);
        if ($mysqli->connect_errno) {
            throw new \RuntimeException('Connessione MySQL admin fallita: ' . $mysqli->connect_error);
        }

        $mysqli->set_charset('utf8mb4');
        return $mysqli;
    }

    private function queryMysqlValue(mysqli $mysqli, string $sql): ?string
    {
        $result = $mysqli->query($sql);
        if (!$result) {
            throw new \RuntimeException('Errore SQL reset tenant: ' . $mysqli->error);
        }

        $row = $result->fetch_row();
        $result->free();

        if (!is_array($row) || !array_key_exists(0, $row)) {
            return null;
        }

        return $row[0] !== null ? (string) $row[0] : null;
    }

    private function escapeMysqlIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
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

    private function assertSafeTenantPath(string $path, string $storageKey): void
    {
        $normalizedPath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, rtrim($path, '\\/'));
        $projectRoot = $this->projectRoot();
        $allowedPrefixes = [
            $projectRoot . DIRECTORY_SEPARATOR . 'upload' . DIRECTORY_SEPARATOR . 'tenants' . DIRECTORY_SEPARATOR . $storageKey,
            rtrim(WRITEPATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'tenants' . DIRECTORY_SEPARATOR . $storageKey,
        ];

        foreach ($allowedPrefixes as $prefix) {
            $normalizedPrefix = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, rtrim($prefix, '\\/'));
            if (strcasecmp($normalizedPath, $normalizedPrefix) === 0 || str_starts_with(strtolower($normalizedPath), strtolower($normalizedPrefix . DIRECTORY_SEPARATOR))) {
                return;
            }
        }

        throw new \RuntimeException('Percorso tenant non sicuro per la cancellazione: ' . $path);
    }

    private function removeDirectoryTree(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            if (!@unlink($path)) {
                throw new \RuntimeException('Impossibile eliminare il file tenant: ' . $path);
            }

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if (!is_array($items)) {
            throw new \RuntimeException('Impossibile leggere la cartella tenant: ' . $path);
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $childPath = $path . DIRECTORY_SEPARATOR . $item;
            $this->removeDirectoryTree($childPath);
        }

        if (!@rmdir($path)) {
            throw new \RuntimeException('Impossibile eliminare la cartella tenant: ' . $path);
        }
    }
}
