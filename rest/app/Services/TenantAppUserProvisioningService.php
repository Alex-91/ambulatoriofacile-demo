<?php

namespace App\Services;

use App\Libraries\Crypto_helper;
use App\Libraries\DatabaseConfig;
use App\Models\PlatformUserTenantsModel;
use App\Models\PlatformUsersModel;
use Config\Database;

class TenantAppUserProvisioningService
{
    private \CodeIgniter\Database\BaseConnection $platformDb;
    private PlatformUserTenantsModel $membershipsModel;
    private PlatformUsersModel $platformUsersModel;
    private TenantCatalogService $catalog;
    private TenantDatabaseConnector $tenantDbConnector;
    private DatabaseConfig $databaseConfig;
    private Crypto_helper $crypto;

    public function __construct()
    {
        $this->platformDb = Database::connect('platform');
        $this->membershipsModel = new PlatformUserTenantsModel();
        $this->platformUsersModel = new PlatformUsersModel();
        $this->catalog = new TenantCatalogService();
        $this->tenantDbConnector = new TenantDatabaseConnector();
        $this->databaseConfig = new DatabaseConfig();
        $this->crypto = new Crypto_helper();
    }

    /**
     * @return array<string, mixed>
     */
    public function syncMembership(int $membershipId, bool $strict = false): array
    {
        if ($membershipId <= 0) {
            throw new \InvalidArgumentException('Membership tenant non valida.');
        }

        $membership = $this->loadMembership($membershipId);
        if (!$membership) {
            throw new \RuntimeException('Membership tenant non trovata.');
        }

        $tenant = $this->catalog->getTenantById((int) ($membership['id_tenant'] ?? 0));
        if (!$tenant) {
            throw new \RuntimeException('Tenant della membership non trovato.');
        }

        $platformUser = $this->platformUsersModel->find((int) ($membership['id_platform_user'] ?? 0));
        if (!$platformUser) {
            throw new \RuntimeException('Platform user della membership non trovato.');
        }

        if (!$this->tenantConfigLooksReady($tenant)) {
            return $this->skipResult($membershipId, 'Configurazione database tenant non ancora completa.', $strict);
        }

        try {
            $tenantDb = $this->tenantDbConnector->connect($tenant);
        } catch (\Throwable $e) {
            return $this->skipResult(
                $membershipId,
                'Database tenant non ancora raggiungibile: ' . $e->getMessage(),
                $strict
            );
        }

        $this->databaseConfig->setEncryptionConfig($tenantDb);

        if (!$tenantDb->tableExists('dap01_users') || !$tenantDb->tableExists('dap03_personale')) {
            return $this->skipResult(
                $membershipId,
                'Schema tenant non ancora pronto per la sincronizzazione utenti.',
                $strict
            );
        }

        $defaultGroupId = $this->ensureDefaultGroup($tenantDb);
        $profile = $this->resolveRoleProfile((string) ($membership['tenant_role'] ?? 'tenant_staff'));
        $appUserId = (int) ($membership['app_user_id'] ?? 0);
        $linkedByEmail = false;

        if ($appUserId > 0 && !$this->tenantUserExists($tenantDb, $appUserId)) {
            $appUserId = 0;
        }

        if ($appUserId <= 0) {
            $appUserId = $this->findAppUserIdByEmail($tenantDb, (string) ($platformUser['email'] ?? ''));
            $linkedByEmail = $appUserId > 0;
        }

        $tenantDb->transBegin();

        try {
            if ($appUserId > 0) {
                $this->updateTenantAppUser($tenantDb, $appUserId, $platformUser, $profile, $defaultGroupId);
                $mode = $linkedByEmail ? 'linked_existing' : 'updated_existing';
            } else {
                $appUserId = $this->createTenantAppUser($tenantDb, $platformUser, $profile, $defaultGroupId);
                $mode = 'created';
            }

            if ($appUserId <= 0) {
                throw new \RuntimeException('Impossibile determinare l app user del tenant.');
            }

            if ((int) ($membership['app_user_id'] ?? 0) !== $appUserId) {
                $this->membershipsModel->update($membershipId, [
                    'app_user_id' => $appUserId,
                ]);
            }

            if (!$tenantDb->transStatus()) {
                throw new \RuntimeException('Sincronizzazione utente tenant non riuscita.');
            }

            $tenantDb->transCommit();
        } catch (\Throwable $e) {
            $tenantDb->transRollback();
            throw $e;
        }

        return [
            'status' => 'ready',
            'mode' => $mode,
            'membership_id' => $membershipId,
            'tenant_id' => (int) ($membership['id_tenant'] ?? 0),
            'app_user_id' => $appUserId,
            'tenant_role' => (string) ($membership['tenant_role'] ?? ''),
            'message' => $mode === 'created'
                ? 'Utente applicativo tenant creato automaticamente.'
                : 'Utente applicativo tenant sincronizzato.',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function syncTenantMembers(int $tenantId, bool $strict = false): array
    {
        if ($tenantId <= 0) {
            return [];
        }

        $memberships = $this->platformDb->table('platform_user_tenants')
            ->select('id_platform_user_tenant')
            ->where('id_tenant', $tenantId)
            ->orderBy('is_owner', 'DESC')
            ->orderBy('id_platform_user_tenant', 'ASC')
            ->get()
            ->getResultArray();

        $results = [];
        foreach ($memberships as $membership) {
            $membershipId = (int) ($membership['id_platform_user_tenant'] ?? 0);
            if ($membershipId <= 0) {
                continue;
            }

            $results[] = $this->syncMembership($membershipId, $strict);
        }

        return $results;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadMembership(int $membershipId): ?array
    {
        return $this->platformDb->table('platform_user_tenants put')
            ->select('put.*, t.tenant_key, t.tenant_name, t.db_host, t.db_port, t.db_name, t.db_username, t.db_password_ref, t.db_driver, t.db_prefix, t.storage_key, t.metadata_json')
            ->join('platform_tenants t', 't.id_tenant = put.id_tenant')
            ->where('put.id_platform_user_tenant', $membershipId)
            ->get(1)
            ->getRowArray() ?: null;
    }

    /**
     * @param array<string, mixed> $tenant
     */
    private function tenantConfigLooksReady(array $tenant): bool
    {
        return trim((string) ($tenant['db_host'] ?? '')) !== ''
            && trim((string) ($tenant['db_name'] ?? '')) !== ''
            && trim((string) ($tenant['db_username'] ?? '')) !== ''
            && trim((string) ($tenant['db_password_ref'] ?? '')) !== '';
    }

    /**
     * @return array<string, mixed>
     */
    private function skipResult(int $membershipId, string $message, bool $strict): array
    {
        if ($strict) {
            throw new \RuntimeException($message);
        }

        return [
            'status' => 'skipped',
            'membership_id' => $membershipId,
            'message' => $message,
        ];
    }

    private function ensureDefaultGroup(\CodeIgniter\Database\BaseConnection $db): int
    {
        if (!$db->tableExists('dap21_gruppo')) {
            return 1;
        }

        $row = $db->table('dap21_gruppo')
            ->select('id_gruppo')
            ->orderBy('id_gruppo', 'ASC')
            ->get(1)
            ->getRowArray();

        if ($row) {
            return (int) ($row['id_gruppo'] ?? 1);
        }

        $db->table('dap21_gruppo')->insert([
            'nome' => 'Generale',
        ]);

        return max(1, (int) $db->insertID());
    }

    private function tenantUserExists(\CodeIgniter\Database\BaseConnection $db, int $appUserId): bool
    {
        if ($appUserId <= 0) {
            return false;
        }

        return $db->table('dap01_users')
            ->select('id_user')
            ->where('id_user', $appUserId)
            ->get(1)
            ->getRowArray() !== null;
    }

    private function findAppUserIdByEmail(\CodeIgniter\Database\BaseConnection $db, string $email): int
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return 0;
        }

        $rows = $db->query("
            SELECT p.id_user
            FROM dap03_personale p
            WHERE LOWER(" . $this->crypto->decryptSenzaAlias('p.email') . ") = ?
            ORDER BY p.id_user ASC
        ", [$email])->getResultArray();

        $matches = [];
        foreach ($rows as $row) {
            $idUser = (int) ($row['id_user'] ?? 0);
            if ($idUser > 0 && !in_array($idUser, $matches, true)) {
                $matches[] = $idUser;
            }
        }

        if (count($matches) > 1) {
            throw new \RuntimeException('Email presente piu volte nel tenant. Collega manualmente App user ID.');
        }

        return $matches[0] ?? 0;
    }

    /**
     * @param array<string, mixed> $platformUser
     * @param array<string, mixed> $profile
     */
    private function createTenantAppUser(
        \CodeIgniter\Database\BaseConnection $db,
        array $platformUser,
        array $profile,
        int $defaultGroupId
    ): int {
        $db->query('SET @init_vector = RANDOM_BYTES(16)');

        $username = $this->resolveUniqueUsername($db, $this->preferredUsername($platformUser), 0);
        $password = $this->randomPassword();
        $expiresAt = date('Y-m-d H:i:s', strtotime('+5 years'));
        $privacyDate = date('Y-m-d');

        $db->query("
            INSERT INTO dap01_users
            (username, password, datascadenza, tipo_user, vector_id, privacy, data_privacy, is_active)
            VALUES
            (?, " . $this->crypto->encrypt_insert('?') . ", ?, ?, @init_vector, 0, ?, 1)
        ", [
            $username,
            $password,
            $expiresAt,
            (int) ($profile['tipo_user'] ?? 2),
            $privacyDate,
        ]);

        $appUserId = (int) $db->insertID();
        if ($appUserId <= 0) {
            throw new \RuntimeException('Creazione dap01_users tenant non riuscita.');
        }

        $this->insertOrUpdatePersonale($db, $appUserId, $platformUser, $profile, $defaultGroupId, false);

        return $appUserId;
    }

    /**
     * @param array<string, mixed> $platformUser
     * @param array<string, mixed> $profile
     */
    private function updateTenantAppUser(
        \CodeIgniter\Database\BaseConnection $db,
        int $appUserId,
        array $platformUser,
        array $profile,
        int $defaultGroupId
    ): void {
        $userRow = $db->table('dap01_users')
            ->select('id_user, tipo_user')
            ->where('id_user', $appUserId)
            ->get(1)
            ->getRowArray();

        if (!$userRow) {
            throw new \RuntimeException('App user tenant non trovato.');
        }

        $db->query('SET @init_vector = RANDOM_BYTES(16)');

        $currentType = (int) ($userRow['tipo_user'] ?? 0);
        $desiredType = $this->resolveUpdatedTipoUser($currentType, (string) ($profile['role_key'] ?? ''));
        $username = $this->resolveUniqueUsername($db, $this->preferredUsername($platformUser), $appUserId);

        $db->table('dap01_users')
            ->where('id_user', $appUserId)
            ->update([
                'username' => $username,
                'tipo_user' => $desiredType,
                'datascadenza' => date('Y-m-d H:i:s', strtotime('+5 years')),
                'is_active' => 1,
            ]);

        $this->insertOrUpdatePersonale($db, $appUserId, $platformUser, $profile, $defaultGroupId, true);
    }

    /**
     * @param array<string, mixed> $platformUser
     * @param array<string, mixed> $profile
     */
    private function insertOrUpdatePersonale(
        \CodeIgniter\Database\BaseConnection $db,
        int $appUserId,
        array $platformUser,
        array $profile,
        int $defaultGroupId,
        bool $preferUpdate
    ): void {
        $existing = $db->table('dap03_personale')
            ->select('id_personale, luogo, qualifica, cellulare, tipo')
            ->where('id_user', $appUserId)
            ->get(1)
            ->getRowArray();

        $firstName = $this->safeName((string) ($platformUser['first_name'] ?? ''), $this->fallbackFirstName((string) ($platformUser['email'] ?? '')));
        $lastName = $this->safeName((string) ($platformUser['last_name'] ?? ''), 'Utente');
        $email = strtolower(trim((string) ($platformUser['email'] ?? '')));
        $qualifica = (string) ($profile['qualifica'] ?? 'Operatore');
        $personaleTipo = (int) ($profile['personale_tipo'] ?? 3);
        $luogo = $existing ? max(1, (int) ($existing['luogo'] ?? 0)) : max(1, $defaultGroupId);
        $cellulare = $existing ? '' : '';

        if ($existing && $preferUpdate) {
            $db->query("
                UPDATE dap03_personale
                SET nome = " . $this->crypto->encrypt_insert('?') . ",
                    cognome = " . $this->crypto->encrypt_insert('?') . ",
                    email = " . $this->crypto->encrypt_insert('?') . ",
                    is_active = 1,
                    vector_id = @init_vector
                WHERE id_user = ?
                LIMIT 1
            ", [
                $firstName,
                $lastName,
                $email,
                $appUserId,
            ]);

            return;
        }

        $db->query("
            INSERT INTO dap03_personale
            (
                id_user,
                nome,
                cognome,
                qualifica,
                tipo,
                email,
                cellulare,
                sostituto,
                titolare,
                luogo,
                is_active,
                show_in_agenda,
                show_in_posta,
                show_in_chat,
                vector_id
            )
            VALUES
            (
                ?,
                " . $this->crypto->encrypt_insert('?') . ",
                " . $this->crypto->encrypt_insert('?') . ",
                " . $this->crypto->encrypt_insert('?') . ",
                ?,
                " . $this->crypto->encrypt_insert('?') . ",
                " . $this->crypto->encrypt_insert('?') . ",
                0,
                ?,
                ?,
                1,
                1,
                1,
                1,
                @init_vector
            )
        ", [
            $appUserId,
            $firstName,
            $lastName,
            $qualifica,
            $personaleTipo,
            $email,
            $cellulare,
            (int) ($profile['titolare'] ?? 0),
            $luogo,
        ]);
    }

    /**
     * @param array<string, mixed> $platformUser
     */
    private function preferredUsername(array $platformUser): string
    {
        $email = strtolower(trim((string) ($platformUser['email'] ?? '')));
        if ($email !== '') {
            return $email;
        }

        return 'tenant.user.' . (int) ($platformUser['id_platform_user'] ?? 0);
    }

    private function resolveUniqueUsername(\CodeIgniter\Database\BaseConnection $db, string $baseUsername, int $ignoreUserId): string
    {
        $baseUsername = trim(strtolower($baseUsername));
        if ($baseUsername === '') {
            $baseUsername = 'tenant.user.' . bin2hex(random_bytes(4));
        }

        $candidate = $baseUsername;
        $suffix = 1;

        while ($this->usernameTaken($db, $candidate, $ignoreUserId)) {
            $candidate = $baseUsername . '.' . $suffix;
            $suffix++;
        }

        return $candidate;
    }

    private function usernameTaken(\CodeIgniter\Database\BaseConnection $db, string $username, int $ignoreUserId): bool
    {
        $builder = $db->table('dap01_users')
            ->select('id_user')
            ->where('username', $username);

        if ($ignoreUserId > 0) {
            $builder->where('id_user !=', $ignoreUserId);
        }

        return $builder->get(1)->getRowArray() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveRoleProfile(string $tenantRole): array
    {
        $tenantRole = trim(strtolower($tenantRole));

        if (in_array($tenantRole, ['tenant_master', 'tenant_admin'], true)) {
            return [
                'role_key' => $tenantRole,
                'tipo_user' => 1,
                'personale_tipo' => 4,
                'qualifica' => 'Amministrazione',
                'titolare' => 1,
            ];
        }

        return [
            'role_key' => $tenantRole !== '' ? $tenantRole : 'tenant_staff',
            'tipo_user' => 2,
            'personale_tipo' => 3,
            'qualifica' => 'Operatore',
            'titolare' => 0,
        ];
    }

    private function resolveUpdatedTipoUser(int $currentType, string $roleKey): int
    {
        if (in_array($roleKey, ['tenant_master', 'tenant_admin'], true)) {
            return 1;
        }

        return $currentType > 0 ? $currentType : 2;
    }

    private function fallbackFirstName(string $email): string
    {
        $localPart = trim((string) strtok($email, '@'));
        $localPart = preg_replace('/[^a-z0-9]+/i', ' ', $localPart) ?? '';
        $localPart = trim($localPart);

        return $localPart !== '' ? ucwords($localPart) : 'Utente';
    }

    private function safeName(string $value, string $fallback): string
    {
        $value = trim($value);
        return $value !== '' ? $value : $fallback;
    }

    private function randomPassword(): string
    {
        return substr(bin2hex(random_bytes(18)), 0, 24);
    }
}
