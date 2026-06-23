<?php

namespace App\Services;

use App\Models\PlatformTenantsModel;
use App\Models\PlatformUserTenantsModel;
use App\Models\PlatformUsersModel;
use Config\Database;

class DemoAccessService
{
    public const SESSION_KEY_ACTIVE = 'demo_public_session';
    public const SESSION_KEY_CURRENT = 'demo_public_current_account';
    public const SESSION_KEY_SWITCH_ACCOUNTS = 'demo_public_switch_accounts';

    private const DEMO_TENANT_KEY = 'demo_ambulatoriofacile';
    private const DEMO_TENANT_STORAGE_KEY = 'demo-ambulatoriofacile';
    private const DEMO_TENANT_NAME = 'Demo AmbulatorioFacile';
    private const DEMO_TENANT_MASTER_EMAIL = 'demo.tenant.master@ambulatoriofacile.demo';
    private const DEMO_TENANT_USER_EMAIL = 'demo.tenant.user@ambulatoriofacile.demo';
    private const DEMO_PLATFORM_PASSWORD = 'DemoTenantNoLogin2026!';

    private \CodeIgniter\Database\BaseConnection $db;
    private \CodeIgniter\Database\BaseConnection $platformDb;

    public function __construct(
        ?\CodeIgniter\Database\BaseConnection $db = null,
        ?\CodeIgniter\Database\BaseConnection $platformDb = null
    ) {
        $this->db = $db ?? Database::connect();
        $this->platformDb = $platformDb ?? Database::connect('platform');
    }

