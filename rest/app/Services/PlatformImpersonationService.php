<?php

namespace App\Services;

use App\Libraries\Crypto_helper;
use App\Libraries\DatabaseConfig;
use App\Libraries\SystemUserMask;
use App\Models\PlatformImpersonationLogsModel;
use App\Models\PlatformTenantsModel;
use App\Models\PlatformUserTenantsModel;
use App\Models\PlatformUsersModel;
use CodeIgniter\Database\BaseConnection;

class PlatformImpersonationService
{
    public const SESSION_KEY = 'platform_impersonation';
    private const DEFAULT_TTL_MINUTES = 30;
    private const MIN_REASON_LENGTH = 8;

    private PlatformAdminAccessService $platformAdminAccess;
    private PlatformAuthService $platformAuth;
    private PlatformUsersModel $platformUsersModel;
    private PlatformUserTenantsModel $membershipsModel;
    private PlatformTenantsModel $tenantsModel;
    private PlatformImpersonationLogsModel $logsModel;
    private TenantCatalogService $tenantCatalog;
    private TenantDatabaseConnector $tenantDbConnector;
    private DatabaseConfig $databaseConfig;
    private Crypto_helper $crypto;
    private BaseConnection $platformDb;

    public function __construct()
    {
        $this->platformUsersModel = new PlatformUsersModel();
        $this->membershipsModel = new PlatformUserTenantsModel();
        $this->tenantsModel = new PlatformTenantsModel();
        $this->logsModel = new PlatformImpersonationLogsModel();
        $this->tenantCatalog = new TenantCatalogService();
        $this->tenantDbConnector = new TenantDatabaseConnector();
        $this->databaseConfig = new DatabaseConfig();
        $this->crypto = new Crypto_helper();
        $this->platformAuth = new PlatformAuthService($this->platformUsersModel, $this->tenantCatalog);
        $this->platformAdminAccess = new PlatformAdminAccessService($this->platformUsersModel, $this->platformAuth);
        $this->platformDb = \Config\Database::connect('platform');
    }

