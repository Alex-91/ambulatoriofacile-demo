<?php

namespace App\Services;

use App\Models\FseDocumentEventModel;
use App\Models\FseDocumentModel;

class FseTenantDatabaseContextService
{
    private TenantCatalogService $catalog;
    private TenantDatabaseConnector $connector;

    public function __construct(?TenantCatalogService $catalog = null, ?TenantDatabaseConnector $connector = null)
    {
        $this->catalog = $catalog ?? new TenantCatalogService();
        $this->connector = $connector ?? new TenantDatabaseConnector();
    }

    /** @return array<string,mixed> */
    public function resolveTenantContext(int $tenantId): array
    {
        $tenant = $tenantId > 0 ? $this->catalog->getTenantById($tenantId) : $this->catalog->resolveCurrentRuntimeTenant();
        if (!is_array($tenant) || (int) ($tenant['id_tenant'] ?? 0) <= 0) {
            throw new \RuntimeException('Spazio FSE non risolto.');
        }
        $db = $this->connector->connect($tenant);
        $events = new FseDocumentEventModel($db);

        return ['tenant' => $tenant, 'db' => $db, 'documents' => new FseDocumentModel($db), 'events' => $events, 'audit' => new FseAuditService($events)];
    }
}
