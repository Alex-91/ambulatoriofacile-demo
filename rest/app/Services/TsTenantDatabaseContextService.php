<?php

namespace App\Services;

use App\Models\TsDocumentEventModel;
use App\Models\TsDocumentModel;
use App\Models\TsDocumentReceiptModel;

class TsTenantDatabaseContextService
{
    private TenantCatalogService $tenantCatalog;
    private TenantDatabaseConnector $tenantDbConnector;

    public function __construct(
        ?TenantCatalogService $tenantCatalog = null,
        ?TenantDatabaseConnector $tenantDbConnector = null
    ) {
        $this->tenantCatalog = $tenantCatalog ?? new TenantCatalogService();
        $this->tenantDbConnector = $tenantDbConnector ?? new TenantDatabaseConnector();
    }

    /**
     * @return array<string, mixed>
     */
    public function resolveTenantContext(int $tenantId = 0): array
    {
        $tenant = $tenantId > 0
            ? $this->tenantCatalog->getTenantById($tenantId)
            : $this->tenantCatalog->resolveCurrentRuntimeTenant();

        if (!is_array($tenant) || (int) ($tenant['id_tenant'] ?? 0) <= 0) {
            throw new \RuntimeException('Tenant TS non risolto per il database documentale.');
        }

        $db = $this->tenantDbConnector->connect($tenant);
        $documents = new TsDocumentModel($db);
        $events = new TsDocumentEventModel($db);
        $receipts = new TsDocumentReceiptModel($db);

        return [
            'tenant' => $tenant,
            'db' => $db,
            'documents' => $documents,
            'events' => $events,
            'receipts' => $receipts,
            'audit' => new TsAuditService($events),
        ];
    }
}
