<?php

namespace App\Services;

use App\Libraries\FilteredMigrationRunner;
use CodeIgniter\Database\BaseConnection;
use Config\Database as DatabaseConfig;
use Config\Migrations as MigrationsConfig;

class BillingTenantSchemaService
{
    /**
     * @var array<int, string>
     */
    private const TENANT_MIGRATION_FILES = [
        '2026-07-06-000003_CreateBillingDocumentsTable.php',
        '2026-08-03-000001_AddBillingCollectionsAndEmailDelivery.php',
    ];

    private TenantCatalogService $tenantCatalog;
    private TenantDatabaseConnector $tenantDbConnector;
    private MigrationsConfig $migrationsConfig;

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $statusCache = [];

    public function __construct(
        ?TenantCatalogService $tenantCatalog = null,
        ?TenantDatabaseConnector $tenantDbConnector = null,
        ?MigrationsConfig $migrationsConfig = null
    ) {
        $this->tenantCatalog = $tenantCatalog ?? new TenantCatalogService();
        $this->tenantDbConnector = $tenantDbConnector ?? new TenantDatabaseConnector();
        $this->migrationsConfig = $migrationsConfig ?? new MigrationsConfig();
    }

    /**
     * @return array<string, mixed>
     */
    public function ensureTenantSchemaReady(int $tenantId = 0, bool $attemptRepair = true): array
    {
        try {
            $tenant = $this->resolveTenantRecord($tenantId);
        } catch (\Throwable $e) {
            return [
                'ready' => false,
                'status' => 'error',
                'applied' => false,
                'message' => 'Tenant Fatturazione non risolto: ' . $e->getMessage(),
                'cli_messages' => [],
            ];
        }

        $resolvedTenantId = (int) ($tenant['id_tenant'] ?? 0);
        $cacheKey = $resolvedTenantId . ':' . ($attemptRepair ? '1' : '0');
        if (isset($this->statusCache[$cacheKey])) {
            return $this->statusCache[$cacheKey];
        }

        try {
            $db = $this->tenantDbConnector->connect($tenant);
            if ($this->hasRequiredSchema($db)) {
                return $this->statusCache[$cacheKey] = [
                    'ready' => true,
                    'status' => 'ok',
                    'applied' => false,
                    'tenant_id' => $resolvedTenantId,
                    'tenant_name' => trim((string) ($tenant['tenant_name'] ?? '')),
                    'message' => 'Schema Fatturazione già pronto per questo spazio.',
                    'cli_messages' => [],
                ];
            }

            if (!$attemptRepair) {
                return $this->statusCache[$cacheKey] = [
                    'ready' => false,
                    'status' => 'warning',
                    'applied' => false,
                    'tenant_id' => $resolvedTenantId,
                    'tenant_name' => trim((string) ($tenant['tenant_name'] ?? '')),
                    'message' => 'La tabella billing_documents non è ancora disponibile nel database di questo spazio.',
                    'cli_messages' => [],
                ];
            }

            $runResult = $this->runFilteredMigrations($tenant);
            $verificationDb = $this->tenantDbConnector->connect($tenant);

            if ($this->hasRequiredSchema($verificationDb)) {
                return $this->statusCache[$cacheKey] = [
                    'ready' => true,
                    'status' => 'ok',
                    'applied' => true,
                    'tenant_id' => $resolvedTenantId,
                    'tenant_name' => trim((string) ($tenant['tenant_name'] ?? '')),
                    'message' => 'Schema Fatturazione riallineato automaticamente per questo spazio.',
                    'cli_messages' => (array) ($runResult['cli_messages'] ?? []),
                ];
            }

            return $this->statusCache[$cacheKey] = [
                'ready' => false,
                'status' => 'error',
                'applied' => true,
                'tenant_id' => $resolvedTenantId,
                'tenant_name' => trim((string) ($tenant['tenant_name'] ?? '')),
                'message' => 'Allineamento schema Fatturazione eseguito, ma la tabella billing_documents non risulta ancora disponibile.',
                'cli_messages' => (array) ($runResult['cli_messages'] ?? []),
            ];
        } catch (\Throwable $e) {
            return $this->statusCache[$cacheKey] = [
                'ready' => false,
                'status' => 'error',
                'applied' => false,
                'tenant_id' => $resolvedTenantId,
                'tenant_name' => trim((string) ($tenant['tenant_name'] ?? '')),
                'message' => 'Allineamento schema Fatturazione non riuscito: ' . $e->getMessage(),
                'cli_messages' => [],
            ];
        }
    }

