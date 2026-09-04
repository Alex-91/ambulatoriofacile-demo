<?php

namespace App\Services;

use App\Libraries\FilteredMigrationRunner;
use CodeIgniter\Database\BaseConnection;
use Config\Database as DatabaseConfig;
use Config\Migrations as MigrationsConfig;

class FseTenantSchemaService
{
    private const TENANT_MIGRATION_FILES = [
        '2026-09-04-010003_CreateFseDocumentsTables.php',
    ];

    private TenantCatalogService $tenantCatalog;
    private TenantDatabaseConnector $tenantDbConnector;
    private MigrationsConfig $migrationsConfig;

    /** @var array<string, array<string, mixed>> */
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

    /** @return array<string, mixed> */
    public function ensureTenantSchemaReady(int $tenantId = 0, bool $attemptRepair = true): array
    {
        try {
            $tenant = $this->resolveTenantRecord($tenantId);
        } catch (\Throwable $e) {
            return $this->result(false, 'error', false, 0, '', 'Tenant FSE non risolto: ' . $e->getMessage());
        }

        $resolvedTenantId = (int) ($tenant['id_tenant'] ?? 0);
        $tenantName = trim((string) ($tenant['tenant_name'] ?? ''));
        $cacheKey = $resolvedTenantId . ':' . ($attemptRepair ? '1' : '0');
        if (isset($this->statusCache[$cacheKey])) {
            return $this->statusCache[$cacheKey];
        }

        try {
            $db = $this->tenantDbConnector->connect($tenant);
            if ($this->hasRequiredSchema($db)) {
                return $this->statusCache[$cacheKey] = $this->result(
                    true,
                    'ok',
                    false,
                    $resolvedTenantId,
                    $tenantName,
                    'Schema FSE già pronto per questo spazio.'
                );
            }

            if (!$attemptRepair) {
                return $this->statusCache[$cacheKey] = $this->result(
                    false,
                    'warning',
                    false,
                    $resolvedTenantId,
                    $tenantName,
                    'Le tabelle FSE non sono ancora presenti nel database dello spazio.'
                );
            }

            $runResult = $this->runFilteredMigrations($tenant);
            $verificationDb = $this->tenantDbConnector->connect($tenant);
            if ($this->hasRequiredSchema($verificationDb)) {
                return $this->statusCache[$cacheKey] = $this->result(
                    true,
                    'ok',
                    true,
                    $resolvedTenantId,
                    $tenantName,
                    'Schema FSE installato nel database dello spazio.',
                    (array) ($runResult['cli_messages'] ?? [])
                );
            }

            return $this->statusCache[$cacheKey] = $this->result(
                false,
                'error',
                true,
                $resolvedTenantId,
                $tenantName,
                'Migration FSE eseguita, ma le tabelle richieste non risultano disponibili.',
                (array) ($runResult['cli_messages'] ?? [])
            );
        } catch (\Throwable $e) {
            return $this->statusCache[$cacheKey] = $this->result(
                false,
                'error',
                false,
                $resolvedTenantId,
                $tenantName,
                'Installazione schema FSE non riuscita: ' . $e->getMessage()
            );
        }
    }

    public function isReady(array $status): bool
    {
        return !empty($status['ready']) && (string) ($status['status'] ?? '') === 'ok';
    }

    private function hasRequiredSchema(BaseConnection $db): bool
    {
        if (!$db->tableExists('fse_documents') || !$db->tableExists('fse_document_events')) {
            return false;
        }

        $documentFields = array_map('strtolower', $db->getFieldNames('fse_documents'));
        foreach ([
            'id_fse_document',
            'local_state',
            'document_unique_id',
            'patient_cf_enc',
            'patient_birth_date_enc',
            'report_text_enc',
            'workflow_instance_id',
            'validated_at',
            'published_at',
        ] as $field) {
            if (!in_array($field, $documentFields, true)) {
                return false;
            }
        }

        $eventFields = array_map('strtolower', $db->getFieldNames('fse_document_events'));
        foreach (['id_fse_event', 'id_fse_document', 'event_type', 'context_json', 'created_at'] as $field) {
            if (!in_array($field, $eventFields, true)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $tenant @return array<string, mixed> */
    private function runFilteredMigrations(array $tenant): array
    {
        $connectionConfig = $this->tenantDbConnector->buildConnectionConfig($tenant);
        $tempPath = $this->prepareFilteredMigrationPath();

        try {
            $runner = new FilteredMigrationRunner($this->migrationsConfig, $connectionConfig);
            $runner->setNamespace(APP_NAMESPACE);
            $runner->setCustomPath($tempPath);
            $runner->setSilent(false);
            $runner->clearCliMessages();
            $runner->latest($this->resolveTenantHistoryGroup());

            return ['cli_messages' => $runner->getCliMessages()];
        } finally {
            $this->cleanupDirectory($tempPath);
        }
    }

    private function prepareFilteredMigrationPath(): string
    {
        $sourceDir = APPPATH . 'Database' . DIRECTORY_SEPARATOR . 'Migrations';
        $targetDir = rtrim(WRITEPATH, '\\/') . DIRECTORY_SEPARATOR . 'fse-migration-runtime' . DIRECTORY_SEPARATOR . bin2hex(random_bytes(8));

        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new \RuntimeException('Impossibile creare la cartella temporanea per le migration FSE.');
        }

        foreach (self::TENANT_MIGRATION_FILES as $file) {
            $sourcePath = $sourceDir . DIRECTORY_SEPARATOR . $file;
            $targetPath = $targetDir . DIRECTORY_SEPARATOR . $file;
            if (!is_file($sourcePath)) {
                throw new \RuntimeException('Migration FSE non trovata localmente: ' . $file);
            }
            if (!copy($sourcePath, $targetPath)) {
                throw new \RuntimeException('Impossibile preparare la migration FSE: ' . $file);
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

    /** @return array<string, mixed> */
    private function resolveTenantRecord(int $tenantId): array
    {
        $tenant = $tenantId > 0
            ? $this->tenantCatalog->getTenantById($tenantId)
            : $this->tenantCatalog->resolveCurrentRuntimeTenant();
        if (!is_array($tenant) || (int) ($tenant['id_tenant'] ?? 0) <= 0) {
            throw new \RuntimeException('Tenant non trovato.');
        }
        return $tenant;
    }

    /** @param array<int, mixed> $cliMessages @return array<string, mixed> */
    private function result(bool $ready, string $status, bool $applied, int $tenantId, string $tenantName, string $message, array $cliMessages = []): array
    {
        return [
            'ready' => $ready,
            'status' => $status,
            'applied' => $applied,
            'tenant_id' => $tenantId,
            'tenant_name' => $tenantName,
            'message' => $message,
            'cli_messages' => $cliMessages,
        ];
    }
}
