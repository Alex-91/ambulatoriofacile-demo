<?php

namespace App\Services;

use App\Models\BillingDocumentModel;

class BillingTenantDatabaseContextService
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
            throw new \RuntimeException('Tenant fatturazione non risolto per il database documentale.');
        }

        $db = $this->tenantDbConnector->connect($tenant);
        if ($db->tableExists('billing_documents') && !$db->fieldExists('deleted_at', 'billing_documents')) {
            $status = (new BillingTenantSchemaService($this->tenantCatalog, $this->tenantDbConnector))->ensureTenantSchemaReady((int) $tenant['id_tenant']);
            if (empty($status['ready'])) throw new \RuntimeException('Archivio fatture non aggiornato: ' . ($status['message'] ?? 'allineamento richiesto'));
            $db->resetDataCache();
        }

        return [
            'tenant' => $tenant,
            'db' => $db,
            'documents' => new BillingDocumentModel($db),
        ];
    }
}
