<?php

namespace App\Services;

use App\Libraries\Crypto_helper;
use App\Libraries\DatabaseConfig;
use Config\Database;

class LegacyTenantLoginService
{
    private \CodeIgniter\Database\BaseConnection $platformDb;
    private TenantDatabaseConnector $tenantDbConnector;
    private DatabaseConfig $databaseConfig;
    private Crypto_helper $crypto;
    private LegacyTenantSessionService $tenantSession;

    public function __construct()
    {
        $this->platformDb = Database::connect('platform');
        $this->tenantDbConnector = new TenantDatabaseConnector();
        $this->databaseConfig = new DatabaseConfig();
        $this->crypto = new Crypto_helper();
        $this->tenantSession = new LegacyTenantSessionService();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function authenticate(string $username, string $password): ?array
    {
        $username = trim($username);
        if ($username === '' || $password === '') {
            return null;
        }

        $this->tenantSession->clearAllPending();

        $matches = $this->findCredentialMatches($username, $password);
        if ($matches === []) {
            return null;
        }

        if (count($matches) > 1) {
            $this->tenantSession->storePendingSelection($matches);

            return [
                'resp' => 'TENANT_SELECT',
                'success' => true,
                'message' => 'Seleziona lo spazio cliente.',
                'tenants' => array_map(static function (array $match): array {
                    return [
                        'id_tenant' => (int) ($match['id_tenant'] ?? 0),
                        'tenant_key' => (string) ($match['tenant_key'] ?? ''),
                        'tenant_name' => (string) ($match['tenant_name'] ?? ''),
                        'package_code' => (string) ($match['package_code'] ?? ''),
                        'package_name' => (string) ($match['package_name'] ?? ''),
                        'login_hint' => (string) ($match['login_hint'] ?? ''),
                    ];
                }, $matches),
            ];
        }

        return $this->bootstrapMatch($matches[0], $username);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function completePendingSelection(int $tenantId): ?array
    {
        $match = $this->tenantSession->consumePendingSelection($tenantId);
        if ($match === null) {
            return null;
        }

        return $this->bootstrapMatch($match, (string) ($match['username'] ?? ''));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function findCredentialMatches(string $username, string $password): array
    {
        $tenants = $this->platformDb->table('platform_tenants t')
            ->select('t.*, p.package_code, p.package_name')
            ->join('platform_packages p', 'p.id_package = t.id_package', 'left')
            ->where('t.is_active', 1)
            ->orderBy('t.tenant_name', 'ASC')
            ->get()
            ->getResultArray();

        $matches = [];

        foreach ($tenants as $tenant) {
            $status = strtolower(trim((string) ($tenant['status'] ?? 'active')));
            if (in_array($status, ['archived', 'suspended'], true)) {
                continue;
            }

            try {
                $tenantDb = $this->tenantDbConnector->connect($tenant);
                $this->databaseConfig->setEncryptionConfig($tenantDb);
            } catch (\Throwable $e) {
                log_message('warning', 'LegacyTenantLoginService connect failed: ' . $e->getMessage(), [
                    'tenant_id' => (int) ($tenant['id_tenant'] ?? 0),
                    'tenant_key' => (string) ($tenant['tenant_key'] ?? ''),
                ]);
                continue;
            }

            if (!$tenantDb->tableExists('dap01_users')) {
                continue;
            }

            try {
                $row = $tenantDb->query(
                    "SELECT a.id_user,
                            a.username,
                            a.tipo_user,
                            CASE WHEN a.datascadenza <= NOW() THEN 'SCADENZA' ELSE 'OK' END AS resp
                     FROM dap01_users a
                     WHERE a.username = ?
                       AND a.password = " . $this->crypto->encrypt_select_login('?') . "
                     LIMIT 1",
                    [$username, $password]
                )->getRowArray();
            } catch (\Throwable $e) {
                log_message('warning', 'LegacyTenantLoginService credential query failed: ' . $e->getMessage(), [
                    'tenant_id' => (int) ($tenant['id_tenant'] ?? 0),
                    'tenant_key' => (string) ($tenant['tenant_key'] ?? ''),
                    'username' => $username,
                ]);
                continue;
            }

            if (!$row) {
                continue;
            }

            $matches[] = [
                'id_tenant' => (int) ($tenant['id_tenant'] ?? 0),
                'tenant_key' => (string) ($tenant['tenant_key'] ?? ''),
                'tenant_name' => (string) ($tenant['tenant_name'] ?? ''),
                'package_code' => (string) ($tenant['package_code'] ?? ''),
                'package_name' => (string) ($tenant['package_name'] ?? ''),
                'login_hint' => (string) ($tenant['login_hint'] ?? ''),
                'app_user_id' => (int) ($row['id_user'] ?? 0),
                'user_type' => (int) ($row['tipo_user'] ?? 0),
                'username' => (string) ($row['username'] ?? $username),
                'resp' => (string) ($row['resp'] ?? 'OK'),
            ];
        }

        return $matches;
    }

    /**
     * @param array<string, mixed> $match
     * @return array<string, mixed>
     */
    private function bootstrapMatch(array $match, string $expectedUsername): array
    {
        try {
            $tenantId = (int) ($match['id_tenant'] ?? 0);
            if ($tenantId <= 0) {
                throw new \RuntimeException('Tenant legacy non valido.');
            }

            $tenant = $this->platformDb->table('platform_tenants t')
                ->select('t.*, p.package_code, p.package_name')
                ->join('platform_packages p', 'p.id_package = t.id_package', 'left')
                ->where('t.id_tenant', $tenantId)
                ->get(1)
                ->getRowArray();

            if (!$tenant || (int) ($tenant['is_active'] ?? 0) !== 1) {
                throw new \RuntimeException('Spazio cliente non disponibile per il login.');
            }

            $tenantDb = $this->tenantDbConnector->connect($tenant);
            $this->databaseConfig->setEncryptionConfig($tenantDb);

            $handoff = new LegacyLoginHandoffService($tenantDb);
            $result = $handoff->bootstrapUserById((int) ($match['app_user_id'] ?? 0), $expectedUsername);

            $userType = (int) ($result['userType'] ?? $match['user_type'] ?? 0);
            $this->tenantSession->queuePendingRuntime($tenant, (int) ($match['app_user_id'] ?? 0), $userType);

            if (($result['resp'] ?? 'KO') === 'OK' && !(bool) ($result['requiresOtp'] ?? false)) {
                $this->tenantSession->activatePendingRuntime();
            }

            return [
                'resp' => (string) ($result['resp'] ?? 'OK'),
                'success' => true,
                'redirectUrl' => (string) ($result['redirectUrl'] ?? 'auth'),
            ];
        } catch (\Throwable $e) {
            $this->tenantSession->clearAllPending();
            throw $e;
        }
    }
}
