<?php

namespace App\Models;

use App\Libraries\DatabaseConfig;
use CodeIgniter\Model;

class DoctorPatientSearchModel extends Model
{
    public const TABLE = 'dap26_doctor_patient_search';

    protected $table = self::TABLE;
    protected $primaryKey = 'id_client';
    protected $allowedFields = [
        'id_dot',
        'id_client',
        'cognome_norm',
        'nome_norm',
        'full_norm',
        'cf_norm',
        'tel_norm',
        'cell_norm',
        'email_norm',
        'paz_spec_norm',
        'updated_at',
    ];

    protected $db;
    private ?bool $tableExistsCache = null;
    private ?bool $pazSpecColumnExistsCache = null;

    public function __construct()
    {
        parent::__construct();
        $this->db = \Config\Database::connect();

        $dbConfig = new DatabaseConfig();
        $dbConfig->setEncryptionConfig($this->db);
    }

    public function tableExists(): bool
    {
        if ($this->tableExistsCache !== null) {
            return $this->tableExistsCache;
        }

        $this->tableExistsCache = $this->db->tableExists(self::TABLE);
        return $this->tableExistsCache;
    }

    public function ensureTable(): void
    {
        $this->db->query($this->getCreateTableSql());
        $this->ensurePazSpecStructure();
        $this->tableExistsCache = true;
    }

    public function rebuildAll(): array
    {
        $this->ensureTable();

        $this->db->query('TRUNCATE TABLE ' . self::TABLE);

        $inserted = 0;
        foreach ($this->buildBulkRebuildQueries() as [$sql, $params]) {
            $this->db->query($sql, $params);
            $inserted += max(0, $this->db->affectedRows());
        }

        $countRow = $this->db->table(self::TABLE)
            ->selectCount('id_client', 'c')
            ->get()
            ->getRowArray();

        return [
            'inserted_rows' => $inserted,
            'total_rows'    => (int) ($countRow['c'] ?? 0),
        ];
    }

    public function syncClient(int $idClient): void
    {
        if ($idClient <= 0 || !$this->tableExists()) {
            return;
        }

        $this->ensurePazSpecStructure();

        $this->db->table(self::TABLE)
            ->where('id_client', $idClient)
            ->delete();

        foreach ($this->buildClientSyncQueries($idClient) as [$sql, $params]) {
            $this->db->query($sql, $params);
        }
    }

    public function listClientIdsForDoctor(int $legacyIdDot, string $term = ''): array
    {
        return $this->listClientIdsForDoctors([$legacyIdDot], $term);
    }

    public function listClientIdsForDoctors(array $legacyIdDots, string $term = ''): array
    {
        $legacyIdDots = $this->normalizeLegacyDoctorIds($legacyIdDots);
        if ($legacyIdDots === [] || !$this->tableExists()) {
            return [];
        }

        $term = trim($term);
        if ($term !== '') {
            return $this->searchClientIdsForDoctors($legacyIdDots, $term, 500);
        }

        [$doctorWhereSql, $doctorParams] = $this->buildDoctorWhereSql($legacyIdDots);

        $sqlWithIndex = "
            SELECT id_client
            FROM " . self::TABLE . " FORCE INDEX (idx_dps_cognome)
            WHERE {$doctorWhereSql}
              AND {$this->buildVisibleIndexWhereSql()}
            GROUP BY id_client
            ORDER BY
                CASE WHEN MAX(CASE WHEN COALESCE(paz_spec_norm, '') <> '' THEN 1 ELSE 0 END) = 1 THEN 0 ELSE 1 END,
                MIN(cognome_norm) ASC,
                MIN(nome_norm) ASC,
                id_client ASC
        ";

        $sqlFallback = "
            SELECT id_client
            FROM " . self::TABLE . "
            WHERE {$doctorWhereSql}
              AND {$this->buildVisibleIndexWhereSql()}
            GROUP BY id_client
            ORDER BY
                CASE WHEN MAX(CASE WHEN COALESCE(paz_spec_norm, '') <> '' THEN 1 ELSE 0 END) = 1 THEN 0 ELSE 1 END,
                MIN(cognome_norm) ASC,
                MIN(nome_norm) ASC,
                id_client ASC
        ";

        try {
            $rows = $this->db->query($sqlWithIndex, $doctorParams)->getResultArray();
        } catch (\Throwable $e) {
            log_message('warning', 'DoctorPatientSearchModel list fallback without FORCE INDEX: ' . $e->getMessage());
            $rows = $this->db->query($sqlFallback, $doctorParams)->getResultArray();
        }

        return array_values(array_filter(array_map(
            static fn(array $row): int => (int) ($row['id_client'] ?? 0),
            $rows
        ), static fn(int $id): bool => $id > 0));
    }

