<?php

namespace App\Models;

use App\Libraries\Crypto_helper;
use App\Libraries\DatabaseConfig;
use CodeIgniter\Model;
use Exception;

class PazientiModel extends Model
{
    private const CLIENTS_TABLE = 'dap02_clients';
    private const CLIENT_DOCTOR_TABLE = 'dap09_client_doctor';
    private const APPOINTMENTS_TABLE = 'dap12_agenda_appuntamenti';
    private const SPECIAL_PATIENT_TOKENS = ['DDD', 'STOP', 'INFO', 'INF', 'URG', 'CER', 'DOT'];

    protected $db;
    protected Crypto_helper $crypto;
    protected ClientDoctorModel $clientDoctorModel;
    protected DoctorPatientSearchModel $doctorPatientSearchModel;
    private array $doctorIdCache = [];

    public function __construct()
    {
        parent::__construct();
        $this->db = \Config\Database::connect();
        $this->crypto = new Crypto_helper();
        $this->clientDoctorModel = new ClientDoctorModel();
        $this->doctorPatientSearchModel = new DoctorPatientSearchModel();

        $dbConfig = new DatabaseConfig();
        $dbConfig->setEncryptionConfig($this->db);
    }

    public function autocompleteByDoctor(int $idDot, string $term, bool $onlyFutureAppointments = false): array
    {
        $term = trim($term);
        if ($term === '') {
            return [];
        }

        $idPersonale = $this->resolvePersonaleIdFromLegacyDot($idDot);
        if ($idPersonale <= 0) {
            return [];
        }

        if ($onlyFutureAppointments) {
            $ids = $this->searchFutureAppointmentClientIdsByDoctor($idDot, $term, 20);
            return $this->getPatientsByIds($ids, true);
        }

        if ($this->doctorPatientSearchModel->tableExists()) {
            try {
                $ids = $this->doctorPatientSearchModel->searchClientIdsForDoctor($idDot, $term, 20);
                return $this->getPatientsByIds($ids, true);
            } catch (\Throwable $e) {
                log_message('warning', 'PazientiModel autocomplete indexed search fallback: ' . $e->getMessage(), [
                    'id_dot' => $idDot,
                    'term' => $term,
                ]);
            }
        }

        $needle = '%' . mb_strtolower($term) . '%';
        $params = [$idPersonale, $idPersonale, $idDot];
        $sql = "
            SELECT
                c.id_client AS id_paziente,
                {$this->dec('c.nome')} AS nome,
                {$this->dec('c.cognome')} AS cognome,
                {$this->dec('c.telefono')} AS telefono,
                {$this->dec('c.cellulare')} AS cellulare,
                {$this->dec('c.email')} AS email,
                {$this->dec('c.codice_fiscale')} AS cod_fis,
                {$this->dec('c.paz_spec')} AS paz_spec,
                {$this->dec('c.indirizzo')} AS indirizzo,
                {$this->dec('c.citta')} AS citta,
                CONCAT({$this->decExpr('c.cognome')}, ' ', {$this->decExpr('c.nome')}) AS label
            FROM " . self::CLIENTS_TABLE . " c
            INNER JOIN (
                {$this->buildDoctorScopedPatientIdsSql()}
            ) scope
                ON scope.id_client = c.id_client
            WHERE 1 = 1
              AND (
                    LOWER(COALESCE({$this->decExpr('c.cognome')}, '')) LIKE ?
                 OR LOWER(COALESCE({$this->decExpr('c.nome')}, '')) LIKE ?
                 OR LOWER(COALESCE({$this->decExpr('c.codice_fiscale')}, '')) LIKE ?
                 OR LOWER(COALESCE({$this->decExpr('c.telefono')}, '')) LIKE ?
                 OR LOWER(COALESCE({$this->decExpr('c.cellulare')}, '')) LIKE ?
                 OR LOWER(COALESCE({$this->decExpr('c.email')}, '')) LIKE ?
                 OR LOWER(COALESCE({$this->decExpr('c.paz_spec')}, '')) LIKE ?
              )
            ORDER BY
                CASE WHEN COALESCE(TRIM({$this->decExpr('c.paz_spec')}), '') <> '' THEN 0 ELSE 1 END,
                {$this->decExpr('c.cognome')} ASC,
                {$this->decExpr('c.nome')} ASC
            LIMIT 20
        ";

        array_push($params, $needle, $needle, $needle, $needle, $needle, $needle, $needle);

        return $this->db->query($sql, $params)->getResultArray();
    }

    private function searchFutureAppointmentClientIdsByDoctor(int $idDot, string $term, int $limit = 20): array
    {
        $term = trim($term);
        if ($idDot <= 0 || $term === '') {
            return [];
        }

        $limit = max(1, min(50, $limit));

        $specialTerm = $this->normalizeSpecialPatientSearchTerm($term);
        if ($specialTerm !== '') {
            return $this->searchFutureSpecialAppointmentClientIdsByDoctor($idDot, $specialTerm, $limit);
        }

        if ($this->doctorPatientSearchModel->tableExists()) {
            try {
                $candidateIds = $this->doctorPatientSearchModel->searchClientIdsForDoctor(
                    $idDot,
                    $term,
                    min(160, max(60, $limit * 6))
                );

                if ($candidateIds !== []) {
                    $ordered = $this->filterClientIdsWithFutureAppointmentsByDoctor($idDot, $candidateIds, $limit);
                    if ($ordered !== []) {
                        return $ordered;
                    }
                }
            } catch (\Throwable $e) {
                log_message('warning', 'PazientiModel future appointment indexed search fallback: ' . $e->getMessage(), [
                    'id_dot' => $idDot,
                    'term' => $term,
                ]);
            }
        }

        return $this->searchFutureAppointmentClientIdsByAppointmentText($idDot, $term, $limit);
    }

