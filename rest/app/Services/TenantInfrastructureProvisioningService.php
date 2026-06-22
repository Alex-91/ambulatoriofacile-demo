<?php

namespace App\Services;

use App\Libraries\FilteredMigrationRunner;
use App\Models\PlatformTenantsModel;
use Config\Database as DatabaseConfig;
use Config\Migrations as MigrationsConfig;
use mysqli;

class TenantInfrastructureProvisioningService
{
    /**
     * Platform-only migrations must never be applied inside tenant databases.
     *
     * @var array<int, string>
     */
    private array $excludedMigrationFiles = [
        '2026-06-19-000001_CreatePlatformMultiTenantFoundation.php',
        '2026-06-19-000002_CreatePlatformUserAccessTokens.php',
    ];

    private PlatformTenantsModel $tenantsModel;
    private TenantProvisioningService $tenantProvisioning;
    private TenantCatalogService $catalog;
    private TenantAppUserProvisioningService $tenantAppUsers;

    public function __construct()
    {
        $this->tenantsModel = new PlatformTenantsModel();
        $this->tenantProvisioning = new TenantProvisioningService();
        $this->catalog = new TenantCatalogService();
        $this->tenantAppUsers = new TenantAppUserProvisioningService();
    }

    /**
     * @return array<string, mixed>
     */
    public function provisionTenantInfrastructure(int $tenantId): array
    {
        $tenant = $this->catalog->getTenantById($tenantId);
        if (!$tenant) {
            throw new \RuntimeException('Spazio cliente non trovato.');
        }

        $resolvedTenant = $this->persistResolvedDatabaseDefaults($tenant);
        $adminConnection = $this->connectAdminMysql($resolvedTenant);
        try {
            $databaseCreated = $this->ensureDatabaseExists($adminConnection, (string) ($resolvedTenant['db_name'] ?? ''));
            $grantApplied = $this->ensureRuntimeUserPrivileges($adminConnection, $resolvedTenant);
            $tableCountBefore = $this->countTables($adminConnection, (string) ($resolvedTenant['db_name'] ?? ''));

            $templateMode = 'skipped';
            if ($tableCountBefore === 0) {
                $templateMode = $this->provisionTemplate($adminConnection, $resolvedTenant);
            }

            try {
                $migrationsApplied = $this->runTenantMigrations($resolvedTenant);
            } catch (\Throwable $e) {
                throw new \RuntimeException(
                    'Migrazioni tenant fallite in ' . basename((string) $e->getFile()) . ':' . (int) $e->getLine() . ' - ' . $e->getMessage(),
                    0,
                    $e
                );
            }

            try {
                $appUsersSynced = $this->tenantAppUsers->syncTenantMembers($tenantId, true);
            } catch (\Throwable $e) {
                throw new \RuntimeException(
                    'Sincronizzazione utenti tenant fallita in ' . basename((string) $e->getFile()) . ':' . (int) $e->getLine() . ' - ' . $e->getMessage(),
                    0,
                    $e
                );
            }

            try {
                $directories = $this->tenantProvisioning->prepareLocalDirectories(
                    (string) ($resolvedTenant['tenant_key'] ?? ''),
                    (string) ($resolvedTenant['storage_key'] ?? '')
                );
            } catch (\Throwable $e) {
                throw new \RuntimeException(
                    'Preparazione cartelle tenant fallita in ' . basename((string) $e->getFile()) . ':' . (int) $e->getLine() . ' - ' . $e->getMessage(),
                    0,
                    $e
                );
            }

            $metadata = $this->decodeMetadata((string) ($resolvedTenant['metadata_json'] ?? ''));
            $metadata['provisioning'] = [
                'status' => 'ready',
                'last_run_at' => date('Y-m-d H:i:s'),
                'db_created' => $databaseCreated,
                'grant_applied' => $grantApplied,
                'template_mode' => $templateMode,
                'table_count_before' => $tableCountBefore,
                'migrations_applied' => $migrationsApplied,
                'app_users_synced' => $appUsersSynced,
                'directories' => $directories,
            ];

            $updateData = [
                'metadata_json' => json_encode($metadata, JSON_UNESCAPED_SLASHES),
            ];

            if (in_array((string) ($resolvedTenant['onboarding_status'] ?? 'draft'), ['draft'], true)) {
                $updateData['onboarding_status'] = 'setup';
            }

            $this->tenantsModel->update($tenantId, $updateData);

            return [
                'tenant' => $this->catalog->getTenantById($tenantId) ?? $resolvedTenant,
                'database_created' => $databaseCreated,
                'grant_applied' => $grantApplied,
                'template_mode' => $templateMode,
                'table_count_before' => $tableCountBefore,
                'migrations_applied' => $migrationsApplied,
                'app_users_synced' => $appUsersSynced,
                'directories' => $directories,
            ];
        } finally {
            $adminConnection->close();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function resolveDatabaseDefaults(array $tenant): array
    {
        $databaseConfig = new DatabaseConfig();
        $platform = $databaseConfig->platform;
        $tenantKey = trim((string) ($tenant['tenant_key'] ?? ''));
        $storageKey = trim((string) ($tenant['storage_key'] ?? '')) !== ''
            ? trim((string) ($tenant['storage_key'] ?? ''))
            : $tenantKey;

        return [
            'db_host' => trim((string) ($tenant['db_host'] ?? '')) !== ''
                ? trim((string) ($tenant['db_host'] ?? ''))
                : $this->envValue('tenant.provisioning.runtimeHost', 'TENANT_PROVISIONING_RUNTIME_HOST', (string) ($platform['hostname'] ?? 'localhost')),
            'db_port' => (int) ($tenant['db_port'] ?? 0) > 0
                ? (int) ($tenant['db_port'] ?? 3306)
                : (int) $this->envValue('tenant.provisioning.runtimePort', 'TENANT_PROVISIONING_RUNTIME_PORT', (string) ($platform['port'] ?? 3306)),
            'db_name' => trim((string) ($tenant['db_name'] ?? '')) !== ''
                ? trim((string) ($tenant['db_name'] ?? ''))
                : $this->defaultTenantDatabaseName($tenantKey),
            'db_username' => trim((string) ($tenant['db_username'] ?? '')) !== ''
                ? trim((string) ($tenant['db_username'] ?? ''))
                : $this->envValue('tenant.provisioning.runtimeUsername', 'TENANT_PROVISIONING_RUNTIME_USERNAME', (string) ($platform['username'] ?? '')),
            'db_password_ref' => trim((string) ($tenant['db_password_ref'] ?? '')) !== ''
                ? trim((string) ($tenant['db_password_ref'] ?? ''))
                : $this->envValue('tenant.provisioning.runtimePasswordRef', 'TENANT_PROVISIONING_RUNTIME_PASSWORD_REF', ''),
            'db_driver' => trim((string) ($tenant['db_driver'] ?? '')) !== ''
                ? trim((string) ($tenant['db_driver'] ?? ''))
                : $this->envValue('tenant.provisioning.runtimeDriver', 'TENANT_PROVISIONING_RUNTIME_DRIVER', (string) ($platform['DBDriver'] ?? 'MySQLi')),
            'db_prefix' => (string) ($tenant['db_prefix'] ?? ''),
            'storage_key' => $storageKey,
        ];
    }

    /**
     * @param array<string, mixed> $tenant
     * @return array<string, mixed>
     */
    private function persistResolvedDatabaseDefaults(array $tenant): array
    {
        $tenantId = (int) ($tenant['id_tenant'] ?? 0);
        if ($tenantId <= 0) {
            throw new \RuntimeException('Tenant non valido.');
        }

        $defaults = $this->resolveDatabaseDefaults($tenant);
        $updateData = [];

        foreach ($defaults as $key => $value) {
            $currentValue = $tenant[$key] ?? null;
            if ((string) $currentValue !== (string) $value) {
                $updateData[$key] = $value;
                $tenant[$key] = $value;
            }
        }

        if (!empty($updateData)) {
            $this->tenantsModel->update($tenantId, $updateData);
            $tenant = $this->catalog->getTenantById($tenantId) ?? array_merge($tenant, $updateData);
        }

        return $tenant;
    }

    /**
     * @param array<string, mixed> $tenant
     */
    private function connectAdminMysql(array $tenant): mysqli
    {
        $databaseConfig = new DatabaseConfig();
        $platform = $databaseConfig->platform;

        $host = $this->envValue('tenant.provisioning.adminHost', 'TENANT_PROVISIONING_ADMIN_HOST', (string) ($tenant['db_host'] ?? $platform['hostname'] ?? 'localhost'));
        $port = (int) $this->envValue('tenant.provisioning.adminPort', 'TENANT_PROVISIONING_ADMIN_PORT', (string) ($tenant['db_port'] ?? $platform['port'] ?? 3306));
        $user = $this->envValue('tenant.provisioning.adminUsername', 'TENANT_PROVISIONING_ADMIN_USERNAME', (string) ($platform['username'] ?? ''));
        $password = $this->envValue('tenant.provisioning.adminPassword', 'TENANT_PROVISIONING_ADMIN_PASSWORD', (string) ($platform['password'] ?? ''));

        if ($host === '' || $user === '') {
            throw new \RuntimeException('Configurazione admin MySQL mancante per il provisioning del tenant.');
        }

        $mysqli = new mysqli($host, $user, $password, '', $port);
        if ($mysqli->connect_errno) {
            throw new \RuntimeException('Connessione MySQL admin fallita: ' . $mysqli->connect_error);
        }

        $mysqli->set_charset('utf8mb4');
        return $mysqli;
    }

    private function ensureDatabaseExists(mysqli $mysqli, string $databaseName): bool
    {
        $databaseName = trim($databaseName);
        if ($databaseName === '') {
            throw new \RuntimeException('Nome database tenant non configurato.');
        }

        $exists = $this->queryValue($mysqli, "
            SELECT SCHEMA_NAME
            FROM information_schema.SCHEMATA
            WHERE SCHEMA_NAME = '" . $mysqli->real_escape_string($databaseName) . "'
            LIMIT 1
        ");

        if ($exists !== null) {
            return false;
        }

        $sql = 'CREATE DATABASE ' . $this->escapeIdentifier($databaseName) . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
        if (!$mysqli->query($sql)) {
            throw new \RuntimeException('Creazione database tenant non riuscita: ' . $mysqli->error);
        }

        return true;
    }

    /**
     * @param array<string, mixed> $tenant
     */
    private function ensureRuntimeUserPrivileges(mysqli $mysqli, array $tenant): bool
    {
        $databaseName = trim((string) ($tenant['db_name'] ?? ''));
        $runtimeUser = trim((string) ($tenant['db_username'] ?? ''));
        $passwordRef = trim((string) ($tenant['db_password_ref'] ?? ''));
        if ($databaseName === '' || $runtimeUser === '' || $passwordRef === '') {
            return false;
        }

        $runtimePassword = (string) env($passwordRef, '');
        if ($runtimePassword === '') {
            return false;
        }

        $runtimeHost = $this->envValue('tenant.provisioning.runtimeUserHost', 'TENANT_PROVISIONING_RUNTIME_USER_HOST', '%');
        $escapedUser = $mysqli->real_escape_string($runtimeUser);
        $escapedHost = $mysqli->real_escape_string($runtimeHost);
        $escapedPassword = $mysqli->real_escape_string($runtimePassword);

        $createUserSql = "CREATE USER IF NOT EXISTS '{$escapedUser}'@'{$escapedHost}' IDENTIFIED BY '{$escapedPassword}'";
        if (!$mysqli->query($createUserSql)) {
            $message = strtolower($mysqli->error);
            if (!str_contains($message, 'exists')) {
                throw new \RuntimeException('Creazione utente MySQL tenant non riuscita: ' . $mysqli->error);
            }
        }

        $grantSql = 'GRANT ALL PRIVILEGES ON '
            . $this->escapeIdentifier($databaseName)
            . ".* TO '{$escapedUser}'@'{$escapedHost}'";

        if (!$mysqli->query($grantSql)) {
            throw new \RuntimeException('Assegnazione permessi MySQL tenant non riuscita: ' . $mysqli->error);
        }

        $mysqli->query('FLUSH PRIVILEGES');
        return true;
    }

    private function countTables(mysqli $mysqli, string $databaseName): int
    {
        $result = $this->queryValue($mysqli, "
            SELECT COUNT(*)
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = '" . $mysqli->real_escape_string($databaseName) . "'
              AND TABLE_TYPE = 'BASE TABLE'
        ");

        return (int) ($result ?? 0);
    }

    /**
     * @param array<string, mixed> $tenant
     */
    private function provisionTemplate(mysqli $mysqli, array $tenant): string
    {
        $templateDatabase = trim($this->envValue('tenant.provisioning.templateDatabase', 'TENANT_PROVISIONING_TEMPLATE_DATABASE', ''));
        $templateSqlPath = trim($this->envValue('tenant.provisioning.templateSqlPath', 'TENANT_PROVISIONING_TEMPLATE_SQL_PATH', ''));
        $databaseName = trim((string) ($tenant['db_name'] ?? ''));

        if ($templateDatabase !== '') {
            $this->cloneDatabase($mysqli, $templateDatabase, $databaseName);
            return 'clone_database';
        }

        if ($templateSqlPath !== '') {
            $this->importSqlTemplate($mysqli, $databaseName, $templateSqlPath);
            return 'import_sql';
        }

        throw new \RuntimeException('Nessun template DB configurato. Imposta TENANT_PROVISIONING_TEMPLATE_DATABASE oppure TENANT_PROVISIONING_TEMPLATE_SQL_PATH.');
    }

    private function cloneDatabase(mysqli $mysqli, string $sourceDatabase, string $targetDatabase): void
    {
        $sourceDatabase = trim($sourceDatabase);
        $targetDatabase = trim($targetDatabase);
        if ($sourceDatabase === '' || $targetDatabase === '') {
            throw new \RuntimeException('Template database non valido.');
        }

        if ($sourceDatabase === $targetDatabase) {
            throw new \RuntimeException('Il template database non puo coincidere con il tenant di destinazione.');
        }

        $exists = $this->queryValue($mysqli, "
            SELECT SCHEMA_NAME
            FROM information_schema.SCHEMATA
            WHERE SCHEMA_NAME = '" . $mysqli->real_escape_string($sourceDatabase) . "'
            LIMIT 1
        ");

        if ($exists === null) {
            throw new \RuntimeException('Template database non trovato: ' . $sourceDatabase);
        }

        $tables = $this->queryColumn($mysqli, "
            SELECT TABLE_NAME
            FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = '" . $mysqli->real_escape_string($sourceDatabase) . "'
              AND TABLE_TYPE = 'BASE TABLE'
            ORDER BY TABLE_NAME ASC
        ");

        if ($tables === []) {
            throw new \RuntimeException('Il template database non contiene tabelle base.');
        }

        $mysqli->query('SET FOREIGN_KEY_CHECKS=0');
        try {
            foreach ($tables as $tableName) {
                $sourceTable = $this->escapeIdentifier($sourceDatabase) . '.' . $this->escapeIdentifier($tableName);
                $targetTable = $this->escapeIdentifier($targetDatabase) . '.' . $this->escapeIdentifier($tableName);

                if (!$mysqli->query('CREATE TABLE ' . $targetTable . ' LIKE ' . $sourceTable)) {
                    throw new \RuntimeException('Clonazione struttura fallita per ' . $tableName . ': ' . $mysqli->error);
                }

                if (!$mysqli->query('INSERT INTO ' . $targetTable . ' SELECT * FROM ' . $sourceTable)) {
                    throw new \RuntimeException('Clonazione dati fallita per ' . $tableName . ': ' . $mysqli->error);
                }
            }
        } finally {
            $mysqli->query('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    private function importSqlTemplate(mysqli $mysqli, string $databaseName, string $sqlPath): void
    {
        $sqlPath = trim($sqlPath);
        if ($sqlPath === '' || !is_file($sqlPath)) {
            throw new \RuntimeException('Template SQL non trovato: ' . $sqlPath);
        }

        $sql = (string) file_get_contents($sqlPath);
        if ($sql === '') {
            throw new \RuntimeException('Template SQL vuoto: ' . $sqlPath);
        }

        if (!$mysqli->select_db($databaseName)) {
            throw new \RuntimeException('Impossibile selezionare il database tenant per import SQL: ' . $mysqli->error);
        }

        if (!$mysqli->multi_query($sql)) {
            throw new \RuntimeException('Import template SQL non riuscito: ' . $mysqli->error);
        }

        do {
            if ($result = $mysqli->store_result()) {
                $result->free();
            }
        } while ($mysqli->more_results() && $mysqli->next_result());

        if ($mysqli->errno) {
            throw new \RuntimeException('Import template SQL non completato: ' . $mysqli->error);
        }
    }

    /**
     * @param array<string, mixed> $tenant
     */
    private function runTenantMigrations(array $tenant): bool
    {
        $connector = new TenantDatabaseConnector();
        $connectionConfig = $connector->buildConnectionConfig($tenant);
        $tempPath = $this->prepareFilteredMigrationPath();

        try {
            $runner = new FilteredMigrationRunner(new MigrationsConfig(), $connectionConfig);
            $runner->setNamespace(APP_NAMESPACE);
            $runner->setCustomPath($tempPath);
            return (bool) $runner->latest();
        } finally {
            $this->cleanupDirectory($tempPath);
        }
    }

    private function prepareFilteredMigrationPath(): string
    {
        $sourceDir = APPPATH . 'Database' . DIRECTORY_SEPARATOR . 'Migrations';
        $targetDir = rtrim(WRITEPATH, '\\/') . DIRECTORY_SEPARATOR . 'tenant-migration-runtime' . DIRECTORY_SEPARATOR . bin2hex(random_bytes(8));

        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new \RuntimeException('Impossibile creare cartella temporanea migration: ' . $targetDir);
        }

        $files = scandir($sourceDir);
        if (!is_array($files)) {
            throw new \RuntimeException('Impossibile leggere le migration applicative.');
        }

        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }

            if (in_array($file, $this->excludedMigrationFiles, true)) {
                continue;
            }

            $sourcePath = $sourceDir . DIRECTORY_SEPARATOR . $file;
            $targetPath = $targetDir . DIRECTORY_SEPARATOR . $file;

            if (is_file($sourcePath) && !copy($sourcePath, $targetPath)) {
                throw new \RuntimeException('Impossibile preparare la migration temporanea: ' . $file);
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

    private function defaultTenantDatabaseName(string $tenantKey): string
    {
        $tenantKey = strtolower(trim($tenantKey));
        $tenantKey = preg_replace('/[^a-z0-9_]+/', '_', $tenantKey) ?? '';
        $tenantKey = trim($tenantKey, '_');
        if ($tenantKey === '') {
            throw new \RuntimeException('Chiave tenant non valida per la creazione del database.');
        }

        return 'af_' . $tenantKey;
    }

    private function escapeIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    private function envValue(string $primaryKey, string $fallbackKey, string $default = ''): string
    {
        $value = env($primaryKey);
        if ($value !== null && $value !== '') {
            return trim((string) $value);
        }

        $value = env($fallbackKey);
        if ($value !== null && $value !== '') {
            return trim((string) $value);
        }

        return $default;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeMetadata(string $json): array
    {
        $json = trim($json);
        if ($json === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function queryValue(mysqli $mysqli, string $sql): ?string
    {
        $result = $mysqli->query($sql);
        if (!$result) {
            throw new \RuntimeException('Errore SQL provisioning: ' . $mysqli->error);
        }

        $row = $result->fetch_row();
        $result->free();

        if (!is_array($row) || !array_key_exists(0, $row)) {
            return null;
        }

        return $row[0] !== null ? (string) $row[0] : null;
    }

    /**
     * @return array<int, string>
     */
    private function queryColumn(mysqli $mysqli, string $sql): array
    {
        $result = $mysqli->query($sql);
        if (!$result) {
            throw new \RuntimeException('Errore SQL provisioning: ' . $mysqli->error);
        }

        $values = [];
        while ($row = $result->fetch_row()) {
            if (is_array($row) && array_key_exists(0, $row) && $row[0] !== null) {
                $values[] = (string) $row[0];
            }
        }
        $result->free();

        return $values;
    }
}