    /**
     * @return array<string, mixed>
     */
    public function buildTenantDashboard(int $tenantId, string $query = ''): array
    {
        $tenant = $this->requireTenant($tenantId);
        $tenantDb = $this->tenantDbConnector->connect($tenant);
        $this->databaseConfig->setEncryptionConfig($tenantDb);

        $accounts = $this->loadTenantAccounts($tenantDb, $tenantId);
        $query = trim($query);
        if ($query !== '') {
            $accounts = array_values(array_filter($accounts, static function (array $account) use ($query): bool {
                $haystacks = [
                    (string) ($account['username'] ?? ''),
                    (string) ($account['full_name'] ?? ''),
                    (string) ($account['email'] ?? ''),
                    (string) ($account['cellulare'] ?? ''),
                    (string) ($account['user_type_label'] ?? ''),
                    (string) ($account['tenant_role_label'] ?? ''),
                ];

                foreach ($haystacks as $haystack) {
                    if ($haystack !== '' && mb_stripos($haystack, $query) !== false) {
                        return true;
                    }
                }

                return false;
            }));
        }

        return [
            'tenant' => $tenant,
            'accounts' => $accounts,
            'summary' => $this->buildSummary($accounts),
            'runtime_warning' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function startImpersonation(int $tenantId, int $appUserId, string $reason, string $originPath = ''): array
    {
        helper(['portal', 'session_auth']);

        $platformUser = $this->requireCurrentPlatformAdmin();
        $tenant = $this->requireTenant($tenantId);
        $tenantDb = $this->tenantDbConnector->connect($tenant);
        $this->databaseConfig->setEncryptionConfig($tenantDb);

        $targetAccount = $this->loadTenantAccountById($tenantDb, $tenantId, $appUserId);
        if ($targetAccount === null) {
            throw new \RuntimeException('Account dello spazio non trovato.');
        }

        if (!(bool) ($targetAccount['is_runtime_active'] ?? true)) {
            throw new \RuntimeException('L account selezionato non e attivo e non puo essere impersonato.');
        }

        $reason = $this->normalizeReason($reason);
        $this->closeActiveImpersonation('replaced', false);

        $startedAtTs = time();
        $expiresAtTs = $startedAtTs + $this->ttlSeconds();
        $sessionToken = bin2hex(random_bytes(16));
        $originPath = trim($originPath) !== '' ? trim($originPath) : portal_platform_url('impersonificazione');

        $logId = (int) $this->logsModel->insert([
            'id_platform_user' => (int) ($platformUser['id_platform_user'] ?? 0),
            'id_tenant' => $tenantId,
            'app_user_id' => $appUserId,
            'target_username' => (string) ($targetAccount['username'] ?? ''),
            'target_display_name' => (string) ($targetAccount['full_name'] ?? ''),
            'target_tenant_role' => (string) ($targetAccount['tenant_role'] ?? ''),
            'target_tipo_user' => (int) ($targetAccount['tipo_user'] ?? 0),
            'reason_text' => $reason,
            'session_token' => $sessionToken,
            'origin_login_source' => (string) (session()->get('loginSource') ?? ''),
            'origin_path' => $originPath,
            'origin_ip' => (string) service('request')->getIPAddress(),
            'origin_user_agent' => substr((string) service('request')->getUserAgent(), 0, 255),
            'started_at' => date('Y-m-d H:i:s', $startedAtTs),
            'expires_at' => date('Y-m-d H:i:s', $expiresAtTs),
            'metadata_json' => json_encode([
                'target_email' => (string) ($targetAccount['email'] ?? ''),
                'target_user_type_label' => (string) ($targetAccount['user_type_label'] ?? ''),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ], true);

        if ($logId <= 0) {
            throw new \RuntimeException('Impossibile registrare l audit della sessione delegata.');
        }

        $tenantSession = new LegacyTenantSessionService();

        try {
            $tenantSession->queuePendingRuntime(
                $tenant,
                $appUserId,
                (int) ($targetAccount['tipo_user'] ?? 0),
                'platform_impersonation'
            );

            $handoff = new LegacyLoginHandoffService($tenantDb);
            $result = $handoff->bootstrapDemoSessionByUserId($appUserId, (string) ($targetAccount['username'] ?? ''));

            if (($result['resp'] ?? 'KO') !== 'OK') {
                throw new \RuntimeException('Impossibile aprire la sessione delegata per l account scelto.');
            }

            $tenantSession->activatePendingRuntime();
            session()->set(self::SESSION_KEY, [
                'log_id' => $logId,
                'session_token' => $sessionToken,
                'platform_user_id' => (int) ($platformUser['id_platform_user'] ?? 0),
                'platform_user_email' => (string) ($platformUser['email'] ?? ''),
                'platform_user_name' => $this->platformUserDisplayName($platformUser),
                'tenant_id' => $tenantId,
                'tenant_name' => (string) ($tenant['tenant_name'] ?? ''),
                'tenant_key' => (string) ($tenant['tenant_key'] ?? ''),
                'target_app_user_id' => $appUserId,
                'target_username' => (string) ($targetAccount['username'] ?? ''),
                'target_display_name' => (string) ($targetAccount['full_name'] ?? ''),
                'target_tipo_user' => (int) ($targetAccount['tipo_user'] ?? 0),
                'target_tenant_role' => (string) ($targetAccount['tenant_role'] ?? ''),
                'target_tenant_role_label' => (string) ($targetAccount['tenant_role_label'] ?? ''),
                'target_user_type_label' => (string) ($targetAccount['user_type_label'] ?? ''),
                'reason' => $reason,
                'started_at' => $startedAtTs,
                'expires_at' => $expiresAtTs,
                'return_url' => $originPath,
            ]);

            log_message('info', '[PlatformImpersonationService] Sessione delegata avviata: platform_user_id={platformUserId}, tenant_id={tenantId}, app_user_id={appUserId}', [
                'platformUserId' => (int) ($platformUser['id_platform_user'] ?? 0),
                'tenantId' => $tenantId,
                'appUserId' => $appUserId,
            ]);

            return [
                'account' => $targetAccount,
                'redirectUrl' => portal_operational_home_url(),
            ];
        } catch (\Throwable $e) {
            $tenantSession->clearAllPending();
            $this->closeLogById($logId, 'failed');
            session()->remove(self::SESSION_KEY);
            throw $e;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function currentImpersonation(): ?array
    {
        $payload = session()->get(self::SESSION_KEY);
        return is_array($payload) && $payload !== [] ? $payload : null;
    }

    public function isImpersonating(): bool
    {
        return $this->currentImpersonation() !== null;
    }

    public function isExpired(): bool
    {
        $payload = $this->currentImpersonation();
        if ($payload === null) {
            return false;
        }

        return (int) ($payload['expires_at'] ?? 0) > 0 && time() >= (int) $payload['expires_at'];
    }

    /**
     * @return array<string, mixed>
     */
    public function stopImpersonation(string $endReason = 'manual', bool $restorePlatformSession = true): array
    {
        helper('portal');

        $payload = $this->currentImpersonation();
        if ($payload === null) {
            return [
                'restored' => false,
                'redirectUrl' => portal_platform_url('impersonificazione'),
            ];
        }

        $this->closeLogByPayload($payload, $endReason);
        session()->remove(self::SESSION_KEY);

        $restored = false;
        $redirectUrl = portal_public_access_url('login');

        if ($restorePlatformSession) {
            $platformUserId = (int) ($payload['platform_user_id'] ?? 0);
            $restored = $this->restorePlatformConsoleSession($platformUserId);
            $redirectUrl = $restored
                ? (string) ($payload['return_url'] ?? portal_platform_url('impersonificazione'))
                : portal_public_access_url('login');
        }

        log_message('info', '[PlatformImpersonationService] Sessione delegata chiusa: reason={reason}, restored={restored}', [
            'reason' => $endReason,
            'restored' => $restored ? 1 : 0,
        ]);

        return [
            'restored' => $restored,
            'redirectUrl' => $redirectUrl,
        ];
    }

    public function closeActiveImpersonation(string $endReason = 'manual', bool $restorePlatformSession = true): void
    {
        if ($this->currentImpersonation() === null) {
            return;
        }

        $this->stopImpersonation($endReason, $restorePlatformSession);
    }

    private function restorePlatformConsoleSession(int $platformUserId): bool
    {
        if ($platformUserId <= 0) {
            return false;
        }

        $platformUser = $this->platformUsersModel->find($platformUserId);
        if (!is_array($platformUser) || !$this->platformAdminAccess->isPlatformAdmin($platformUser)) {
            return false;
        }

        $memberships = $this->tenantCatalog->listTenantsForPlatformUser($platformUserId);
        $this->platformAdminAccess->bootstrapSession($platformUser, $memberships);
        session()->remove(self::SESSION_KEY);

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function requireCurrentPlatformAdmin(): array
    {
        if (!$this->platformAdminAccess->canAccessPlatformConsole()) {
            throw new \RuntimeException('Area riservata agli account master piattaforma.');
        }

        $platformUser = $this->platformAdminAccess->currentPlatformUser();
        if (!is_array($platformUser)) {
            throw new \RuntimeException('Sessione piattaforma non disponibile.');
        }

        return $platformUser;
    }

    /**
     * @return array<string, mixed>
     */
    private function requireTenant(int $tenantId): array
    {
        if ($tenantId <= 0) {
            throw new \InvalidArgumentException('Spazio cliente non valido.');
        }

        $tenant = $this->tenantsModel->find($tenantId);
        if (!is_array($tenant)) {
            throw new \RuntimeException('Spazio cliente non trovato.');
        }

        if ((int) ($tenant['is_active'] ?? 0) !== 1) {
            throw new \RuntimeException('Lo spazio cliente non e attivo.');
        }

        $status = strtolower(trim((string) ($tenant['status'] ?? 'active')));
        if (in_array($status, ['archived', 'suspended'], true)) {
            throw new \RuntimeException('Lo spazio cliente non e disponibile per l accesso delegato.');
        }

        return $tenant;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadTenantAccounts(BaseConnection $tenantDb, int $tenantId): array
    {
        if (!$tenantDb->tableExists('dap01_users')) {
            return [];
        }

        $runtimeUsers = $this->loadRuntimeUsers($tenantDb);
        if ($runtimeUsers === []) {
            return [];
        }

        $userIds = array_map(static fn(array $row): int => (int) ($row['id_user'] ?? 0), $runtimeUsers);
        $personaleProfiles = $this->loadPersonaleProfiles($tenantDb, $userIds);
        $clientProfiles = $this->loadClientProfiles($tenantDb, $userIds);
        $segProfiles = $this->loadLegacySegProfiles($tenantDb, $userIds);
        $memberships = $this->loadTenantMembershipsByAppUserId($tenantId);

        $accounts = [];

        foreach ($runtimeUsers as $runtimeUser) {
            $userId = (int) ($runtimeUser['id_user'] ?? 0);
            if ($userId <= 0) {
                continue;
            }

            $personale = $personaleProfiles[$userId] ?? null;
            $client = $clientProfiles[$userId] ?? null;
            $seg = $segProfiles[$userId] ?? null;
            $membership = $memberships[$userId] ?? null;
            $tipoUser = (int) ($runtimeUser['tipo_user'] ?? 0);
            $username = trim((string) ($runtimeUser['username'] ?? ''));
            $fullName = '';
            $email = '';
            $cellulare = '';
            $qualifica = '';
            $personaleTipo = 0;

            if (is_array($personale)) {
                $fullName = $this->composeFullName((string) ($personale['nome'] ?? ''), (string) ($personale['cognome'] ?? ''));
                $email = trim((string) ($personale['email'] ?? ''));
                $cellulare = trim((string) ($personale['cellulare'] ?? ''));
                $qualifica = trim((string) ($personale['qualifica'] ?? ''));
                $personaleTipo = (int) ($personale['tipo'] ?? 0);
            } elseif (is_array($client)) {
                $fullName = SystemUserMask::getMaskedClientDisplayName(
                    (int) ($client['id_client'] ?? 0),
                    $this->composeFullName((string) ($client['nome'] ?? ''), (string) ($client['cognome'] ?? ''))
                );
                $email = trim((string) ($client['email'] ?? ''));
                $cellulare = trim((string) ($client['cellulare'] ?? ''));
            } elseif (is_array($seg)) {
                $fullName = $this->composeFullName((string) ($seg['nome'] ?? ''), (string) ($seg['cognome'] ?? ''));
            }

            if ($fullName === '') {
                $fullName = $username !== '' ? $username : ('Account #' . $userId);
            }

            $tenantRole = trim((string) ($membership['tenant_role'] ?? ''));
            if ($tenantRole === '') {
                $tenantRole = $this->inferTenantRole($tipoUser);
            }

            $accounts[] = [
                'app_user_id' => $userId,
                'username' => $username,
                'tipo_user' => $tipoUser,
                'user_type_label' => $this->resolveUserTypeLabel($tipoUser, $personaleTipo, is_array($client), $qualifica),
                'full_name' => $fullName,
                'email' => $email,
                'cellulare' => $cellulare,
                'qualifica' => $qualifica,
                'tenant_role' => $tenantRole,
                'tenant_role_label' => $this->resolveTenantRoleLabel($tenantRole),
                'platform_user_id' => (int) ($membership['id_platform_user'] ?? 0),
                'platform_user_email' => (string) ($membership['platform_email'] ?? ''),
                'is_runtime_active' => (int) ($runtimeUser['is_active'] ?? 1) === 1,
                'password_status' => strtolower(trim((string) ($runtimeUser['password_status'] ?? 'ok'))),
            ];
        }

        usort($accounts, static function (array $left, array $right): int {
            $leftActive = !empty($left['is_runtime_active']) ? 1 : 0;
            $rightActive = !empty($right['is_runtime_active']) ? 1 : 0;
            if ($leftActive !== $rightActive) {
                return $rightActive <=> $leftActive;
            }

            return strcasecmp((string) ($left['full_name'] ?? ''), (string) ($right['full_name'] ?? ''));
        });

        return $accounts;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function loadTenantAccountById(BaseConnection $tenantDb, int $tenantId, int $appUserId): ?array
    {
        if ($appUserId <= 0) {
            return null;
        }

        foreach ($this->loadTenantAccounts($tenantDb, $tenantId) as $account) {
            if ((int) ($account['app_user_id'] ?? 0) === $appUserId) {
                return $account;
            }
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadRuntimeUsers(BaseConnection $tenantDb): array
    {
        $selectFields = ['id_user', 'username'];

        if ($tenantDb->fieldExists('tipo_user', 'dap01_users')) {
            $selectFields[] = 'tipo_user';
        }

        if ($tenantDb->fieldExists('is_active', 'dap01_users')) {
            $selectFields[] = 'is_active';
        }

        $selectFields[] = $tenantDb->fieldExists('datascadenza', 'dap01_users')
            ? "CASE WHEN datascadenza <= NOW() THEN 'scadenza' ELSE 'ok' END AS password_status"
            : "'ok' AS password_status";

        $query = $tenantDb->table('dap01_users')
            ->select(implode(', ', $selectFields), false)
            ->orderBy('username', 'ASC')
            ->get();

        return $query ? $query->getResultArray() : [];
    }

    /**
     * @param array<int, int> $userIds
     * @return array<int, array<string, mixed>>
     */
    private function loadPersonaleProfiles(BaseConnection $tenantDb, array $userIds): array
    {
        if ($userIds === [] || !$tenantDb->tableExists('dap03_personale')) {
            return [];
        }

        $selectFields = ['p.id_user'];

        if ($tenantDb->fieldExists('id_personale', 'dap03_personale')) {
            $selectFields[] = 'p.id_personale';
        }

        if ($tenantDb->fieldExists('tipo', 'dap03_personale')) {
            $selectFields[] = 'p.tipo';
        }

        foreach (['nome', 'cognome', 'email', 'cellulare', 'qualifica'] as $field) {
            if ($tenantDb->fieldExists($field, 'dap03_personale')) {
                $selectFields[] = $this->crypto->decryptSenzaAlias('p.' . $field) . ' AS ' . $field;
            }
        }

        $builder = $tenantDb->table('dap03_personale p')
            ->select(implode(', ', $selectFields), false)
            ->whereIn('p.id_user', $userIds);

        if ($tenantDb->fieldExists('is_active', 'dap03_personale')) {
            $builder->orderBy('p.is_active', 'DESC');
        }

        if ($tenantDb->fieldExists('titolare', 'dap03_personale')) {
            $builder->orderBy('p.titolare', 'DESC');
        }

        if ($tenantDb->fieldExists('sostituto', 'dap03_personale')) {
            $builder->orderBy('p.sostituto', 'ASC');
        }

        if ($tenantDb->fieldExists('id_personale', 'dap03_personale')) {
            $builder->orderBy('p.id_personale', 'ASC');
        }

        $query = $builder->get();
        $rows = $query ? $query->getResultArray() : [];

        $indexed = [];
        foreach ($rows as $row) {
            $userId = (int) ($row['id_user'] ?? 0);
            if ($userId <= 0 || isset($indexed[$userId])) {
                continue;
            }

            $indexed[$userId] = $row;
        }

        return $indexed;
    }

    /**
     * @param array<int, int> $userIds
     * @return array<int, array<string, mixed>>
     */
    private function loadClientProfiles(BaseConnection $tenantDb, array $userIds): array
    {
        if ($userIds === [] || !$tenantDb->tableExists('dap02_clients')) {
            return [];
        }

        $selectFields = ['c.id_user'];

        if ($tenantDb->fieldExists('id_client', 'dap02_clients')) {
            $selectFields[] = 'c.id_client';
        }

        if ($tenantDb->fieldExists('id_personale', 'dap02_clients')) {
            $selectFields[] = 'c.id_personale';
        }

        foreach (['nome', 'cognome', 'email', 'cellulare'] as $field) {
            if ($tenantDb->fieldExists($field, 'dap02_clients')) {
                $selectFields[] = $this->crypto->decryptSenzaAlias('c.' . $field) . ' AS ' . $field;
            }
        }

        $builder = $tenantDb->table('dap02_clients c')
            ->select(implode(', ', $selectFields), false)
            ->whereIn('c.id_user', $userIds);

        if ($tenantDb->fieldExists('id_client', 'dap02_clients')) {
            $builder->orderBy('c.id_client', 'ASC');
        }

        $query = $builder->get();
        $rows = $query ? $query->getResultArray() : [];

        $indexed = [];
        foreach ($rows as $row) {
            $userId = (int) ($row['id_user'] ?? 0);
            if ($userId <= 0 || isset($indexed[$userId])) {
                continue;
            }

            $indexed[$userId] = $row;
        }

        return $indexed;
    }

    /**
     * @param array<int, int> $userIds
     * @return array<int, array<string, mixed>>
     */
    private function loadLegacySegProfiles(BaseConnection $tenantDb, array $userIds): array
    {
        if ($userIds === [] || !$tenantDb->tableExists('dap13_seg')) {
            return [];
        }

        $query = $tenantDb->table('dap13_seg')
            ->select('id_user, id_inf, nome, cognome')
            ->whereIn('id_user', $userIds)
            ->get();

        $rows = $query ? $query->getResultArray() : [];

        $indexed = [];
        foreach ($rows as $row) {
            $indexed[(int) ($row['id_user'] ?? 0)] = $row;
        }

        return $indexed;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function loadTenantMembershipsByAppUserId(int $tenantId): array
    {
        if ($tenantId <= 0 || !$this->platformDb->tableExists('platform_user_tenants')) {
            return [];
        }

        if (!$this->platformDb->fieldExists('app_user_id', 'platform_user_tenants')) {
            return [];
        }

        $selectFields = ['put.app_user_id'];

        if ($this->platformDb->fieldExists('id_platform_user', 'platform_user_tenants')) {
            $selectFields[] = 'put.id_platform_user';
        }

        if ($this->platformDb->fieldExists('tenant_role', 'platform_user_tenants')) {
            $selectFields[] = 'put.tenant_role';
        }

        $builder = $this->platformDb->table('platform_user_tenants put')
            ->select(implode(', ', $selectFields), false)
            ->where('put.id_tenant', $tenantId)
            ->where('put.app_user_id IS NOT NULL', null, false);

        if (
            $this->platformDb->tableExists('platform_users')
            && $this->platformDb->fieldExists('id_platform_user', 'platform_user_tenants')
            && $this->platformDb->fieldExists('id_platform_user', 'platform_users')
            && $this->platformDb->fieldExists('email', 'platform_users')
        ) {
            $builder
                ->select('pu.email AS platform_email')
                ->join('platform_users pu', 'pu.id_platform_user = put.id_platform_user', 'left');
        }

        $query = $builder->get();
        $rows = $query ? $query->getResultArray() : [];

        $indexed = [];
        foreach ($rows as $row) {
            $appUserId = (int) ($row['app_user_id'] ?? 0);
            if ($appUserId <= 0 || isset($indexed[$appUserId])) {
                continue;
            }

            $indexed[$appUserId] = $row;
        }

        return $indexed;
    }

    /**
     * @param array<int, array<string, mixed>> $accounts
     * @return array<string, int>
     */
    private function buildSummary(array $accounts): array
    {
        $activeAccounts = 0;
        $patientAccounts = 0;

        foreach ($accounts as $account) {
            if (!empty($account['is_runtime_active'])) {
                $activeAccounts++;
            }

            if ((int) ($account['tipo_user'] ?? 0) === 3) {
                $patientAccounts++;
            }
        }

        return [
            'total_accounts' => count($accounts),
            'active_accounts' => $activeAccounts,
            'patient_accounts' => $patientAccounts,
        ];
    }

    private function ttlSeconds(): int
    {
        $minutes = (int) env('PLATFORM_IMPERSONATION_TTL_MINUTES', self::DEFAULT_TTL_MINUTES);
        $minutes = max(5, min(120, $minutes));
        return $minutes * 60;
    }

    private function normalizeReason(string $reason): string
    {
        $reason = trim(preg_replace('/\s+/', ' ', $reason) ?? '');
        if (mb_strlen($reason) < self::MIN_REASON_LENGTH) {
            throw new \InvalidArgumentException('Inserisci un motivo chiaro di almeno 8 caratteri per avviare l accesso delegato.');
        }

        return $reason;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function closeLogByPayload(array $payload, string $endReason): void
    {
        $logId = (int) ($payload['log_id'] ?? 0);
        if ($logId <= 0) {
            return;
        }

        $this->closeLogById($logId, $endReason);
    }

    private function closeLogById(int $logId, string $endReason): void
    {
        if ($logId <= 0) {
            return;
        }

        $current = $this->logsModel->find($logId);
        if (!is_array($current) || !empty($current['ended_at'])) {
            return;
        }

        $this->logsModel->update($logId, [
            'ended_at' => date('Y-m-d H:i:s'),
            'end_reason' => substr(trim($endReason), 0, 30),
        ]);
    }

    /**
     * @param array<string, mixed> $platformUser
     */
    private function platformUserDisplayName(array $platformUser): string
    {
        $displayName = trim((string) ($platformUser['first_name'] ?? '') . ' ' . (string) ($platformUser['last_name'] ?? ''));
        return $displayName !== '' ? $displayName : (string) ($platformUser['email'] ?? 'Account master');
    }

    private function composeFullName(string $firstName, string $lastName): string
    {
        return trim(trim($firstName) . ' ' . trim($lastName));
    }

    private function inferTenantRole(int $tipoUser): string
    {
        return $tipoUser === 1 ? 'tenant_admin' : 'tenant_staff';
    }

    private function resolveTenantRoleLabel(string $tenantRole): string
    {
        return match (strtolower(trim($tenantRole))) {
            'tenant_master' => 'Responsabile dello studio',
            'tenant_admin' => 'Amministratore dello studio',
            'tenant_staff' => 'Collaboratore dello studio',
            default => 'Profilo operativo',
        };
    }

    private function resolveUserTypeLabel(int $tipoUser, int $personaleTipo, bool $isClient, string $qualifica): string
    {
        if ($qualifica !== '') {
            return $qualifica;
        }

        if ($isClient || $tipoUser === 3) {
            return 'Paziente';
        }

        if ($personaleTipo > 0) {
            return match ($personaleTipo) {
                1 => 'Medico',
                2 => 'Infermiere',
                3 => 'Segreteria',
                default => 'Personale',
            };
        }

        return match ($tipoUser) {
            1 => 'Amministrazione',
            2 => 'Operatore',
            4 => 'Segreteria',
            default => 'Account applicativo',
        };
    }
}