    private function filterClientIdsWithFutureAppointmentsByDoctor(int $idDot, array $candidateIds, int $limit = 20): array
    {
        if ($idDot <= 0) {
            return [];
        }

        $candidateIds = array_values(array_unique(array_map('intval', $candidateIds)));
        $candidateIds = array_values(array_filter($candidateIds, static fn(int $id): bool => $id > 0));

        if ($candidateIds === []) {
            return [];
        }

        $limit = max(1, min(50, $limit));
        $idListSql = implode(',', $candidateIds);

        $clientRows = $this->db->query(
            "
                SELECT
                    c.id_client,
                    COALESCE(c.legacy_id_paziente, 0) AS legacy_id_paziente
                FROM " . self::CLIENTS_TABLE . " c
                WHERE c.id_client IN ({$idListSql})
            "
        )->getResultArray();

        if ($clientRows === []) {
            return [];
        }

        $appointmentPatientIdMap = [];
        foreach ($clientRows as $row) {
            $idClient = (int)($row['id_client'] ?? 0);
            if ($idClient <= 0) {
                continue;
            }

            $candidateAppointmentIds = [
                $idClient,
                (int)($row['legacy_id_paziente'] ?? 0),
            ];

            foreach ($candidateAppointmentIds as $appointmentPatientId) {
                if ($appointmentPatientId <= 0) {
                    continue;
                }

                $appointmentPatientIdMap[$appointmentPatientId][$idClient] = true;
            }
        }

        if ($appointmentPatientIdMap === []) {
            return [];
        }

        $hasAppointmentClientColumn = $this->db->fieldExists('id_client', self::APPOINTMENTS_TABLE);
        $now = date('Y-m-d H:i:s');
        $matchedLookup = [];

        if ($hasAppointmentClientColumn) {
            $directRows = $this->db->query(
                "
                    SELECT DISTINCT a.id_client
                    FROM " . self::APPOINTMENTS_TABLE . " a
                    INNER JOIN dap11_agenda_slot s
                        ON s.id_slot = a.id_slot
                    WHERE a.id_dot = ?
                      AND a.stato <> 'ANNULLATO'
                      AND s.ora_inizio >= ?
                      AND a.id_client IN ({$idListSql})
                ",
                [$idDot, $now]
            )->getResultArray();

            foreach ($directRows as $row) {
                $matchedId = (int)($row['id_client'] ?? 0);
                if ($matchedId > 0) {
                    $matchedLookup[$matchedId] = true;
                }
            }
        }

        $appointmentPatientIds = array_keys($appointmentPatientIdMap);
        if ($appointmentPatientIds !== []) {
            $appointmentPatientIdSql = implode(',', array_map('intval', $appointmentPatientIds));
            $legacyMatchSql = $hasAppointmentClientColumn ? 'AND COALESCE(a.id_client, 0) = 0' : '';

            $legacyRows = $this->db->query(
                "
                    SELECT DISTINCT a.id_paziente
                    FROM " . self::APPOINTMENTS_TABLE . " a
                    INNER JOIN dap11_agenda_slot s
                        ON s.id_slot = a.id_slot
                    WHERE a.id_dot = ?
                      AND a.stato <> 'ANNULLATO'
                      AND s.ora_inizio >= ?
                      {$legacyMatchSql}
                      AND a.id_paziente IN ({$appointmentPatientIdSql})
                ",
                [$idDot, $now]
            )->getResultArray();

            foreach ($legacyRows as $row) {
                $appointmentPatientId = (int)($row['id_paziente'] ?? 0);
                foreach (array_keys($appointmentPatientIdMap[$appointmentPatientId] ?? []) as $matchedId) {
                    $matchedLookup[(int)$matchedId] = true;
                }
            }
        }

        if ($matchedLookup === []) {
            return [];
        }

        $ordered = [];
        foreach ($candidateIds as $idClient) {
            if (!isset($matchedLookup[$idClient])) {
                continue;
            }

            $ordered[] = $idClient;
            if (count($ordered) >= $limit) {
                break;
            }
        }

        return $ordered;
    }

    private function normalizeSpecialPatientSearchTerm(string $term): string
    {
        $normalized = strtoupper(trim(preg_replace('/\s+/', ' ', $term) ?? ''));
        if ($normalized === '') {
            return '';
        }

        foreach (self::SPECIAL_PATIENT_TOKENS as $token) {
            if (strpos($token, $normalized) === 0 || strpos($normalized, $token) === 0) {
                return $normalized;
            }
        }

        return '';
    }

    private function listFutureAppointmentClientIdsByDoctor(int $idDot, int $limit = 500): array
    {
        if ($idDot <= 0) {
            return [];
        }

        $hasAppointmentClientColumn = $this->db->fieldExists('id_client', self::APPOINTMENTS_TABLE);
        $resolvedClientExpr = $hasAppointmentClientColumn
            ? 'COALESCE(NULLIF(a.id_client, 0), c_legacy.id_client)'
            : 'c_legacy.id_client';

        $legacyJoin = $hasAppointmentClientColumn
            ? 'LEFT JOIN ' . self::CLIENTS_TABLE . ' c_legacy
                ON COALESCE(a.id_client, 0) = 0
               AND COALESCE(c_legacy.legacy_id_paziente, 0) = a.id_paziente'
            : 'LEFT JOIN ' . self::CLIENTS_TABLE . ' c_legacy
                ON COALESCE(c_legacy.legacy_id_paziente, 0) = a.id_paziente';

        $limit = max(1, min(2000, $limit));

        $sql = "
            SELECT
                {$resolvedClientExpr} AS id_client,
                MIN(s.ora_inizio) AS next_appointment_at
            FROM " . self::APPOINTMENTS_TABLE . " a
            INNER JOIN dap11_agenda_slot s
                ON s.id_slot = a.id_slot
            {$legacyJoin}
            WHERE a.id_dot = ?
              AND a.stato <> 'ANNULLATO'
              AND s.ora_inizio >= ?
              AND {$resolvedClientExpr} IS NOT NULL
            GROUP BY {$resolvedClientExpr}
            ORDER BY next_appointment_at ASC, id_client ASC
            LIMIT {$limit}
        ";

        $rows = $this->db->query($sql, [$idDot, date('Y-m-d H:i:s')])->getResultArray();

        return array_values(array_filter(array_map(
            static fn(array $row): int => (int)($row['id_client'] ?? 0),
            $rows
        ), static fn(int $id): bool => $id > 0));
    }

    private function searchFutureSpecialAppointmentClientIdsByDoctor(int $idDot, string $term, int $limit = 20): array
    {
        if ($idDot <= 0 || $term === '') {
            return [];
        }

        $hasAppointmentClientColumn = $this->db->fieldExists('id_client', self::APPOINTMENTS_TABLE);
        $resolvedClientExpr = $hasAppointmentClientColumn
            ? 'COALESCE(NULLIF(a.id_client, 0), c_legacy.id_client)'
            : 'c_legacy.id_client';

        $legacyJoin = $hasAppointmentClientColumn
            ? 'LEFT JOIN ' . self::CLIENTS_TABLE . ' c_legacy
                ON COALESCE(a.id_client, 0) = 0
               AND COALESCE(c_legacy.legacy_id_paziente, 0) = a.id_paziente'
            : 'LEFT JOIN ' . self::CLIENTS_TABLE . ' c_legacy
                ON COALESCE(c_legacy.legacy_id_paziente, 0) = a.id_paziente';

        $limit = max(1, min(50, $limit));
        $needle = $term . '%';

        $sql = "
            SELECT
                {$resolvedClientExpr} AS id_client,
                MIN(s.ora_inizio) AS next_appointment_at
            FROM " . self::APPOINTMENTS_TABLE . " a
            INNER JOIN dap11_agenda_slot s
                ON s.id_slot = a.id_slot
            {$legacyJoin}
            WHERE a.id_dot = ?
              AND a.stato <> 'ANNULLATO'
              AND s.ora_inizio >= ?
              AND {$resolvedClientExpr} IS NOT NULL
              AND (
                    UPPER(TRIM(COALESCE(a.cognome, ''))) LIKE ?
                 OR UPPER(TRIM(COALESCE(a.nome, ''))) LIKE ?
                 OR UPPER(TRIM(CONCAT(COALESCE(a.cognome, ''), ' ', COALESCE(a.nome, '')))) LIKE ?
              )
            GROUP BY {$resolvedClientExpr}
            ORDER BY next_appointment_at ASC, id_client ASC
            LIMIT {$limit}
        ";

        $rows = $this->db->query($sql, [$idDot, date('Y-m-d H:i:s'), $needle, $needle, $needle])->getResultArray();

        return array_values(array_filter(array_map(
            static fn(array $row): int => (int)($row['id_client'] ?? 0),
            $rows
        ), static fn(int $id): bool => $id > 0));
    }

