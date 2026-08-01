<?php

namespace App\Services;

use App\Config\TsBilling;
use App\Libraries\FilteredMigrationRunner;
use CodeIgniter\Database\BaseConnection;
use Config\Database as DatabaseConfig;
use Config\Migrations as MigrationsConfig;

class TsMigrationSafetyService
{
    private const PLATFORM_SCOPE = 'platform';
    private const TENANT_SCOPE = 'tenant';
    private const NON_TS_PENDING_PREVIEW = 5;

    /**
     * @var array<int, string>
     */
    private const TS_PLATFORM_MIGRATIONS = [
        '2026-07-04-000001_AddTsBillingFeature.php',
        '2026-07-04-000002_CreatePlatformTenantTsProfiles.php',
        '2026-07-05-000005_SetTsBillingFeaturePlatformManaged.php',
    ];

    /**
     * @var array<int, string>
     */
    private const TS_TENANT_MIGRATIONS = [
        '2026-07-04-000003_CreateTsDocumentsTables.php',
        '2026-07-05-000004_AddTsVatMetadataToDocuments.php',
        '2026-07-05-000006_RepairTsDocumentsVatMetadataColumns.php',
    ];

    private TenantCatalogService $tenantCatalog;
    private TenantDatabaseConnector $tenantDbConnector;
    private MigrationsConfig $migrationsConfig;

