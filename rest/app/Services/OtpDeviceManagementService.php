<?php

namespace App\Services;

use App\Libraries\Crypto_helper;
use App\Libraries\DatabaseConfig;
use CodeIgniter\Database\BaseConnection;

class OtpDeviceManagementService
{
    private TenantCatalogService $tenantCatalog;
    private TenantDatabaseConnector $tenantDatabaseConnector;
    private DatabaseConfig $databaseConfig;
    private Crypto_helper $crypto;

    public function __construct(
        ?TenantCatalogService $tenantCatalog = null,
        ?TenantDatabaseConnector $tenantDatabaseConnector = null,
        ?DatabaseConfig $databaseConfig = null,
        ?Crypto_helper $crypto = null
    ) {
        $this->tenantCatalog = $tenantCatalog ?? new TenantCatalogService();
        $this->tenantDatabaseConnector = $tenantDatabaseConnector ?? new TenantDatabaseConnector();
        $this->databaseConfig = $databaseConfig ?? new DatabaseConfig();
        $this->crypto = $crypto ?? new Crypto_helper();
    }

    /**
     * @return array<string, mixed>
     */
    public function buildTenantDashboard(int $tenantId): array
    {
        $tenant = $this->requireTenant($tenantId);
        $tenantDb = $this->tenantDatabaseConnector->connect($tenant);
        $this->databaseConfig->setEncryptionConfig($tenantDb);

        $runtimeWarning = null;
        if (!$tenantDb->tableExists('push_subscriptions')) {
            $runtimeWarning = 'Archivio dispositivi OTP non disponibile in questo studio.';
        }

        $accounts = $this->loadActiveOtpAccounts($tenantDb);

        return [
            'tenant' => $tenant,
            'accounts' => $accounts,
            'summary' => $this->buildSummary($accounts),
            'runtime_warning' => $runtimeWarning,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function disconnectTenantAccountDevices(int $tenantId, int $appUserId): array
    {
        $tenant = $this->requireTenant($tenantId);
        if ($appUserId <= 0) {
            throw new \InvalidArgumentException('Account applicativo non valido.');
        }

        $tenantDb = $this->tenantDatabaseConnector->connect($tenant);
        $this->databaseConfig->setEncryptionConfig($tenantDb);

        if (!$tenantDb->tableExists('push_subscriptions')) {
            throw new \RuntimeException('Archivio dispositivi OTP non disponibile in questo studio.');
        }

        $activeDevices = $this->loadActiveMobileDevices($tenantDb, [$appUserId]);
        if (!isset($activeDevices[$appUserId])) {
            return [
                'tenant' => $tenant,
                'app_user_id' => $appUserId,
                'disconnected' => false,
                'message' => 'Nessun dispositivo OTP attivo da disassociare per questo account.',
            ];
        }

        $builder = $tenantDb->table('push_subscriptions')
            ->where('user_id', $appUserId)
            ->where('is_active', 1);

        if ($tenantDb->fieldExists('is_mobile', 'push_subscriptions')) {
            $builder->where('is_mobile', 1);
        }

        $updatePayload = [
            'is_active' => 0,
        ];

        if ($tenantDb->fieldExists('updated_at', 'push_subscriptions')) {
            $updatePayload['updated_at'] = date('Y-m-d H:i:s');
        }

        $builder->update($updatePayload);

        $runtimeUsers = $this->loadRuntimeUsers($tenantDb, [$appUserId]);
        $username = trim((string) ($runtimeUsers[$appUserId]['username'] ?? ''));
        $accountLabel = $username !== '' ? $username : ('ID utente ' . $appUserId);

        return [
            'tenant' => $tenant,
            'app_user_id' => $appUserId,
            'disconnected' => true,
            'message' => 'Dispositivi OTP disassociati per l account ' . $accountLabel . '.',
        ];
    }

    /**
     * @param array<int, int> $filterUserIds
     * @return array<int, array<string, mixed>>
     */
    private function loadActiveOtpAccounts(BaseConnection $tenantDb, array $filterUserIds = []): array
    {
        $devicesByUserId = $this->loadActiveMobileDevices($tenantDb, $filterUserIds);
        if ($devicesByUserId === []) {
            return [];
        }

        $userIds = array_map('intval', array_keys($devicesByUserId));
        $runtimeUsers = $this->loadRuntimeUsers($tenantDb, $userIds);
        $personaleProfiles = $this->loadPersonaleProfiles($tenantDb, $userIds);
        $clientProfiles = $this->loadClientProfiles($tenantDb, $userIds);

        $accounts = [];

        foreach ($devicesByUserId as $userId => $deviceMeta) {
            $runtimeUser = $runtimeUsers[$userId] ?? [];
            $personale = $personaleProfiles[$userId] ?? null;
            $client = $clientProfiles[$userId] ?? null;
            $latestDevice = is_array($deviceMeta['latest_device'] ?? null) ? $deviceMeta['latest_device'] : [];
            $username = trim((string) ($runtimeUser['username'] ?? ''));
            $tipoUser = (int) ($runtimeUser['tipo_user'] ?? 0);

            $fullName = '';
            $email = '';
            $cellulare = '';
            $qualifica = '';
            $personaleTipo = 0;

            if (is_array($personale)) {
                $fullName = $this->composeFullName(
                    (string) ($personale['nome'] ?? ''),
                    (string) ($personale['cognome'] ?? '')
                );
                $email = trim((string) ($personale['email'] ?? ''));
                $cellulare = trim((string) ($personale['cellulare'] ?? ''));
                $qualifica = trim((string) ($personale['qualifica'] ?? ''));
                $personaleTipo = (int) ($personale['tipo'] ?? 0);
            } elseif (is_array($client)) {
                $fullName = $this->composeFullName(
                    (string) ($client['nome'] ?? ''),
                    (string) ($client['cognome'] ?? '')
                );
                $email = trim((string) ($client['email'] ?? ''));
                $cellulare = trim((string) ($client['cellulare'] ?? ''));
            }

            if ($fullName === '') {
                $fullName = $username !== '' ? $username : ('Account #' . $userId);
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
                'device_count' => (int) ($deviceMeta['device_count'] ?? 0),
                'device_label' => $this->resolveDeviceLabel($latestDevice),
                'device_name' => trim((string) ($latestDevice['device_name'] ?? '')),
                'device_os' => trim((string) ($latestDevice['device_os'] ?? '')),
                'device_type' => trim((string) ($latestDevice['device_type'] ?? '')),
                'last_seen' => trim((string) ($latestDevice['_sort_last_seen'] ?? '')),
                'has_active_device' => (int) ($deviceMeta['device_count'] ?? 0) > 0,
                'is_runtime_active' => (int) ($runtimeUser['is_active'] ?? 1) === 1,
            ];
        }

        usort($accounts, static function (array $left, array $right): int {
            $byDevices = (int) ($right['device_count'] ?? 0) <=> (int) ($left['device_count'] ?? 0);
            if ($byDevices !== 0) {
                return $byDevices;
            }

            $leftSeen = trim((string) ($left['last_seen'] ?? ''));
            $rightSeen = trim((string) ($right['last_seen'] ?? ''));
            if ($leftSeen !== $rightSeen) {
                return strcmp($rightSeen, $leftSeen);
            }

            return strcasecmp((string) ($left['full_name'] ?? ''), (string) ($right['full_name'] ?? ''));
        });

        return $accounts;
    }

    /**
     * @param array<int, array<string, mixed>> $accounts
     * @return array<string, int>
     */
    private function buildSummary(array $accounts): array
    {
        $activeDevices = 0;
        $multipleDevicesAccounts = 0;

        foreach ($accounts as $account) {
            $deviceCount = (int) ($account['device_count'] ?? 0);
            $activeDevices += $deviceCount;

            if ($deviceCount > 1) {
                $multipleDevicesAccounts++;
            }
        }

        return [
            'total_accounts' => count($accounts),
            'active_devices' => $activeDevices,
            'multiple_devices_accounts' => $multipleDevicesAccounts,
        ];
    }

    /**
     * @param array<int, int> $userIds
     * @return array<int, array<string, mixed>>
     */
    private function loadRuntimeUsers(BaseConnection $tenantDb, array $userIds): array
    {
        if ($userIds === [] || !$tenantDb->tableExists('dap01_users')) {
            return [];
        }

        $selectFields = ['id_user', 'username'];

        if ($tenantDb->fieldExists('tipo_user', 'dap01_users')) {
            $selectFields[] = 'tipo_user';
        }

        if ($tenantDb->fieldExists('is_active', 'dap01_users')) {
            $selectFields[] = 'is_active';
        }

        $rows = $tenantDb->table('dap01_users')
            ->select(implode(', ', $selectFields), false)
            ->whereIn('id_user', $userIds)
            ->get()
            ->getResultArray();

        $indexed = [];
        foreach ($rows as $row) {
            $userId = (int) ($row['id_user'] ?? 0);
            if ($userId <= 0) {
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

        if ($tenantDb->fieldExists('is_active', 'dap03_personale')) {
            $selectFields[] = 'p.is_active';
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

        $rows = $builder->get()->getResultArray();

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

        $rows = $builder->get()->getResultArray();

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
    private function loadActiveMobileDevices(BaseConnection $tenantDb, array $userIds = []): array
    {
        if (!$tenantDb->tableExists('push_subscriptions')) {
            return [];
        }

        $selectFields = ['user_id'];

        foreach (['id', 'endpoint', 'endpoint_hash', 'device_name', 'device_label', 'device_os', 'device_type', 'last_seen', 'updated_at', 'created_at'] as $field) {
            if ($tenantDb->fieldExists($field, 'push_subscriptions')) {
                $selectFields[] = $field;
            }
        }

        $builder = $tenantDb->table('push_subscriptions')
            ->select(implode(', ', $selectFields), false)
            ->where('is_active', 1);

        if ($tenantDb->fieldExists('is_mobile', 'push_subscriptions')) {
            $builder->where('is_mobile', 1);
        }

        if ($userIds !== []) {
            $builder->whereIn('user_id', $userIds);
        }

        $builder->orderBy('user_id', 'ASC');

        if ($tenantDb->fieldExists('last_seen', 'push_subscriptions')) {
            $builder->orderBy('last_seen', 'DESC');
        } elseif ($tenantDb->fieldExists('updated_at', 'push_subscriptions')) {
            $builder->orderBy('updated_at', 'DESC');
        } elseif ($tenantDb->fieldExists('created_at', 'push_subscriptions')) {
            $builder->orderBy('created_at', 'DESC');
        }

        if ($tenantDb->fieldExists('id', 'push_subscriptions')) {
            $builder->orderBy('id', 'DESC');
        }

        $rows = $builder->get()->getResultArray();

        $devicesByUserId = [];

        foreach ($rows as $row) {
            $userId = (int) ($row['user_id'] ?? 0);
            if ($userId <= 0) {
                continue;
            }

            $deviceKey = trim((string) ($row['endpoint_hash'] ?? ''));
            if ($deviceKey === '') {
                $deviceKey = trim((string) ($row['endpoint'] ?? ''));
            }
            if ($deviceKey === '') {
                $deviceKey = 'row:' . (string) ($row['id'] ?? uniqid('otp_', true));
            }

            $row['_sort_last_seen'] = trim((string) ($row['last_seen'] ?? ''));
            if ($row['_sort_last_seen'] === '') {
                $row['_sort_last_seen'] = trim((string) ($row['updated_at'] ?? ''));
            }
            if ($row['_sort_last_seen'] === '') {
                $row['_sort_last_seen'] = trim((string) ($row['created_at'] ?? ''));
            }

            if (!isset($devicesByUserId[$userId])) {
                $devicesByUserId[$userId] = [
                    'device_count' => 0,
                    'latest_device' => $row,
                    '_keys' => [],
                ];
            }

            if (isset($devicesByUserId[$userId]['_keys'][$deviceKey])) {
                continue;
            }

            $devicesByUserId[$userId]['_keys'][$deviceKey] = true;
            $devicesByUserId[$userId]['device_count']++;
        }

        foreach ($devicesByUserId as $userId => $deviceMeta) {
            unset($devicesByUserId[$userId]['_keys']);
        }

        return $devicesByUserId;
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
            return $this->resolvePersonaleTypeLabel($personaleTipo);
        }

        return match ($tipoUser) {
            1 => 'Amministrazione',
            2 => 'Operatore',
            4 => 'Segreteria',
            default => 'Account applicativo',
        };
    }

    private function resolvePersonaleTypeLabel(int $personaleTipo): string
    {
        return match ($personaleTipo) {
            1 => 'Medico',
            2 => 'Infermiere',
            3 => 'Segreteria',
            default => 'Personale',
        };
    }

    private function composeFullName(string $firstName, string $lastName): string
    {
        return trim(trim($firstName) . ' ' . trim($lastName));
    }

    /**
     * @param array<string, mixed> $device
     */
    private function resolveDeviceLabel(array $device): string
    {
        $label = trim((string) ($device['device_label'] ?? ''));
        if ($label !== '') {
            return $label;
        }

        $label = trim((string) ($device['device_name'] ?? ''));
        if ($label !== '') {
            return $label;
        }

        return 'Dispositivo mobile';
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