    private function searchFutureAppointmentClientIdsByAppointmentText(int $idDot, string $term, int $limit = 20): array
    {
        $term = mb_strtolower(trim($term), 'UTF-8');
        if ($idDot <= 0 || $term === '') {
            return [];
        }

        $limit = max(1, min(50, $limit));
        $tokens = preg_split('/\s+/', $term) ?: [];
        $tokens = array_values(array_filter($tokens, static fn(string $token): bool => $token !== ''));

        $hasAppointmentClientColumn = $this->db->fieldExists('id_client', self::APPOINTMENTS_TABLE);
        $resolvedClientExpr = $hasAppointmentClientColumn
            ? 'COALESCE(NULLIF(a.id_client, 0), c_legacy.id_client)'
            : 'c_legacy.id_client';

        $legacyJoin = $hasAppointmentClientColumn
            ? 'LEFT JOIN ' . self::CLIENTS_TABLE . ' c_legacy
                ON COALESCE(a.id_client, 0) = 0
               AND COALESCE(c_legacy.legacy_id_paziente, 0) = a.id_paziente'
            : 'LEFT JOIN ' . self::CLIENTS_TABLE . ' c_legacy
                ON COALESCE(c_legacy.legacy_id_paziente, 0) = a.id_paziente';

        $params = [$idDot, date('Y-m-d H:i:s')];
        $searchSql = '';

        if (count($tokens) >= 2) {
            $first = $tokens[0] . '%';
            $second = $tokens[1] . '%';
            $searchSql = "
                AND (
                    (LOWER(TRIM(COALESCE(a.cognome, ''))) LIKE ? AND LOWER(TRIM(COALESCE(a.nome, ''))) LIKE ?)
                    OR
                    (LOWER(TRIM(COALESCE(a.nome, ''))) LIKE ? AND LOWER(TRIM(COALESCE(a.cognome, ''))) LIKE ?)
                )
            ";
            array_push($params, $first, $second, $first, $second);
        } else {
            $needle = $term . '%';
            $searchSql = "
                AND (
                    LOWER(TRIM(COALESCE(a.cognome, ''))) LIKE ?
                    OR LOWER(TRIM(COALESCE(a.nome, ''))) LIKE ?
                )
            ";
            array_push($params, $needle, $needle);
        }

        $sql = "
            SELECT
                {$resolvedClientExpr} AS id_client,
                MIN(s.ora_inizio) AS next_appointment_at
            FROM " . self::APPOINTMENTS_TABLE . " a
            INNER JOIN dap11_agenda_slot s
                ON s.id_slot = a.id_slot
            {$legacyJoin}
            WHERE a.id_dot = ?
              AND a.stato <> 'ANNULLATO'
              AND s.ora_inizio >= ?
              AND {$resolvedClientExpr} IS NOT NULL
              {$searchSql}
            GROUP BY {$resolvedClientExpr}
            ORDER BY next_appointment_at ASC, id_client ASC
            LIMIT {$limit}
        ";

        $rows = $this->db->query($sql, $params)->getResultArray();

        return array_values(array_filter(array_map(
            static fn(array $row): int => (int)($row['id_client'] ?? 0),
            $rows
        ), static fn(int $id): bool => $id > 0));
    }

    public function getPatientsByDoctor(int $idDot, string $term = ''): array
    {
        $idPersonale = $this->resolvePersonaleIdFromLegacyDot($idDot);
        if ($idPersonale <= 0) {
            return [];
        }

        if ($this->doctorPatientSearchModel->tableExists()) {
            try {
                $ids = $this->doctorPatientSearchModel->listClientIdsForDoctor($idDot, $term);
                return $this->getPatientsByIds($ids, false);
            } catch (\Throwable $e) {
                log_message('warning', 'PazientiModel patient list indexed search fallback: ' . $e->getMessage(), [
                    'id_dot' => $idDot,
                    'term' => $term,
                ]);
            }
        }

        $params = [$idPersonale, $idPersonale, $idDot];
        $whereSearch = '';

        $term = trim($term);
        if ($term !== '') {
            $needle = '%' . mb_strtolower($term) . '%';
            $whereSearch = "
                AND (
                    LOWER(COALESCE({$this->decExpr('c.cognome')}, '')) LIKE ?
                    OR LOWER(COALESCE({$this->decExpr('c.nome')}, '')) LIKE ?
                    OR LOWER(COALESCE({$this->decExpr('c.codice_fiscale')}, '')) LIKE ?
                    OR LOWER(COALESCE({$this->decExpr('c.telefono')}, '')) LIKE ?
                    OR LOWER(COALESCE({$this->decExpr('c.cellulare')}, '')) LIKE ?
                    OR LOWER(COALESCE({$this->decExpr('c.email')}, '')) LIKE ?
                    OR LOWER(COALESCE({$this->decExpr('c.paz_spec')}, '')) LIKE ?
                )
            ";
            array_push($params, $needle, $needle, $needle, $needle, $needle, $needle, $needle);
        }

        $sql = "
            SELECT
                c.id_client AS id_paziente,
                {$this->dec('c.nome')} AS nome,
                {$this->dec('c.cognome')} AS cognome,
                {$this->dec('c.telefono')} AS telefono,
                {$this->dec('c.cellulare')} AS cellulare,
                {$this->dec('c.email')} AS email,
                {$this->dec('c.codice_fiscale')} AS cod_fis,
                {$this->dec('c.data_nascita')} AS data_nascita,
                {$this->dec('c.comune_nascita')} AS comune_nascita,
                {$this->dec('c.provincia_nascita')} AS provincia_nascita,
                {$this->dec('c.indirizzo')} AS indirizzo,
                {$this->dec('c.citta')} AS citta,
                {$this->dec('c.cap')} AS cap,
                {$this->dec('c.provincia')} AS provincia,
                {$this->dec('c.residenza_indirizzo')} AS residenza_indirizzo,
                {$this->dec('c.residenza_comune')} AS residenza_comune,
                {$this->dec('c.residenza_cap')} AS residenza_cap,
                {$this->dec('c.residenza_provincia')} AS residenza_provincia,
                {$this->dec('c.paz_spec')} AS paz_spec,
                COALESCE(c.bloccato, 0) AS bloccato
            FROM " . self::CLIENTS_TABLE . " c
            INNER JOIN (
                {$this->buildDoctorScopedPatientIdsSql()}
            ) scope
                ON scope.id_client = c.id_client
            WHERE 1 = 1
            {$whereSearch}
            ORDER BY
                CASE WHEN COALESCE(TRIM({$this->decExpr('c.paz_spec')}), '') <> '' THEN 0 ELSE 1 END,
                {$this->decExpr('c.cognome')} ASC,
                {$this->decExpr('c.nome')} ASC
        ";

        return $this->sanitizePatientRows($this->db->query($sql, $params)->getResultArray(), false);
    }

