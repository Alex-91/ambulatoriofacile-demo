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

        $nameExpr = $this->decryptExpr('c.nome');
        $surnameExpr = $this->decryptExpr('c.cognome');
        $taxCodeExpr = $this->decryptExpr('c.codice_fiscale');
        $emailExpr = $this->decryptExpr('c.email');
        $mobileExpr = $this->decryptExpr('c.cellulare');
        $usernameExpr = $hasUsersTable ? "COALESCE(u.username, '')" : "''";
        $joinUsersSql = $hasUsersTable
            ? 'LEFT JOIN dap01_users u ON u.id_user = c.id_user'
            : "LEFT JOIN (SELECT 0 AS id_user, '' AS username) u ON 1 = 0";

        $sql = "
            SELECT
                c.id_client,
                {$nameExpr} AS nome,
                {$surnameExpr} AS cognome,
                COALESCE(NULLIF({$usernameExpr}, ''), NULLIF({$taxCodeExpr}, '')) AS codice_fiscale,
                {$emailExpr} AS email,
                {$mobileExpr} AS cellulare
            FROM dap02_clients c
            {$joinUsersSql}
            WHERE (
                LOWER(COALESCE(CAST({$nameExpr} AS CHAR), '')) LIKE ?
                OR LOWER(COALESCE(CAST({$surnameExpr} AS CHAR), '')) LIKE ?
                OR LOWER(CONCAT(
                    TRIM(COALESCE(CAST({$nameExpr} AS CHAR), '')),
                    ' ',
                    TRIM(COALESCE(CAST({$surnameExpr} AS CHAR), ''))
                )) LIKE ?
                OR LOWER(CONCAT(
                    TRIM(COALESCE(CAST({$surnameExpr} AS CHAR), '')),
                    ' ',
                    TRIM(COALESCE(CAST({$nameExpr} AS CHAR), ''))
                )) LIKE ?
                OR LOWER(COALESCE({$usernameExpr}, '')) LIKE ?
                OR LOWER(COALESCE(CAST({$taxCodeExpr} AS CHAR), '')) LIKE ?
                OR LOWER(COALESCE(CAST({$emailExpr} AS CHAR), '')) LIKE ?
                OR LOWER(COALESCE(CAST({$mobileExpr} AS CHAR), '')) LIKE ?
            )
            ORDER BY
                TRIM(COALESCE(CAST({$surnameExpr} AS CHAR), '')) ASC,
                TRIM(COALESCE(CAST({$nameExpr} AS CHAR), '')) ASC,
                c.id_client ASC
            LIMIT {$limit}
        ";

        $rows = $db->query($sql, [$like, $like, $like, $like, $like, $like, $like, $like])->getResultArray();
        $results = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $patientName = trim((string) (($row['nome'] ?? '') . ' ' . ($row['cognome'] ?? '')));
            if ($patientName === '') {
                $patientName = 'Paziente #' . (int) ($row['id_client'] ?? 0);
            }

            $taxCode = strtoupper(trim((string) ($row['codice_fiscale'] ?? '')));
            $email = trim((string) ($row['email'] ?? ''));
            $mobile = trim((string) ($row['cellulare'] ?? ''));
            $metaParts = [];

            if ($taxCode !== '') {
                $metaParts[] = 'CF: ' . $taxCode;
            }
            if ($email !== '') {
                $metaParts[] = $email;
            }
            if ($mobile !== '') {
                $metaParts[] = $mobile;
            }

            $results[] = [
                'id_client' => (int) ($row['id_client'] ?? 0),
                'patient_name' => $patientName,
                'patient_tax_code' => $taxCode,
                'email' => $email,
                'mobile' => $mobile,
                'label' => $patientName,
                'meta' => implode(' | ', $metaParts),
            ];
        }

        return $results;
    }

    private function decryptExpr(string $fieldExpr): string
    {
        $dotPos = strrpos($fieldExpr, '.');
        $vectorExpr = $dotPos === false
            ? 'vector_id'
            : substr($fieldExpr, 0, $dotPos + 1) . 'vector_id';

        return "CONVERT(CAST(AES_DECRYPT(UNHEX({$fieldExpr}), @key_str, {$vectorExpr}) AS CHAR CHARACTER SET latin1) USING utf8mb4)";
    }
}