    /**
     * @var array<string, array<string, mixed>>|null
     */
    private ?array $cachedMigrationDescriptors = null;

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
    public function inspectAll(?int $tenantId = null): array
    {
        $platform = $this->inspectPlatform();
        $tenant = $this->inspectTenant($tenantId);

        $errors = array_values(array_unique(array_merge(
            (array) ($platform['errors'] ?? []),
            (array) ($tenant['errors'] ?? [])
        )));
        $warnings = array_values(array_unique(array_merge(
            (array) ($platform['warnings'] ?? []),
            (array) ($tenant['warnings'] ?? [])
        )));

        return [
            'status' => $this->resolveStatus($errors, $warnings),
            'platform' => $platform,
            'tenant' => $tenant,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function inspectPlatform(): array
    {
        try {
            return $this->inspectTargetContext($this->resolvePlatformContext());
        } catch (\Throwable $e) {
            return $this->buildFailedInspection(
                self::PLATFORM_SCOPE,
                'Database platform TS',
                $e
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function inspectTenant(?int $tenantId = null): array
    {
        try {
            $tenant = $this->resolveTenantRecord($tenantId);

            return $this->inspectTargetContext($this->resolveTenantContext($tenant));
        } catch (\Throwable $e) {
            return $this->buildFailedInspection(
                self::TENANT_SCOPE,
                'Database tenant TS',
                $e
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function migrateSafe(string $scope = 'all', ?int $tenantId = null, bool $allowDrift = false): array
    {
        $contexts = $this->resolveRequestedContexts($scope, $tenantId);
        $results = [];

        foreach ($contexts as $context) {
            $results[(string) ($context['scope'] ?? count($results))] = $this->migrateTargetContext($context, $allowDrift);
        }

        $statuses = array_map(
            static fn(array $result): string => trim((string) ($result['status'] ?? 'error')),
            $results
        );

        return [
            'status' => $this->mergeStatuses($statuses),
            'scope' => $scope,
            'allow_drift' => $allowDrift,
            'results' => $results,
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function inspectTargetContext(array $context): array
    {
        $historyIndex = $this->resolveHistoryIndex(
            $context['runner_connection'] ?? null,
            (string) ($context['history_group'] ?? 'default')
        );
        $descriptors = $this->resolveAppMigrationDescriptors();
        $targetGroup = (string) ($context['history_group'] ?? 'default');

        $targetDescriptors = array_values(array_filter(
            $descriptors,
            static fn(array $descriptor): bool => (string) ($descriptor['effective_group'] ?? 'default') === $targetGroup
        ));

        $tsMigrations = [];
        $pendingTsMigrations = [];
        $missingLocalMigrations = [];
        $expectedMigrationResolution = [];
        $expectedTsFiles = array_fill_keys((array) ($context['migration_files'] ?? []), true);

        foreach ((array) ($context['migration_files'] ?? []) as $file) {
            $descriptor = $descriptors[$file] ?? null;
            $expectedMigrationResolution[] = [
                'file' => (string) $file,
                'descriptor_found' => is_array($descriptor),
                'descriptor_class' => is_array($descriptor) ? (string) ($descriptor['class'] ?? '') : '',
                'descriptor_declared_group' => is_array($descriptor) ? (string) ($descriptor['declared_group'] ?? '') : '',
                'descriptor_effective_group' => is_array($descriptor) ? (string) ($descriptor['effective_group'] ?? '') : '',
                'descriptor_path' => is_array($descriptor) ? (string) ($descriptor['path'] ?? '') : '',
                'target_group' => $targetGroup,
            ];

            if (!is_array($descriptor) || (string) ($descriptor['effective_group'] ?? '') !== $targetGroup) {
                $missingLocalMigrations[] = (string) $file;
                $tsMigrations[] = [
                    'file' => (string) $file,
                    'status' => 'error',
                    'applied' => false,
                    'message' => 'Migration TS attesa ma non trovata localmente nel gruppo corretto.',
                ];
                continue;
            }

            $applied = !empty($historyIndex[(string) ($descriptor['uid'] ?? '')]);
            $migrationRow = [
                'file' => (string) ($descriptor['file'] ?? $file),
                'class' => (string) ($descriptor['class'] ?? ''),
                'version' => (string) ($descriptor['version'] ?? ''),
                'status' => $applied ? 'ok' : 'warning',
                'applied' => $applied,
                'message' => $applied
                    ? 'Migration TS già registrata nella history del database.'
                    : 'Migration TS ancora pendente nella history del database.',
            ];

            $tsMigrations[] = $migrationRow;

            if (!$applied) {
                $pendingTsMigrations[] = $migrationRow;
            }
        }

        $pendingNonTsMigrations = array_values(array_filter(
            $targetDescriptors,
            static function (array $descriptor) use ($expectedTsFiles, $historyIndex): bool {
                $file = (string) ($descriptor['file'] ?? '');
                $uid = (string) ($descriptor['uid'] ?? '');

                return $file !== ''
                    && !isset($expectedTsFiles[$file])
                    && empty($historyIndex[$uid]);
            }
        ));

        $schemaChecks = $this->buildSchemaChecks(
            $context['schema_db'],
            (array) ($context['required_schema'] ?? [])
        );
        $extraChecks = (array) ($context['extra_checks'] ?? []);

        $errors = [];
        $warnings = [];

        if ($missingLocalMigrations !== []) {
            $errors[] = 'Mancano localmente alcune migration TS attese per ' . (string) ($context['label'] ?? 'questo target') . ': '
                . implode(', ', $missingLocalMigrations) . '.';
        }

        foreach (array_merge($schemaChecks, $extraChecks) as $check) {
            $status = trim((string) ($check['status'] ?? ''));
            $message = trim((string) ($check['message'] ?? ''));
            if ($message === '') {
                continue;
            }

            if ($status === 'error') {
                $errors[] = $message;
            } elseif ($status === 'warning') {
                $warnings[] = $message;
            }
        }

        if ($pendingTsMigrations !== []) {
            $warnings[] = 'Sono presenti ' . count($pendingTsMigrations) . ' migration TS pendenti per '
                . (string) ($context['label'] ?? 'questo target') . ': '
                . $this->previewMigrationFiles($pendingTsMigrations) . '.';
        }

        if ($pendingNonTsMigrations !== []) {
            $warnings[] = 'Sono presenti ' . count($pendingNonTsMigrations) . ' migration App non TS pendenti per '
                . (string) ($context['label'] ?? 'questo target') . ': '
                . $this->previewMigrationFiles($pendingNonTsMigrations) . '.';
        }

        $errors = array_values(array_unique($errors));
        $warnings = array_values(array_unique($warnings));
        $status = $this->resolveStatus($errors, $warnings);

        return [
            'scope' => (string) ($context['scope'] ?? ''),
            'label' => (string) ($context['label'] ?? ''),
            'status' => $status,
            'message' => $this->buildInspectionMessage(
                (string) ($context['label'] ?? 'Target TS'),
                $status,
                count($pendingTsMigrations),
                count($pendingNonTsMigrations)
            ),
            'connection' => (array) ($context['connection'] ?? []),
            'tenant' => (array) ($context['tenant'] ?? []),
            'ts_migrations' => $tsMigrations,
            'pending_ts_migrations' => $pendingTsMigrations,
            'pending_non_ts_migrations' => array_map(
                static fn(array $descriptor): array => [
                    'file' => (string) ($descriptor['file'] ?? ''),
                    'class' => (string) ($descriptor['class'] ?? ''),
                    'version' => (string) ($descriptor['version'] ?? ''),
                    'effective_group' => (string) ($descriptor['effective_group'] ?? ''),
                ],
                $pendingNonTsMigrations
            ),
            'schema_checks' => $schemaChecks,
            'extra_checks' => $extraChecks,
            'expected_migration_resolution' => $expectedMigrationResolution,
            'missing_local_migrations' => $missingLocalMigrations,
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function migrateTargetContext(array $context, bool $allowDrift): array
    {
        $before = $this->inspectTargetContext($context);
        $pendingTs = (array) ($before['pending_ts_migrations'] ?? []);
        $pendingNonTs = (array) ($before['pending_non_ts_migrations'] ?? []);
        $missingLocal = (array) ($before['missing_local_migrations'] ?? []);

        if ($missingLocal !== []) {
            return [
                'status' => 'error',
                'message' => 'Impossibile eseguire migration TS: mancano file locali richiesti.',
                'before' => $before,
                'after' => $before,
                'cli_messages' => [],
            ];
        }

        if ($pendingTs === []) {
            return [
                'status' => in_array((string) ($before['status'] ?? 'ok'), ['error', 'warning'], true)
                    ? (string) ($before['status'] ?? 'warning')
                    : 'ok',
                'message' => 'Nessuna migration TS pendente per ' . (string) ($context['label'] ?? 'questo target') . '.',
                'before' => $before,
                'after' => $before,
                'cli_messages' => [],
            ];
        }

        if ($pendingNonTs !== [] && !$allowDrift) {
            return [
                'status' => 'blocked',
                'message' => 'Sono presenti migration non TS pendenti nello stesso gruppo. Riesegui con --allow-drift=1 solo se vuoi applicare esclusivamente il pacchetto TS filtrato.',
                'before' => $before,
                'after' => $before,
                'cli_messages' => [],
            ];
        }

        try {
            $runResult = $this->runFilteredMigrations($context);
            $after = $this->inspectTargetContext($context);
        } catch (\Throwable $e) {
            return [
                'status' => 'error',
                'message' => 'Esecuzione migration TS fallita: ' . $e->getMessage(),
                'before' => $before,
                'after' => $before,
                'cli_messages' => [],
            ];
        }

        $status = in_array((string) ($after['status'] ?? 'ok'), ['error', 'warning'], true)
            ? (string) ($after['status'] ?? 'warning')
            : 'ok';

        return [
            'status' => $status,
            'message' => $status === 'ok'
                ? 'Migration TS applicate con successo per ' . (string) ($context['label'] ?? 'questo target') . '.'
                : 'Migration TS applicate, ma restano avvisi da verificare per ' . (string) ($context['label'] ?? 'questo target') . '.',
            'before' => $before,
            'after' => $after,
            'cli_messages' => (array) ($runResult['cli_messages'] ?? []),
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function runFilteredMigrations(array $context): array
    {
        $files = array_values((array) ($context['migration_files'] ?? []));
        $tempPath = $this->prepareFilteredMigrationPath($files);

        try {
            $runner = new FilteredMigrationRunner($this->migrationsConfig, $context['runner_connection'] ?? null);
            $runner->setNamespace(APP_NAMESPACE);
            $runner->setCustomPath($tempPath);
            $runner->setSilent(false);
            $runner->clearCliMessages();
            $runner->latest((string) ($context['history_group'] ?? 'default'));

            return [
                'cli_messages' => $runner->getCliMessages(),
            ];
        } finally {
            $this->cleanupDirectory($tempPath);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function resolveRequestedContexts(string $scope, ?int $tenantId): array
    {
        $scope = trim(strtolower($scope));
        if (!in_array($scope, ['platform', 'tenant', 'all'], true)) {
            throw new \InvalidArgumentException('Scope TS non valido. Usa platform, tenant oppure all.');
        }

        $contexts = [];
        if ($scope === 'platform' || $scope === 'all') {
            $contexts[] = $this->resolvePlatformContext();
        }
        if ($scope === 'tenant' || $scope === 'all') {
            $contexts[] = $this->resolveTenantContext($this->resolveTenantRecord($tenantId));
        }

        return $contexts;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolvePlatformContext(): array
    {
        $databaseConfig = config(DatabaseConfig::class);
        $platformConfig = is_array($databaseConfig->platform ?? null) ? $databaseConfig->platform : [];
        $db = DatabaseConfig::connect('platform');

        return [
            'scope' => self::PLATFORM_SCOPE,
            'label' => 'Database platform TS',
            'runner_connection' => 'platform',
            'history_group' => 'platform',
            'schema_db' => $db,
            'migration_files' => self::TS_PLATFORM_MIGRATIONS,
            'required_schema' => [
                'platform_tenant_ts_profiles' => [
                    'id_tenant',
                    'profile_name',
                    'owner_piva',
                    'auth_username',
                    'auth_password_enc',
                    'pincode_enc',
                    'environment',
                    'metadata_json',
                ],
            ],
            'connection' => $this->buildConnectionSummary('platform', $platformConfig),
            'extra_checks' => $this->buildPlatformExtraChecks($db),
        ];
    }

    /**
     * @param array<string, mixed> $tenant
     * @return array<string, mixed>
     */
    private function resolveTenantContext(array $tenant): array
    {
        $connectionConfig = $this->tenantDbConnector->buildConnectionConfig($tenant);
        $db = DatabaseConfig::connect($connectionConfig, false);
        $tenantHistoryGroup = $this->resolveTenantHistoryGroup();

        return [
            'scope' => self::TENANT_SCOPE,
            'label' => 'Database tenant TS',
            'runner_connection' => $connectionConfig,
            'history_group' => $tenantHistoryGroup,
            'schema_db' => $db,
            'migration_files' => self::TS_TENANT_MIGRATIONS,
            'required_schema' => [
                'ts_documents' => [
                    'document_identifier_hash',
                    'document_device',
                    'expense_type_code',
                    'payment_mode',
                    'document_type',
                    'vat_rate',
                    'vat_nature',
                    'ts_protocol',
                ],
                'ts_document_events' => [
                    'id_ts_document',
                    'event_type',
                    'event_level',
                    'message',
                ],
                'ts_document_receipts' => [
                    'id_ts_document',
                    'receipt_type',
                    'ts_protocol',
                    'storage_path',
                    'mime_type',
                ],
            ],
            'connection' => $this->buildConnectionSummary($tenantHistoryGroup, $connectionConfig),
            'tenant' => [
                'id_tenant' => (int) ($tenant['id_tenant'] ?? 0),
                'tenant_key' => trim((string) ($tenant['tenant_key'] ?? '')),
                'tenant_name' => trim((string) ($tenant['tenant_name'] ?? '')),
            ],
            'extra_checks' => $this->buildTenantExtraChecks($tenant),
        ];
    }

    private function resolveTenantHistoryGroup(): string
    {
        $group = trim((string) (config(DatabaseConfig::class)->defaultGroup ?? 'default'));

        return $group !== '' ? $group : 'default';
    }

    /**
     * @param array<string, mixed>|null $runnerConnection
     * @return array<string, bool>
     */
    private function resolveHistoryIndex($runnerConnection, string $historyGroup): array
    {
        $runner = new FilteredMigrationRunner($this->migrationsConfig, $runnerConnection);
        $runner->setNamespace(APP_NAMESPACE);
        $history = $runner->getHistory($historyGroup);

        $index = [];
        foreach ($history as $row) {
            $uid = $runner->getObjectUid($row);
            $index[$uid] = true;
        }

        return $index;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function resolveAppMigrationDescriptors(): array
    {
        if (is_array($this->cachedMigrationDescriptors)) {
            return $this->cachedMigrationDescriptors;
        }

        $defaultGroup = (string) (config(DatabaseConfig::class)->defaultGroup ?? 'default');
        $runner = new FilteredMigrationRunner($this->migrationsConfig, 'default');
        $runner->setNamespace(APP_NAMESPACE);

        $descriptors = [];
        foreach ($runner->findMigrations() as $migration) {
            $class = (string) ($migration->class ?? '');
            $declaredGroup = $this->readDeclaredDbGroup((string) ($migration->path ?? ''));
            $file = basename((string) $migration->path);
            $descriptors[$file] = [
                'file' => $file,
                'class' => $class,
                'version' => (string) ($migration->version ?? ''),
                'uid' => (string) ($migration->uid ?? ''),
                'path' => (string) ($migration->path ?? ''),
                'declared_group' => $declaredGroup,
                'effective_group' => trim((string) ($declaredGroup ?? $defaultGroup)),
            ];
        }

        ksort($descriptors);
        $this->cachedMigrationDescriptors = $descriptors;

        return $this->cachedMigrationDescriptors;
    }

    private function readDeclaredDbGroup(string $path): ?string
    {
        $path = trim($path);
        if ($path === '' || !is_file($path)) {
            return null;
        }

        $source = @file_get_contents($path);
        if (!is_string($source) || $source === '') {
            return null;
        }

        if (preg_match('/protected\s+\$DBGroup\s*=\s*[\'"]([^\'"]+)[\'"]\s*;/', $source, $matches) === 1) {
            return trim((string) ($matches[1] ?? '')) ?: null;
        }

        return null;
    }

    /**
     * @param array<string, array<int, string>> $requiredSchema
     * @return array<int, array<string, string>>
     */
    private function buildSchemaChecks(BaseConnection $db, array $requiredSchema): array
    {
        $checks = [];

        foreach ($requiredSchema as $table => $columns) {
            if (!$db->tableExists($table)) {
                $checks[] = [
                    'label' => 'Tabella ' . $table,
                    'status' => 'error',
                    'message' => 'Manca la tabella richiesta `' . $table . '` nel database TS corrente.',
                ];
                continue;
            }

            $fieldNames = array_map('strtolower', $db->getFieldNames($table));
            $missingColumns = [];
            foreach ($columns as $column) {
                if (!in_array(strtolower((string) $column), $fieldNames, true)) {
                    $missingColumns[] = (string) $column;
                }
            }

            if ($missingColumns !== []) {
                $checks[] = [
                    'label' => 'Schema ' . $table,
                    'status' => 'error',
                    'message' => 'La tabella `' . $table . '` esiste ma mancano le colonne: ' . implode(', ', $missingColumns) . '.',
                ];
                continue;
            }

            $checks[] = [
                'label' => 'Schema ' . $table,
                'status' => 'ok',
                'message' => 'La tabella `' . $table . '` è presente con le colonne chiave attese.',
            ];
        }

        return $checks;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function buildPlatformExtraChecks(BaseConnection $db): array
    {
        if (!$db->tableExists('platform_features')) {
            return [[
                'label' => 'Feature catalog platform',
                'status' => 'error',
                'message' => 'La tabella `platform_features` non è disponibile nel database platform.',
            ]];
        }

        $featureExists = $db->table('platform_features')
            ->where('feature_key', TsBilling::FEATURE_KEY)
            ->countAllResults() > 0;

        return [[
            'label' => 'Feature Sistema TS registrata',
            'status' => $featureExists ? 'ok' : 'error',
            'message' => $featureExists
                ? 'La feature `ts_billing` risulta registrata nel catalogo platform.'
                : 'La feature `ts_billing` non risulta ancora registrata nel catalogo platform.',
        ]];
    }

    /**
     * @param array<string, mixed> $tenant
     * @return array<int, array<string, string>>
     */
    private function buildTenantExtraChecks(array $tenant): array
    {
        $runtimeTenant = $this->tenantCatalog->resolveCurrentRuntimeTenant();
        if (!is_array($runtimeTenant) || (int) ($runtimeTenant['id_tenant'] ?? 0) <= 0) {
            return [[
                'label' => 'Runtime tenant locale',
                'status' => 'warning',
                'message' => 'Il database default locale non è associato automaticamente a un tenant platform: la UI admin TS lavorerà comunque sul runtime corrente, quindi verifica a mano l’allineamento.',
            ]];
        }

        $runtimeTenantId = (int) ($runtimeTenant['id_tenant'] ?? 0);
        $targetTenantId = (int) ($tenant['id_tenant'] ?? 0);

        if ($runtimeTenantId === $targetTenantId) {
            return [[
                'label' => 'Runtime tenant locale',
                'status' => 'ok',
                'message' => 'Il runtime locale punta allo stesso tenant che stai configurando per la TS.',
            ]];
        }

        return [[
            'label' => 'Runtime tenant locale',
            'status' => 'warning',
            'message' => 'Il runtime locale corrente punta al tenant `'
                . trim((string) ($runtimeTenant['tenant_name'] ?? $runtimeTenant['tenant_key'] ?? $runtimeTenantId))
                . '`, mentre questo controllo sta leggendo il tenant `'
                . trim((string) ($tenant['tenant_name'] ?? $tenant['tenant_key'] ?? $targetTenantId))
                . '`. La configurazione TS è corretta, ma la UI admin documenti userà il DB runtime attuale finché non riallinei l’ambiente.',
        ]];
    }

    /**
     * @param array<string, mixed> $tenant
     * @return array<string, mixed>
     */
    private function resolveTenantRecord(?int $tenantId): array
    {
        $tenant = $tenantId !== null && $tenantId > 0
            ? $this->tenantCatalog->getTenantById($tenantId)
            : $this->tenantCatalog->resolveCurrentRuntimeTenant();

        if (!is_array($tenant) || (int) ($tenant['id_tenant'] ?? 0) <= 0) {
            throw new \RuntimeException('Tenant TS non risolto. Passa --tenant-id oppure controlla il mapping del runtime locale.');
        }

        return $tenant;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function buildConnectionSummary(string $group, array $config): array
    {
        return [
            'group' => $group,
            'host' => trim((string) ($config['hostname'] ?? '')),
            'port' => (int) ($config['port'] ?? 0),
            'database' => trim((string) ($config['database'] ?? '')),
            'driver' => trim((string) ($config['DBDriver'] ?? '')),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $migrations
     */
    private function previewMigrationFiles(array $migrations): string
    {
        $files = [];
        foreach (array_slice($migrations, 0, self::NON_TS_PENDING_PREVIEW) as $migration) {
            $file = trim((string) ($migration['file'] ?? ''));
            if ($file !== '') {
                $files[] = $file;
            }
        }

        $preview = implode(', ', $files);
        if (count($migrations) > self::NON_TS_PENDING_PREVIEW) {
            $preview .= ' +' . (count($migrations) - self::NON_TS_PENDING_PREVIEW) . ' altre';
        }

        return $preview;
    }

    private function buildInspectionMessage(string $label, string $status, int $pendingTsCount, int $pendingNonTsCount): string
    {
        return match ($status) {
            'ok' => $label . ': schema e migrazioni TS risultano allineati.',
            'warning' => $label . ': base TS utilizzabile con avvisi'
                . ($pendingTsCount > 0 ? ' (' . $pendingTsCount . ' migration TS pendenti)' : '')
                . ($pendingNonTsCount > 0 ? ' e drift App non TS nello stesso gruppo' : '')
                . '.',
            default => $label . ': controllo non superato, correggi prima schema o configurazione tecnica.',
        };
    }

    /**
     * @param array<int, string> $errors
     * @param array<int, string> $warnings
     */
    private function resolveStatus(array $errors, array $warnings): string
    {
        if ($errors !== []) {
            return 'error';
        }

        return $warnings !== [] ? 'warning' : 'ok';
    }

    /**
     * @param array<int, string> $statuses
     */
    private function mergeStatuses(array $statuses): string
    {
        $rank = [
            'ok' => 0,
            'warning' => 1,
            'blocked' => 2,
            'error' => 3,
        ];

        $winningStatus = 'ok';
        $winningRank = -1;

        foreach ($statuses as $status) {
            $status = trim($status);
            $currentRank = $rank[$status] ?? $rank['error'];
            if ($currentRank > $winningRank) {
                $winningRank = $currentRank;
                $winningStatus = $status;
            }
        }

        return $winningStatus;
    }

    private function prepareFilteredMigrationPath(array $files): string
    {
        $sourceDir = APPPATH . 'Database' . DIRECTORY_SEPARATOR . 'Migrations';
        $targetDir = rtrim(WRITEPATH, '\\/') . DIRECTORY_SEPARATOR . 'ts-migration-runtime' . DIRECTORY_SEPARATOR . bin2hex(random_bytes(8));

        if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
            throw new \RuntimeException('Impossibile creare la cartella temporanea delle migration TS.');
        }

        foreach ($files as $file) {
            $sourcePath = $sourceDir . DIRECTORY_SEPARATOR . $file;
            $targetPath = $targetDir . DIRECTORY_SEPARATOR . $file;

            if (!is_file($sourcePath)) {
                throw new \RuntimeException('Migration TS locale non trovata: ' . $file);
            }

            if (!copy($sourcePath, $targetPath)) {
                throw new \RuntimeException('Impossibile copiare la migration TS: ' . $file);
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

    /**
     * @return array<string, mixed>
     */
    private function buildFailedInspection(string $scope, string $label, \Throwable $e): array
    {
        $message = $label . ': ' . $e->getMessage();

        return [
            'scope' => $scope,
            'label' => $label,
            'status' => 'error',
            'message' => $message,
            'connection' => [],
            'tenant' => [],
            'ts_migrations' => [],
            'pending_ts_migrations' => [],
            'pending_non_ts_migrations' => [],
            'schema_checks' => [],
            'extra_checks' => [],
            'missing_local_migrations' => [],
            'errors' => [$message],
            'warnings' => [],
        ];
    }
}
