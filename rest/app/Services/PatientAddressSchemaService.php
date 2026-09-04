<?php

namespace App\Services;

use App\Libraries\Crypto_helper;
use App\Libraries\DatabaseConfig as EncryptionDatabaseConfig;
use App\Libraries\FilteredMigrationRunner;
use CodeIgniter\Database\BaseConnection;
use Config\Database as DatabaseConfig;
use Config\Migrations as MigrationsConfig;

class PatientAddressSchemaService
{
    private const MIGRATION_FILE = '2026-09-04-030001_BackfillPatientResidenceAndDomicile.php';

    private TenantCatalogService $tenantCatalog;
    private TenantDatabaseConnector $tenantDbConnector;
    private MigrationsConfig $migrationsConfig;

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
    public function ensureTenantReady(int $tenantId): array
    {
        $tenant = $this->tenantCatalog->getTenantById($tenantId);
        if (!is_array($tenant) || (int) ($tenant['id_tenant'] ?? 0) <= 0) {
            return [
                'ready' => false,
                'status' => 'error',
                'tenant_id' => $tenantId,
                'message' => 'Spazio non trovato.',
            ];
        }

        try {
            $db = $this->tenantDbConnector->connect($tenant);
            if (!$this->hasRequiredSchema($db)) {
                $this->runFilteredMigration($tenant);
                $db = $this->tenantDbConnector->connect($tenant);
            }

            if (!$this->hasRequiredSchema($db)) {
                throw new \RuntimeException('Lo schema indirizzi non risulta completo dopo la migrazione.');
            }

            (new EncryptionDatabaseConfig())->setEncryptionConfig($db);
            $before = $this->inspectPendingBackfill($db);
            if ($before['residence'] > 0 || $before['domicile'] > 0) {
                $this->backfillMissingAddresses($db);
            }
            $partialRepairs = $this->repairIncompleteCopiedGroup(
                $db,
                ['indirizzo', 'nr_civico', 'citta', 'cap', 'provincia'],
                [
                    'residenza_indirizzo',
                    'residenza_nr_civico',
                    'residenza_comune',
                    'residenza_cap',
                    'residenza_provincia',
                ]
            );
            $partialRepairs += $this->repairIncompleteCopiedGroup(
                $db,
                ['indirizzo', 'nr_civico', 'citta', 'cap', 'provincia'],
                [
                    'indirizzo_secondario',
                    'nr_civico_secondario',
                    'comune_secondario',
                    'cap_secondario',
                    'provincia_secondaria',
                ]
            );
            $after = $this->inspectPendingBackfill($db);
            $ready = $after['residence'] === 0 && $after['domicile'] === 0;
            return [
                'ready' => $ready,
                'status' => $ready ? 'ok' : 'error',
                'tenant_id' => (int) $tenant['id_tenant'],
                'tenant_name' => trim((string) ($tenant['tenant_name'] ?? '')),
                'backfilled_residence' => $before['residence'],
                'backfilled_domicile' => $before['domicile'],
                'repaired_partial_fields' => $partialRepairs,
                'remaining_residence' => $after['residence'],
                'remaining_domicile' => $after['domicile'],
                'message' => $ready
                    ? 'Residenza e domicilio sono pronti (residenze riallineate: '
                        . $before['residence'] . ', domicili riallineati: ' . $before['domicile']
                        . ', campi parziali completati: ' . $partialRepairs . ').'
                    : 'Riallineamento incompleto: restano ' . $after['residence']
                        . ' residenze e ' . $after['domicile'] . ' domicili da sistemare.',
            ];
        } catch (\Throwable $error) {
            return [
                'ready' => false,
                'status' => 'error',
                'tenant_id' => (int) ($tenant['id_tenant'] ?? $tenantId),
                'tenant_name' => trim((string) ($tenant['tenant_name'] ?? '')),
                'message' => $error->getMessage(),
            ];
        }
    }