    public function getPatientsByDoctorPaginate(int $idDot, string $term = '', int $page = 1, int $perPage = 20): array
    {
        $idPersonale = $this->resolvePersonaleIdFromLegacyDot($idDot);
        if ($idPersonale <= 0) {
            return [
                'rows' => [],
                'page' => 1,
                'perPage' => max(1, $perPage),
                'total' => 0,
                'lastPage' => 1,
                'from' => 0,
                'to' => 0,
            ];
        }

        if ($this->doctorPatientSearchModel->tableExists()) {
            try {
                $indexed = $this->doctorPatientSearchModel->paginateClientIdsForDoctor($idDot, $term, $page, $perPage);
                $rows = $this->getPatientsByIds($indexed['ids'], false);

                return [
                    'rows' => $rows,
                    'page' => $indexed['page'],
                    'perPage' => $indexed['perPage'],
                    'total' => $indexed['total'],
                    'lastPage' => $indexed['lastPage'],
                    'from' => $indexed['total'] > 0 && !empty($rows) ? $indexed['from'] : 0,
                    'to' => $indexed['total'] > 0 && !empty($rows)
                        ? min($indexed['from'] + count($rows) - 1, $indexed['total'])
                        : 0,
                ];
            } catch (\Throwable $e) {
                log_message('warning', 'PazientiModel indexed paginated patient list fallback: ' . $e->getMessage(), [
                    'id_dot' => $idDot,
                    'term' => $term,
                    'page' => $page,
                    'per_page' => $perPage,
                ]);
            }
        }

        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $params = [$idPersonale, $idPersonale, $idDot];
        $whereSearch = '';

        $term = trim($term);
        if ($term !== '') {
            $needle = '%' . mb_strtolower($term) . '%';
            $whereSearch = "
                AND (
                    LOWER(COALESCE({$this->decExpr('c.cognome')}, '')) LIKE ?
                    OR LOWER(COALESCE({$this->decExpr('c.nome')}, '')) LIKE ?
                    OR LOWER(COALESCE({$this->decExpr('c.codice_fiscale')}, '')) LIKE ?
                    OR LOWER(COALESCE({$this->decExpr('c.telefono')}, '')) LIKE ?
                    OR LOWER(COALESCE({$this->decExpr('c.cellulare')}, '')) LIKE ?
                    OR LOWER(COALESCE({$this->decExpr('c.email')}, '')) LIKE ?
                    OR LOWER(COALESCE({$this->decExpr('c.paz_spec')}, '')) LIKE ?
                )
            ";
            array_push($params, $needle, $needle, $needle, $needle, $needle, $needle, $needle);
        }

        $baseFromSql = "
            FROM " . self::CLIENTS_TABLE . " c
            INNER JOIN (
                {$this->buildDoctorScopedPatientIdsSql()}
            ) scope
                ON scope.id_client = c.id_client
            WHERE 1 = 1
              AND {$this->buildNonEmptyPatientDataSql('c')}
              {$whereSearch}
        ";

        $countSql = "SELECT COUNT(*) AS total {$baseFromSql}";
        $countRow = $this->db->query($countSql, $params)->getRowArray();
        $total = (int)($countRow['total'] ?? 0);
        $lastPage = max(1, (int)ceil($total / $perPage));
        $page = min($page, $lastPage);
        $offset = max(0, ($page - 1) * $perPage);

        if ($total === 0) {
            return [
                'rows' => [],
                'page' => 1,
                'perPage' => $perPage,
                'total' => 0,
                'lastPage' => 1,
                'from' => 0,
                'to' => 0,
            ];
        }

        $rowsSql = "
            SELECT
                c.id_client AS id_paziente,
                {$this->dec('c.nome')} AS nome,
                {$this->dec('c.cognome')} AS cognome,
                {$this->dec('c.telefono')} AS telefono,
                {$this->dec('c.cellulare')} AS cellulare,
                {$this->dec('c.email')} AS email,
                {$this->dec('c.codice_fiscale')} AS cod_fis,
                {$this->dec('c.data_nascita')} AS data_nascita,
                {$this->dec('c.comune_nascita')} AS comune_nascita,
                {$this->dec('c.provincia_nascita')} AS provincia_nascita,
                {$this->dec('c.indirizzo')} AS indirizzo,
                {$this->dec('c.citta')} AS citta,
                {$this->dec('c.cap')} AS cap,
                {$this->dec('c.provincia')} AS provincia,
                {$this->dec('c.residenza_indirizzo')} AS residenza_indirizzo,
                {$this->dec('c.residenza_comune')} AS residenza_comune,
                {$this->dec('c.residenza_cap')} AS residenza_cap,
                {$this->dec('c.residenza_provincia')} AS residenza_provincia,
                {$this->dec('c.paz_spec')} AS paz_spec,
                COALESCE(c.bloccato, 0) AS bloccato
            {$baseFromSql}
            ORDER BY
                CASE WHEN COALESCE(TRIM({$this->decExpr('c.paz_spec')}), '') <> '' THEN 0 ELSE 1 END,
                {$this->decExpr('c.cognome')} ASC,
                {$this->decExpr('c.nome')} ASC
            LIMIT {$perPage} OFFSET {$offset}
        ";

        $rows = $this->sanitizePatientRows($this->db->query($rowsSql, $params)->getResultArray(), false);

        return [
            'rows' => $rows,
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'lastPage' => $lastPage,
            'from' => $total > 0 && !empty($rows) ? $offset + 1 : 0,
            'to' => $total > 0 && !empty($rows) ? min($offset + count($rows), $total) : 0,
        ];
    }

    public function deletePatientByDoctor(int $idPaziente, int $idDot): bool
    {
        $idPersonale = $this->resolvePersonaleIdFromLegacyDot($idDot);
        if ($idPersonale <= 0) {
            throw new Exception('Dottore non valido.');
        }

        $row = $this->getVisiblePatientSnapshot($idPaziente, $idPersonale, $idDot);
        if (!$row) {
            throw new Exception('Paziente non trovato.');
        }

        $legacyId = (int)($row['legacy_id_paziente'] ?? 0);
        $countSql = "
            SELECT COUNT(*) AS c
            FROM " . self::APPOINTMENTS_TABLE . " a
            WHERE a.stato <> 'ANNULLATO'
              AND (
                    a.id_client = ?
                 OR a.id_paziente = ?
                 " . ($legacyId > 0 ? " OR (COALESCE(a.id_client, 0) = 0 AND a.id_paziente = {$legacyId})" : '') . "
              )
        ";
        $countRow = $this->db->query($countSql, [$idPaziente, $idPaziente])->getRowArray();
        $appointments = (int)($countRow['c'] ?? 0);

        if ($appointments > 0) {
            throw new Exception('Non puoi eliminare il paziente perché ha appuntamenti collegati.');
        }

        $this->db->transStart();

        $this->db->table(self::CLIENT_DOCTOR_TABLE)
            ->where('id_client', $idPaziente)
            ->where('id_dot', $idPersonale)
            ->delete();

        $remainingLinks = $this->db->table(self::CLIENT_DOCTOR_TABLE)
            ->select('id_dot')
            ->where('id_client', $idPaziente)
            ->get()
            ->getResultArray();

        $remainingDoctorIds = array_values(array_unique(array_map(
            static fn(array $item): int => (int)($item['id_dot'] ?? 0),
            $remainingLinks
        )));
        $remainingDoctorIds = array_values(array_filter($remainingDoctorIds, static fn(int $doctorId): bool => $doctorId > 0));

        $currentPrimaryDoctorId = (int)($row['id_personale'] ?? 0);
        if ($currentPrimaryDoctorId === $idPersonale) {
            $newPrimaryDoctorId = $this->resolveReplacementPrimaryDoctorId($remainingDoctorIds);
            $this->db->table(self::CLIENTS_TABLE)
                ->where('id_client', $idPaziente)
                ->update(['id_personale' => $newPrimaryDoctorId > 0 ? $newPrimaryDoctorId : null]);
        }

        if (empty($remainingDoctorIds) && (int)($row['id_user'] ?? 0) <= 0) {
            $this->db->table(self::CLIENTS_TABLE)
                ->where('id_client', $idPaziente)
                ->delete();
        }

        $this->db->transComplete();

        if (!$this->db->transStatus()) {
            throw new Exception('Errore durante l\'eliminazione del paziente.');
        }

        $this->doctorPatientSearchModel->syncClient($idPaziente);

        return true;
    }