    private function hasRequiredSchema(BaseConnection $db): bool
    {
        if (!$db->tableExists('billing_documents')) {
            return false;
        }

        $fieldNames = array_map('strtolower', $db->getFieldNames('billing_documents'));
        foreach ([
            'document_number',
            'document_type',
            'issue_date',
            'patient_name',
            'patient_email',
            'amount_total',
            'due_date',
            'payment_status',
            'invoice_email_sent_at',
            'last_reminder_sent_at',
            'reminder_count',
            'ts_sync_state',
            'local_state',
        ] as $field) {
            if (!in_array($field, $fieldNames, true)) {
                return false;
            }
        }

        if (!$db->tableExists('billing_document_email_log')) {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $tenant
     * @return array<string, mixed>
     */
    private function runFilteredMigrations(array $tenant): array
    {
        $connectionConfig = $this->tenantDbConnector->buildConnectionConfig($tenant);
        $tempPath = $this->prepareFilteredMigrationPath();
        $historyGroup = $this->resolveTenantHistoryGroup();

        try {
            $runner = new FilteredMigrationRunner($this->migrationsConfig, $connectionConfig);
            $runner->setNamespace(APP_NAMESPACE);
            $runner->setCustomPath($tempPath);
            $runner->setSilent(false);
            $runner->clearCliMessages();
            $runner->latest($historyGroup);

            return [
                'cli_messages' => $runner->getCliMessages(),
            ];
        } finally {
            $this->cleanupDirectory($tempPath);
        }
    }

    private function prepareFilteredMigrationPath(): string
    {
        $sourceDir = APPPATH . 'Database' . DIRECTORY_SEPARATOR . 'Migrations';
        $targetDir = rtrim(WRITEPATH, '\\/') . DIRECTORY_SEPARATOR . 'billing-migration-runtime' . DIRECTORY_SEPARATOR . bin2hex(random_bytes(8));

        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new \RuntimeException('Impossibile creare la cartella temporanea per le migration Fatturazione.');
        }

        foreach (self::TENANT_MIGRATION_FILES as $file) {
            $sourcePath = $sourceDir . DIRECTORY_SEPARATOR . $file;
            $targetPath = $targetDir . DIRECTORY_SEPARATOR . $file;

            if (!is_file($sourcePath)) {
                throw new \RuntimeException('Migration Fatturazione non trovata localmente: ' . $file);
            }

            if (!copy($sourcePath, $targetPath)) {
                throw new \RuntimeException('Impossibile preparare la migration Fatturazione: ' . $file);
            }
        }

        return $targetDir;
    }

    private function cleanupDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = scandir($directory);
        if (is_array($items)) {
            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }

                $path = $directory . DIRECTORY_SEPARATOR . $item;
                if (is_file($path)) {
                    @unlink($path);
                }
            }
        }

        @rmdir($directory);
    }

    private function resolveTenantHistoryGroup(): string
    {
        $group = trim((string) (config(DatabaseConfig::class)->defaultGroup ?? 'default'));

        return $group !== '' ? $group : 'default';
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveTenantRecord(int $tenantId = 0): array
    {
        $tenant = $tenantId > 0
            ? $this->tenantCatalog->getTenantById($tenantId)
            : $this->tenantCatalog->resolveCurrentRuntimeTenant();

        if (!is_array($tenant) || (int) ($tenant['id_tenant'] ?? 0) <= 0) {
            throw new \RuntimeException('Tenant non trovato.');
        }

        return $tenant;
    }
}