    public function paginateClientIdsForDoctor(int $legacyIdDot, string $term = '', int $page = 1, int $perPage = 20): array
    {
        return $this->paginateClientIdsForDoctors([$legacyIdDot], $term, $page, $perPage);
    }

    public function paginateClientIdsForDoctors(array $legacyIdDots, string $term = '', int $page = 1, int $perPage = 20): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        $legacyIdDots = $this->normalizeLegacyDoctorIds($legacyIdDots);
        if ($legacyIdDots === [] || !$this->tableExists()) {
            return [
                'ids' => [],
                'page' => 1,
                'perPage' => $perPage,
                'total' => 0,
                'lastPage' => 1,
                'from' => 0,
                'to' => 0,
            ];
        }

        $term = trim($term);
        if ($term !== '') {
            $lookupLimit = min(5000, max(500, ($page * $perPage) + 400));
            $ids = $this->searchClientIdsForDoctors($legacyIdDots, $term, $lookupLimit);
            $total = count($ids);
            $lastPage = max(1, (int) ceil($total / $perPage));
            $page = min($page, $lastPage);
            $offset = max(0, ($page - 1) * $perPage);
            $pageIds = array_slice($ids, $offset, $perPage);

            return [
                'ids' => $pageIds,
                'page' => $page,
                'perPage' => $perPage,
                'total' => $total,
                'lastPage' => $lastPage,
                'from' => $total > 0 ? $offset + 1 : 0,
                'to' => $total > 0 ? min($offset + count($pageIds), $total) : 0,
            ];
        }

        [$doctorWhereSql, $doctorParams] = $this->buildDoctorWhereSql($legacyIdDots);

        $countSqlWithIndex = "
            SELECT COUNT(DISTINCT id_client) AS total
            FROM " . self::TABLE . " FORCE INDEX (idx_dps_cognome)
            WHERE {$doctorWhereSql}
              AND {$this->buildVisibleIndexWhereSql()}
        ";

        $countSqlFallback = "
            SELECT COUNT(DISTINCT id_client) AS total
            FROM " . self::TABLE . "
            WHERE {$doctorWhereSql}
              AND {$this->buildVisibleIndexWhereSql()}
        ";

        try {
            $countRow = $this->db->query($countSqlWithIndex, $doctorParams)->getRowArray();
        } catch (\Throwable $e) {
            log_message('warning', 'DoctorPatientSearchModel paginate count fallback without FORCE INDEX: ' . $e->getMessage());
            $countRow = $this->db->query($countSqlFallback, $doctorParams)->getRowArray();
        }