    public function getPaziente(int $idPaziente): ?array
    {
        if ($idPaziente <= 0) {
            return null;
        }

        $sql = "
            SELECT
                c.id_client AS id_paziente,
                {$this->dec('c.nome')} AS nome,
                {$this->dec('c.cognome')} AS cognome,
                {$this->dec('c.data_nascita')} AS data_nascita,
                {$this->dec('c.codice_fiscale')} AS cod_fis,
                {$this->dec('c.comune_nascita')} AS comune_nascita,
                {$this->dec('c.provincia_nascita')} AS provincia_nascita,
                {$this->dec('c.indirizzo')} AS indirizzo,
                {$this->dec('c.citta')} AS citta,
                {$this->dec('c.cap')} AS cap,
                {$this->dec('c.provincia')} AS provincia,
                {$this->dec('c.residenza_indirizzo')} AS residenza_indirizzo,
                {$this->dec('c.residenza_comune')} AS residenza_comune,
                {$this->dec('c.residenza_cap')} AS residenza_cap,
                {$this->dec('c.residenza_provincia')} AS residenza_provincia,
                {$this->dec('c.telefono')} AS telefono,
                {$this->dec('c.cellulare')} AS cellulare,
                {$this->dec('c.email')} AS email,
                {$this->dec('c.paz_spec')} AS paz_spec,
                COALESCE(c.bloccato, 0) AS bloccato,
                COALESCE(c.id_personale, 0) AS id_dot
            FROM " . self::CLIENTS_TABLE . " c
            WHERE c.id_client = ?
            LIMIT 1
        ";

        $row = $this->db->query($sql, [$idPaziente])->getRowArray();
        return $row ?: null;
    }

    public function getPazienteByDoctor(int $idPaziente, int $idDot): ?array
    {
        if ($idPaziente <= 0 || $idDot <= 0) {
            return null;
        }

        $idPersonale = $this->resolvePersonaleIdFromLegacyDot($idDot);
        if ($idPersonale <= 0) {
            return null;
        }

        if (
            $this->doctorPatientSearchModel->tableExists()
            && $this->doctorPatientSearchModel->hasVisibleClientForDoctor($idDot, $idPaziente)
        ) {
            return $this->sanitizePatientDetailRow($this->getPaziente($idPaziente));
        }

        $sql = "
            SELECT
                c.id_client AS id_paziente,
                {$this->dec('c.nome')} AS nome,
                {$this->dec('c.cognome')} AS cognome,
                {$this->dec('c.data_nascita')} AS data_nascita,
                {$this->dec('c.codice_fiscale')} AS cod_fis,
                {$this->dec('c.comune_nascita')} AS comune_nascita,
                {$this->dec('c.provincia_nascita')} AS provincia_nascita,
                {$this->dec('c.indirizzo')} AS indirizzo,
                {$this->dec('c.citta')} AS citta,
                {$this->dec('c.cap')} AS cap,
                {$this->dec('c.provincia')} AS provincia,
                {$this->dec('c.residenza_indirizzo')} AS residenza_indirizzo,
                {$this->dec('c.residenza_comune')} AS residenza_comune,
                {$this->dec('c.residenza_cap')} AS residenza_cap,
                {$this->dec('c.residenza_provincia')} AS residenza_provincia,
                {$this->dec('c.telefono')} AS telefono,
                {$this->dec('c.cellulare')} AS cellulare,
                {$this->dec('c.email')} AS email,
                {$this->dec('c.paz_spec')} AS paz_spec,
                COALESCE(c.bloccato, 0) AS bloccato,
                COALESCE(c.id_personale, 0) AS id_dot
            FROM " . self::CLIENTS_TABLE . " c
            INNER JOIN (
                {$this->buildDoctorScopedPatientIdsSql()}
            ) scope
                ON scope.id_client = c.id_client
            WHERE c.id_client = ?
            LIMIT 1
        ";

        $row = $this->db->query($sql, [$idPersonale, $idPersonale, $idDot, $idPaziente])->getRowArray();
        return $this->sanitizePatientDetailRow($row ?: null);
    }

    public function getAppointmentsByDoctorAndPatient(int $idPaziente, int $idDot, int $limit = 200): array
    {
        if ($idPaziente <= 0 || $idDot <= 0) {
            return [];
        }

        $patientSnapshot = $this->getClientSnapshot($idPaziente);
        if (!$patientSnapshot) {
            return [];
        }

        $legacyPatientIds = [$idPaziente];
        $legacyIdPaziente = (int)($patientSnapshot['legacy_id_paziente'] ?? 0);
        if ($legacyIdPaziente > 0) {
            $legacyPatientIds[] = $legacyIdPaziente;
        }
        $legacyPatientIds = array_values(array_unique(array_filter(
            array_map('intval', $legacyPatientIds),
            static fn(int $value): bool => $value > 0
        )));

        $hasAppointmentClientColumn = $this->db->fieldExists('id_client', self::APPOINTMENTS_TABLE);
        $hasAppointmentCreatedByColumn = $this->db->fieldExists('created_by', self::APPOINTMENTS_TABLE);
        $limit = max(1, min(200, $limit));
        $queries = [];
        $params = [];
        $createdBySelect = $hasAppointmentCreatedByColumn
            ? "COALESCE(u_created.username, '') AS created_by_username,"
            : "'' AS created_by_username,";
        $createdByJoin = $hasAppointmentCreatedByColumn
            ? "
            LEFT JOIN dap01_users u_created
                ON u_created.id_user = a.created_by"
            : '';

        $selectSql = "
            SELECT
                a.id_appuntamento,
                a.id_slot,
                a.id_dot,
                s.data_slot,
                s.ora_inizio,
                s.ora_fine,
                TIME_FORMAT(s.ora_inizio, '%H:%i') AS ora_inizio_label,
                TIME_FORMAT(s.ora_fine, '%H:%i') AS ora_fine_label,
                COALESCE(a.stato, '') AS stato,
                COALESCE(s.stato, '') AS stato_slot,
                COALESCE(a.motivo_visita, '') AS motivo_visita,
                COALESCE(a.note, '') AS note,
                {$createdBySelect}
                COALESCE(a.indirizzo_visita, '') AS indirizzo_visita,
                COALESCE(a.comune_visita, '') AS comune_visita
            FROM " . self::APPOINTMENTS_TABLE . " a
            INNER JOIN dap11_agenda_slot s
                ON s.id_slot = a.id_slot
            {$createdByJoin}
            WHERE a.id_dot = ?
              AND a.stato <> 'ANNULLATO'
              AND __PATIENT_MATCH_CONDITION__
        ";

        if ($hasAppointmentClientColumn) {
            $queries[] = str_replace('__PATIENT_MATCH_CONDITION__', 'a.id_client = ?', $selectSql);
            array_push($params, $idDot, $idPaziente);
        }

        if ($legacyPatientIds !== []) {
            $placeholders = implode(',', array_fill(0, count($legacyPatientIds), '?'));
            $legacySql = 'a.id_paziente IN (' . $placeholders . ')';
            if ($hasAppointmentClientColumn) {
                $legacySql = 'COALESCE(a.id_client, 0) = 0 AND ' . $legacySql;
            }

            $queries[] = str_replace('__PATIENT_MATCH_CONDITION__', $legacySql, $selectSql);
            $params[] = $idDot;
            $params = array_merge($params, $legacyPatientIds);
        }

        if ($queries === []) {
            return [];
        }

        $sql = "
            SELECT *
            FROM (
                " . implode("
                UNION
                ", $queries) . "
            ) appointment_rows
            ORDER BY data_slot DESC, ora_inizio DESC, id_appuntamento DESC
            LIMIT {$limit}
        ";

        return $this->db->query($sql, $params)->getResultArray();
    }

