<?php

namespace App\Services;

use App\Libraries\Crypto_helper;
use App\Libraries\DatabaseConfig;
use App\Libraries\SystemUserMask;
use App\Models\PlatformUserTenantsModel;

class TenantAppSessionBootstrapService
{
    public const PLATFORM_SELECTABLE_TENANTS_SESSION_KEY = 'platform_selectable_tenants';

    private TenantCatalogService $catalog;
    private TenantContextService $tenantContext;
    private TenantDatabaseConnector $tenantDbConnector;
    private PlatformUserTenantsModel $membershipsModel;
    private \App\Models\PlatformUsersModel $platformUsersModel;
    private PlatformAuthService $platformAuth;
    private PlatformAdminAccessService $platformAdminAccess;
    private TenantAppUserProvisioningService $tenantAppUserProvisioning;
    private Crypto_helper $crypto;

    public function __construct()
    {
        $this->catalog = new TenantCatalogService();
        $this->tenantContext = new TenantContextService($this->catalog);
        $this->tenantDbConnector = new TenantDatabaseConnector();
        $this->membershipsModel = new PlatformUserTenantsModel();
        $this->platformUsersModel = new \App\Models\PlatformUsersModel();
        $this->platformAuth = new PlatformAuthService();
        $this->platformAdminAccess = new PlatformAdminAccessService($this->platformUsersModel, $this->platformAuth);
        $this->tenantAppUserProvisioning = new TenantAppUserProvisioningService();
        $this->crypto = new Crypto_helper();
    }

