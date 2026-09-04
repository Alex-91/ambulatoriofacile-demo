<?php

namespace App\Services;

use App\Libraries\DatabaseConfig;

class TenantPatientLookupService
{
    private TenantCatalogService $tenantCatalog;
    private TenantDatabaseConnector $tenantDbConnector;
    private DatabaseConfig $databaseConfig;

    public function __construct(
        ?TenantCatalogService $tenantCatalog = null,
        ?TenantDatabaseConnector $tenantDbConnector = null,
        ?DatabaseConfig $databaseConfig = null
    ) {
        $this->tenantCatalog = $tenantCatalog ?? new TenantCatalogService();
        $this->tenantDbConnector = $tenantDbConnector ?? new TenantDatabaseConnector();
        $this->databaseConfig = $databaseConfig ?? new DatabaseConfig();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function searchPatientsForTenant(int $tenantId, string $term, int $limit = 12): array
    {
        $term = trim($term);
        if (mb_strlen($term) < 2) {
            return [];
        }

        $tenant = $tenantId > 0
            ? $this->tenantCatalog->getTenantById($tenantId)
            : $this->tenantCatalog->resolveCurrentRuntimeTenant();

        if (!is_array($tenant) || (int) ($tenant['id_tenant'] ?? 0) <= 0) {
            throw new \RuntimeException('Tenant non risolto per la ricerca pazienti.');
        }

        $db = $this->tenantDbConnector->connect($tenant);
        $this->databaseConfig->setEncryptionConfig($db);

        if (!$db->tableExists('dap02_clients')) {
            return [];
        }

        $hasUsersTable = $db->tableExists('dap01_users');
        $limit = max(1, min(20, $limit));
        $like = '%' . mb_strtolower($term) . '%';
        $query = $this->buildTenantPatientBaseQuery($db, $hasUsersTable);
        $sql = "
            {$query['select_sql']}
            WHERE (
                LOWER(COALESCE(CAST({$query['name_expr']} AS CHAR), '')) LIKE ?
                OR LOWER(COALESCE(CAST({$query['surname_expr']} AS CHAR), '')) LIKE ?
                OR LOWER(CONCAT(
                    TRIM(COALESCE(CAST({$query['name_expr']} AS CHAR), '')),
                    ' ',
                    TRIM(COALESCE(CAST({$query['surname_expr']} AS CHAR), ''))
                )) LIKE ?
                OR LOWER(CONCAT(
                    TRIM(COALESCE(CAST({$query['surname_expr']} AS CHAR), '')),
                    ' ',
                    TRIM(COALESCE(CAST({$query['name_expr']} AS CHAR), ''))
                )) LIKE ?
                OR LOWER(COALESCE({$query['username_expr']}, '')) LIKE ?
                OR LOWER(COALESCE(CAST({$query['tax_code_expr']} AS CHAR), '')) LIKE ?
                OR LOWER(COALESCE(CAST({$query['email_expr']} AS CHAR), '')) LIKE ?
                OR LOWER(COALESCE(CAST({$query['mobile_expr']} AS CHAR), '')) LIKE ?
                OR LOWER(COALESCE(CAST({$query['phone_expr']} AS CHAR), '')) LIKE ?
            )
            ORDER BY
                TRIM(COALESCE(CAST({$query['surname_expr']} AS CHAR), '')) ASC,
                TRIM(COALESCE(CAST({$query['name_expr']} AS CHAR), '')) ASC,
                c.id_client ASC
            LIMIT {$limit}
        ";

        $rows = $db->query($sql, [$like, $like, $like, $like, $like, $like, $like, $like, $like])->getResultArray();

        return $this->mapTenantPatientRows($rows);
    }

    /**
     * @return array<string, mixed>
     */
    public function getPatientByIdForTenant(int $tenantId, int $idClient): array
    {
        $idClient = max(0, $idClient);
        if ($idClient <= 0) {
            return [];
        }

        $tenant = $tenantId > 0
            ? $this->tenantCatalog->getTenantById($tenantId)
            : $this->tenantCatalog->resolveCurrentRuntimeTenant();

        if (!is_array($tenant) || (int) ($tenant['id_tenant'] ?? 0) <= 0) {
            throw new \RuntimeException('Tenant non risolto per il dettaglio paziente.');
        }

        $db = $this->tenantDbConnector->connect($tenant);
        $this->databaseConfig->setEncryptionConfig($db);

        if (!$db->tableExists('dap02_clients')) {
            return [];
        }

        $query = $this->buildTenantPatientBaseQuery($db, $db->tableExists('dap01_users'));
        $sql = $query['select_sql'] . '
            WHERE c.id_client = ?
            LIMIT 1
        ';

        $row = $db->query($sql, [$idClient])->getRowArray();
        if (!is_array($row)) {
            return [];
        }

        $mapped = $this->mapTenantPatientRows([$row]);

        return is_array($mapped[0] ?? null) ? $mapped[0] : [];
    }

    private function decryptExpr(string $fieldExpr): string
    {
        $dotPos = strrpos($fieldExpr, '.');
        $vectorExpr = $dotPos === false
            ? 'vector_id'
            : substr($fieldExpr, 0, $dotPos + 1) . 'vector_id';

        return "CONVERT(CAST(AES_DECRYPT(UNHEX({$fieldExpr}), @key_str, {$vectorExpr}) AS CHAR CHARACTER SET latin1) USING utf8mb4)";
    }

    /**
     * @return array<string, string>
     */
    private function buildTenantPatientBaseQuery(\CodeIgniter\Database\BaseConnection $db, bool $hasUsersTable): array
    {
        $clientColumns = array_fill_keys(array_map(
            'strtolower',
            $db->getFieldNames('dap02_clients')
        ), true);
        $nameExpr = $this->decryptExpr('c.nome');
        $surnameExpr = $this->decryptExpr('c.cognome');
        $taxCodeExpr = $this->decryptExpr('c.codice_fiscale');
        $emailExpr = $this->decryptExpr('c.email');
        $mobileExpr = $this->decryptExpr('c.cellulare');
        $phoneExpr = $this->decryptExpr('c.telefono');
        $legacyAddressExpr = $this->decryptedColumnOrEmpty($clientColumns, 'indirizzo');
        $legacyCivicExpr = $this->decryptedColumnOrEmpty($clientColumns, 'nr_civico');
        $residenceAddressExpr = $this->decryptedColumnOrEmpty($clientColumns, 'residenza_indirizzo');
        $residenceCivicExpr = $this->decryptedColumnOrEmpty($clientColumns, 'residenza_nr_civico');
        $domicileAddressExpr = $this->decryptedColumnOrEmpty($clientColumns, 'indirizzo_secondario');
        $domicileCivicExpr = $this->decryptedColumnOrEmpty($clientColumns, 'nr_civico_secondario');
        $addressExpr = $this->firstNonEmptySql([
            $this->fullAddressSql($residenceAddressExpr, $residenceCivicExpr),
            $this->fullAddressSql($domicileAddressExpr, $domicileCivicExpr),
            $this->fullAddressSql($legacyAddressExpr, $legacyCivicExpr),
        ]);
        $cityExpr = $this->firstNonEmptySql([
            $this->decryptedColumnOrEmpty($clientColumns, 'residenza_comune'),
            $this->decryptedColumnOrEmpty($clientColumns, 'comune_secondario'),
            $this->decryptedColumnOrEmpty($clientColumns, 'citta'),
        ]);
        $usernameExpr = $hasUsersTable ? "COALESCE(u.username, '')" : "''";
        $joinUsersSql = $hasUsersTable
            ? 'LEFT JOIN dap01_users u ON u.id_user = c.id_user'
            : "LEFT JOIN (SELECT 0 AS id_user, '' AS username) u ON 1 = 0";

        return [
            'name_expr' => $nameExpr,
            'surname_expr' => $surnameExpr,
            'tax_code_expr' => $taxCodeExpr,
            'email_expr' => $emailExpr,
            'mobile_expr' => $mobileExpr,
            'phone_expr' => $phoneExpr,
            'username_expr' => $usernameExpr,
            'select_sql' => "
                SELECT
                    c.id_client,
                    COALESCE(c.id_user, 0) AS id_user,
                    {$nameExpr} AS nome,
                    {$surnameExpr} AS cognome,
                    COALESCE(NULLIF({$taxCodeExpr}, ''), NULLIF({$usernameExpr}, '')) AS codice_fiscale,
                    {$emailExpr} AS email,
                    {$mobileExpr} AS cellulare,
                    {$phoneExpr} AS telefono,
                    {$addressExpr} AS indirizzo,
                    {$cityExpr} AS citta
                FROM dap02_clients c
                {$joinUsersSql}
            ",
        ];
    }

    /**
     * @param array<string, bool> $availableColumns
     */
    private function decryptedColumnOrEmpty(array $availableColumns, string $column): string
    {
        if (!isset($availableColumns[strtolower($column)])) {
            return "''";
        }

        return $this->decryptExpr('c.' . $column);
    }

    private function fullAddressSql(string $addressExpr, string $civicExpr): string
    {
        return "TRIM(CONCAT_WS(' ', NULLIF(TRIM(COALESCE(CAST({$addressExpr} AS CHAR), '')), ''), NULLIF(TRIM(COALESCE(CAST({$civicExpr} AS CHAR), '')), '')))";
    }

    /**
     * @param array<int, string> $expressions
     */
    private function firstNonEmptySql(array $expressions): string
    {
        $parts = array_map(
            static fn(string $expression): string => "NULLIF(TRIM(COALESCE(CAST({$expression} AS CHAR), '')), '')",
            $expressions
        );

        return 'COALESCE(' . implode(', ', $parts) . ", '')";
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function mapTenantPatientRows(array $rows): array
    {
        $results = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $lastName = trim((string) ($row['cognome'] ?? ''));
            $firstName = trim((string) ($row['nome'] ?? ''));
            $patientName = trim(preg_replace('/\s+/', ' ', $lastName . ' ' . $firstName) ?? '');
            if ($patientName === '') {
                $patientName = 'Paziente #' . (int) ($row['id_client'] ?? 0);
            }

            $taxCode = strtoupper(trim((string) ($row['codice_fiscale'] ?? '')));
            $email = trim((string) ($row['email'] ?? ''));
            $mobile = trim((string) ($row['cellulare'] ?? ''));
            $phone = trim((string) ($row['telefono'] ?? ''));
            $address = trim((string) ($row['indirizzo'] ?? ''));
            $city = trim((string) ($row['citta'] ?? ''));
            $metaParts = [];

            if ($taxCode !== '') {
                $metaParts[] = 'CF: ' . $taxCode;
            }
            if ($email !== '') {
                $metaParts[] = $email;
            }
            if ($mobile !== '') {
                $metaParts[] = $mobile;
            } elseif ($phone !== '') {
                $metaParts[] = $phone;
            }

            $results[] = [
                'id_client' => (int) ($row['id_client'] ?? 0),
                'id_user' => (int) ($row['id_user'] ?? 0),
                'patient_name' => $patientName,
                'patient_first_name' => $firstName,
                'patient_last_name' => $lastName,
                'patient_tax_code' => $taxCode,
                'patient_phone' => $phone,
                'patient_mobile' => $mobile,
                'patient_email' => $email,
                'patient_address' => $address,
                'patient_city' => $city,
                'email' => $email,
                'mobile' => $mobile,
                'phone' => $phone,
                'address' => $address,
                'city' => $city,
                'label' => $patientName,
                'meta' => implode(' | ', $metaParts),
            ];
        }

        return $results;
    }
}