    public function savePatientAndLink(array $payload, int $idDot): int
    {
        $idPersonale = $this->resolvePersonaleIdFromLegacyDot($idDot);
        if ($idPersonale <= 0) {
            throw new Exception('Dottore non valido.');
        }

        $providedFields = $this->resolveProvidedPatientFields($payload);

        $data = [
            'cognome' => trim((string)($payload['cognome'] ?? '')),
            'nome' => trim((string)($payload['nome'] ?? '')),
            'data_nascita' => trim((string)($payload['data_nascita'] ?? '')),
            'codice_fiscale' => $this->normalizeFiscalCode((string)($payload['cod_fis'] ?? ($payload['codice_fiscale'] ?? ''))),
            'comune_nascita' => trim((string)($payload['comune_nascita'] ?? '')),
            'provincia_nascita' => trim((string)($payload['provincia_nascita'] ?? '')),
            'indirizzo' => trim((string)($payload['indirizzo'] ?? '')),
            'citta' => trim((string)($payload['citta'] ?? '')),
            'cap' => trim((string)($payload['cap'] ?? '')),
            'provincia' => trim((string)($payload['provincia'] ?? '')),
            'residenza_indirizzo' => trim((string)($payload['residenza_indirizzo'] ?? '')),
            'residenza_comune' => trim((string)($payload['residenza_comune'] ?? '')),
            'residenza_cap' => trim((string)($payload['residenza_cap'] ?? '')),
            'residenza_provincia' => trim((string)($payload['residenza_provincia'] ?? '')),
            'telefono' => trim((string)($payload['telefono'] ?? '')),
            'cellulare' => trim((string)($payload['cellulare'] ?? '')),
            'email' => trim((string)($payload['email'] ?? '')),
            'bloccato' => (int)($payload['bloccato'] ?? 0),
            'paz_spec' => trim((string)($payload['paz_spec'] ?? '')),
        ];

        if ($data['cognome'] === '' || $data['nome'] === '') {
            throw new Exception('Nome e cognome sono obbligatori.');
        }

        $idClient = (int)($payload['id_paziente'] ?? 0);
        $existing = null;
        if ($idClient > 0) {
            $existing = $this->getVisiblePatientSnapshot($idClient, $idPersonale, $idDot);
            if (!$existing) {
                throw new Exception('Paziente non trovato per il medico selezionato.');
            }
        }

        $this->db->transStart();

        if ($existing) {
            $this->updateClientRow($idClient, $data, $existing, $idPersonale, $providedFields);
        } else {
            $idClient = $this->insertClientRow($data, $idPersonale);
            $existing = $this->getClientSnapshot($idClient);
        }

        if ($idClient <= 0 || !$existing) {
            $this->db->transRollback();
            throw new Exception('Impossibile salvare il paziente.');
        }

        $this->clientDoctorModel->setDoctorForClient($idClient, $idPersonale, false);

        if ($this->isFamilyDoctor($idPersonale) && trim((string)($existing['paz_spec'] ?? '')) === '') {
            $this->db->table(self::CLIENTS_TABLE)
                ->where('id_client', $idClient)
                ->update(['id_personale' => $idPersonale]);
        }

        $this->db->transComplete();

        if (!$this->db->transStatus()) {
            throw new Exception('Errore durante il salvataggio del paziente.');
        }

        $this->doctorPatientSearchModel->syncClient($idClient);

        return $idClient;
    }

    private function getPatientsByIds(array $ids, bool $autocompleteMode): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $ids = array_values(array_filter($ids, static fn(int $id): bool => $id > 0));

        if ($ids === []) {
            return [];
        }

        $idListSql = implode(',', $ids);

        if ($autocompleteMode) {
            $sql = "
                SELECT
                    c.id_client AS id_paziente,
                    {$this->dec('c.nome')} AS nome,
                    {$this->dec('c.cognome')} AS cognome,
                    {$this->dec('c.telefono')} AS telefono,
                    {$this->dec('c.cellulare')} AS cellulare,
                    {$this->dec('c.email')} AS email,
                    {$this->dec('c.codice_fiscale')} AS cod_fis,
                    {$this->dec('c.paz_spec')} AS paz_spec,
                    {$this->dec('c.indirizzo')} AS indirizzo,
                    {$this->dec('c.citta')} AS citta,
                    CONCAT({$this->decExpr('c.cognome')}, ' ', {$this->decExpr('c.nome')}) AS label
                FROM " . self::CLIENTS_TABLE . " c
                WHERE c.id_client IN ({$idListSql})
                ORDER BY FIELD(c.id_client, {$idListSql})
            ";
        } else {
            $sql = "
                SELECT
                    c.id_client AS id_paziente,
                    {$this->dec('c.nome')} AS nome,
                    {$this->dec('c.cognome')} AS cognome,
                    {$this->dec('c.telefono')} AS telefono,
                    {$this->dec('c.cellulare')} AS cellulare,
                    {$this->dec('c.email')} AS email,
                    {$this->dec('c.codice_fiscale')} AS cod_fis,
                    {$this->dec('c.data_nascita')} AS data_nascita,
                    {$this->dec('c.comune_nascita')} AS comune_nascita,
                    {$this->dec('c.provincia_nascita')} AS provincia_nascita,
                    {$this->dec('c.indirizzo')} AS indirizzo,
                    {$this->dec('c.citta')} AS citta,
                    {$this->dec('c.cap')} AS cap,
                    {$this->dec('c.provincia')} AS provincia,
                    {$this->dec('c.residenza_indirizzo')} AS residenza_indirizzo,
                    {$this->dec('c.residenza_comune')} AS residenza_comune,
                    {$this->dec('c.residenza_cap')} AS residenza_cap,
                    {$this->dec('c.residenza_provincia')} AS residenza_provincia,
                    {$this->dec('c.paz_spec')} AS paz_spec,
                    COALESCE(c.bloccato, 0) AS bloccato
                FROM " . self::CLIENTS_TABLE . " c
                WHERE c.id_client IN ({$idListSql})
                ORDER BY FIELD(c.id_client, {$idListSql})
            ";
        }