    /**
     * Inspects one tenant without changing its schema or patient rows.
     *
     * @return array<string, mixed>
     */
    public function inspectTenant(int $tenantId): array
    {
        $tenant = $this->tenantCatalog->getTenantById($tenantId);
        if (!is_array($tenant) || (int) ($tenant['id_tenant'] ?? 0) <= 0) {
            return [
                'ready' => false,
                'status' => 'error',
                'tenant_id' => $tenantId,
                'message' => 'Spazio non trovato.',
            ];
        }

        try {
            $db = $this->tenantDbConnector->connect($tenant);
            if (!$db->tableExists('dap02_clients')) {
                throw new \RuntimeException('Tabella pazienti non disponibile.');
            }

            (new EncryptionDatabaseConfig())->setEncryptionConfig($db);
            $columns = array_fill_keys(array_map('strtolower', $db->getFieldNames('dap02_clients')), true);
            $mainPresent = $this->buildAvailableAddressPresentSql(
                ['indirizzo', 'nr_civico', 'citta', 'cap', 'provincia'],
                $columns
            );
            $residencePresent = $this->buildAvailableAddressPresentSql([
                'residenza_indirizzo',
                'residenza_nr_civico',
                'residenza_comune',
                'residenza_cap',
                'residenza_provincia',
            ], $columns);
            $domicilePresent = $this->buildAvailableAddressPresentSql([
                'indirizzo_secondario',
                'nr_civico_secondario',
                'comune_secondario',
                'cap_secondario',
                'provincia_secondaria',
            ], $columns);
            $mainEqualsResidence = $this->buildAvailableAddressEqualsSql(
                ['indirizzo', 'nr_civico', 'citta', 'cap', 'provincia'],
                [
                    'residenza_indirizzo',
                    'residenza_nr_civico',
                    'residenza_comune',
                    'residenza_cap',
                    'residenza_provincia',
                ],
                $columns
            );
            $mainEqualsDomicile = $this->buildAvailableAddressEqualsSql(
                ['indirizzo', 'nr_civico', 'citta', 'cap', 'provincia'],
                [
                    'indirizzo_secondario',
                    'nr_civico_secondario',
                    'comune_secondario',
                    'cap_secondario',
                    'provincia_secondaria',
                ],
                $columns
            );
            $residenceFieldMatches = $this->buildFieldMatchSelects(
                ['indirizzo', 'nr_civico', 'citta', 'cap', 'provincia'],
                [
                    'residenza_indirizzo',
                    'residenza_nr_civico',
                    'residenza_comune',
                    'residenza_cap',
                    'residenza_provincia',
                ],
                $columns,
                $mainPresent,
                'residence'
            );
            $domicileFieldMatches = $this->buildFieldMatchSelects(
                ['indirizzo', 'nr_civico', 'citta', 'cap', 'provincia'],
                [
                    'indirizzo_secondario',
                    'nr_civico_secondario',
                    'comune_secondario',
                    'cap_secondario',
                    'provincia_secondaria',
                ],
                $columns,
                $mainPresent,
                'domicile'
            );
            $row = $db->query(
                "SELECT
                    COUNT(*) AS total,
                    SUM(CASE WHEN ({$mainPresent}) THEN 1 ELSE 0 END) AS main_present,
                    SUM(CASE WHEN ({$residencePresent}) THEN 1 ELSE 0 END) AS residence_present,
                    SUM(CASE WHEN ({$domicilePresent}) THEN 1 ELSE 0 END) AS domicile_present,
                    SUM(CASE WHEN ({$mainPresent}) AND NOT ({$residencePresent}) AND NOT ({$domicilePresent}) THEN 1 ELSE 0 END) AS main_only,
                    SUM(CASE WHEN ({$mainPresent}) AND NOT ({$residencePresent}) AND ({$domicilePresent}) THEN 1 ELSE 0 END) AS main_and_domicile,
                    SUM(CASE WHEN ({$mainPresent}) AND ({$mainEqualsResidence}) THEN 1 ELSE 0 END) AS main_equals_residence,
                    SUM(CASE WHEN ({$mainPresent}) AND ({$mainEqualsDomicile}) THEN 1 ELSE 0 END) AS main_equals_domicile,
                    " . implode(",\n                    ", array_merge($residenceFieldMatches, $domicileFieldMatches)) . "
                 FROM dap02_clients"
            )->getRowArray();

            return [
                'ready' => true,
                'status' => 'ok',
                'tenant_id' => (int) $tenant['id_tenant'],
                'tenant_name' => trim((string) ($tenant['tenant_name'] ?? '')),
                'schema_ready' => $this->hasRequiredSchema($db),
                'total' => max(0, (int) ($row['total'] ?? 0)),
                'main_present' => max(0, (int) ($row['main_present'] ?? 0)),
                'residence_present' => max(0, (int) ($row['residence_present'] ?? 0)),
                'domicile_present' => max(0, (int) ($row['domicile_present'] ?? 0)),
                'main_only' => max(0, (int) ($row['main_only'] ?? 0)),
                'main_and_domicile' => max(0, (int) ($row['main_and_domicile'] ?? 0)),
                'main_equals_residence' => max(0, (int) ($row['main_equals_residence'] ?? 0)),
                'main_equals_domicile' => max(0, (int) ($row['main_equals_domicile'] ?? 0)),
                'residence_field_matches' => $this->readFieldMatchCounts($row, 'residence'),
                'domicile_field_matches' => $this->readFieldMatchCounts($row, 'domicile'),
                'message' => 'Analisi completata senza modifiche.',
            ];
        } catch (\Throwable $error) {
            return [
                'ready' => false,
                'status' => 'error',
                'tenant_id' => (int) ($tenant['id_tenant'] ?? $tenantId),
                'tenant_name' => trim((string) ($tenant['tenant_name'] ?? '')),
                'message' => $error->getMessage(),
            ];
        }
    }