    /**
     * @return array<string, mixed>
     */
    public function bootstrap(int $platformUserId, int $tenantId): array
    {
        helper('portal');

        $membership = $this->catalog->getTenantMembership($platformUserId, $tenantId);
        if (!$membership) {
            throw new \RuntimeException('Accesso al tenant non autorizzato.');
        }

        if ((int) ($membership['tenant_is_active'] ?? 0) !== 1) {
            throw new \RuntimeException('Lo spazio cliente non e attivo.');
        }

        $tenantStatus = strtolower(trim((string) ($membership['tenant_status'] ?? '')));
        if (in_array($tenantStatus, ['archived', 'suspended'], true)) {
            throw new \RuntimeException('Lo spazio cliente non e disponibile per il login.');
        }

        $tenant = $this->catalog->getTenantById($tenantId);
        if (!$tenant) {
            throw new \RuntimeException('Tenant non trovato.');
        }

        $platformUser = $this->platformUsersModel->find($platformUserId);
        if (!$platformUser) {
            throw new \RuntimeException('Platform user non trovato.');
        }

        $tenantDb = $this->tenantDbConnector->connect($tenant);
        (new DatabaseConfig())->setEncryptionConfig($tenantDb);

        $appUserId = (int) ($membership['app_user_id'] ?? 0);
        if ($appUserId <= 0 || !$this->findTenantUserById($tenantDb, $appUserId)) {
            $appUserId = $this->ensureMembershipAppUser(
                $tenantDb,
                $membership,
                $platformUserId,
                $tenantId,
                (string) ($platformUser['email'] ?? '')
            );
        }

        $user = $this->findTenantUserById($tenantDb, $appUserId);
        if (!$user) {
            throw new \RuntimeException('Utente applicativo del tenant non trovato.');
        }

        $this->platformUsersModel->update($platformUserId, [
            'last_login_at' => date('Y-m-d H:i:s'),
        ]);

        if ((int) ($membership['id_platform_user_tenant'] ?? 0) > 0) {
            $membershipUpdate = [
                'invitation_status' => 'accepted',
            ];

            if (empty($membership['accepted_at'])) {
                $membershipUpdate['accepted_at'] = date('Y-m-d H:i:s');
            }

            $this->membershipsModel->update((int) $membership['id_platform_user_tenant'], $membershipUpdate);
        }

        $this->resetSessionState();
        $this->hydrateTenantSession($tenantDb, $user);

        $context = $this->tenantContext->activateTenantForPlatformUser($platformUserId, $tenantId);
        if ($context === null) {
            throw new \RuntimeException('Impossibile attivare il contesto tenant.');
        }

        session()->set([
            'isLoggedIn' => true,
            'isLoggedInConfirmed' => true,
            'platform_user_id' => $platformUserId,
            'platform_user_email' => (string) ($platformUser['email'] ?? ''),
            'platform_is_admin' => $this->platformAdminAccess->isPlatformAdmin($platformUser),
            self::PLATFORM_SELECTABLE_TENANTS_SESSION_KEY => $this->platformAuth->buildSelectableTenants(
                $this->catalog->listTenantsForPlatformUser($platformUserId)
            ),
            'loginSource' => 'platform_tenant',
        ]);

        $redirectUrl = $this->resolvePlatformTenantRedirectUrl($context, $membership);

        return [
            'redirectUrl' => $redirectUrl,
            'tenant' => $tenant,
            'tenant_context' => $context->toArray(),
            'app_user_id' => $appUserId,
            'tipoUser' => (int) ($user['tipo_user'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $membership
     */
    private function resolvePlatformTenantRedirectUrl(\App\Libraries\TenantContext $context, array $membership): string
    {
        $tenantRole = strtolower(trim((string) ($membership['tenant_role'] ?? $context->tenantRole)));
        $onboardingStatus = strtolower(trim((string) ($membership['onboarding_status'] ?? $context->onboardingStatus)));

        if ($tenantRole === 'tenant_master' && in_array($onboardingStatus, ['draft', 'setup'], true)) {
            return portal_tenant_space_url('onboarding');
        }

        if ($tenantRole === 'tenant_master') {
            return portal_tenant_space_url('funzioni');
        }

        return site_url('app');
    }

    private function resetSessionState(): void
    {
        $session = session();
        $session->regenerate(true);
        $session->remove([
            'isLoggedIn',
            'isLoggedInConfirmed',
            'is_admin',
            'admin',
            'userId',
            'id_user',
            'username',
            'tipoUser',
            'nome_visualizzato',
            'cellulare',
            'utente_sess',
            'menuData',
            'menuAgenda',
            'menuDataAdmin',
            'requireDoctorSelection',
            'selectedDoctorId',
            'forcePwdChange',
            'pwd_userId',
            'pwd_username',
            'pwd_expired_flow',
            'otp_ok_for_expired',
            'reset_flow',
            'otp_ok_for_reset',
            'otp',
            'otp_identity',
            'badge_posta_unread',
            'badge_chat_unread',
            'header_nav_items',
            'header_menu_items',
            'schede_access_map',
            'schede_data',
            'nav_refresh_meta',
            'loginSource',
            'platform_user_id',
            'platform_user_email',
            'platform_is_admin',
            self::PLATFORM_SELECTABLE_TENANTS_SESSION_KEY,
            PlatformAccessService::SESSION_KEY_PENDING_PASSWORD_SETUP,
            TenantContextService::SESSION_KEY,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findTenantUserById(\CodeIgniter\Database\BaseConnection $db, int $appUserId): ?array
    {
        if ($appUserId <= 0) {
            return null;
        }

        $row = $db->query("
            SELECT a.*,
                   CASE WHEN datascadenza <= NOW() THEN 'SCADENZA' ELSE 'OK' END AS resp
            FROM dap01_users a
            WHERE a.id_user = ?
            LIMIT 1
        ", [$appUserId])->getRowArray();

        return $row ?: null;
    }

    /**
     * @return int
     */
    private function resolveAppUserIdByEmail(\CodeIgniter\Database\BaseConnection $db, string $email): int
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return 0;
        }

        $personaleRows = $this->queryUserIdsByEncryptedEmail($db, 'dap03_personale', 'p', $email);
        $clientRows = $this->queryUserIdsByEncryptedEmail($db, 'dap02_clients', 'c', $email);

        $matches = [];
        foreach (array_merge($personaleRows, $clientRows) as $row) {
            $idUser = (int) ($row['id_user'] ?? 0);
            if ($idUser > 0 && !in_array($idUser, $matches, true)) {
                $matches[] = $idUser;
            }
        }

        return count($matches) === 1 ? $matches[0] : 0;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function queryUserIdsByEncryptedEmail(
        \CodeIgniter\Database\BaseConnection $db,
        string $table,
        string $alias,
        string $email
    ): array {
        if (!$db->tableExists($table) || !$db->fieldExists('email', $table)) {
            return [];
        }

        try {
            $query = $db->query("
                SELECT {$alias}.id_user
                FROM {$table} {$alias}
                WHERE LOWER(" . $this->crypto->decryptSenzaAlias($alias . '.email') . ") = ?
            ", [$email]);
        } catch (\Throwable $e) {
            log_message('warning', 'Tenant app session lookup by email failed: ' . $e->getMessage(), [
                'table' => $table,
                'email' => $email,
            ]);
            return [];
        }

        if (!$query) {
            log_message('warning', 'Tenant app session lookup by email returned no result object.', [
                'table' => $table,
                'email' => $email,
                'db_error' => $db->error(),
            ]);
            return [];
        }

        return $query->getResultArray();
    }

    /**
     * @param array<string, mixed> $membership
     */
    private function ensureMembershipAppUser(
        \CodeIgniter\Database\BaseConnection $tenantDb,
        array $membership,
        int $platformUserId,
        int $tenantId,
        string $email
    ): int {
        $membershipId = (int) ($membership['id_platform_user_tenant'] ?? 0);

        if ($membershipId > 0) {
            try {
                $this->tenantAppUserProvisioning->syncMembership($membershipId, false);
            } catch (\Throwable $e) {
                log_message('warning', 'Tenant app user sync failed during login bootstrap: ' . $e->getMessage(), [
                    'membership_id' => $membershipId,
                    'tenant_id' => $tenantId,
                    'platform_user_id' => $platformUserId,
                ]);
            }

            $membership = $this->catalog->getTenantMembership($platformUserId, $tenantId) ?? $membership;
            $appUserId = (int) ($membership['app_user_id'] ?? 0);
            if ($appUserId > 0 && $this->findTenantUserById($tenantDb, $appUserId)) {
                return $appUserId;
            }
        }

        $appUserId = $this->resolveAppUserIdByEmail($tenantDb, $email);
        if ($appUserId > 0 && $membershipId > 0) {
            $this->membershipsModel->update($membershipId, [
                'app_user_id' => $appUserId,
            ]);

            return $appUserId;
        }

        throw new \RuntimeException(
            'Utente applicativo del tenant non collegato automaticamente. Apri lo spazio cliente e usa Salva e provisiona, oppure imposta App user ID nello spazio cliente.'
        );
    }

    /**
     * @param array<string, mixed> $user
     */
    private function hydrateTenantSession(\CodeIgniter\Database\BaseConnection $db, array $user): void
    {
        $userType = (int) ($user['tipo_user'] ?? 0);
        $userId = (int) ($user['id_user'] ?? 0);
        $username = (string) ($user['username'] ?? '');

        session()->set([
            'userId' => $userId,
            'id_user' => $userId,
            'username' => $username,
            'tipoUser' => $userType,
        ]);

        if ($userType === 1) {
            $this->hydrateAdminSession($db, $userId);
            return;
        }

        if ($userType === 2) {
            $this->hydratePersonaleSession($db, $userId, $userType);
            return;
        }

        if ($userType === 3) {
            $this->hydrateClientSession($db, $userId);
            return;
        }

        if ($userType === 4) {
            $this->hydrateLegacySegSession($db, $userId, $userType);
            return;
        }

        throw new \RuntimeException('Tipo utente tenant non supportato.');
    }

    private function hydrateAdminSession(\CodeIgniter\Database\BaseConnection $db, int $userId): void
    {
        $admin = $db->query("
            SELECT a.tipo, a.id_user, a.id_personale,
                   " . $this->crypto->decrypt('a.nome') . ",
                   " . $this->crypto->decrypt('a.cognome') . ",
                   " . $this->crypto->decrypt('a.cellulare') . ",
                   " . $this->crypto->decrypt('a.email') . ",
                   " . $this->crypto->decrypt('a.qualifica') . ",
                   CONCAT(" . $this->crypto->decrypt_concat('a.qualifica') . ", ' ', " .
                        $this->crypto->decrypt_concat('a.cognome') . ", ' ', " .
                        $this->crypto->decrypt_concat('a.nome') . ") AS nome_completo
            FROM dap03_personale a
            WHERE a.id_user = ?
            LIMIT 1
        ", [$userId])->getRowArray();

        if (!$admin) {
            throw new \RuntimeException('Profilo amministratore tenant non trovato.');
        }

        $admin = $this->normalizeLegacyRowStrings($admin, ['nome', 'cognome', 'cellulare', 'email', 'qualifica', 'nome_completo']);

        $obj = new \stdClass();
        $obj->id_user = (int) $admin['id_user'];
        $obj->id_personale = (int) $admin['id_personale'];
        $obj->id_utente = (int) $admin['id_personale'];
        $obj->nome = (string) $admin['nome'];
        $obj->cognome = (string) $admin['cognome'];
        $obj->cellulare = (string) $admin['cellulare'];
        $obj->email = (string) $admin['email'];
        $obj->qualifica = (string) $admin['qualifica'];
        $obj->nome_completo = (string) $admin['nome_completo'];
        $obj->tipo = 1;
        $obj->tipo_pers = (int) $admin['tipo'];
        $obj->da_dottore = 0;
        $obj->tabella = 'dap10_message';
        $obj->tabella_reply = 'dap10_message_reply';

        $menuAdmin = $db->query("
            SELECT titolo_menu, class, class_icon, admin, link2 AS link
            FROM dap06_mnu
            WHERE admin = 1
            ORDER BY ordinamento ASC, id_mnu ASC
        ")->getResultArray();

        session()->set([
            'nome_visualizzato' => trim($obj->nome . ' ' . $obj->cognome),
            'cellulare' => $obj->cellulare,
            'admin' => 1,
            'is_admin' => true,
            'utente_sess' => $obj,
            'menuDataAdmin' => ['result' => $menuAdmin],
        ]);
    }

    private function hydratePersonaleSession(\CodeIgniter\Database\BaseConnection $db, int $userId, int $userType): void
    {
        $personale = $db->query("
            SELECT a.sostituto,
                   a.tipo,
                   CASE WHEN a.tipo = 1 THEN 'P'
                        WHEN a.tipo = 2 THEN 'I'
                        WHEN a.tipo = 3 THEN 'S'
                        ELSE '' END AS tipo_stringa,
                   a.id_user,
                   a.id_personale,
                   " . $this->crypto->decrypt('a.nome') . ",
                   " . $this->crypto->decrypt('a.cognome') . ",
                   " . $this->crypto->decrypt('a.cellulare') . ",
                   " . $this->crypto->decrypt('a.email') . ",
                   " . $this->crypto->decrypt('a.qualifica') . ",
                   CONCAT(" . $this->crypto->decrypt_concat('a.qualifica') . ", ' ', " .
                        $this->crypto->decrypt_concat('a.cognome') . ", ' ', " .
                        $this->crypto->decrypt_concat('a.nome') . ") AS nome_completo
            FROM dap03_personale a
            WHERE id_user = ?
            LIMIT 1
        ", [$userId])->getRowArray();

        if (!$personale) {
            throw new \RuntimeException('Profilo personale tenant non trovato.');
        }

        $personale = $this->normalizeLegacyRowStrings($personale, ['nome', 'cognome', 'cellulare', 'email', 'qualifica', 'nome_completo']);

        $obj = new \stdClass();
        $obj->id_user = (int) $personale['id_user'];
        $obj->id_personale = (int) $personale['id_personale'];
        $obj->id_utente = (int) $personale['id_personale'];
        $obj->nome = (string) $personale['nome'];
        $obj->cognome = (string) $personale['cognome'];
        $obj->cellulare = (string) $personale['cellulare'];
        $obj->email = (string) $personale['email'];
        $obj->qualifica = (string) $personale['qualifica'];
        $obj->nome_completo = (string) $personale['nome_completo'];
        $obj->tipo = $userType;
        $obj->tipo_pers = (int) $personale['tipo'];
        $obj->tipo_stringa = (string) $personale['tipo_stringa'];
        $obj->sostituto = (int) $personale['sostituto'];
        $obj->da_dottore = 0;
        $obj->tabella = 'dap10_message';
        $obj->tabella_reply = 'dap10_message_reply';

        session()->set([
            'nome_visualizzato' => $obj->nome_completo,
            'cellulare' => $obj->cellulare,
            'utente_sess' => $obj,
        ]);
    }

    private function hydrateClientSession(\CodeIgniter\Database\BaseConnection $db, int $userId): void
    {
        $client = $db->query("
            SELECT a.id_user,
                   a.id_client,
                   a.id_personale,
                   " . $this->crypto->decrypt('a.nome') . ",
                   " . $this->crypto->decrypt('a.cognome') . ",
                   " . $this->crypto->decrypt('a.cellulare') . ",
                   " . $this->crypto->decrypt('a.email') . ",
                   " . $this->crypto->decrypt('a.indirizzo') . ",
                   " . $this->crypto->decrypt('a.citta') . ",
                   " . $this->crypto->decrypt('a.provincia') . ",
                   " . $this->crypto->decrypt('a.codice_fiscale') . "
            FROM dap02_clients a
            WHERE a.id_user = ?
            LIMIT 1
        ", [$userId])->getRowArray();

        if (!$client) {
            throw new \RuntimeException('Profilo cliente tenant non trovato.');
        }

        $client = $this->normalizeLegacyRowStrings($client, ['nome', 'cognome', 'cellulare', 'email', 'indirizzo', 'citta', 'provincia', 'codice_fiscale']);

        $doctorLink = $this->resolvePreferredDoctorLink($db, (int) $client['id_client'], (int) ($client['id_personale'] ?? 0));

        $obj = new \stdClass();
        $obj->id_user = (int) $client['id_user'];
        $obj->id_client = (int) $client['id_client'];
        $obj->nome = (string) $client['nome'];
        $obj->cognome = (string) $client['cognome'];
        $obj->cellulare = (string) $client['cellulare'];
        $obj->email = (string) $client['email'];
        $obj->indirizzo = (string) $client['indirizzo'];
        $obj->citta = (string) $client['citta'];
        $obj->provincia = (string) $client['provincia'];
        $obj->codice_fiscale = (string) $client['codice_fiscale'];
        $obj->id_doctor = (int) ($doctorLink['id_dot'] ?? 0);
        $obj->tipo = 3;
        $obj->tabella = ' dap10_message';
        $obj->tabella_reply = ' dap10_message_reply';
        $obj->da_dottore = 1;
        $obj->id_utente = (int) $client['id_client'];

        if (SystemUserMask::isMaskedClientId((int) $obj->id_client)) {
            $obj->nome = SystemUserMask::SYSTEM_USER_LABEL;
            $obj->cognome = '';
            $obj->nome_completo = SystemUserMask::SYSTEM_USER_LABEL;
        }

        $displayName = SystemUserMask::getMaskedClientDisplayName(
            (int) $obj->id_client,
            trim($obj->nome . ' ' . $obj->cognome)
        );

        session()->set([
            'nome_visualizzato' => $displayName,
            'cellulare' => $obj->cellulare,
            'utente_sess' => $obj,
        ]);
    }

    private function hydrateLegacySegSession(\CodeIgniter\Database\BaseConnection $db, int $userId, int $userType): void
    {
        $seg = $db->query(
            'SELECT id_user, id_inf, nome, cognome FROM dap13_seg WHERE id_user = ? LIMIT 1',
            [$userId]
        )->getRowArray();

        if (!$seg) {
            throw new \RuntimeException('Profilo segreteria legacy tenant non trovato.');
        }

        $dotRows = $db->query(
            'SELECT id_dot FROM dap14_seg_dot WHERE id_inf = ?',
            [(int) $seg['id_inf']]
        )->getResultArray();

        $idPersonale = array_map(static fn(array $row): int => (int) $row['id_dot'], $dotRows);

        $obj = new \stdClass();
        $obj->id_user = (int) $seg['id_user'];
        $obj->nome = (string) $seg['nome'];
        $obj->cognome = (string) $seg['cognome'];
        $obj->id_inf = (int) $seg['id_inf'];
        $obj->tipo = $userType;
        $obj->tabella = ' dap10_message_seg';
        $obj->tabella_reply = ' dap10_message_reply_seg';
        $obj->da_dottore = 0;
        $obj->id_personale = empty($idPersonale) ? '' : '(' . implode(',', $idPersonale) . ')';

        session()->set([
            'nome_visualizzato' => trim($obj->nome . ' ' . $obj->cognome),
            'utente_sess' => $obj,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolvePreferredDoctorLink(\CodeIgniter\Database\BaseConnection $db, int $idClient, int $preferredDoctorId = 0): array
    {
        $rows = $db->table('dap09_client_doctor')
            ->select('id_users_doctor, id_dot')
            ->where('id_client', $idClient)
            ->orderBy('id_users_doctor', 'DESC')
            ->get()
            ->getResultArray();

        $primaryRow = null;
        $selected = null;

        foreach ($rows as $row) {
            $doctorId = (int) ($row['id_dot'] ?? 0);

            if ($selected === null && $preferredDoctorId > 0 && $doctorId === $preferredDoctorId) {
                $selected = $row;
            }

            if ($primaryRow === null && $preferredDoctorId > 0 && $doctorId === $preferredDoctorId) {
                $primaryRow = $row;
            }
        }

        if ($selected === null) {
            $selected = $primaryRow ?? ($rows[0] ?? null);
        }

        return [
            'id_dot' => $selected ? (int) ($selected['id_dot'] ?? 0) : max(0, $preferredDoctorId),
            'relation_count' => count($rows),
        ];
    }

    private function normalizeLegacyString($value)
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }

        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        return mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
    }

    private function normalizeLegacyRowStrings(array $row, array $keys): array
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = $this->normalizeLegacyString($row[$key]);
            }
        }

        return $row;
    }
}