        return $this->sanitizePatientRows($this->db->query($sql)->getResultArray(), $autocompleteMode);
    }

    private function insertClientRow(array $data, int $idPersonale): int
    {
        $this->db->query('SET @init_vector = RANDOM_BYTES(16)');

        $primaryDoctorId = $this->isFamilyDoctor($idPersonale) ? $idPersonale : null;
        $sql = "
            INSERT INTO " . self::CLIENTS_TABLE . " (
                id_user,
                nome,
                cognome,
                cellulare,
                telefono,
                email,
                indirizzo,
                citta,
                provincia,
                cap,
                data_nascita,
                codice_fiscale,
                comune_nascita,
                provincia_nascita,
                residenza_indirizzo,
                residenza_comune,
                residenza_cap,
                residenza_provincia,
                paz_spec,
                bloccato,
                id_personale,
                avviso_mail,
                vector_id
            ) VALUES (
                NULL,
                {$this->enc($data['nome'])},
                {$this->enc($data['cognome'])},
                {$this->enc($data['cellulare'])},
                {$this->enc($data['telefono'])},
                {$this->enc($data['email'])},
                {$this->enc($data['indirizzo'])},
                {$this->enc($data['citta'])},
                {$this->enc($data['provincia'])},
                {$this->enc($data['cap'])},
                {$this->enc($data['data_nascita'])},
                {$this->enc($data['codice_fiscale'])},
                {$this->enc($data['comune_nascita'])},
                {$this->enc($data['provincia_nascita'])},
                {$this->enc($data['residenza_indirizzo'])},
                {$this->enc($data['residenza_comune'])},
                {$this->enc($data['residenza_cap'])},
                {$this->enc($data['residenza_provincia'])},
                {$this->enc($data['paz_spec'])},
                " . (int)$data['bloccato'] . ",
                " . ($primaryDoctorId !== null ? (int)$primaryDoctorId : 'NULL') . ",
                0,
                @init_vector
            )
        ";

        $this->db->query($sql);
        return (int)$this->db->insertID();
    }

    private function updateClientRow(int $idClient, array $data, array $existing, int $idPersonale, array $providedFields = []): void
    {
        $set = [];
        $fieldSqlMap = [
            'nome' => 'nome=' . $this->encWithVector($data['nome']),
            'cognome' => 'cognome=' . $this->encWithVector($data['cognome']),
            'cellulare' => 'cellulare=' . $this->encWithVector($data['cellulare']),
            'telefono' => 'telefono=' . $this->encWithVector($data['telefono']),
            'email' => 'email=' . $this->encWithVector($data['email']),
            'indirizzo' => 'indirizzo=' . $this->encWithVector($data['indirizzo']),
            'citta' => 'citta=' . $this->encWithVector($data['citta']),
            'provincia' => 'provincia=' . $this->encWithVector($data['provincia']),
            'cap' => 'cap=' . $this->encWithVector($data['cap']),
            'data_nascita' => 'data_nascita=' . $this->encWithVector($data['data_nascita']),
            'codice_fiscale' => 'codice_fiscale=' . $this->encWithVector($data['codice_fiscale']),
            'comune_nascita' => 'comune_nascita=' . $this->encWithVector($data['comune_nascita']),
            'provincia_nascita' => 'provincia_nascita=' . $this->encWithVector($data['provincia_nascita']),
            'residenza_indirizzo' => 'residenza_indirizzo=' . $this->encWithVector($data['residenza_indirizzo']),
            'residenza_comune' => 'residenza_comune=' . $this->encWithVector($data['residenza_comune']),
            'residenza_cap' => 'residenza_cap=' . $this->encWithVector($data['residenza_cap']),
            'residenza_provincia' => 'residenza_provincia=' . $this->encWithVector($data['residenza_provincia']),
            'paz_spec' => 'paz_spec=' . $this->encWithVector($data['paz_spec']),
            'bloccato' => 'bloccato=' . (int)$data['bloccato'],
        ];

        foreach ($fieldSqlMap as $field => $sql) {
            if (!empty($providedFields[$field])) {
                $set[] = $sql;
            }
        }

        $isSpecial = trim((string)($existing['paz_spec'] ?? '')) !== '' || $data['paz_spec'] !== '';
        if (!$isSpecial && $this->isFamilyDoctor($idPersonale)) {
            $set[] = 'id_personale=' . (int)$idPersonale;
        }

        if ($set === []) {
            return;
        }

        $sql = "UPDATE " . self::CLIENTS_TABLE . "
                SET " . implode(', ', $set) . "
                WHERE id_client = " . (int)$idClient . "
                LIMIT 1";

        $this->db->query($sql);
    }

    private function getVisiblePatientSnapshot(int $idClient, int $idPersonale, int $legacyIdDot): ?array
    {
        if ($idClient <= 0 || $idPersonale <= 0 || $legacyIdDot <= 0) {
            return null;
        }

        $sql = "
            SELECT
                c.id_client,
                c.id_user,
                COALESCE(c.id_personale, 0) AS id_personale,
                COALESCE(c.legacy_id_paziente, 0) AS legacy_id_paziente,
                {$this->dec('c.paz_spec')} AS paz_spec
            FROM " . self::CLIENTS_TABLE . " c
            WHERE c.id_client = ?
              AND (
                    " . $this->buildDoctorSearchVisibilitySql('c') . "
                 OR " . $this->buildDoctorAppointmentVisibilitySql('c') . "
              )
            LIMIT 1
        ";

        $row = $this->db->query(
            $sql,
            [$idClient, $idPersonale, $idPersonale, $legacyIdDot, $legacyIdDot]
        )->getRowArray();
        return $row ?: null;
    }

    private function getClientSnapshot(int $idClient): ?array
    {
        if ($idClient <= 0) {
            return null;
        }

        $sql = "
            SELECT
                c.id_client,
                c.id_user,
                COALESCE(c.id_personale, 0) AS id_personale,
                COALESCE(c.legacy_id_paziente, 0) AS legacy_id_paziente,
                {$this->dec('c.paz_spec')} AS paz_spec
            FROM " . self::CLIENTS_TABLE . " c
            WHERE c.id_client = ?
            LIMIT 1
        ";

        $row = $this->db->query($sql, [$idClient])->getRowArray();
        return $row ?: null;
    }

    private function buildDoctorVisibilitySql(string $alias): string
    {
        return "(
            {$alias}.id_personale = ?
            OR EXISTS (
                SELECT 1
                FROM " . self::CLIENT_DOCTOR_TABLE . " cd
                WHERE cd.id_client = {$alias}.id_client
                  AND cd.id_dot = ?
            )
            OR COALESCE(TRIM({$this->decExpr($alias . '.paz_spec')}), '') <> ''
        )";
    }

    private function buildDoctorScopedPatientIdsSql(): string
    {
        return "
            SELECT c_scope.id_client
            FROM " . self::CLIENTS_TABLE . " c_scope
            WHERE c_scope.id_personale = ?

            UNION

            SELECT cd.id_client
            FROM " . self::CLIENT_DOCTOR_TABLE . " cd
            WHERE cd.id_dot = ?

            UNION

            SELECT COALESCE(NULLIF(a.id_client, 0), c_legacy.id_client) AS id_client
            FROM " . self::APPOINTMENTS_TABLE . " a
            LEFT JOIN " . self::CLIENTS_TABLE . " c_legacy
                ON COALESCE(a.id_client, 0) = 0
               AND COALESCE(c_legacy.legacy_id_paziente, 0) = a.id_paziente
            WHERE a.id_dot = ?
              AND a.stato <> 'ANNULLATO'
              AND (
                    COALESCE(a.id_client, 0) > 0
                 OR c_legacy.id_client IS NOT NULL
              )

            UNION

            SELECT c_special.id_client
            FROM " . self::CLIENTS_TABLE . " c_special
            WHERE " . $this->buildGlobalSpecialPatientSql('c_special') . "
        ";
    }

    private function buildDoctorSearchVisibilitySql(string $alias): string
    {
        return '(' . $this->buildDoctorVisibilitySql($alias) . '
            OR ' . $this->buildLegacySpecialTokenSql($alias) . '
        )';
    }

    private function buildDoctorAppointmentVisibilitySql(string $alias): string
    {
        return "(
            EXISTS (
                SELECT 1
                FROM " . self::APPOINTMENTS_TABLE . " a
                WHERE a.id_dot = ?
                  AND a.id_client = {$alias}.id_client
                  AND a.stato <> 'ANNULLATO'
            )
            OR (
                COALESCE({$alias}.legacy_id_paziente, 0) > 0
                AND EXISTS (
                    SELECT 1
                    FROM " . self::APPOINTMENTS_TABLE . " a_legacy
                    WHERE a_legacy.id_dot = ?
                      AND COALESCE(a_legacy.id_client, 0) = 0
                      AND a_legacy.id_paziente = {$alias}.legacy_id_paziente
                      AND a_legacy.stato <> 'ANNULLATO'
                )
            )
        )";
    }

    private function buildLegacySpecialTokenSql(string $alias): string
    {
        $cognome = 'UPPER(TRIM(COALESCE(' . $this->decExpr($alias . '.cognome') . ", '')))";
        $nome = 'UPPER(TRIM(COALESCE(' . $this->decExpr($alias . '.nome') . ", '')))";
        $combined = 'UPPER(TRIM(CONCAT(COALESCE(' . $this->decExpr($alias . '.cognome') . ", ''), ' ', COALESCE(" . $this->decExpr($alias . '.nome') . ", ''))))";

        return "(
            {$cognome} IN ('DDD', 'STOP', 'INFO', 'INF', 'URG', 'CER', 'DOT')
            OR {$nome} IN ('DDD', 'STOP', 'INFO', 'INF', 'URG', 'CER', 'DOT')
            OR {$combined} REGEXP '^(DDD|STOP|INFO|INF|URG|CER|DOT) '
        )";
    }

    private function buildGlobalSpecialPatientSql(string $alias): string
    {
        return "(
            COALESCE(TRIM({$this->decExpr($alias . '.paz_spec')}), '') <> ''
            OR " . $this->buildLegacySpecialTokenSql($alias) . "
        )";
    }

    private function buildNonEmptyPatientDataSql(string $alias): string
    {
        $fields = [
            'cognome',
            'nome',
            'codice_fiscale',
            'telefono',
            'cellulare',
            'email',
            'data_nascita',
            'comune_nascita',
            'provincia_nascita',
            'indirizzo',
            'citta',
            'cap',
            'provincia',
            'residenza_indirizzo',
            'residenza_comune',
            'residenza_cap',
            'residenza_provincia',
            'paz_spec',
        ];

        $checks = array_map(function (string $field) use ($alias): string {
            return "COALESCE(TRIM({$this->decExpr($alias . '.' . $field)}), '') <> ''";
        }, $fields);

        return '(' . implode(' OR ', $checks) . ')';
    }

    private function sanitizePatientRows(array $rows, bool $autocompleteMode): array
    {
        $sanitized = [];

        foreach ($rows as $row) {
            $cleanRow = [];

            foreach ($row as $key => $value) {
                if (is_string($value)) {
                    $cleanRow[$key] = $this->normalizePatientString($value);
                    continue;
                }

                $cleanRow[$key] = $value;
            }

            if (!$this->rowHasVisiblePatientData($cleanRow)) {
                continue;
            }

            if ($this->rowContainsSuspiciousArtifacts($cleanRow)) {
                continue;
            }

            if ($autocompleteMode) {
                $cleanRow['label'] = trim((string)($cleanRow['label'] ?? trim(($cleanRow['cognome'] ?? '') . ' ' . ($cleanRow['nome'] ?? ''))));
            }

            $sanitized[] = $cleanRow;
        }

        return $sanitized;
    }

    private function sanitizePatientDetailRow(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }

        foreach ($row as $key => $value) {
            if (!is_string($value)) {
                continue;
            }

            $row[$key] = $this->normalizePatientString($value);
        }

        return $row;
    }

    private function normalizePatientString(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/[[:cntrl:]]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private function rowHasVisiblePatientData(array $row): bool
    {
        $fields = [
            'cognome',
            'nome',
            'cod_fis',
            'telefono',
            'cellulare',
            'email',
            'data_nascita',
            'comune_nascita',
            'provincia_nascita',
            'indirizzo',
            'citta',
            'cap',
            'provincia',
            'residenza_indirizzo',
            'residenza_comune',
            'residenza_cap',
            'residenza_provincia',
            'paz_spec',
        ];

        foreach ($fields as $field) {
            if (trim((string)($row[$field] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    private function rowContainsSuspiciousArtifacts(array $row): bool
    {
        $fields = [
            'cognome',
            'nome',
            'cod_fis',
            'telefono',
            'cellulare',
            'email',
            'indirizzo',
            'citta',
            'comune_nascita',
            'provincia_nascita',
            'provincia',
            'residenza_indirizzo',
            'residenza_comune',
            'residenza_provincia',
            'paz_spec',
            'label',
        ];

        foreach ($fields as $field) {
            $value = (string)($row[$field] ?? '');
            if ($value === '') {
                continue;
            }

            if (!preg_match('//u', $value)) {
                return true;
            }

            if (preg_match('/\x{FFFD}|Ã.|Â.|â€|â€™|â€œ|â€|â€“|â€”|ãƒ|ã€|�/u', $value)) {
                return true;
            }
        }

        return false;
    }

    private function resolveReplacementPrimaryDoctorId(array $doctorIds): int
    {
        foreach ($doctorIds as $doctorId) {
            if ($this->isFamilyDoctor((int)$doctorId)) {
                return (int)$doctorId;
            }
        }

        return !empty($doctorIds) ? (int)$doctorIds[0] : 0;
    }

    private function isFamilyDoctor(int $idDot): bool
    {
        if ($idDot <= 0) {
            return false;
        }

        $row = $this->db->table('dap03_personale')
            ->select('COALESCE(legacy_dot_tipo_id, 0) AS legacy_dot_tipo_id')
            ->where('id_personale', $idDot)
            ->get()
            ->getRowArray();

        return (int)($row['legacy_dot_tipo_id'] ?? 0) === 1;
    }

    private function normalizeFiscalCode(string $value): string
    {
        $value = strtoupper(trim($value));
        return preg_replace('/[^A-Z0-9]/', '', $value) ?? '';
    }

    private function resolvePersonaleIdFromLegacyDot(int $legacyIdDot): int
    {
        if ($legacyIdDot <= 0) {
            return 0;
        }

        if (array_key_exists($legacyIdDot, $this->doctorIdCache)) {
            return $this->doctorIdCache[$legacyIdDot];
        }

        $row = $this->db->table('dap03_personale')
            ->select('id_personale')
            ->where('legacy_id_dot', $legacyIdDot)
            ->get()
            ->getRowArray();

        $this->doctorIdCache[$legacyIdDot] = (int)($row['id_personale'] ?? 0);
        return $this->doctorIdCache[$legacyIdDot];
    }

    private function resolveProvidedPatientFields(array $payload): array
    {
        return [
            'cognome' => array_key_exists('cognome', $payload),
            'nome' => array_key_exists('nome', $payload),
            'data_nascita' => array_key_exists('data_nascita', $payload),
            'codice_fiscale' => array_key_exists('cod_fis', $payload) || array_key_exists('codice_fiscale', $payload),
            'comune_nascita' => array_key_exists('comune_nascita', $payload),
            'provincia_nascita' => array_key_exists('provincia_nascita', $payload),
            'indirizzo' => array_key_exists('indirizzo', $payload),
            'citta' => array_key_exists('citta', $payload),
            'cap' => array_key_exists('cap', $payload),
            'provincia' => array_key_exists('provincia', $payload),
            'residenza_indirizzo' => array_key_exists('residenza_indirizzo', $payload),
            'residenza_comune' => array_key_exists('residenza_comune', $payload),
            'residenza_cap' => array_key_exists('residenza_cap', $payload),
            'residenza_provincia' => array_key_exists('residenza_provincia', $payload),
            'telefono' => array_key_exists('telefono', $payload),
            'cellulare' => array_key_exists('cellulare', $payload),
            'email' => array_key_exists('email', $payload),
            'bloccato' => array_key_exists('bloccato', $payload),
            'paz_spec' => array_key_exists('paz_spec', $payload),
        ];
    }

    private function dec(string $fieldExpr): string
    {
        return $this->decExpr($fieldExpr);
    }

    private function decExpr(string $fieldExpr): string
    {
        return 'CONVERT(CAST(AES_DECRYPT(UNHEX(' . $fieldExpr . '), @key_str, ' . $this->fieldPrefix($fieldExpr) . 'vector_id) AS CHAR CHARACTER SET latin1) USING utf8mb4)';
    }

    private function fieldPrefix(string $fieldExpr): string
    {
        $pos = strrpos($fieldExpr, '.');
        return $pos === false ? '' : substr($fieldExpr, 0, $pos + 1);
    }

    private function enc(string $value): string
    {
        return $this->crypto->encrypt($value);
    }

    private function encWithVector(string $value): string
    {
        return "HEX(AES_ENCRYPT('" . $this->db->escapeString($value) . "', @key_str, vector_id))";
    }
}