    public function isPublicAccessEnabled(): bool
    {
        $raw = env('DEMO_PUBLIC_ROLE_SWITCH_ENABLED');
        if ($raw === null || $raw === false || trim((string) $raw) === '') {
            $raw = env('demo.publicRoleSwitchEnabled');
        }

        return in_array(strtolower(trim((string) $raw)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function presentationAccounts(): array
    {
        return [
            [
                'group_key' => 'tenant',
                'group_title' => 'Spazio cliente senza login',
                'group_note' => 'Qui provi la nuova area tenant direttamente dalla demo, senza password e senza uscire dal percorso pubblico.',
                'account_type' => 'platform_tenant',
                'role' => 'Tenant master',
                'username' => 'demo.tenant.master',
                'candidate_usernames' => ['demo.tenant.master'],
                'linked_legacy_candidates' => ['demo.admin'],
                'platform_email' => self::DEMO_TENANT_MASTER_EMAIL,
                'platform_first_name' => 'Giulia',
                'platform_last_name' => 'Conti',
                'tenant_role' => 'tenant_master',
                'label' => 'Tenant master Demo Studio',
                'note' => 'Apre lo spazio cliente per gestire funzioni attive, utenti del tenant e console operativa del cliente.',
                'redirect_route' => 'spazio/funzioni',
                'prefer_account_redirect' => true,
                'scenarios' => ['funzioni spazio', 'gestione utenti', 'tenant master'],
            ],
            [
                'group_key' => 'tenant',
                'group_title' => 'Spazio cliente senza login',
                'group_note' => 'Qui provi la nuova area tenant direttamente dalla demo, senza password e senza uscire dal percorso pubblico.',
                'account_type' => 'platform_tenant',
                'role' => 'Utente tenant',
                'username' => 'demo.tenant.user',
                'candidate_usernames' => ['demo.tenant.user'],
                'linked_legacy_candidates' => ['demo.segreteria', 'demo.frontdesk.med'],
                'platform_email' => self::DEMO_TENANT_USER_EMAIL,
                'platform_first_name' => 'Sara',
                'platform_last_name' => 'Colombo',
                'tenant_role' => 'tenant_staff',
                'label' => 'Utente normale dello spazio',
                'note' => 'Simula un accesso tenant standard: entra nell operativita dello spazio senza permessi master e poi puoi cambiare ruolo dalla demo.',
                'redirect_route' => 'agenda',
                'prefer_account_redirect' => true,
                'scenarios' => ['utente normale', 'operativita spazio', 'agenda tenant'],
            ],
            [
                'group_key' => 'main',
                'group_title' => 'Ruoli operativi principali',
                'group_note' => 'Ruoli immediati per far provare agenda, posta, chat e portale paziente con dati fittizi.',
                'account_type' => 'legacy',
                'role' => 'Segreteria',
                'username' => 'demo.segreteria',
                'candidate_usernames' => ['demo.segreteria', 'demo.frontdesk.med'],
                'label' => 'Segreteria Colombo Sara',
                'note' => 'Ideale per agenda segreteria, conferme, spostamenti, posta segreteria e chat con i professionisti.',
                'otp' => '2510',
                'redirect_route' => 'agenda',
                'scenarios' => ['agenda segreteria', 'posta segreteria', 'chat segreteria'],
            ],
            [
                'group_key' => 'main',
                'group_title' => 'Ruoli operativi principali',
                'group_note' => 'Ruoli immediati per far provare agenda, posta, chat e portale paziente con dati fittizi.',
                'account_type' => 'legacy',
                'role' => 'Dottore',
                'username' => 'demo.dietista',
                'candidate_usernames' => ['demo.dietista', 'demo.cardiologia'],
                'label' => 'Dietista Rossi Elena',
                'note' => 'Mostra la vista professionista per agenda dottore, posta dottore, chat interna e follow-up sul paziente.',
                'otp' => '2510',
                'redirect_route' => 'agenda',
                'scenarios' => ['agenda dottore', 'posta dottore', 'chat dottore'],
            ],
            [
                'group_key' => 'main',
                'group_title' => 'Ruoli operativi principali',
                'group_note' => 'Ruoli immediati per far provare agenda, posta, chat e portale paziente con dati fittizi.',
                'account_type' => 'legacy',
                'role' => 'Paziente',
                'username' => 'demo.portal.nutri',
                'candidate_usernames' => ['demo.portal.nutri', 'demo.portal.med'],
                'label' => 'Bianchi Laura',
                'note' => 'Chiude la prova dal lato utente finale con area paziente e posta paziente su dati demo separati.',
                'redirect_route' => 'app',
                'scenarios' => ['portale paziente', 'posta paziente', 'area utente'],
            ],
            [
                'group_key' => 'main',
                'group_title' => 'Ruoli operativi principali',
                'group_note' => 'Ruoli immediati per far provare agenda, posta, chat e portale paziente con dati fittizi.',
                'account_type' => 'legacy',
                'role' => 'Admin demo',
                'username' => 'demo.admin',
                'candidate_usernames' => ['demo.admin'],
                'label' => 'Admin demo Conti Giulia',
                'note' => 'Vista completa per aprire moduli, configurazioni e quadro generale del prodotto senza passare dal login.',
                'redirect_route' => 'admin',
                'scenarios' => ['overview prodotto', 'moduli e ruoli', 'configurazione'],
            ],
            [
                'group_key' => 'main',
                'group_title' => 'Ruoli operativi principali',
                'group_note' => 'Ruoli immediati per far provare agenda, posta, chat e portale paziente con dati fittizi.',
                'account_type' => 'legacy',
                'role' => 'Collaboratrice',
                'username' => 'demo.nutrizionista',
                'candidate_usernames' => ['demo.nutrizionista'],
                'label' => 'Biologa nutrizionista Riva Marta',
                'note' => 'Utile per provare permessi differenziati e convivenza tra piu professionisti nello stesso studio demo.',
                'redirect_route' => 'agenda',
                'scenarios' => ['permessi differenziati', 'secondo professionista', 'agenda condivisa'],
            ],
            [
                'group_key' => 'sport',
                'group_title' => 'Ruoli sport e rehab',
                'group_note' => 'Percorso parallelo utile per far vedere che la stessa base regge un secondo scenario demo gia popolato.',
                'account_type' => 'legacy',
                'role' => 'Fisioterapista',
                'username' => 'demo.fisio1',
                'candidate_usernames' => ['demo.fisio1'],
                'label' => 'Fisioterapista Riva Marco',
                'note' => 'Percorso sport rehab con agenda dedicata, visite e pazienti del centro.',
                'redirect_route' => 'agenda',
                'scenarios' => ['sport rehab', 'agenda fisioterapia', 'presa in carico'],
            ],
            [
                'group_key' => 'sport',
                'group_title' => 'Ruoli sport e rehab',
                'group_note' => 'Percorso parallelo utile per far vedere che la stessa base regge un secondo scenario demo gia popolato.',
                'account_type' => 'legacy',
                'role' => 'Osteopata',
                'username' => 'demo.osteopata',
                'candidate_usernames' => ['demo.osteopata'],
                'label' => 'Osteopata Pace Lorenzo',
                'note' => 'Secondo professionista del centro sport per verificare convivenza e distribuzione operativa tra operatori.',
                'redirect_route' => 'agenda',
                'scenarios' => ['secondo operatore', 'team sport', 'pazienti condivisi'],
            ],
            [
                'group_key' => 'sport',
                'group_title' => 'Ruoli sport e rehab',
                'group_note' => 'Percorso parallelo utile per far vedere che la stessa base regge un secondo scenario demo gia popolato.',
                'account_type' => 'legacy',
                'role' => 'Front desk sport',
                'username' => 'demo.frontdesk.sport',
                'candidate_usernames' => ['demo.frontdesk.sport'],
                'label' => 'Coordinamento Sala Irene',
                'note' => 'Mostra coordinamento operativo, assegnazione slot e gestione delle richieste del centro sportivo.',
                'redirect_route' => 'agenda',
                'scenarios' => ['front desk', 'coordinamento', 'agenda centro'],
            ],
            [
                'group_key' => 'sport',
                'group_title' => 'Ruoli sport e rehab',
                'group_note' => 'Percorso parallelo utile per far vedere che la stessa base regge un secondo scenario demo gia popolato.',
                'account_type' => 'legacy',
                'role' => 'Paziente sport',
                'username' => 'demo.portal.sport',
                'candidate_usernames' => ['demo.portal.sport'],
                'label' => 'Marini Chiara',
                'note' => 'Vista paziente del percorso sportivo, utile per chiudere la prova anche sul secondo scenario.',
                'redirect_route' => 'app',
                'scenarios' => ['area paziente sport', 'visione esterna', 'percorso completo'],
            ],
        ];
    }

    public function buildEntryUrl(string $username): string
    {
        helper('url');
        return site_url('access/entra') . '?' . http_build_query(['u' => trim($username)]);
    }

    public function accessLandingUrl(): string
    {
        helper('url');
        return site_url('access');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findPresentationAccount(string $requestedUsername): ?array
    {
        $requestedUsername = $this->normalizeUsername($requestedUsername);
        if ($requestedUsername === '') {
            return null;
        }

        foreach ($this->presentationAccounts() as $account) {
            if (in_array($requestedUsername, $this->normalizedCandidateUsernames($account), true)) {
                return $account;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function loginAccount(string $requestedUsername): array
    {
        if (!$this->isPublicAccessEnabled()) {
            throw new \RuntimeException('Accesso demo diretto non abilitato.');
        }

        $account = $this->findPresentationAccount($requestedUsername);
        if ($account === null) {
            throw new \RuntimeException('Ruolo demo non disponibile.');
        }

        return strtolower((string) ($account['account_type'] ?? 'legacy')) === 'platform_tenant'
            ? $this->loginPlatformTenantAccount($account)
            : $this->loginLegacyAccount($account);
    }

    private function loginLegacyAccount(array $account): array
    {
        $resolvedUsername = $this->resolveExistingUsernameForAccount($account);
        if ($resolvedUsername === null) {
            throw new \RuntimeException('Account demo non trovato nel database attivo.');
        }

        $user = $this->findLegacyUserByUsername($resolvedUsername);
        if ($user === null) {
            throw new \RuntimeException('Account demo non trovato nel database attivo.');
        }

        session()->remove([
            'platform_user_id',
            'platform_user_email',
            'platform_is_admin',
            TenantAppSessionBootstrapService::PLATFORM_SELECTABLE_TENANTS_SESSION_KEY,
            TenantContextService::SESSION_KEY,
        ]);

        $result = (new LegacyLoginHandoffService())->bootstrapDemoSessionByUserId((int) $user['id_user'], $resolvedUsername);
        if (($result['resp'] ?? 'KO') !== 'OK') {
            throw new \RuntimeException('Impossibile aprire la sessione demo richiesta.');
        }

        $this->decorateCurrentSession($account, $resolvedUsername);

        return [
            'account' => $account,
            'redirectUrl' => $this->resolveRedirectUrl($account, $result),
            'resolved_username' => $resolvedUsername,
        ];
    }

    private function loginPlatformTenantAccount(array $account): array
    {
        $setup = $this->ensurePlatformDemoAccount($account);
        $result = (new TenantAppSessionBootstrapService())->bootstrap($setup['platform_user_id'], $setup['tenant_id']);
        $this->decorateCurrentSession($account, $setup['resolved_username']);

        return [
            'account' => $account,
            'redirectUrl' => $this->resolveRedirectUrl($account, $result),
            'resolved_username' => $setup['resolved_username'],
        ];
    }

    private function resolveRedirectUrl(array $account, array $result): string
    {
        helper('url');

        $fallback = trim((string) ($account['redirect_route'] ?? ''));
        if (!empty($account['prefer_account_redirect']) && $fallback !== '') {
            return site_url($fallback);
        }

        $serviceRedirect = trim((string) ($result['redirectUrl'] ?? ''));
        if ($serviceRedirect !== '' && strtolower($serviceRedirect) !== 'auth') {
            if (preg_match('#^https?://#i', $serviceRedirect) === 1) {
                return $serviceRedirect;
            }

            $serviceRedirect = trim($serviceRedirect, '/');
            if ($serviceRedirect !== '') {
                return site_url($serviceRedirect);
            }
        }

        return $fallback !== '' ? site_url($fallback) : site_url('app');
    }

    private function decorateCurrentSession(array $account, string $resolvedUsername): void
    {
        $switchAccounts = [];
        foreach ($this->presentationAccounts() as $candidate) {
            $switchAccounts[] = [
                'username' => (string) ($candidate['username'] ?? ''),
                'role' => (string) ($candidate['role'] ?? ''),
                'label' => (string) ($candidate['label'] ?? ''),
                'entry_url' => $this->buildEntryUrl((string) ($candidate['username'] ?? '')),
                'is_current' => (string) ($candidate['username'] ?? '') === (string) ($account['username'] ?? ''),
            ];
        }

        session()->set([
            self::SESSION_KEY_ACTIVE => true,
            self::SESSION_KEY_CURRENT => [
                'username' => (string) ($account['username'] ?? ''),
                'session_username' => $resolvedUsername,
                'role' => (string) ($account['role'] ?? ''),
                'label' => (string) ($account['label'] ?? ''),
                'note' => (string) ($account['note'] ?? ''),
                'access_url' => $this->accessLandingUrl(),
            ],
            self::SESSION_KEY_SWITCH_ACCOUNTS => $switchAccounts,
            'loginSource' => 'demo_public_access',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function ensurePlatformDemoAccount(array $account): array
    {
        foreach (['platform_packages', 'platform_tenants', 'platform_users', 'platform_user_tenants'] as $table) {
            if (!$this->platformDb->tableExists($table)) {
                throw new \RuntimeException('Catalogo platform non pronto per la demo tenant.');
            }
        }

        $resolvedUsername = $this->resolveLinkedLegacyUsername($account);
        $legacyUser = $resolvedUsername !== null ? $this->findLegacyUserByUsername($resolvedUsername) : null;
        if ($legacyUser === null) {
            throw new \RuntimeException('Utente operativo demo non trovato per il ruolo tenant richiesto.');
        }

        $tenant = $this->ensurePlatformDemoTenant();
        $platformUser = $this->ensurePlatformDemoUser($account);
        $membership = $this->ensurePlatformDemoMembership(
            (int) $platformUser['id_platform_user'],
            (int) $tenant['id_tenant'],
            trim((string) ($account['tenant_role'] ?? 'tenant_staff')) ?: 'tenant_staff',
            (int) $legacyUser['id_user']
        );

        return [
            'tenant_id' => (int) $tenant['id_tenant'],
            'platform_user_id' => (int) $platformUser['id_platform_user'],
            'membership_id' => (int) $membership['id_platform_user_tenant'],
            'resolved_username' => $resolvedUsername,
        ];
    }

    private function ensurePlatformDemoTenant(): array
    {
        $model = new PlatformTenantsModel();
        $tenant = $model->findByTenantKey(self::DEMO_TENANT_KEY);
        $db = new \Config\Database();
        $default = $db->default;
        $passwordRef = trim((string) (env('tenant.provisioning.runtimePasswordRef') ?: env('TENANT_PROVISIONING_RUNTIME_PASSWORD_REF') ?: ''));
        if ($passwordRef === '') {
            foreach (['database.default.password', 'DB_PASSWORD', 'database.platform.password', 'PLATFORM_DB_PASSWORD'] as $candidate) {
                $value = env($candidate);
                if ($value !== null && $value !== '') {
                    $passwordRef = $candidate;
                    break;
                }
            }
        }

        if ($passwordRef === '') {
            throw new \RuntimeException('Riferimento password DB tenant non configurato per la demo.');
        }

        $packageRow = $this->platformDb->table('platform_packages')
            ->select('id_package')
            ->whereIn('package_code', ['team', 'base', 'enterprise'])
            ->orderBy('FIELD(package_code, "team", "base", "enterprise")', '', false)
            ->get(1)
            ->getRowArray();

        if (!is_array($packageRow) || (int) ($packageRow['id_package'] ?? 0) <= 0) {
            throw new \RuntimeException('Nessun pacchetto platform disponibile per la demo tenant.');
        }

        $payload = [
            'tenant_key' => self::DEMO_TENANT_KEY,
            'tenant_name' => self::DEMO_TENANT_NAME,
            'legal_name' => self::DEMO_TENANT_NAME,
            'status' => 'active',
            'id_package' => (int) $packageRow['id_package'],
            'onboarding_status' => 'ready',
            'login_hint' => 'Spazio demo pubblico senza login iniziale',
            'db_host' => trim((string) ($default['hostname'] ?? env('database.default.hostname', 'localhost'))),
            'db_port' => (int) ($default['port'] ?? env('database.default.port', 3306)),
            'db_name' => trim((string) ($default['database'] ?? env('database.default.database', ''))),
            'db_username' => trim((string) ($default['username'] ?? env('database.default.username', ''))),
            'db_password_ref' => $passwordRef,
            'db_driver' => trim((string) ($default['DBDriver'] ?? env('database.default.DBDriver', 'MySQLi'))),
            'db_prefix' => (string) ($default['DBPrefix'] ?? env('database.default.DBPrefix', '')),
            'storage_key' => self::DEMO_TENANT_STORAGE_KEY,
            'feature_profile' => 'demo',
            'metadata_json' => json_encode(['source' => 'demo_public_access'], JSON_UNESCAPED_SLASHES),
            'is_active' => 1,
        ];

        if ($payload['db_host'] === '' || $payload['db_name'] === '' || $payload['db_username'] === '') {
            throw new \RuntimeException('Configurazione DB tenant demo incompleta.');
        }

        if ($tenant === null) {
            $model->insert($payload);
            return (array) $model->find((int) $model->getInsertID());
        }

        $model->update((int) $tenant['id_tenant'], $payload);
        return (array) $model->find((int) $tenant['id_tenant']);
    }

    private function ensurePlatformDemoUser(array $account): array
    {
        $model = new PlatformUsersModel();
        $email = strtolower(trim((string) ($account['platform_email'] ?? '')));
        if ($email === '') {
            throw new \RuntimeException('Email platform demo mancante.');
        }

        $user = $model->findByEmailInsensitive($email);
        $payload = [
            'first_name' => trim((string) ($account['platform_first_name'] ?? 'Demo')),
            'last_name' => trim((string) ($account['platform_last_name'] ?? 'User')),
            'status' => 'active',
            'must_reset_password' => 0,
            'email_verified_at' => date('Y-m-d H:i:s'),
        ];

        if ($user === null) {
            $payload['email'] = $email;
            $payload['password_hash'] = password_hash(self::DEMO_PLATFORM_PASSWORD, PASSWORD_DEFAULT);
            $model->insert($payload);
            return (array) $model->find((int) $model->getInsertID());
        }

        $model->update((int) $user['id_platform_user'], $payload);
        return (array) $model->find((int) $user['id_platform_user']);
    }

    private function ensurePlatformDemoMembership(int $platformUserId, int $tenantId, string $tenantRole, int $appUserId): array
    {
        $model = new PlatformUserTenantsModel();
        $membership = $model->findMembership($platformUserId, $tenantId);
        $payload = [
            'id_platform_user' => $platformUserId,
            'id_tenant' => $tenantId,
            'tenant_role' => $tenantRole,
            'app_user_id' => $appUserId,
            'is_default' => 1,
            'is_owner' => $tenantRole === 'tenant_master' ? 1 : 0,
            'invitation_status' => 'accepted',
            'invited_at' => $membership['invited_at'] ?? date('Y-m-d H:i:s'),
            'accepted_at' => $membership['accepted_at'] ?? date('Y-m-d H:i:s'),
        ];

        if ($membership === null) {
            $model->insert($payload);
            return (array) $model->find((int) $model->getInsertID());
        }

        $model->update((int) $membership['id_platform_user_tenant'], $payload);
        return (array) $model->find((int) $membership['id_platform_user_tenant']);
    }

    /**
     * @return list<string>
     */
    private function normalizedCandidateUsernames(array $account): array
    {
        $candidates = $account['candidate_usernames'] ?? [(string) ($account['username'] ?? '')];
        $normalized = [];

        foreach ((array) $candidates as $candidate) {
            $candidate = $this->normalizeUsername((string) $candidate);
            if ($candidate !== '' && !in_array($candidate, $normalized, true)) {
                $normalized[] = $candidate;
            }
        }

        return $normalized;
    }

    private function resolveExistingUsernameForAccount(array $account): ?string
    {
        foreach ($this->normalizedCandidateUsernames($account) as $candidate) {
            if ($this->findLegacyUserByUsername($candidate) !== null) {
                return $candidate;
            }
        }

        return null;
    }

    private function resolveLinkedLegacyUsername(array $account): ?string
    {
        foreach ((array) ($account['linked_legacy_candidates'] ?? []) as $candidate) {
            $candidate = $this->normalizeUsername((string) $candidate);
            if ($candidate !== '' && $this->findLegacyUserByUsername($candidate) !== null) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findLegacyUserByUsername(string $username): ?array
    {
        $username = $this->normalizeUsername($username);
        if ($username === '') {
            return null;
        }

        $row = $this->db->table('dap01_users')
            ->select('id_user, username, tipo_user, datascadenza')
            ->where('username', $username)
            ->get(1)
            ->getRowArray();

        return is_array($row) ? $row : null;
    }

    private function normalizeUsername(string $username): string
    {
        return strtolower(trim($username));
    }
}