        $total = (int) ($countRow['total'] ?? 0);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);
        $offset = max(0, ($page - 1) * $perPage);

        if ($total === 0) {
            return [
                'ids' => [],
                'page' => 1,
                'perPage' => $perPage,
                'total' => 0,
                'lastPage' => 1,
                'from' => 0,
                'to' => 0,
            ];
        }

        $pageSqlWithIndex = "
            SELECT id_client
            FROM " . self::TABLE . " FORCE INDEX (idx_dps_cognome)
            WHERE {$doctorWhereSql}
              AND {$this->buildVisibleIndexWhereSql()}
            GROUP BY id_client
            ORDER BY
                CASE WHEN MAX(CASE WHEN COALESCE(paz_spec_norm, '') <> '' THEN 1 ELSE 0 END) = 1 THEN 0 ELSE 1 END,
                MIN(cognome_norm) ASC,
                MIN(nome_norm) ASC,
                id_client ASC
            LIMIT {$perPage} OFFSET {$offset}
        ";

        $pageSqlFallback = "
            SELECT id_client
            FROM " . self::TABLE . "
            WHERE {$doctorWhereSql}
              AND {$this->buildVisibleIndexWhereSql()}
            GROUP BY id_client
            ORDER BY
                CASE WHEN MAX(CASE WHEN COALESCE(paz_spec_norm, '') <> '' THEN 1 ELSE 0 END) = 1 THEN 0 ELSE 1 END,
                MIN(cognome_norm) ASC,
                MIN(nome_norm) ASC,
                id_client ASC
            LIMIT {$perPage} OFFSET {$offset}
        ";

        try {
            $rows = $this->db->query($pageSqlWithIndex, $doctorParams)->getResultArray();
        } catch (\Throwable $e) {
            log_message('warning', 'DoctorPatientSearchModel paginate rows fallback without FORCE INDEX: ' . $e->getMessage());
            $rows = $this->db->query($pageSqlFallback, $doctorParams)->getResultArray();
        }

        $ids = array_values(array_filter(array_map(
            static fn(array $row): int => (int) ($row['id_client'] ?? 0),
            $rows
        ), static fn(int $id): bool => $id > 0));

        return [
            'ids' => $ids,
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'lastPage' => $lastPage,
            'from' => $total > 0 ? $offset + 1 : 0,
            'to' => $total > 0 ? min($offset + count($ids), $total) : 0,
        ];
    }

    public function hasVisibleClientForDoctor(int $legacyIdDot, int $idClient): bool
    {
        return $this->hasVisibleClientForDoctors([$legacyIdDot], $idClient);
    }

    public function hasVisibleClientForDoctors(array $legacyIdDots, int $idClient): bool
    {
        $legacyIdDots = $this->normalizeLegacyDoctorIds($legacyIdDots);
        if ($legacyIdDots === [] || $idClient <= 0 || !$this->tableExists()) {
            return false;
        }

        [$doctorWhereSql, $doctorParams] = $this->buildDoctorWhereSql($legacyIdDots);

        $sqlWithIndex = "
            SELECT 1
            FROM " . self::TABLE . " FORCE INDEX (PRIMARY)
            WHERE {$doctorWhereSql}
              AND id_client = ?
              AND {$this->buildVisibleIndexWhereSql()}
            LIMIT 1
        ";

        $sqlFallback = "
            SELECT 1
            FROM " . self::TABLE . "
            WHERE {$doctorWhereSql}
              AND id_client = ?
              AND {$this->buildVisibleIndexWhereSql()}
            LIMIT 1
        ";

        $params = array_merge($doctorParams, [$idClient]);

        try {
            $row = $this->db->query($sqlWithIndex, $params)->getRowArray();
        } catch (\Throwable $e) {
            log_message('warning', 'DoctorPatientSearchModel visible client fallback without FORCE INDEX: ' . $e->getMessage(), [
                'id_dot' => implode(',', $legacyIdDots),
                'id_client' => $idClient,
            ]);
            $row = $this->db->query($sqlFallback, $params)->getRowArray();
        }

        return !empty($row);
    }

    public function searchClientIdsForDoctor(int $legacyIdDot, string $term, int $limit = 20): array
    {
        return $this->searchClientIdsForDoctors([$legacyIdDot], $term, $limit);
    }

    public function searchClientIdsForDoctors(array $legacyIdDots, string $term, int $limit = 20): array
    {
        $legacyIdDots = $this->normalizeLegacyDoctorIds($legacyIdDots);
        if ($legacyIdDots === [] || !$this->tableExists()) {
            return [];
        }

        $term = trim($term);
        if ($term === '') {
            return [];
        }

        $limit = max(1, min(500, $limit));
        $branchLimit = min(120, max(20, $limit * 3));
        $matches = [];

        $phone = $this->normalizePhone($term);
        if ($phone !== '' && strlen($phone) >= 3) {
            $prefix = $phone . '%';

            $this->appendIndexedMatchesForDoctors(
                $matches,
                $legacyIdDots,
                'idx_dps_cell',
                'cell_norm LIKE ?',
                [$prefix],
                'cell_norm ASC, id_client ASC',
                $branchLimit
            );

            $this->appendIndexedMatchesForDoctors(
                $matches,
                $legacyIdDots,
                'idx_dps_tel',
                'tel_norm LIKE ?',
                [$prefix],
                'tel_norm ASC, id_client ASC',
                $branchLimit
            );
        } else {
            $normalized = $this->normalizeText($term);
            if ($normalized === '') {
                return [];
            }

            $cf = $this->normalizeCode($term);
            $tokens = preg_split('/\s+/', $normalized) ?: [];
            $tokens = array_values(array_filter($tokens, static fn(string $token): bool => $token !== ''));

            if (count($tokens) >= 2) {
                $first = $tokens[0] . '%';
                $second = $tokens[1] . '%';
                $full = implode(' ', array_slice($tokens, 0, 3)) . '%';
                $email = $normalized . '%';
                $cfPrefix = $cf !== '' ? $cf . '%' : $normalized . '%';

                $this->appendIndexedMatchesForDoctors(
                    $matches,
                    $legacyIdDots,
                    'idx_dps_cognome',
                    'cognome_norm LIKE ? AND nome_norm LIKE ?',
                    [$first, $second],
                    'cognome_norm ASC, nome_norm ASC, id_client ASC',
                    $branchLimit
                );

                $this->appendIndexedMatchesForDoctors(
                    $matches,
                    $legacyIdDots,
                    'idx_dps_nome',
                    'nome_norm LIKE ? AND cognome_norm LIKE ?',
                    [$first, $second],
                    'nome_norm ASC, cognome_norm ASC, id_client ASC',
                    $branchLimit
                );

                $this->appendIndexedMatchesForDoctors(
                    $matches,
                    $legacyIdDots,
                    'idx_dps_full',
                    'full_norm LIKE ?',
                    [$full],
                    'full_norm ASC, id_client ASC',
                    $branchLimit
                );

                $this->appendIndexedMatchesForDoctors(
                    $matches,
                    $legacyIdDots,
                    'idx_dps_email',
                    'email_norm LIKE ?',
                    [$email],
                    'email_norm ASC, id_client ASC',
                    $branchLimit
                );

                $this->appendIndexedMatchesForDoctors(
                    $matches,
                    $legacyIdDots,
                    'idx_dps_cf',
                    'cf_norm LIKE ?',
                    [$cfPrefix],
                    'cf_norm ASC, id_client ASC',
                    $branchLimit
                );

                if ($this->hasPazSpecColumn()) {
                    $this->appendIndexedMatchesForDoctors(
                        $matches,
                        $legacyIdDots,
                        'idx_dps_paz_spec',
                        'paz_spec_norm LIKE ?',
                        [$email],
                        'paz_spec_norm ASC, id_client ASC',
                        $branchLimit
                    );
                }
            } else {
                $prefix = $normalized . '%';
                $cfPrefix = $cf !== '' ? $cf . '%' : $prefix;

                $this->appendIndexedMatchesForDoctors(
                    $matches,
                    $legacyIdDots,
                    'idx_dps_cognome',
                    'cognome_norm LIKE ?',
                    [$prefix],
                    'cognome_norm ASC, nome_norm ASC, id_client ASC',
                    $branchLimit
                );

                $this->appendIndexedMatchesForDoctors(
                    $matches,
                    $legacyIdDots,
                    'idx_dps_nome',
                    'nome_norm LIKE ?',
                    [$prefix],
                    'nome_norm ASC, cognome_norm ASC, id_client ASC',
                    $branchLimit
                );

                $this->appendIndexedMatchesForDoctors(
                    $matches,
                    $legacyIdDots,
                    'idx_dps_full',
                    'full_norm LIKE ?',
                    [$prefix],
                    'full_norm ASC, id_client ASC',
                    $branchLimit
                );

                $this->appendIndexedMatchesForDoctors(
                    $matches,
                    $legacyIdDots,
                    'idx_dps_email',
                    'email_norm LIKE ?',
                    [$prefix],
                    'email_norm ASC, id_client ASC',
                    $branchLimit
                );

                $this->appendIndexedMatchesForDoctors(
                    $matches,
                    $legacyIdDots,
                    'idx_dps_cf',
                    'cf_norm LIKE ?',
                    [$cfPrefix],
                    'cf_norm ASC, id_client ASC',
                    $branchLimit
                );

                if ($this->hasPazSpecColumn()) {
                    $this->appendIndexedMatchesForDoctors(
                        $matches,
                        $legacyIdDots,
                        'idx_dps_paz_spec',
                        'paz_spec_norm LIKE ?',
                        [$prefix],
                        'paz_spec_norm ASC, id_client ASC',
                        $branchLimit
                    );
                }
            }
        }

        if ($matches === []) {
            return [];
        }

        $rows = array_values($matches);
        usort($rows, static function (array $a, array $b): int {
            $cmp = strcmp((string) ($a['cognome_norm'] ?? ''), (string) ($b['cognome_norm'] ?? ''));
            if ($cmp !== 0) {
                return $cmp;
            }

            $cmp = strcmp((string) ($a['nome_norm'] ?? ''), (string) ($b['nome_norm'] ?? ''));
            if ($cmp !== 0) {
                return $cmp;
            }

            return ((int) ($a['id_client'] ?? 0)) <=> ((int) ($b['id_client'] ?? 0));
        });

        return array_map(
            static fn(array $row): int => (int) ($row['id_client'] ?? 0),
            array_slice($rows, 0, $limit)
        );
    }

    public function getCreateTableSql(): string
    {
        return "
            CREATE TABLE IF NOT EXISTS `" . self::TABLE . "` (
              `id_dot` int NOT NULL,
              `id_client` int NOT NULL,
              `cognome_norm` varchar(191) NOT NULL DEFAULT '',
              `nome_norm` varchar(191) NOT NULL DEFAULT '',
              `full_norm` varchar(191) NOT NULL DEFAULT '',
              `cf_norm` varchar(32) DEFAULT NULL,
              `tel_norm` varchar(32) DEFAULT NULL,
              `cell_norm` varchar(32) DEFAULT NULL,
              `email_norm` varchar(191) DEFAULT NULL,
              `paz_spec_norm` varchar(191) DEFAULT NULL,
              `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (`id_dot`, `id_client`),
              KEY `idx_dps_client` (`id_client`),
              KEY `idx_dps_cognome` (`id_dot`, `cognome_norm`, `nome_norm`, `id_client`),
              KEY `idx_dps_nome` (`id_dot`, `nome_norm`, `cognome_norm`, `id_client`),
              KEY `idx_dps_full` (`id_dot`, `full_norm`, `id_client`),
              KEY `idx_dps_cf` (`id_dot`, `cf_norm`, `id_client`),
              KEY `idx_dps_tel` (`id_dot`, `tel_norm`, `id_client`),
              KEY `idx_dps_cell` (`id_dot`, `cell_norm`, `id_client`),
              KEY `idx_dps_email` (`id_dot`, `email_norm`, `id_client`),
              KEY `idx_dps_paz_spec` (`id_dot`, `paz_spec_norm`, `id_client`)
            ) ENGINE=InnoDB DEFAULT CHARSET=latin1
        ";
    }

    private function ensurePazSpecStructure(): void
    {
        if (!$this->db->tableExists(self::TABLE)) {
            return;
        }

        if (!$this->hasPazSpecColumn()) {
            $this->db->query(
                "ALTER TABLE " . self::TABLE . " ADD COLUMN paz_spec_norm varchar(191) DEFAULT NULL AFTER email_norm"
            );
            $this->pazSpecColumnExistsCache = true;
        }

        $indexRow = $this->db->query(
            "SHOW INDEX FROM " . self::TABLE . " WHERE Key_name = 'idx_dps_paz_spec'"
        )->getRowArray();

        if (!$indexRow) {
            $this->db->query(
                "ALTER TABLE " . self::TABLE . " ADD INDEX idx_dps_paz_spec (id_dot, paz_spec_norm, id_client)"
            );
        }
    }

    private function hasPazSpecColumn(): bool
    {
        if ($this->pazSpecColumnExistsCache !== null) {
            return $this->pazSpecColumnExistsCache;
        }

        if (!$this->db->tableExists(self::TABLE)) {
            return false;
        }

        $row = $this->db->query(
            "SHOW COLUMNS FROM " . self::TABLE . " LIKE 'paz_spec_norm'"
        )->getRowArray();

        $this->pazSpecColumnExistsCache = $row !== null;
        return $this->pazSpecColumnExistsCache;
    }

    private function appendIndexedMatchesForDoctors(
        array &$matches,
        array $legacyIdDots,
        string $indexName,
        string $whereSql,
        array $params,
        string $orderBy,
        int $limit
    ): void
    {
        $legacyIdDots = $this->normalizeLegacyDoctorIds($legacyIdDots);
        if ($legacyIdDots === []) {
            return;
        }

        [$doctorWhereSql, $doctorParams] = $this->buildDoctorWhereSql($legacyIdDots);
        $effectiveLimit = max($limit, min(600, $limit * max(1, count($legacyIdDots))));

        $sql = "
            SELECT id_client, cognome_norm, nome_norm
            FROM " . self::TABLE . " FORCE INDEX ({$indexName})
            WHERE {$doctorWhereSql}
              AND {$this->buildVisibleIndexWhereSql()}
              AND {$whereSql}
            ORDER BY {$orderBy}
            LIMIT {$effectiveLimit}
        ";

        $fallbackSql = "
            SELECT id_client, cognome_norm, nome_norm
            FROM " . self::TABLE . "
            WHERE {$doctorWhereSql}
              AND {$this->buildVisibleIndexWhereSql()}
              AND {$whereSql}
            ORDER BY {$orderBy}
            LIMIT {$effectiveLimit}
        ";

        $queryParams = array_merge($doctorParams, $params);

        try {
            $rows = $this->db->query($sql, $queryParams)->getResultArray();
        } catch (\Throwable $e) {
            log_message('warning', 'DoctorPatientSearchModel indexed search fallback without FORCE INDEX [' . $indexName . ']: ' . $e->getMessage());
            $rows = $this->db->query($fallbackSql, $queryParams)->getResultArray();
        }

        foreach ($rows as $row) {
            $idClient = (int) ($row['id_client'] ?? 0);
            if ($idClient <= 0 || isset($matches[$idClient])) {
                continue;
            }

            $matches[$idClient] = [
                'id_client'     => $idClient,
                'cognome_norm'  => (string) ($row['cognome_norm'] ?? ''),
                'nome_norm'     => (string) ($row['nome_norm'] ?? ''),
            ];
        }
    }

    private function normalizeLegacyDoctorIds(array $legacyIdDots): array
    {
        $normalized = array_values(array_unique(array_filter(array_map(
            static fn($value): int => (int) $value,
            $legacyIdDots
        ), static fn(int $id): bool => $id > 0)));

        sort($normalized);

        return $normalized;
    }

    /**
     * @return array{0:string,1:array<int,int>}
     */
    private function buildDoctorWhereSql(array $legacyIdDots): array
    {
        $legacyIdDots = $this->normalizeLegacyDoctorIds($legacyIdDots);
        if ($legacyIdDots === []) {
            return ['1 = 0', []];
        }

        $placeholders = implode(',', array_fill(0, count($legacyIdDots), '?'));

        return ['id_dot IN (' . $placeholders . ')', $legacyIdDots];
    }

    private function buildBulkRebuildQueries(): array
    {
        return [
            $this->buildPrimaryDoctorInsertQuery(),
            $this->buildRelationInsertQuery(),
            $this->buildAppointmentClientInsertQuery(),
            $this->buildAppointmentLegacyInsertQuery(),
            $this->buildGlobalSpecialInsertQuery(),
        ];
    }

    private function buildClientSyncQueries(int $idClient): array
    {
        return [
            $this->buildPrimaryDoctorInsertQuery($idClient),
            $this->buildRelationInsertQuery($idClient),
            $this->buildAppointmentClientInsertQuery($idClient),
            $this->buildAppointmentLegacyInsertQuery($idClient),
            $this->buildGlobalSpecialInsertQuery($idClient),
        ];
    }

    private function buildPrimaryDoctorInsertQuery(?int $idClient = null): array
    {
        $sql = "
            INSERT IGNORE INTO " . self::TABLE . " (
                id_dot, id_client, cognome_norm, nome_norm, full_norm, cf_norm, tel_norm, cell_norm, email_norm, paz_spec_norm, updated_at
            )
            SELECT DISTINCT
                p.legacy_id_dot AS id_dot,
                c.id_client,
                {$this->normalizedTextExpr('c.cognome')} AS cognome_norm,
                {$this->normalizedTextExpr('c.nome')} AS nome_norm,
                {$this->normalizedFullExpr()} AS full_norm,
                {$this->normalizedCodeExpr('c.codice_fiscale')} AS cf_norm,
                {$this->normalizedPhoneExpr('c.telefono')} AS tel_norm,
                {$this->normalizedPhoneExpr('c.cellulare')} AS cell_norm,
                {$this->normalizedEmailExpr('c.email')} AS email_norm,
                {$this->normalizedTextExpr('c.paz_spec')} AS paz_spec_norm,
                NOW()
            FROM dap02_clients c
            INNER JOIN dap03_personale p
                ON p.id_personale = c.id_personale
            WHERE p.legacy_id_dot > 0
        ";

        $params = [];
        if ($idClient !== null) {
            $sql .= ' AND c.id_client = ?';
            $params[] = $idClient;
        }

        return [$sql, $params];
    }

    private function buildRelationInsertQuery(?int $idClient = null): array
    {
        $sql = "
            INSERT IGNORE INTO " . self::TABLE . " (
                id_dot, id_client, cognome_norm, nome_norm, full_norm, cf_norm, tel_norm, cell_norm, email_norm, paz_spec_norm, updated_at
            )
            SELECT DISTINCT
                p.legacy_id_dot AS id_dot,
                c.id_client,
                {$this->normalizedTextExpr('c.cognome')} AS cognome_norm,
                {$this->normalizedTextExpr('c.nome')} AS nome_norm,
                {$this->normalizedFullExpr()} AS full_norm,
                {$this->normalizedCodeExpr('c.codice_fiscale')} AS cf_norm,
                {$this->normalizedPhoneExpr('c.telefono')} AS tel_norm,
                {$this->normalizedPhoneExpr('c.cellulare')} AS cell_norm,
                {$this->normalizedEmailExpr('c.email')} AS email_norm,
                {$this->normalizedTextExpr('c.paz_spec')} AS paz_spec_norm,
                NOW()
            FROM dap02_clients c
            INNER JOIN dap09_client_doctor cd
                ON cd.id_client = c.id_client
            INNER JOIN dap03_personale p
                ON p.id_personale = cd.id_dot
            WHERE p.legacy_id_dot > 0
        ";

        $params = [];
        if ($idClient !== null) {
            $sql .= ' AND c.id_client = ?';
            $params[] = $idClient;
        }

        return [$sql, $params];
    }

    private function buildAppointmentClientInsertQuery(?int $idClient = null): array
    {
        $sql = "
            INSERT IGNORE INTO " . self::TABLE . " (
                id_dot, id_client, cognome_norm, nome_norm, full_norm, cf_norm, tel_norm, cell_norm, email_norm, paz_spec_norm, updated_at
            )
            SELECT DISTINCT
                a.id_dot,
                c.id_client,
                {$this->normalizedTextExpr('c.cognome')} AS cognome_norm,
                {$this->normalizedTextExpr('c.nome')} AS nome_norm,
                {$this->normalizedFullExpr()} AS full_norm,
                {$this->normalizedCodeExpr('c.codice_fiscale')} AS cf_norm,
                {$this->normalizedPhoneExpr('c.telefono')} AS tel_norm,
                {$this->normalizedPhoneExpr('c.cellulare')} AS cell_norm,
                {$this->normalizedEmailExpr('c.email')} AS email_norm,
                {$this->normalizedTextExpr('c.paz_spec')} AS paz_spec_norm,
                NOW()
            FROM dap12_agenda_appuntamenti a
            INNER JOIN dap02_clients c
                ON c.id_client = a.id_client
            WHERE a.id_dot > 0
              AND a.stato <> 'ANNULLATO'
              AND a.id_client IS NOT NULL
              AND a.id_client > 0
        ";

        $params = [];
        if ($idClient !== null) {
            $sql .= ' AND c.id_client = ?';
            $params[] = $idClient;
        }

        return [$sql, $params];
    }

    private function buildAppointmentLegacyInsertQuery(?int $idClient = null): array
    {
        $sql = "
            INSERT IGNORE INTO " . self::TABLE . " (
                id_dot, id_client, cognome_norm, nome_norm, full_norm, cf_norm, tel_norm, cell_norm, email_norm, paz_spec_norm, updated_at
            )
            SELECT DISTINCT
                a.id_dot,
                c.id_client,
                {$this->normalizedTextExpr('c.cognome')} AS cognome_norm,
                {$this->normalizedTextExpr('c.nome')} AS nome_norm,
                {$this->normalizedFullExpr()} AS full_norm,
                {$this->normalizedCodeExpr('c.codice_fiscale')} AS cf_norm,
                {$this->normalizedPhoneExpr('c.telefono')} AS tel_norm,
                {$this->normalizedPhoneExpr('c.cellulare')} AS cell_norm,
                {$this->normalizedEmailExpr('c.email')} AS email_norm,
                {$this->normalizedTextExpr('c.paz_spec')} AS paz_spec_norm,
                NOW()
            FROM dap12_agenda_appuntamenti a
            INNER JOIN dap02_clients c
                ON COALESCE(c.legacy_id_paziente, 0) = a.id_paziente
            WHERE a.id_dot > 0
              AND a.stato <> 'ANNULLATO'
              AND COALESCE(a.id_client, 0) = 0
              AND COALESCE(c.legacy_id_paziente, 0) > 0
        ";

        $params = [];
        if ($idClient !== null) {
            $sql .= ' AND c.id_client = ?';
            $params[] = $idClient;
        }

        return [$sql, $params];
    }

    private function buildGlobalSpecialInsertQuery(?int $idClient = null): array
    {
        $sql = "
            INSERT IGNORE INTO " . self::TABLE . " (
                id_dot, id_client, cognome_norm, nome_norm, full_norm, cf_norm, tel_norm, cell_norm, email_norm, paz_spec_norm, updated_at
            )
            SELECT DISTINCT
                p.legacy_id_dot AS id_dot,
                s.id_client,
                s.cognome_norm,
                s.nome_norm,
                s.full_norm,
                s.cf_norm,
                s.tel_norm,
                s.cell_norm,
                s.email_norm,
                s.paz_spec_norm,
                NOW()
            FROM dap03_personale p
            INNER JOIN (
                SELECT
                    c.id_client,
                    {$this->normalizedTextExpr('c.cognome')} AS cognome_norm,
                    {$this->normalizedTextExpr('c.nome')} AS nome_norm,
                    {$this->normalizedFullExpr()} AS full_norm,
                    {$this->normalizedCodeExpr('c.codice_fiscale')} AS cf_norm,
                    {$this->normalizedPhoneExpr('c.telefono')} AS tel_norm,
                    {$this->normalizedPhoneExpr('c.cellulare')} AS cell_norm,
                    {$this->normalizedEmailExpr('c.email')} AS email_norm,
                    {$this->normalizedTextExpr('c.paz_spec')} AS paz_spec_norm
                FROM dap02_clients c
                WHERE {$this->buildSpecialPatientWhereSql()}
            ) s
                ON 1 = 1
            WHERE p.legacy_id_dot > 0
        ";

        $params = [];
        if ($idClient !== null) {
            $sql .= ' AND s.id_client = ?';
            $params[] = $idClient;
        }

        return [$sql, $params];
    }

    private function normalizedTextExpr(string $fieldExpr): string
    {
        return 'LEFT(' . $this->lowerTrimDecryptExpr($fieldExpr) . ', 191)';
    }

    private function normalizedFullExpr(): string
    {
        return 'LEFT(TRIM(CONCAT(' . $this->lowerTrimDecryptExpr('c.cognome') . ", ' ', " . $this->lowerTrimDecryptExpr('c.nome') . ')), 191)';
    }

    private function normalizedEmailExpr(string $fieldExpr): string
    {
        return 'NULLIF(LEFT(' . $this->lowerTrimDecryptExpr($fieldExpr) . ', 191), \'\')';
    }

    private function normalizedCodeExpr(string $fieldExpr): string
    {
        return 'NULLIF(LEFT(REPLACE(' . $this->lowerTrimDecryptExpr($fieldExpr) . ", ' ', ''), 32), '')";
    }

    private function normalizedPhoneExpr(string $fieldExpr): string
    {
        $expr = $this->lowerTrimDecryptExpr($fieldExpr);
        $expr = "REPLACE({$expr}, ' ', '')";
        $expr = "REPLACE({$expr}, '-', '')";
        $expr = "REPLACE({$expr}, '+', '')";
        $expr = "REPLACE({$expr}, '/', '')";
        $expr = "REPLACE({$expr}, '(', '')";
        $expr = "REPLACE({$expr}, ')', '')";
        $expr = "REPLACE({$expr}, '.', '')";

        return "NULLIF(LEFT({$expr}, 32), '')";
    }

    private function buildSpecialPatientWhereSql(): string
    {
        $pazSpec = 'TRIM(COALESCE(' . $this->decryptExpr('c.paz_spec') . ", ''))";
        $cognome = 'UPPER(TRIM(COALESCE(' . $this->decryptExpr('c.cognome') . ", '')))";
        $nome = 'UPPER(TRIM(COALESCE(' . $this->decryptExpr('c.nome') . ", '')))";
        $combined = 'UPPER(TRIM(CONCAT(COALESCE(' . $this->decryptExpr('c.cognome') . ", ''), ' ', COALESCE(" . $this->decryptExpr('c.nome') . ", ''))))";

        return "(
            {$pazSpec} <> ''
            OR {$cognome} IN ('DDD', 'STOP', 'INFO', 'INF', 'URG', 'CER', 'DOT')
            OR {$nome} IN ('DDD', 'STOP', 'INFO', 'INF', 'URG', 'CER', 'DOT')
            OR {$combined} REGEXP '^(DDD|STOP|INFO|INF|URG|CER|DOT) '
        )";
    }

    private function lowerTrimDecryptExpr(string $fieldExpr): string
    {
        return 'LOWER(TRIM(COALESCE(' . $this->decryptExpr($fieldExpr) . ", '')))";
    }

    private function decryptExpr(string $fieldExpr): string
    {
        return 'CONVERT(CAST(AES_DECRYPT(UNHEX(' . $fieldExpr . '), @key_str, c.vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4)';
    }

    private function normalizeText(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = preg_replace('/\s+/', ' ', $value) ?? '';

        return $value;
    }

    private function normalizePhone(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }

    private function normalizeCode(string $value): string
    {
        return strtolower(preg_replace('/[^a-zA-Z0-9]+/', '', trim($value)) ?? '');
    }

    private function buildVisibleIndexWhereSql(string $alias = ''): string
    {
        $prefix = $alias !== '' ? rtrim($alias, '.') . '.' : '';
        $fields = [
            'cognome_norm',
            'nome_norm',
            'cf_norm',
            'tel_norm',
            'cell_norm',
            'email_norm',
            'paz_spec_norm',
        ];

        $checks = array_map(
            static fn(string $field): string => "COALESCE({$prefix}{$field}, '') <> ''",
            $fields
        );

        return '(' . implode(' OR ', $checks) . ')';
    }
}
