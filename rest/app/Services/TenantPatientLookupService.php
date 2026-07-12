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
        $query = $this->buildTenantPatientBaseQuery($hasUsersTable);
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

        $query = $this->buildTenantPatientBaseQuery($db->tableExists('dap01_users'));
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
    private function buildTenantPatientBaseQuery(bool $hasUsersTable): array
    {
        $nameExpr = $this->decryptExpr('c.nome');
        $surnameExpr = $this->decryptExpr('c.cognome');
        $taxCodeExpr = $this->decryptExpr('c.codice_fiscale');
        $emailExpr = $this->decryptExpr('c.email');
        $mobileExpr = $this->decryptExpr('c.cellulare');
        $phoneExpr = $this->decryptExpr('c.telefono');
        $addressExpr = $this->decryptExpr('c.indirizzo');
        $cityExpr = $this->decryptExpr('c.citta');
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