    private function hasRequiredSchema(BaseConnection $db): bool
    {
        if (!$db->tableExists('dap02_clients')) {
            return false;
        }

        foreach ([
            'residenza_indirizzo',
            'residenza_nr_civico',
            'residenza_comune',
            'residenza_cap',
            'residenza_provincia',
            'indirizzo_secondario',
            'nr_civico_secondario',
            'comune_secondario',
            'cap_secondario',
            'provincia_secondaria',
        ] as $field) {
            if (!$db->fieldExists($field, 'dap02_clients')) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{residence:int, domicile:int}
     */
    private function inspectPendingBackfill(BaseConnection $db): array
    {
        $mainPresent = $this->buildAddressPresentSql(['indirizzo', 'nr_civico', 'citta', 'cap', 'provincia']);
        $residencePresent = $this->buildAddressPresentSql([
            'residenza_indirizzo',
            'residenza_nr_civico',
            'residenza_comune',
            'residenza_cap',
            'residenza_provincia',
        ]);
        $domicilePresent = $this->buildAddressPresentSql([
            'indirizzo_secondario',
            'nr_civico_secondario',
            'comune_secondario',
            'cap_secondario',
            'provincia_secondaria',
        ]);
        $row = $db->query(
            "SELECT
                SUM(CASE WHEN ({$mainPresent}) AND NOT ({$residencePresent}) THEN 1 ELSE 0 END) AS missing_residence,
                SUM(CASE WHEN ({$mainPresent}) AND NOT ({$domicilePresent}) THEN 1 ELSE 0 END) AS missing_domicile
             FROM dap02_clients"
        )->getRowArray();

        return [
            'residence' => max(0, (int) ($row['missing_residence'] ?? 0)),
            'domicile' => max(0, (int) ($row['missing_domicile'] ?? 0)),
        ];
    }

    private function backfillMissingAddresses(BaseConnection $db): void
    {
        $mainFields = ['indirizzo', 'nr_civico', 'citta', 'cap', 'provincia'];
        $residenceFields = [
            'residenza_indirizzo',
            'residenza_nr_civico',
            'residenza_comune',
            'residenza_cap',
            'residenza_provincia',
        ];
        $domicileFields = [
            'indirizzo_secondario',
            'nr_civico_secondario',
            'comune_secondario',
            'cap_secondario',
            'provincia_secondaria',
        ];
        $mainPresent = $this->buildAddressPresentSql($mainFields);
        $residencePresent = $this->buildAddressPresentSql($residenceFields);
        $domicilePresent = $this->buildAddressPresentSql($domicileFields);
        $this->copyAddressGroup($db, $residenceFields, $mainFields, $mainPresent, $residencePresent);
        $this->copyAddressGroup($db, $domicileFields, $mainFields, $mainPresent, $domicilePresent);
    }

    /**
     * @param array<int, string> $targetFields
     * @param array<int, string> $sourceFields
     */
    private function copyAddressGroup(
        BaseConnection $db,
        array $targetFields,
        array $sourceFields,
        string $sourcePresent,
        string $targetPresent
    ): void {
        $assignments = [];
        foreach (array_combine($targetFields, $sourceFields) as $target => $source) {
            $assignments[] = "`{$target}` = `{$source}`";
        }

        $db->query(
            'UPDATE dap02_clients SET ' . implode(', ', $assignments)
            . " WHERE ({$sourcePresent}) AND NOT ({$targetPresent})"
        );
    }

    /**
     * Completes only empty fields when the address line already matches the
     * historical source. This also safely recovers interrupted/partial runs.
     *
     * @param array<int, string> $sourceFields
     * @param array<int, string> $targetFields
     */
    private function repairIncompleteCopiedGroup(
        BaseConnection $db,
        array $sourceFields,
        array $targetFields
    ): int {
        $crypto = new Crypto_helper();
        $sourceAnchor = $crypto->decryptSenzaAlias('`dap02_clients`.`' . $sourceFields[0] . '`');
        $targetAnchor = $crypto->decryptSenzaAlias('`dap02_clients`.`' . $targetFields[0] . '`');
        $anchorMatches = "COALESCE(TRIM({$sourceAnchor}), '') <> ''"
            . " AND COALESCE(TRIM({$sourceAnchor}), '') = COALESCE(TRIM({$targetAnchor}), '')";
        $repaired = 0;

        foreach ($sourceFields as $index => $sourceField) {
            $targetField = $targetFields[$index];
            $sourcePlain = $crypto->decryptSenzaAlias('`dap02_clients`.`' . $sourceField . '`');
            $targetPlain = $crypto->decryptSenzaAlias('`dap02_clients`.`' . $targetField . '`');
            $db->query(
                "UPDATE dap02_clients
                 SET `{$targetField}` = `{$sourceField}`
                 WHERE ({$anchorMatches})
                   AND COALESCE(TRIM({$sourcePlain}), '') <> ''
                   AND COALESCE(TRIM({$targetPlain}), '') = ''"
            );
            $repaired += max(0, $db->affectedRows());
        }

        return $repaired;
    }

    /**
     * @param array<int, string> $fields
     */
    private function buildAddressPresentSql(array $fields): string
    {
        $crypto = new Crypto_helper();
        $checks = [];
        foreach ($fields as $field) {
            $decrypted = $crypto->decryptSenzaAlias('`dap02_clients`.`' . $field . '`');
            $checks[] = "COALESCE(TRIM({$decrypted}), '') <> ''";
        }

        return '(' . implode(' OR ', $checks) . ')';
    }

    /**
     * @param array<int, string> $fields
     * @param array<string, bool> $availableColumns
     */
    private function buildAvailableAddressPresentSql(array $fields, array $availableColumns): string
    {
        $existingFields = array_values(array_filter(
            $fields,
            static fn(string $field): bool => isset($availableColumns[strtolower($field)])
        ));

        return $existingFields === [] ? '0 = 1' : $this->buildAddressPresentSql($existingFields);
    }

    /**
     * @param array<int, string> $leftFields
     * @param array<int, string> $rightFields
     * @param array<string, bool> $availableColumns
     */
    private function buildAvailableAddressEqualsSql(
        array $leftFields,
        array $rightFields,
        array $availableColumns
    ): string {
        if (count($leftFields) !== count($rightFields)) {
            return '0 = 1';
        }

        $checks = [];
        foreach ($leftFields as $index => $leftField) {
            $rightField = $rightFields[$index];
            if (
                !isset($availableColumns[strtolower($leftField)])
                || !isset($availableColumns[strtolower($rightField)])
            ) {
                return '0 = 1';
            }
            $crypto = new Crypto_helper();
            $left = $crypto->decryptSenzaAlias('`dap02_clients`.`' . $leftField . '`');
            $right = $crypto->decryptSenzaAlias('`dap02_clients`.`' . $rightField . '`');
            $checks[] = "COALESCE(TRIM({$left}), '') = COALESCE(TRIM({$right}), '')";
        }

        return '(' . implode(' AND ', $checks) . ')';
    }

    /**
     * @param array<int, string> $leftFields
     * @param array<int, string> $rightFields
     * @param array<string, bool> $availableColumns
     * @return array<int, string>
     */
    private function buildFieldMatchSelects(
        array $leftFields,
        array $rightFields,
        array $availableColumns,
        string $mainPresent,
        string $prefix
    ): array {
        $selects = [];
        foreach ($leftFields as $index => $leftField) {
            $equals = $this->buildAvailableAddressEqualsSql(
                [$leftField],
                [$rightFields[$index]],
                $availableColumns
            );
            $selects[] = "SUM(CASE WHEN ({$mainPresent}) AND ({$equals}) THEN 1 ELSE 0 END) AS {$prefix}_match_{$index}";
        }

        return $selects;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<int, int>
     */
    private function readFieldMatchCounts(array $row, string $prefix): array
    {
        $counts = [];
        for ($index = 0; $index < 5; $index++) {
            $counts[] = max(0, (int) ($row[$prefix . '_match_' . $index] ?? 0));
        }

        return $counts;
    }

    /**
     * @param array<string, mixed> $tenant
     */
    private function runFilteredMigration(array $tenant): void
    {
        $connectionConfig = $this->tenantDbConnector->buildConnectionConfig($tenant);
        $sourcePath = APPPATH . 'Database' . DIRECTORY_SEPARATOR . 'Migrations' . DIRECTORY_SEPARATOR . self::MIGRATION_FILE;
        $targetDirectory = rtrim(WRITEPATH, '\\/') . DIRECTORY_SEPARATOR . 'patient-address-migration-runtime' . DIRECTORY_SEPARATOR . bin2hex(random_bytes(8));
        $targetPath = $targetDirectory . DIRECTORY_SEPARATOR . self::MIGRATION_FILE;

        if (!is_file($sourcePath)) {
            throw new \RuntimeException('Migration indirizzi pazienti non trovata.');
        }
        if (!is_dir($targetDirectory) && !mkdir($targetDirectory, 0775, true) && !is_dir($targetDirectory)) {
            throw new \RuntimeException('Impossibile preparare la migration indirizzi pazienti.');
        }

        try {
            if (!copy($sourcePath, $targetPath)) {
                throw new \RuntimeException('Impossibile copiare la migration indirizzi pazienti.');
            }

            $runner = new FilteredMigrationRunner($this->migrationsConfig, $connectionConfig);
            $runner->setNamespace(APP_NAMESPACE);
            $runner->setCustomPath($targetDirectory);
            $runner->setSilent(false);
            $runner->latest($this->resolveHistoryGroup());
        } finally {
            if (is_file($targetPath)) {
                @unlink($targetPath);
            }
            @rmdir($targetDirectory);
        }
    }

    private function resolveHistoryGroup(): string
    {
        $group = trim((string) (config(DatabaseConfig::class)->defaultGroup ?? 'default'));
        return $group !== '' ? $group : 'default';
    }
}
