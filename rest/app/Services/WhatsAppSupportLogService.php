<?php

namespace App\Services;

use Config\Database;

class WhatsAppSupportLogService
{
    private \CodeIgniter\Database\BaseConnection $platformDb;
    private WhatsAppConversationService $conversations;

    public function __construct(
        ?\CodeIgniter\Database\BaseConnection $platformDb = null,
        ?WhatsAppConversationService $conversations = null
    ) {
        $this->platformDb = $platformDb ?? Database::connect('platform');
        $this->conversations = $conversations ?? new WhatsAppConversationService();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listTenants(): array
    {
        $rows = $this->platformDb
            ->table('platform_tenants')
            ->select('id_tenant, tenant_key, tenant_name, legal_name, status, onboarding_status, is_active')
            ->orderBy('tenant_name', 'ASC')
            ->get()
            ->getResultArray();

        $catalog = [];
        $seen = [];
        foreach ($rows as $row) {
            $tenantId = (int) ($row['id_tenant'] ?? 0);
            if ($tenantId <= 0 || !WhatsAppGatewayClient::isRoutedToGateway($tenantId)) {
                continue;
            }

            $catalog[] = $this->normalizeTenant($row);
            $seen[$tenantId] = true;
        }

        foreach (WhatsAppGatewayClient::configuredTenantIds() as $tenantId) {
            if (isset($seen[$tenantId]) || !WhatsAppGatewayClient::isRoutedToGateway($tenantId)) {
                continue;
            }
            $catalog[] = $this->normalizeTenant([
                'id_tenant' => $tenantId,
                'tenant_key' => '',
                'tenant_name' => 'Spazio #' . $tenantId,
                'legal_name' => '',
                'status' => 'non presente nel catalogo',
                'onboarding_status' => '',
                'is_active' => 0,
            ]);
        }

        usort($catalog, static function (array $left, array $right): int {
            return strcasecmp((string) ($left['tenant_name'] ?? ''), (string) ($right['tenant_name'] ?? ''));
        });

        return $catalog;
    }

    /**
     * @param array<int, array<string, mixed>> $catalog
     * @return array<string, mixed>|null
     */
    public function findTenant(array $catalog, int $tenantId): ?array
    {
        foreach ($catalog as $tenant) {
            if ((int) ($tenant['id_tenant'] ?? 0) === $tenantId) {
                return $tenant;
            }
        }

        return null;
    }

    /**
     * @return array{account_status:array<string, mixed>, conversation_dashboard:array<string, mixed>}
     */
    public function loadTenantDashboard(int $tenantId, int $limit = 100): array
    {
        if (!WhatsAppGatewayClient::isAvailableForTenant($tenantId)) {
            throw new \RuntimeException('Il gateway WhatsApp non è configurato per questo spazio.');
        }

        $client = new WhatsAppGatewayClient();
        $status = $client->accountStatus($tenantId);
        $timeline = $client->messages($tenantId, $limit);
        if (empty($timeline['ok'])) {
            throw new \RuntimeException((string) ($timeline['error'] ?? 'Registro WhatsApp non disponibile.'));
        }

        return [
            'account_status' => [
                'ok' => !empty($status['ok']),
                'connected' => !empty($status['account']['connected']),
                'logged_in' => !empty($status['account']['logged_in']),
                'state' => trim((string) ($status['account']['state'] ?? 'unknown')),
                'error' => $status['error'] ?? null,
            ],
            'conversation_dashboard' => $this->conversations->buildDashboard(
                is_array($timeline['messages'] ?? null) ? $timeline['messages'] : []
            ),
        ];
    }

    /**
     * @return array{account_status:array<string, mixed>, conversation_dashboard:array<string, mixed>}
     */
    public function emptyDashboard(): array
    {
        return [
            'account_status' => [
                'ok' => false,
                'connected' => false,
                'logged_in' => false,
                'state' => 'unavailable',
                'error' => null,
            ],
            'conversation_dashboard' => $this->conversations->buildDashboard([]),
        ];
    }

    /**
     * @param array<string, mixed> $tenant
     * @return array<string, mixed>
     */
    private function normalizeTenant(array $tenant): array
    {
        $tenantId = (int) ($tenant['id_tenant'] ?? 0);
        $tenantName = trim((string) ($tenant['tenant_name'] ?? ''));
        if ($tenantName === '') {
            $tenantName = 'Spazio #' . $tenantId;
        }

        return [
            'id_tenant' => $tenantId,
            'tenant_key' => trim((string) ($tenant['tenant_key'] ?? '')),
            'tenant_name' => $tenantName,
            'legal_name' => trim((string) ($tenant['legal_name'] ?? '')),
            'status' => trim((string) ($tenant['status'] ?? '')),
            'onboarding_status' => trim((string) ($tenant['onboarding_status'] ?? '')),
            'is_active' => !empty($tenant['is_active']),
        ];
    }
}
