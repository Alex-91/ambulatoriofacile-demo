<?php

namespace App\Models;

use App\Libraries\Crypto_helper;
use App\Libraries\DatabaseConfig;
use App\Services\TenantContextService;
use CodeIgniter\Model;
use Exception;

class PazientiModel extends Model
{
    private const CLIENTS_TABLE = 'dap02_clients';
    private const CLIENT_DOCTOR_TABLE = 'dap09_client_doctor';
    private const APPOINTMENTS_TABLE = 'dap12_agenda_appuntamenti';
    private const APPOINTMENT_REMINDER_SMS_COLUMN = 'appointment_reminder_sms_enabled';
    private const REGISTRY_VISIBILITY_COLUMN = 'visibile_in_anagrafica';
    private const ASSOCIATE_ALL_DOCTORS_COLUMN = 'share_all_doctors';
    private const SHARED_AGENDA_PATIENTS_FEATURE = 'shared_agenda_patients';
    private const SPECIAL_PATIENT_TOKENS = ['DDD', 'STOP', 'INFO', 'INF', 'URG', 'CER', 'DOT'];
    private const EXTRA_PATIENT_FIELDS = [
        'denominazione' => ['encrypted' => true, 'default' => "''"],
        'partita_iva' => ['encrypted' => true, 'default' => "''"],
        'email_pec' => ['encrypted' => true, 'default' => "''"],
        'banca' => ['encrypted' => true, 'default' => "''"],
        'condizioni_pagamento' => ['encrypted' => true, 'default' => "''"],
        'codice_destinatario' => ['encrypted' => true, 'default' => "''"],
        'iva_differita' => ['encrypted' => false, 'default' => '0'],
        'note_cliente' => ['encrypted' => true, 'default' => "''"],
        'nr_civico' => ['encrypted' => true, 'default' => "''"],
        'indirizzo_secondario' => ['encrypted' => true, 'default' => "''"],
        'nr_civico_secondario' => ['encrypted' => true, 'default' => "''"],
        'comune_secondario' => ['encrypted' => true, 'default' => "''"],
        'cap_secondario' => ['encrypted' => true, 'default' => "''"],
        'provincia_secondaria' => ['encrypted' => true, 'default' => "''"],
        'cliente_attivo' => ['encrypted' => false, 'default' => '1'],
        'visibile_in_anagrafica' => ['encrypted' => false, 'default' => '1'],
    ];

    protected $db;
    protected Crypto_helper $crypto;
    protected ClientDoctorModel $clientDoctorModel;
    protected DoctorPatientSearchModel $doctorPatientSearchModel;
    private array $doctorIdCache = [];
    private array $doctorRoleByUserCache = [];
    private ?array $sharedDoctorScopeCache = null;
    private ?bool $sharedAgendaPatientsEnabledCache = null;
    private ?bool $currentTenantMasterCache = null;
    private ?bool $hasClientAppointmentReminderSmsColumn = null;
    private array $clientColumnExistsCache = [];

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

    public function autocompleteByDoctor(
        int $idDot,
        string $term,
        bool $onlyFutureAppointments = false,
        int $actingUserId = 0,
        bool $onlyVisibleInRegistry = false,
        bool $onlyWithAppointments = false
    ): array
    {
        $term = trim($term);
        if ($term === '') {
            return [];
        }

        $scope = $this->resolveDoctorPatientScope($idDot, $actingUserId);
        $idPersonale = (int) ($scope['selected_personale_id'] ?? 0);
        if ($idPersonale <= 0) {
            return [];
        }

        if ($onlyFutureAppointments) {
            $ids = $this->searchFutureAppointmentClientIdsByDoctor(
                $idDot,
                $term,
                $onlyVisibleInRegistry ? 100 : 20,
                $actingUserId
            );

            return array_slice(
                $this->getPatientsByIds($ids, true, $onlyVisibleInRegistry),
                0,
                20
            );
        }

        if (!$onlyWithAppointments && $this->doctorPatientSearchModel->tableExists()) {
            try {
                $lookupLimit = $onlyVisibleInRegistry ? 100 : 20;
                $ids = count((array) ($scope['legacy_dot_ids'] ?? [])) > 1
                    ? $this->doctorPatientSearchModel->searchClientIdsForDoctors((array) ($scope['legacy_dot_ids'] ?? []), $term, $lookupLimit)
                    : $this->doctorPatientSearchModel->searchClientIdsForDoctor($idDot, $term, $lookupLimit);
                if ($ids !== []) {
                    return array_slice(
                        $this->getPatientsByIds($ids, true, $onlyVisibleInRegistry),
                        0,
                        20
                    );
                }
            } catch (\Throwable $e) {
                log_message('warning', 'PazientiModel autocomplete indexed search fallback: ' . $e->getMessage(), [
                    'id_dot' => $idDot,
                    'term' => $term,
                ]);
            }
        }

        $needle = '%' . mb_strtolower($term) . '%';
        $params = [];
        $registryVisibilityWhere = $onlyVisibleInRegistry
            ? "\n              AND " . $this->buildRegistryVisibilitySql('c')
            : '';
        $appointmentPresenceWhere = '';

        if ($onlyWithAppointments) {
            $legacyIdDots = $this->normalizeIdList((array) ($scope['legacy_dot_ids'] ?? []));
            if ($legacyIdDots === []) {
                return [];
            }

            $doctorListSql = implode(',', $legacyIdDots);
            $hasAppointmentClientColumn = $this->db->fieldExists('id_client', self::APPOINTMENTS_TABLE);
            $appointmentPatientMatch = $hasAppointmentClientColumn
                ? "(
                    COALESCE(a.id_client, 0) = c.id_client
                    OR (
                        COALESCE(a.id_client, 0) = 0
                        AND a.id_paziente IN (c.id_client, COALESCE(c.legacy_id_paziente, 0))
                    )
                )"
                : 'a.id_paziente IN (c.id_client, COALESCE(c.legacy_id_paziente, 0))';

            $appointmentPresenceWhere = "
              AND EXISTS (
                    SELECT 1
                    FROM " . self::APPOINTMENTS_TABLE . " a
                    WHERE a.id_dot IN ({$doctorListSql})
                      AND a.stato <> 'ANNULLATO'
                      AND {$appointmentPatientMatch}
                )
            ";
        }
        $sql = "
            SELECT
                c.id_client AS id_paziente,
                {$this->dec('c.nome')} AS nome,
                {$this->dec('c.cognome')} AS cognome,
                {$this->buildAdditionalPatientSelectSql('c')},
                {$this->dec('c.telefono')} AS telefono,
                {$this->dec('c.cellulare')} AS cellulare,
                {$this->dec('c.email')} AS email,
                {$this->dec('c.codice_fiscale')} AS cod_fis,
                {$this->dec('c.paz_spec')} AS paz_spec,
                {$this->patientReminderSmsSelectSql('c')} AS appointment_reminder_sms_enabled,
                {$this->dec('c.indirizzo')} AS indirizzo,
                {$this->dec('c.citta')} AS citta,
                CONCAT({$this->decExpr('c.cognome')}, ' ', {$this->decExpr('c.nome')}) AS label
            FROM " . self::CLIENTS_TABLE . " c
            INNER JOIN (
                {$this->buildDoctorScopedPatientIdsSqlForScope((array) ($scope['personale_ids'] ?? []), (array) ($scope['legacy_dot_ids'] ?? []))}
            ) scope
                ON scope.id_client = c.id_client
            WHERE 1 = 1
              {$registryVisibilityWhere}
              {$appointmentPresenceWhere}
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

    /**
     * @param array<int, int|string> $legacyIdDots
     */
    public function autocompleteFutureAppointmentsForDoctors(
        array $legacyIdDots,
        string $term,
        int $limit = 20
    ): array {
        $legacyIdDots = $this->normalizeIdList($legacyIdDots);
        $term = trim($term);

        if ($legacyIdDots === [] || $term === '') {
            return [];
        }

        $limit = max(1, min(50, $limit));
        $specialTerm = $this->normalizeSpecialPatientSearchTerm($term);

        if ($specialTerm !== '') {
            return $this->getPatientsByIds(
                $this->searchFutureSpecialAppointmentClientIdsByDoctors($legacyIdDots, $specialTerm, $limit),
                true
            );
        }

        if ($this->doctorPatientSearchModel->tableExists()) {
            try {
                $lookupLimit = min(160, max(60, $limit * 6));
                $candidateIds = count($legacyIdDots) > 1
                    ? $this->doctorPatientSearchModel->searchClientIdsForDoctors($legacyIdDots, $term, $lookupLimit)
                    : $this->doctorPatientSearchModel->searchClientIdsForDoctor($legacyIdDots[0], $term, $lookupLimit);

                if ($candidateIds !== []) {
                    $ordered = $this->filterClientIdsWithFutureAppointmentsByDoctors(
                        $legacyIdDots,
                        $candidateIds,
                        $limit
                    );
                    if ($ordered !== []) {
                        return $this->getPatientsByIds($ordered, true);
                    }
                }
            } catch (\Throwable $e) {
                log_message('warning', 'PazientiModel bulk appointment patient search fallback: ' . $e->getMessage(), [
                    'doctor_count' => count($legacyIdDots),
                    'term' => $term,
                ]);
            }
        }

        return $this->getPatientsByIds(
            $this->searchFutureAppointmentClientIdsByAppointmentTextForDoctors($legacyIdDots, $term, $limit),
            true
        );
    }

    private function searchFutureAppointmentClientIdsByDoctor(int $idDot, string $term, int $limit = 20, int $actingUserId = 0): array
    {
        $term = trim($term);
        if ($idDot <= 0 || $term === '') {
            return [];
        }

        $scope = $this->resolveDoctorPatientScope($idDot, $actingUserId);
        $scopeLegacyIdDots = (array) ($scope['legacy_dot_ids'] ?? []);
        if ($scopeLegacyIdDots === []) {
            return [];
        }

        $limit = max(1, min(50, $limit));

        $specialTerm = $this->normalizeSpecialPatientSearchTerm($term);
        if ($specialTerm !== '') {
            return $this->searchFutureSpecialAppointmentClientIdsByDoctors($scopeLegacyIdDots, $specialTerm, $limit);
        }

        if ($this->doctorPatientSearchModel->tableExists()) {
            try {
                $lookupLimit = min(160, max(60, $limit * 6));
                $candidateIds = count($scopeLegacyIdDots) > 1
                    ? $this->doctorPatientSearchModel->searchClientIdsForDoctors($scopeLegacyIdDots, $term, $lookupLimit)
                    : $this->doctorPatientSearchModel->searchClientIdsForDoctor($idDot, $term, $lookupLimit);

                if ($candidateIds !== []) {
                    $ordered = $this->filterClientIdsWithFutureAppointmentsByDoctors($scopeLegacyIdDots, $candidateIds, $limit);
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

        return $this->searchFutureAppointmentClientIdsByAppointmentTextForDoctors($scopeLegacyIdDots, $term, $limit);
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
                      AND COALESCE(a.ora_inizio_appuntamento, s.ora_inizio) >= ?
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
                      AND COALESCE(a.ora_inizio_appuntamento, s.ora_inizio) >= ?
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

    private function filterClientIdsWithFutureAppointmentsByDoctors(array $legacyIdDots, array $candidateIds, int $limit = 20): array
    {
        $legacyIdDots = $this->normalizeIdList($legacyIdDots);
        if ($legacyIdDots === []) {
            return [];
        }

        if (count($legacyIdDots) === 1) {
            return $this->filterClientIdsWithFutureAppointmentsByDoctor($legacyIdDots[0], $candidateIds, $limit);
        }

        $candidateIds = array_values(array_unique(array_map('intval', $candidateIds)));
        $candidateIds = array_values(array_filter($candidateIds, static fn(int $id): bool => $id > 0));

        if ($candidateIds === []) {
            return [];
        }

        $limit = max(1, min(50, $limit));
        $idListSql = implode(',', $candidateIds);
        $doctorListSql = implode(',', $legacyIdDots);

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
                    WHERE a.id_dot IN ({$doctorListSql})
                      AND a.stato <> 'ANNULLATO'
                      AND COALESCE(a.ora_inizio_appuntamento, s.ora_inizio) >= ?
                      AND a.id_client IN ({$idListSql})
                ",
                [$now]
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
                    WHERE a.id_dot IN ({$doctorListSql})
                      AND a.stato <> 'ANNULLATO'
                      AND COALESCE(a.ora_inizio_appuntamento, s.ora_inizio) >= ?
                      {$legacyMatchSql}
                      AND a.id_paziente IN ({$appointmentPatientIdSql})
                ",
                [$now]
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

    private function normalizeSpecialPatientCode(string $value): string
    {
        $value = trim((string) (preg_replace('/\s+/', ' ', $value) ?? ''));
        if ($value === '') {
            return '';
        }

        return function_exists('mb_strtoupper')
            ? mb_strtoupper($value, 'UTF-8')
            : strtoupper($value);
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
                MIN(COALESCE(a.ora_inizio_appuntamento, s.ora_inizio)) AS next_appointment_at
            FROM " . self::APPOINTMENTS_TABLE . " a
            INNER JOIN dap11_agenda_slot s
                ON s.id_slot = a.id_slot
            {$legacyJoin}
            WHERE a.id_dot = ?
              AND a.stato <> 'ANNULLATO'
              AND COALESCE(a.ora_inizio_appuntamento, s.ora_inizio) >= ?
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
        return $this->searchFutureSpecialAppointmentClientIdsByDoctors([$idDot], $term, $limit);
    }

    private function searchFutureSpecialAppointmentClientIdsByDoctors(array $legacyIdDots, string $term, int $limit = 20): array
    {
        $legacyIdDots = $this->normalizeIdList($legacyIdDots);
        if ($legacyIdDots === [] || $term === '') {
            return [];
        }

        $doctorListSql = implode(',', $legacyIdDots);
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
                MIN(COALESCE(a.ora_inizio_appuntamento, s.ora_inizio)) AS next_appointment_at
            FROM " . self::APPOINTMENTS_TABLE . " a
            INNER JOIN dap11_agenda_slot s
                ON s.id_slot = a.id_slot
            {$legacyJoin}
            WHERE a.id_dot IN ({$doctorListSql})
              AND a.stato <> 'ANNULLATO'
              AND COALESCE(a.ora_inizio_appuntamento, s.ora_inizio) >= ?
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

        $rows = $this->db->query($sql, [date('Y-m-d H:i:s'), $needle, $needle, $needle])->getResultArray();

        return array_values(array_filter(array_map(
            static fn(array $row): int => (int)($row['id_client'] ?? 0),
            $rows
        ), static fn(int $id): bool => $id > 0));
    }

    private function searchFutureAppointmentClientIdsByAppointmentText(int $idDot, string $term, int $limit = 20): array
    {
        return $this->searchFutureAppointmentClientIdsByAppointmentTextForDoctors([$idDot], $term, $limit);
    }

    private function searchFutureAppointmentClientIdsByAppointmentTextForDoctors(array $legacyIdDots, string $term, int $limit = 20): array
    {
        $term = mb_strtolower(trim($term), 'UTF-8');
        $legacyIdDots = $this->normalizeIdList($legacyIdDots);
        if ($legacyIdDots === [] || $term === '') {
            return [];
        }

        $limit = max(1, min(50, $limit));
        $tokens = preg_split('/\s+/', $term) ?: [];
        $tokens = array_values(array_filter($tokens, static fn(string $token): bool => $token !== ''));
        $doctorListSql = implode(',', $legacyIdDots);

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

        $params = [date('Y-m-d H:i:s')];
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
                MIN(COALESCE(a.ora_inizio_appuntamento, s.ora_inizio)) AS next_appointment_at
            FROM " . self::APPOINTMENTS_TABLE . " a
            INNER JOIN dap11_agenda_slot s
                ON s.id_slot = a.id_slot
            {$legacyJoin}
            WHERE a.id_dot IN ({$doctorListSql})
              AND a.stato <> 'ANNULLATO'
              AND COALESCE(a.ora_inizio_appuntamento, s.ora_inizio) >= ?
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

    public function getPatientsByDoctor(int $idDot, string $term = '', int $actingUserId = 0): array
    {
        $scope = $this->resolveDoctorPatientScope($idDot, $actingUserId);
        $idPersonale = (int) ($scope['selected_personale_id'] ?? 0);
        if ($idPersonale <= 0) {
            return [];
        }

        if ($this->doctorPatientSearchModel->tableExists()) {
            try {
                $ids = count((array) ($scope['legacy_dot_ids'] ?? [])) > 1
                    ? $this->doctorPatientSearchModel->listClientIdsForDoctors((array) ($scope['legacy_dot_ids'] ?? []), $term)
                    : $this->doctorPatientSearchModel->listClientIdsForDoctor($idDot, $term);
                if ($ids !== []) {
                    return $this->getPatientsByIds($ids, false);
                }
            } catch (\Throwable $e) {
                log_message('warning', 'PazientiModel patient list indexed search fallback: ' . $e->getMessage(), [
                    'id_dot' => $idDot,
                    'term' => $term,
                ]);
            }
        }

        $params = [];
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
                {$this->buildAdditionalPatientSelectSql('c')},
                {$this->buildAssociateAllDoctorsSelectSql('c')} AS associate_all_doctors,
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
                {$this->patientReminderSmsSelectSql('c')} AS appointment_reminder_sms_enabled,
                COALESCE(c.bloccato, 0) AS bloccato
            FROM " . self::CLIENTS_TABLE . " c
            INNER JOIN (
                {$this->buildDoctorScopedPatientIdsSqlForScope((array) ($scope['personale_ids'] ?? []), (array) ($scope['legacy_dot_ids'] ?? []))}
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

    public function getPatientsByDoctorPaginate(
        int $idDot,
        string $term = '',
        int $page = 1,
        int $perPage = 20,
        int $actingUserId = 0,
        bool $onlyVisibleInRegistry = false,
        int $idPaziente = 0
    ): array
    {
        $scope = $this->resolveDoctorPatientScope($idDot, $actingUserId);
        $idPersonale = (int) ($scope['selected_personale_id'] ?? 0);
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

        if (!$onlyVisibleInRegistry && $this->doctorPatientSearchModel->tableExists()) {
            try {
                $indexed = count((array) ($scope['legacy_dot_ids'] ?? [])) > 1
                    ? $this->doctorPatientSearchModel->paginateClientIdsForDoctors((array) ($scope['legacy_dot_ids'] ?? []), $term, $page, $perPage)
                    : $this->doctorPatientSearchModel->paginateClientIdsForDoctor($idDot, $term, $page, $perPage);
                $rows = $this->getPatientsByIds($indexed['ids'], false);

                if ((int) ($indexed['total'] ?? 0) > 0 || $rows !== []) {
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
                }
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
        $params = [];
        $whereSearch = '';
        $whereSelectedPatient = '';
        $registryVisibilityWhere = $onlyVisibleInRegistry
            ? "\n              AND " . $this->buildRegistryVisibilitySql('c')
            : '';

        $idPaziente = max(0, $idPaziente);
        $term = trim($term);
        if ($idPaziente > 0) {
            // Il suggerimento identifica il paziente con il suo ID: non
            // riusare l'etichetta completa come filtro testuale, altrimenti
            // "Cognome Nome" non trova i due campi separati.
            $term = '';
            $whereSelectedPatient = "\n              AND c.id_client = ?";
            $params[] = $idPaziente;
        }

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
                {$this->buildDoctorScopedPatientIdsSqlForScope((array) ($scope['personale_ids'] ?? []), (array) ($scope['legacy_dot_ids'] ?? []))}
            ) scope
                ON scope.id_client = c.id_client
            WHERE 1 = 1
              AND {$this->buildNonEmptyPatientDataSql('c')}
              {$registryVisibilityWhere}
              {$whereSearch}
              {$whereSelectedPatient}
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
                {$this->buildAdditionalPatientSelectSql('c')},
                {$this->buildAssociateAllDoctorsSelectSql('c')} AS associate_all_doctors,
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
                {$this->patientReminderSmsSelectSql('c')} AS appointment_reminder_sms_enabled,
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

    public function deletePatientByDoctor(int $idPaziente, int $idDot, int $actingUserId = 0): bool
    {
        $scope = $this->resolveDoctorPatientScope($idDot, $actingUserId);
        $idPersonale = (int) ($scope['selected_personale_id'] ?? 0);
        if ($idPersonale <= 0) {
            throw new Exception('Dottore non valido.');
        }

        $row = $this->getVisiblePatientSnapshotForScope(
            $idPaziente,
            (array) ($scope['personale_ids'] ?? []),
            (array) ($scope['legacy_dot_ids'] ?? [])
        );
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

        // The shared agenda scope exposes one tenant-wide patient list to the
        // responsible user and to professionals. Deletion must use that same
        // scope: the patient is removed from every professional in the studio.
        $deleteAcrossSharedScope = !empty($scope['shared']);
        $isSharedWithAllDoctors = (int) ($row['share_all_doctors'] ?? 0) === 1;

        if ($deleteAcrossSharedScope) {
            $this->db->table(self::CLIENT_DOCTOR_TABLE)
                ->where('id_client', $idPaziente)
                ->delete();

            $this->updateAssociateAllDoctorsFlag($idPaziente, false);
            $remainingDoctorIds = [];
        } elseif ($isSharedWithAllDoctors) {
            $remainingDoctorIds = array_values(array_filter(
                $this->listAllDoctorAssociationPersonaleIds(),
                static fn(int $doctorId): bool => $doctorId > 0 && $doctorId !== $idPersonale
            ));

            $this->updateAssociateAllDoctorsFlag($idPaziente, false);
            $this->clientDoctorModel->setDoctorsForClient(
                $idPaziente,
                $remainingDoctorIds,
                $this->resolveReplacementPrimaryDoctorId($remainingDoctorIds),
                false
            );
        } else {
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
        }

        $currentPrimaryDoctorId = (int)($row['id_personale'] ?? 0);
        if ($currentPrimaryDoctorId > 0 && !in_array($currentPrimaryDoctorId, $remainingDoctorIds, true)) {
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
                {$this->buildAdditionalPatientSelectSql('c')},
                {$this->buildAssociateAllDoctorsSelectSql('c')} AS associate_all_doctors,
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
                {$this->patientReminderSmsSelectSql('c')} AS appointment_reminder_sms_enabled,
                COALESCE(c.bloccato, 0) AS bloccato,
                COALESCE(c.id_personale, 0) AS id_dot
            FROM " . self::CLIENTS_TABLE . " c
            WHERE c.id_client = ?
            LIMIT 1
        ";

        $row = $this->db->query($sql, [$idPaziente])->getRowArray();
        return $row ?: null;
    }

    public function getPazienteByDoctor(int $idPaziente, int $idDot, int $actingUserId = 0): ?array
    {
        if ($idPaziente <= 0 || $idDot <= 0) {
            return null;
        }

        $scope = $this->resolveDoctorPatientScope($idDot, $actingUserId);
        $idPersonale = (int) ($scope['selected_personale_id'] ?? 0);
        if ($idPersonale <= 0) {
            return null;
        }

        if (
            $this->doctorPatientSearchModel->tableExists()
            && (
                count((array) ($scope['legacy_dot_ids'] ?? [])) > 1
                    ? $this->doctorPatientSearchModel->hasVisibleClientForDoctors((array) ($scope['legacy_dot_ids'] ?? []), $idPaziente)
                    : $this->doctorPatientSearchModel->hasVisibleClientForDoctor($idDot, $idPaziente)
            )
        ) {
            return $this->sanitizePatientDetailRow($this->getPaziente($idPaziente));
        }

        $sql = "
            SELECT
                c.id_client AS id_paziente,
                {$this->dec('c.nome')} AS nome,
                {$this->dec('c.cognome')} AS cognome,
                {$this->buildAdditionalPatientSelectSql('c')},
                {$this->buildAssociateAllDoctorsSelectSql('c')} AS associate_all_doctors,
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
                {$this->patientReminderSmsSelectSql('c')} AS appointment_reminder_sms_enabled,
                COALESCE(c.bloccato, 0) AS bloccato,
                COALESCE(c.id_personale, 0) AS id_dot
            FROM " . self::CLIENTS_TABLE . " c
            INNER JOIN (
                {$this->buildDoctorScopedPatientIdsSqlForScope((array) ($scope['personale_ids'] ?? []), (array) ($scope['legacy_dot_ids'] ?? []))}
            ) scope
                ON scope.id_client = c.id_client
            WHERE c.id_client = ?
            LIMIT 1
        ";

        $row = $this->db->query($sql, [$idPaziente])->getRowArray();
        return $this->sanitizePatientDetailRow($row ?: null);
    }

    public function findOrCreateSpecialPatientForDoctor(
        int $idDot,
        string $specialCode,
        array $defaults = [],
        int $actingUserId = 0
    ): int {
        if ($idDot <= 0) {
            throw new Exception('Dottore non valido per il paziente speciale.');
        }

        $specialCode = $this->normalizeSpecialPatientCode($specialCode);
        if ($specialCode === '') {
            throw new Exception('Codice paziente speciale non valido.');
        }

        $sql = "
            SELECT
                c.id_client
            FROM " . self::CLIENTS_TABLE . " c
            WHERE UPPER(TRIM(COALESCE({$this->decExpr('c.paz_spec')}, ''))) = ?
            ORDER BY
                {$this->buildAssociateAllDoctorsSelectSql('c')} DESC,
                c.id_client ASC
            LIMIT 1
        ";

        $query = $this->db->query($sql, [$specialCode]);
        if (!$query) {
            $dbError = $this->db->error();
            log_message('error', 'PazientiModel::findOrCreateSpecialPatientForDoctor query failed for id_dot={id_dot} code={code} db_code={db_code} db_message={db_message}', [
                'id_dot' => $idDot,
                'code' => $specialCode,
                'db_code' => (string) ($dbError['code'] ?? ''),
                'db_message' => (string) ($dbError['message'] ?? ''),
            ]);
            throw new Exception('Errore durante la ricerca del paziente speciale.');
        }

        $existing = $query->getRowArray();
        $idClient = (int) ($existing['id_client'] ?? 0);
        if ($idClient > 0) {
            return $idClient;
        }

        $payload = [
            'cognome' => trim((string) ($defaults['cognome'] ?? '')),
            'nome' => trim((string) ($defaults['nome'] ?? '')),
            'denominazione' => trim((string) ($defaults['denominazione'] ?? '')),
            'telefono' => trim((string) ($defaults['telefono'] ?? '')),
            'cellulare' => trim((string) ($defaults['cellulare'] ?? '')),
            'email' => trim((string) ($defaults['email'] ?? '')),
            'appointment_reminder_sms_enabled' => 0,
            'cliente_attivo' => array_key_exists('cliente_attivo', $defaults) ? (int) ($defaults['cliente_attivo'] ?? 1) : 1,
            'bloccato' => array_key_exists('bloccato', $defaults) ? (int) ($defaults['bloccato'] ?? 0) : 0,
            'associate_all_doctors' => array_key_exists('associate_all_doctors', $defaults)
                ? (int) ($defaults['associate_all_doctors'] ?? 0)
                : 1,
            'paz_spec' => $specialCode,
        ];

        return $this->savePatientAndLink($payload, $idDot, $actingUserId);
    }

    public function getAppointmentsByDoctorAndPatient(int $idPaziente, int $idDot, int $limit = 200, int $actingUserId = 0): array
    {
        if ($idPaziente <= 0 || $idDot <= 0) {
            return [];
        }

        $scope = $this->resolveDoctorPatientScope($idDot, $actingUserId);
        $scopeLegacyIdDots = $this->normalizeIdList((array) ($scope['legacy_dot_ids'] ?? []));
        if ($scopeLegacyIdDots === []) {
            return [];
        }

        return $this->getAppointmentsByDoctorsAndPatient(
            $idPaziente,
            $scopeLegacyIdDots,
            max(1, min(200, $limit))
        );
    }

    /**
     * @param array<int, int|string> $legacyIdDots
     */
    public function getFutureAppointmentsForPatientByDoctors(
        int $idPaziente,
        array $legacyIdDots,
        string $fromDateTime,
        int $limit = 500
    ): array {
        $legacyIdDots = $this->normalizeIdList($legacyIdDots);
        $timestamp = strtotime($fromDateTime);

        if ($idPaziente <= 0 || $legacyIdDots === [] || $timestamp === false) {
            return [];
        }

        return $this->getAppointmentsByDoctorsAndPatient(
            $idPaziente,
            $legacyIdDots,
            max(1, min(500, $limit)),
            date('Y-m-d H:i:s', $timestamp),
            'ASC'
        );
    }

    /**
     * @param array<int, int> $legacyIdDots
     */
    private function getAppointmentsByDoctorsAndPatient(
        int $idPaziente,
        array $legacyIdDots,
        int $limit,
        ?string $fromDateTime = null,
        string $sortDirection = 'DESC'
    ): array {
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
        $hasAppointmentEndColumn = $this->db->fieldExists('ora_fine_appuntamento', self::APPOINTMENTS_TABLE);
        $hasAppointmentVisitTypeLabelColumn = $this->db->fieldExists('tipo_visita_label', self::APPOINTMENTS_TABLE);
        $hasAppointmentDurationColumn = $this->db->fieldExists('durata_minuti', self::APPOINTMENTS_TABLE);
        $legacyIdDots = $this->normalizeIdList($legacyIdDots);
        $limit = max(1, min(500, $limit));
        $sortDirection = strtoupper($sortDirection) === 'ASC' ? 'ASC' : 'DESC';

        if ($legacyIdDots === []) {
            return [];
        }

        $queries = [];
        $params = [];
        $doctorPlaceholders = implode(',', array_fill(0, count($legacyIdDots), '?'));
        $appointmentEndExpr = $hasAppointmentEndColumn ? 'a.ora_fine_appuntamento' : 'NULL';
        $appointmentVisitTypeLabelExpr = $hasAppointmentVisitTypeLabelColumn ? 'a.tipo_visita_label' : "''";
        $appointmentDurationExpr = $hasAppointmentDurationColumn ? 'a.durata_minuti' : 'NULL';
        $appointmentStartExpr = $this->db->fieldExists('ora_inizio_appuntamento', self::APPOINTMENTS_TABLE)
            ? 'COALESCE(a.ora_inizio_appuntamento, s.ora_inizio)'
            : 's.ora_inizio';
        $fromDateSql = $fromDateTime !== null ? 'AND ' . $appointmentStartExpr . ' >= ?' : '';
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
                {$appointmentStartExpr} AS ora_inizio,
                COALESCE({$appointmentEndExpr}, s.ora_fine) AS ora_fine,
                TIME_FORMAT({$appointmentStartExpr}, '%H:%i') AS ora_inizio_label,
                TIME_FORMAT(COALESCE({$appointmentEndExpr}, s.ora_fine), '%H:%i') AS ora_fine_label,
                COALESCE(a.stato, '') AS stato,
                COALESCE(s.stato, '') AS stato_slot,
                COALESCE(a.motivo_visita, '') AS motivo_visita,
                COALESCE({$appointmentVisitTypeLabelExpr}, '') AS tipo_visita_label,
                COALESCE(
                    {$appointmentDurationExpr},
                    TIMESTAMPDIFF(MINUTE, {$appointmentStartExpr}, COALESCE({$appointmentEndExpr}, s.ora_fine))
                ) AS durata_minuti,
                COALESCE(a.note, '') AS note,
                {$createdBySelect}
                COALESCE(a.indirizzo_visita, '') AS indirizzo_visita,
                COALESCE(a.comune_visita, '') AS comune_visita
            FROM " . self::APPOINTMENTS_TABLE . " a
            INNER JOIN dap11_agenda_slot s
                ON s.id_slot = a.id_slot
            {$createdByJoin}
            WHERE a.id_dot IN ({$doctorPlaceholders})
              AND a.stato <> 'ANNULLATO'
              {$fromDateSql}
              AND __PATIENT_MATCH_CONDITION__
        ";

        if ($hasAppointmentClientColumn) {
            $queries[] = str_replace('__PATIENT_MATCH_CONDITION__', 'a.id_client = ?', $selectSql);
            $params = array_merge(
                $params,
                $legacyIdDots,
                $fromDateTime !== null ? [$fromDateTime] : [],
                [$idPaziente]
            );
        }

        if ($legacyPatientIds !== []) {
            $placeholders = implode(',', array_fill(0, count($legacyPatientIds), '?'));
            $legacySql = 'a.id_paziente IN (' . $placeholders . ')';
            if ($hasAppointmentClientColumn) {
                $legacySql = 'COALESCE(a.id_client, 0) = 0 AND ' . $legacySql;
            }

            $queries[] = str_replace('__PATIENT_MATCH_CONDITION__', $legacySql, $selectSql);
            $params = array_merge(
                $params,
                $legacyIdDots,
                $fromDateTime !== null ? [$fromDateTime] : [],
                $legacyPatientIds
            );
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
            ORDER BY data_slot {$sortDirection}, ora_inizio {$sortDirection}, id_appuntamento {$sortDirection}
            LIMIT {$limit}
        ";

        return $this->db->query($sql, $params)->getResultArray();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function resolveImportPatientMatch(array $payload, int $idDot, int $actingUserId = 0): array
    {
        $result = [
            'id_paziente' => 0,
            'matched_by' => '',
            'conflict' => false,
            'message' => '',
            'row' => null,
            'candidates' => [],
        ];

        if ($idDot <= 0) {
            return $result;
        }

        $codiceFiscale = $this->normalizeFiscalCode((string) ($payload['cod_fis'] ?? ($payload['codice_fiscale'] ?? '')));
        $partitaIva = $this->normalizeVatNumber((string) ($payload['partita_iva'] ?? ''));

        if ($codiceFiscale === '' && $partitaIva === '') {
            return $result;
        }

        $scope = $this->resolveDoctorPatientScope($idDot, $actingUserId);
        $idPersonale = (int) ($scope['selected_personale_id'] ?? 0);
        if ($idPersonale <= 0) {
            return $result;
        }

        $conditions = [];
        $params = [];

        if ($codiceFiscale !== '') {
            $conditions[] = $this->buildNormalizedComparableSql($this->decExpr('c.codice_fiscale')) . ' = ?';
            $params[] = $codiceFiscale;
        }

        if ($partitaIva !== '' && $this->clientTableHasColumn('partita_iva')) {
            $conditions[] = $this->buildNormalizedComparableSql($this->decExpr('c.partita_iva')) . ' = ?';
            $params[] = $partitaIva;
        }

        if ($conditions === []) {
            return $result;
        }

        // L'importazione deve impedire i doppioni nell'intero spazio tenant,
        // anche quando il paziente esistente non e' ancora collegato al medico
        // selezionato. Il database corrente e' gia' quello isolato del tenant.
        $sql = "
            SELECT
                c.id_client AS id_paziente,
                {$this->dec('c.nome')} AS nome,
                {$this->dec('c.cognome')} AS cognome,
                {$this->buildAdditionalPatientSelectSql('c')},
                {$this->dec('c.codice_fiscale')} AS cod_fis
            FROM " . self::CLIENTS_TABLE . " c
            WHERE " . implode(' OR ', $conditions) . "
            ORDER BY c.id_client ASC
            LIMIT 25
        ";

        $rows = $this->sanitizePatientRows($this->db->query($sql, $params)->getResultArray(), false);
        if ($rows === []) {
            return $result;
        }

        $matchesByCodiceFiscale = [];
        $matchesByPartitaIva = [];

        foreach ($rows as $row) {
            $idPaziente = (int) ($row['id_paziente'] ?? 0);
            if ($idPaziente <= 0) {
                continue;
            }

            if (
                $codiceFiscale !== ''
                && $this->normalizeFiscalCode((string) ($row['cod_fis'] ?? '')) === $codiceFiscale
            ) {
                $matchesByCodiceFiscale[$idPaziente] = $row;
            }

            if (
                $partitaIva !== ''
                && $this->normalizeVatNumber((string) ($row['partita_iva'] ?? '')) === $partitaIva
            ) {
                $matchesByPartitaIva[$idPaziente] = $row;
            }
        }

        if (count($matchesByCodiceFiscale) > 1) {
            $result['conflict'] = true;
            $result['message'] = 'Esistono più pazienti nello spazio con lo stesso codice fiscale: la riga non è stata importata per evitare altri doppioni.';
            $result['candidates'] = array_values($matchesByCodiceFiscale);
            return $result;
        }

        if (count($matchesByPartitaIva) > 1) {
            $result['conflict'] = true;
            $result['message'] = 'Esistono più pazienti con la stessa partita IVA.';
            $result['candidates'] = array_values($matchesByPartitaIva);
            return $result;
        }

        if ($matchesByCodiceFiscale !== [] && $matchesByPartitaIva !== []) {
            $cfId = (int) array_key_first($matchesByCodiceFiscale);
            $pIvaId = (int) array_key_first($matchesByPartitaIva);

            if ($cfId > 0 && $pIvaId > 0 && $cfId !== $pIvaId) {
                $result['conflict'] = true;
                $result['message'] = 'Codice fiscale e partita IVA puntano a due pazienti diversi.';
                $result['candidates'] = array_values(array_replace($matchesByCodiceFiscale, $matchesByPartitaIva));
                return $result;
            }
        }

        $matchedRow = null;
        $matchedBy = '';

        if ($matchesByCodiceFiscale !== [] && $matchesByPartitaIva !== []) {
            $matchedRow = reset($matchesByCodiceFiscale) ?: null;
            $matchedBy = 'codice_fiscale+partita_iva';
        } elseif ($matchesByCodiceFiscale !== []) {
            $matchedRow = reset($matchesByCodiceFiscale) ?: null;
            $matchedBy = 'codice_fiscale';
        } elseif ($matchesByPartitaIva !== []) {
            $matchedRow = reset($matchesByPartitaIva) ?: null;
            $matchedBy = 'partita_iva';
        }

        if (is_array($matchedRow)) {
            $result['id_paziente'] = (int) ($matchedRow['id_paziente'] ?? 0);
            $result['matched_by'] = $matchedBy;
            $result['row'] = $matchedRow;
        }

        return $result;
    }

    public function savePatientAndLink(
        array $payload,
        int $idDot,
        int $actingUserId = 0,
        bool $allowExistingOutsideDoctorScope = false
    ): int
    {
        $scope = $this->resolveDoctorPatientScope($idDot, $actingUserId);
        $idPersonale = (int) ($scope['selected_personale_id'] ?? 0);
        if ($idPersonale <= 0) {
            throw new Exception('Dottore non valido.');
        }

        $useSharedScope = (bool) ($scope['shared'] ?? false);
        $allDoctorsAssociation = $this->resolveAllDoctorsAssociationMode($payload);

        $providedFields = $this->resolveProvidedPatientFields($payload);

        $data = [
            'cognome' => trim((string)($payload['cognome'] ?? '')),
            'nome' => trim((string)($payload['nome'] ?? '')),
            'denominazione' => trim((string)($payload['denominazione'] ?? '')),
            'data_nascita' => trim((string)($payload['data_nascita'] ?? '')),
            'codice_fiscale' => $this->normalizeFiscalCode((string)($payload['cod_fis'] ?? ($payload['codice_fiscale'] ?? ''))),
            'partita_iva' => $this->normalizeVatNumber((string)($payload['partita_iva'] ?? '')),
            'comune_nascita' => trim((string)($payload['comune_nascita'] ?? '')),
            'provincia_nascita' => trim((string)($payload['provincia_nascita'] ?? '')),
            'indirizzo' => trim((string)($payload['indirizzo'] ?? '')),
            'nr_civico' => trim((string)($payload['nr_civico'] ?? '')),
            'citta' => trim((string)($payload['citta'] ?? '')),
            'cap' => trim((string)($payload['cap'] ?? '')),
            'provincia' => trim((string)($payload['provincia'] ?? '')),
            'indirizzo_secondario' => trim((string)($payload['indirizzo_secondario'] ?? '')),
            'nr_civico_secondario' => trim((string)($payload['nr_civico_secondario'] ?? '')),
            'comune_secondario' => trim((string)($payload['comune_secondario'] ?? '')),
            'cap_secondario' => trim((string)($payload['cap_secondario'] ?? '')),
            'provincia_secondaria' => trim((string)($payload['provincia_secondaria'] ?? '')),
            'residenza_indirizzo' => trim((string)($payload['residenza_indirizzo'] ?? '')),
            'residenza_comune' => trim((string)($payload['residenza_comune'] ?? '')),
            'residenza_cap' => trim((string)($payload['residenza_cap'] ?? '')),
            'residenza_provincia' => trim((string)($payload['residenza_provincia'] ?? '')),
            'telefono' => trim((string)($payload['telefono'] ?? '')),
            'cellulare' => trim((string)($payload['cellulare'] ?? '')),
            'email' => trim((string)($payload['email'] ?? '')),
            'email_pec' => trim((string)($payload['email_pec'] ?? '')),
            'banca' => trim((string)($payload['banca'] ?? '')),
            'condizioni_pagamento' => trim((string)($payload['condizioni_pagamento'] ?? '')),
            'codice_destinatario' => trim((string)($payload['codice_destinatario'] ?? '')),
            'iva_differita' => (int)($payload['iva_differita'] ?? 0),
            'note_cliente' => trim((string)($payload['note_cliente'] ?? '')),
            'appointment_reminder_sms_enabled' => (int)($payload['appointment_reminder_sms_enabled'] ?? 0),
            'visibile_in_anagrafica' => array_key_exists(self::REGISTRY_VISIBILITY_COLUMN, $payload)
                ? ($this->normalizeBooleanFlag($payload[self::REGISTRY_VISIBILITY_COLUMN]) ? 1 : 0)
                : 1,
            'bloccato' => (int)($payload['bloccato'] ?? 0),
            'cliente_attivo' => array_key_exists('cliente_attivo', $payload)
                ? (int)($payload['cliente_attivo'] ?? 0)
                : ((int)($payload['bloccato'] ?? 0) === 1 ? 0 : 1),
            'paz_spec' => trim((string)($payload['paz_spec'] ?? '')),
        ];

        if ($data['denominazione'] === '') {
            $data['denominazione'] = trim((string)(preg_replace('/\s+/', ' ', trim($data['cognome'] . ' ' . $data['nome'])) ?? ''));
        }

        if ($data['denominazione'] !== '' && $data['cognome'] === '' && $data['nome'] === '') {
            $data['cognome'] = $data['denominazione'];
        }

        if ($data['denominazione'] === '' && ($data['cognome'] === '' || $data['nome'] === '')) {
            throw new Exception('Nome e cognome sono obbligatori.');
        }

        $idClient = (int)($payload['id_paziente'] ?? 0);
        $existing = null;
        $existingOutsideDoctorScope = false;
        if ($idClient > 0) {
            $existing = $this->getVisiblePatientSnapshotForScope(
                $idClient,
                (array) ($scope['personale_ids'] ?? []),
                (array) ($scope['legacy_dot_ids'] ?? [])
            );

            if (!$existing && $allowExistingOutsideDoctorScope) {
                $existing = $this->getClientSnapshot($idClient);
                $existingOutsideDoctorScope = (bool) $existing;
            }

            if (!$existing) {
                throw new Exception($allowExistingOutsideDoctorScope
                    ? 'Paziente esistente non trovato nello spazio.'
                    : 'Paziente non trovato per il medico selezionato.');
            }
        }

        $persistDoctorLink = !$useSharedScope
            || !empty($allDoctorsAssociation['provided'])
            || $idClient <= 0
            || $existingOutsideDoctorScope
            || $this->hasDirectDoctorRelationship($idClient, $idPersonale)
            || $this->isPatientAssociatedToAllDoctors($idClient);

        $this->db->transStart();

        if ($existing) {
            $this->updateClientRow($idClient, $data, $existing, $idPersonale, $providedFields, $persistDoctorLink);
        } else {
            $idClient = $this->insertClientRow($data, $idPersonale);
            $existing = $this->getClientSnapshot($idClient);
        }

        if ($idClient <= 0 || !$existing) {
            $this->db->transRollback();
            throw new Exception('Impossibile salvare il paziente.');
        }

        $isSpecialPatient = trim((string)($existing['paz_spec'] ?? '')) !== '' || $data['paz_spec'] !== '';
        $doctorIdsToAssociate = [];

        if (!empty($allDoctorsAssociation['provided'])) {
            $this->updateAssociateAllDoctorsFlag($idClient, !empty($allDoctorsAssociation['enabled']));
            $doctorIdsToAssociate = !empty($allDoctorsAssociation['enabled'])
                ? $this->listAllDoctorAssociationPersonaleIds($idPersonale)
                : [$idPersonale];
        }

        if ($doctorIdsToAssociate !== []) {
            $this->clientDoctorModel->setDoctorsForClient($idClient, $doctorIdsToAssociate, $idPersonale, false);

            if (!$isSpecialPatient) {
                $newPrimaryDoctorId = $this->isFamilyDoctor($idPersonale)
                    ? $idPersonale
                    : $this->resolveReplacementPrimaryDoctorId($doctorIdsToAssociate);
                $this->db->table(self::CLIENTS_TABLE)
                    ->where('id_client', $idClient)
                    ->update(['id_personale' => $newPrimaryDoctorId > 0 ? $newPrimaryDoctorId : null]);
            }
        } elseif ($persistDoctorLink) {
            $this->clientDoctorModel->setDoctorForClient($idClient, $idPersonale, false);

            if ($this->isFamilyDoctor($idPersonale) && !$isSpecialPatient) {
                $this->db->table(self::CLIENTS_TABLE)
                    ->where('id_client', $idClient)
                    ->update(['id_personale' => $idPersonale]);
            }
        }

        $this->db->transComplete();

        if (!$this->db->transStatus()) {
            throw new Exception('Errore durante il salvataggio del paziente.');
        }

        $this->doctorPatientSearchModel->syncClient($idClient);

        return $idClient;
    }

    private function getPatientsByIds(
        array $ids,
        bool $autocompleteMode,
        bool $onlyVisibleInRegistry = false
    ): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $ids = array_values(array_filter($ids, static fn(int $id): bool => $id > 0));

        if ($ids === []) {
            return [];
        }

        $idListSql = implode(',', $ids);
        $registryVisibilityWhere = $onlyVisibleInRegistry
            ? "\n                  AND " . $this->buildRegistryVisibilitySql('c')
            : '';

        if ($autocompleteMode) {
            $sql = "
                SELECT
                    c.id_client AS id_paziente,
                    {$this->dec('c.nome')} AS nome,
                    {$this->dec('c.cognome')} AS cognome,
                    {$this->buildAdditionalPatientSelectSql('c')},
                    {$this->buildAssociateAllDoctorsSelectSql('c')} AS associate_all_doctors,
                    {$this->dec('c.telefono')} AS telefono,
                    {$this->dec('c.cellulare')} AS cellulare,
                    {$this->dec('c.email')} AS email,
                    {$this->dec('c.codice_fiscale')} AS cod_fis,
                    {$this->dec('c.paz_spec')} AS paz_spec,
                    {$this->patientReminderSmsSelectSql('c')} AS appointment_reminder_sms_enabled,
                    {$this->dec('c.indirizzo')} AS indirizzo,
                    {$this->dec('c.citta')} AS citta,
                    CONCAT({$this->decExpr('c.cognome')}, ' ', {$this->decExpr('c.nome')}) AS label
                FROM " . self::CLIENTS_TABLE . " c
                WHERE c.id_client IN ({$idListSql})
                  {$registryVisibilityWhere}
                ORDER BY FIELD(c.id_client, {$idListSql})
            ";
        } else {
            $sql = "
                SELECT
                    c.id_client AS id_paziente,
                    {$this->dec('c.nome')} AS nome,
                    {$this->dec('c.cognome')} AS cognome,
                    {$this->buildAdditionalPatientSelectSql('c')},
                    {$this->buildAssociateAllDoctorsSelectSql('c')} AS associate_all_doctors,
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
                    {$this->patientReminderSmsSelectSql('c')} AS appointment_reminder_sms_enabled,
                    COALESCE(c.bloccato, 0) AS bloccato
                FROM " . self::CLIENTS_TABLE . " c
                WHERE c.id_client IN ({$idListSql})
                  {$registryVisibilityWhere}
                ORDER BY FIELD(c.id_client, {$idListSql})
            ";
        }

        return $this->sanitizePatientRows($this->db->query($sql)->getResultArray(), $autocompleteMode);
    }

    private function insertClientRow(array $data, int $idPersonale): int
    {
        $this->db->query('SET @init_vector = RANDOM_BYTES(16)');

        $primaryDoctorId = $this->isFamilyDoctor($idPersonale) ? $idPersonale : null;
        $extraInsert = $this->buildAdditionalPatientInsertFragments($data);
        $reminderColumn = $this->clientTableHasAppointmentReminderSmsColumn()
            ? ', ' . self::APPOINTMENT_REMINDER_SMS_COLUMN
            : '';
        $reminderValue = $this->clientTableHasAppointmentReminderSmsColumn()
            ? ', ' . (int)$data['appointment_reminder_sms_enabled']
            : '';
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
                avviso_mail{$reminderColumn}{$extraInsert['columns']},
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
                0{$reminderValue}{$extraInsert['values']},
                @init_vector
            )
        ";

        $this->db->query($sql);
        return (int)$this->db->insertID();
    }

    private function updateClientRow(
        int $idClient,
        array $data,
        array $existing,
        int $idPersonale,
        array $providedFields = [],
        bool $allowPrimaryDoctorReassignment = true
    ): void
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

        if ($this->clientTableHasAppointmentReminderSmsColumn()) {
            $fieldSqlMap['appointment_reminder_sms_enabled'] = self::APPOINTMENT_REMINDER_SMS_COLUMN . '=' . (int)$data['appointment_reminder_sms_enabled'];
        }

        foreach ($this->buildAdditionalPatientUpdateMap($data) as $field => $sql) {
            $fieldSqlMap[$field] = $sql;
        }

        foreach ($fieldSqlMap as $field => $sql) {
            if (!empty($providedFields[$field])) {
                $set[] = $sql;
            }
        }

        $isSpecial = trim((string)($existing['paz_spec'] ?? '')) !== '' || $data['paz_spec'] !== '';
        if ($allowPrimaryDoctorReassignment && !$isSpecial && $this->isFamilyDoctor($idPersonale)) {
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
        return $this->getVisiblePatientSnapshotForScope($idClient, [$idPersonale], [$legacyIdDot]);
    }

    private function getVisiblePatientSnapshotForScope(int $idClient, array $personaleIds, array $legacyIdDots): ?array
    {
        $personaleIds = $this->normalizeIdList($personaleIds);
        $legacyIdDots = $this->normalizeIdList($legacyIdDots);

        if ($idClient <= 0 || $personaleIds === [] || $legacyIdDots === []) {
            return null;
        }

        $sql = "
            SELECT
                c.id_client,
                c.id_user,
                COALESCE(c.id_personale, 0) AS id_personale,
                COALESCE(c.legacy_id_paziente, 0) AS legacy_id_paziente,
                {$this->dec('c.paz_spec')} AS paz_spec,
                {$this->buildAssociateAllDoctorsSelectSql('c')} AS share_all_doctors
            FROM " . self::CLIENTS_TABLE . " c
            WHERE c.id_client = ?
              AND (
                    " . $this->buildDoctorSearchVisibilitySqlForScope('c', $personaleIds) . "
                 OR " . $this->buildDoctorAppointmentVisibilitySqlForScope('c', $legacyIdDots) . "
              )
            LIMIT 1
        ";

        $row = $this->db->query($sql, [$idClient])->getRowArray();
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
                {$this->dec('c.paz_spec')} AS paz_spec,
                {$this->buildAssociateAllDoctorsSelectSql('c')} AS share_all_doctors
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
            OR " . $this->buildAssociateAllDoctorsVisibilitySql($alias) . "
            OR COALESCE(TRIM({$this->decExpr($alias . '.paz_spec')}), '') <> ''
        )";
    }

    private function buildDoctorVisibilitySqlForScope(string $alias, array $personaleIds): string
    {
        $personaleListSql = $this->buildIntListSql($personaleIds);
        if ($personaleListSql === '') {
            return '(1 = 0)';
        }

        return "(
            {$alias}.id_personale IN ({$personaleListSql})
            OR EXISTS (
                SELECT 1
                FROM " . self::CLIENT_DOCTOR_TABLE . " cd
                WHERE cd.id_client = {$alias}.id_client
                  AND cd.id_dot IN ({$personaleListSql})
            )
            OR " . $this->buildAssociateAllDoctorsVisibilitySql($alias) . "
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

            " . $this->buildAssociateAllDoctorsPatientIdsSql('c_shared') . "

            UNION

            SELECT c_special.id_client
            FROM " . self::CLIENTS_TABLE . " c_special
            WHERE " . $this->buildGlobalSpecialPatientSql('c_special') . "
        ";
    }

    private function buildDoctorScopedPatientIdsSqlForScope(array $personaleIds, array $legacyIdDots): string
    {
        $personaleListSql = $this->buildIntListSql($personaleIds);
        $legacyListSql = $this->buildIntListSql($legacyIdDots);

        if ($personaleListSql === '' || $legacyListSql === '') {
            return 'SELECT NULL AS id_client WHERE 1 = 0';
        }

        return "
            SELECT c_scope.id_client
            FROM " . self::CLIENTS_TABLE . " c_scope
            WHERE c_scope.id_personale IN ({$personaleListSql})

            UNION

            SELECT cd.id_client
            FROM " . self::CLIENT_DOCTOR_TABLE . " cd
            WHERE cd.id_dot IN ({$personaleListSql})

            UNION

            SELECT COALESCE(NULLIF(a.id_client, 0), c_legacy.id_client) AS id_client
            FROM " . self::APPOINTMENTS_TABLE . " a
            LEFT JOIN " . self::CLIENTS_TABLE . " c_legacy
                ON COALESCE(a.id_client, 0) = 0
               AND COALESCE(c_legacy.legacy_id_paziente, 0) = a.id_paziente
            WHERE a.id_dot IN ({$legacyListSql})
              AND a.stato <> 'ANNULLATO'
              AND (
                    COALESCE(a.id_client, 0) > 0
                 OR c_legacy.id_client IS NOT NULL
              )

            UNION

            " . $this->buildAssociateAllDoctorsPatientIdsSql('c_shared') . "

            UNION

            SELECT c_special.id_client
            FROM " . self::CLIENTS_TABLE . " c_special
            WHERE " . $this->buildGlobalSpecialPatientSql('c_special') . "
        ";
    }

    private function buildRegistryVisibilitySql(string $alias): string
    {
        if (!$this->clientTableHasColumn(self::REGISTRY_VISIBILITY_COLUMN)) {
            return '1 = 1';
        }

        return 'COALESCE(' . $alias . '.' . self::REGISTRY_VISIBILITY_COLUMN . ', 1) = 1';
    }

    private function buildAssociateAllDoctorsVisibilitySql(string $alias): string
    {
        if (!$this->clientTableHasColumn(self::ASSOCIATE_ALL_DOCTORS_COLUMN)) {
            return '0 = 1';
        }

        return 'COALESCE(' . $alias . '.' . self::ASSOCIATE_ALL_DOCTORS_COLUMN . ', 0) = 1';
    }

    private function buildAssociateAllDoctorsPatientIdsSql(string $alias): string
    {
        if (!$this->clientTableHasColumn(self::ASSOCIATE_ALL_DOCTORS_COLUMN)) {
            return 'SELECT NULL AS id_client WHERE 1 = 0';
        }

        return "
            SELECT {$alias}.id_client
            FROM " . self::CLIENTS_TABLE . " {$alias}
            WHERE COALESCE({$alias}." . self::ASSOCIATE_ALL_DOCTORS_COLUMN . ", 0) = 1
        ";
    }

    private function buildDoctorSearchVisibilitySql(string $alias): string
    {
        return '(' . $this->buildDoctorVisibilitySql($alias) . '
            OR ' . $this->buildLegacySpecialTokenSql($alias) . '
        )';
    }

    private function buildDoctorSearchVisibilitySqlForScope(string $alias, array $personaleIds): string
    {
        return '(' . $this->buildDoctorVisibilitySqlForScope($alias, $personaleIds) . '
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

    private function buildDoctorAppointmentVisibilitySqlForScope(string $alias, array $legacyIdDots): string
    {
        $legacyListSql = $this->buildIntListSql($legacyIdDots);
        if ($legacyListSql === '') {
            return '(1 = 0)';
        }

        return "(
            EXISTS (
                SELECT 1
                FROM " . self::APPOINTMENTS_TABLE . " a
                WHERE a.id_dot IN ({$legacyListSql})
                  AND a.id_client = {$alias}.id_client
                  AND a.stato <> 'ANNULLATO'
            )
            OR (
                COALESCE({$alias}.legacy_id_paziente, 0) > 0
                AND EXISTS (
                    SELECT 1
                    FROM " . self::APPOINTMENTS_TABLE . " a_legacy
                    WHERE a_legacy.id_dot IN ({$legacyListSql})
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

        foreach (array_keys(self::EXTRA_PATIENT_FIELDS) as $field) {
            if (!$this->isAdditionalPatientEncryptedField($field) || !$this->clientTableHasColumn($field)) {
                continue;
            }

            $fields[] = $field;
        }

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
                $label = trim((string)($cleanRow['label'] ?? trim(($cleanRow['cognome'] ?? '') . ' ' . ($cleanRow['nome'] ?? ''))));
                if ($label === '' && trim((string)($cleanRow['denominazione'] ?? '')) !== '') {
                    $label = trim((string)$cleanRow['denominazione']);
                }

                $cleanRow['label'] = $label;
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
            'denominazione',
            'cod_fis',
            'partita_iva',
            'telefono',
            'cellulare',
            'email',
            'email_pec',
            'data_nascita',
            'comune_nascita',
            'provincia_nascita',
            'indirizzo',
            'nr_civico',
            'citta',
            'cap',
            'provincia',
            'indirizzo_secondario',
            'nr_civico_secondario',
            'comune_secondario',
            'cap_secondario',
            'provincia_secondaria',
            'residenza_indirizzo',
            'residenza_comune',
            'residenza_cap',
            'residenza_provincia',
            'banca',
            'condizioni_pagamento',
            'codice_destinatario',
            'note_cliente',
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
            'denominazione',
            'cod_fis',
            'partita_iva',
            'telefono',
            'cellulare',
            'email',
            'email_pec',
            'indirizzo',
            'nr_civico',
            'citta',
            'comune_nascita',
            'provincia_nascita',
            'provincia',
            'indirizzo_secondario',
            'nr_civico_secondario',
            'comune_secondario',
            'cap_secondario',
            'provincia_secondaria',
            'residenza_indirizzo',
            'residenza_comune',
            'residenza_provincia',
            'banca',
            'condizioni_pagamento',
            'codice_destinatario',
            'note_cliente',
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

    private function resolveDoctorPatientScope(int $idDot, int $actingUserId = 0): array
    {
        $selectedPersonaleId = $this->resolvePersonaleIdFromLegacyDot($idDot);
        $scope = [
            'selected_personale_id' => $selectedPersonaleId,
            'selected_legacy_id_dot' => $idDot > 0 ? $idDot : 0,
            'personale_ids' => $selectedPersonaleId > 0 ? [$selectedPersonaleId] : [],
            'legacy_dot_ids' => $idDot > 0 ? [$idDot] : [],
            'shared' => false,
        ];

        if ($selectedPersonaleId <= 0 || $idDot <= 0 || !$this->shouldUseSharedDoctorPatientScope($actingUserId)) {
            return $scope;
        }

        $sharedScope = $this->listSharedDoctorScopeIds();
        $personaleIds = $this->normalizeIdList(array_merge(
            (array) ($sharedScope['personale_ids'] ?? []),
            [$selectedPersonaleId]
        ));
        $legacyIdDots = $this->normalizeIdList(array_merge(
            (array) ($sharedScope['legacy_dot_ids'] ?? []),
            [$idDot]
        ));

        if (count($personaleIds) <= 1 || count($legacyIdDots) <= 1) {
            return $scope;
        }

        $scope['personale_ids'] = $personaleIds;
        $scope['legacy_dot_ids'] = $legacyIdDots;
        $scope['shared'] = true;

        return $scope;
    }

    private function shouldUseSharedDoctorPatientScope(int $actingUserId): bool
    {
        if (!$this->isSharedAgendaPatientsEnabledForCurrentTenant()) {
            return false;
        }

        if ($this->isCurrentTenantMaster()) {
            return true;
        }

        return $actingUserId > 0 && $this->isDoctorAgendaUser($actingUserId);
    }

    private function isCurrentTenantMaster(): bool
    {
        if ($this->currentTenantMasterCache !== null) {
            return $this->currentTenantMasterCache;
        }

        try {
            $context = (new TenantContextService())->getCurrentTenant();
            $this->currentTenantMasterCache = $context !== null
                && $context->isValid()
                && strtolower(trim((string) $context->tenantRole)) === 'tenant_master';
        } catch (\Throwable $e) {
            $this->currentTenantMasterCache = false;
        }

        return $this->currentTenantMasterCache;
    }

    private function isDoctorAgendaUser(int $idUser): bool
    {
        if ($idUser <= 0) {
            return false;
        }

        if (array_key_exists($idUser, $this->doctorRoleByUserCache)) {
            return $this->doctorRoleByUserCache[$idUser];
        }

        $row = $this->db->table('dap03_personale')
            ->select('tipo')
            ->where('id_user', $idUser)
            ->orderBy('id_personale', 'ASC')
            ->get(1)
            ->getRowArray();

        $this->doctorRoleByUserCache[$idUser] = (int)($row['tipo'] ?? 0) === 1;

        return $this->doctorRoleByUserCache[$idUser];
    }

    private function isSharedAgendaPatientsEnabledForCurrentTenant(): bool
    {
        if ($this->sharedAgendaPatientsEnabledCache !== null) {
            return $this->sharedAgendaPatientsEnabledCache;
        }

        try {
            $context = (new TenantContextService())->getCurrentTenant();
            $this->sharedAgendaPatientsEnabledCache = $context !== null
                && $context->allows(self::SHARED_AGENDA_PATIENTS_FEATURE);
        } catch (\Throwable $e) {
            $this->sharedAgendaPatientsEnabledCache = false;
        }

        return $this->sharedAgendaPatientsEnabledCache;
    }

    /**
     * @return array{personale_ids: array<int, int>, legacy_dot_ids: array<int, int>}
     */
    private function listSharedDoctorScopeIds(): array
    {
        if ($this->sharedDoctorScopeCache !== null) {
            return $this->sharedDoctorScopeCache;
        }

        $rows = $this->db->table('dap03_personale')
            ->select('id_personale, COALESCE(legacy_id_dot, 0) AS legacy_id_dot')
            ->where('tipo', 1)
            ->where('legacy_id_dot >', 0)
            ->get()
            ->getResultArray();

        $personaleIds = [];
        $legacyIdDots = [];

        foreach ($rows as $row) {
            $personaleId = (int)($row['id_personale'] ?? 0);
            $legacyIdDot = (int)($row['legacy_id_dot'] ?? 0);

            if ($personaleId > 0) {
                $personaleIds[] = $personaleId;
            }

            if ($legacyIdDot > 0) {
                $legacyIdDots[] = $legacyIdDot;
            }
        }

        $this->sharedDoctorScopeCache = [
            'personale_ids' => $this->normalizeIdList($personaleIds),
            'legacy_dot_ids' => $this->normalizeIdList($legacyIdDots),
        ];

        return $this->sharedDoctorScopeCache;
    }

    private function hasDirectDoctorRelationship(int $idClient, int $idPersonale): bool
    {
        if ($idClient <= 0 || $idPersonale <= 0) {
            return false;
        }

        $row = $this->db->query(
            "
                SELECT c.id_client
                FROM " . self::CLIENTS_TABLE . " c
                WHERE c.id_client = ?
                  AND (
                        c.id_personale = ?
                     OR EXISTS (
                            SELECT 1
                            FROM " . self::CLIENT_DOCTOR_TABLE . " cd
                            WHERE cd.id_client = c.id_client
                              AND cd.id_dot = ?
                        )
                  )
                LIMIT 1
            ",
            [$idClient, $idPersonale, $idPersonale]
        )->getRowArray();

        return !empty($row);
    }

    private function normalizeIdList(array $values): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn($value): int => (int) $value,
            $values
        ), static fn(int $id): bool => $id > 0)));

        sort($ids);

        return $ids;
    }

    private function buildIntListSql(array $values): string
    {
        $values = $this->normalizeIdList($values);
        if ($values === []) {
            return '';
        }

        return implode(',', $values);
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

    private function normalizeVatNumber(string $value): string
    {
        $value = strtoupper(trim($value));
        return preg_replace('/[^A-Z0-9]/', '', $value) ?? '';
    }

    private function normalizeBooleanFlag($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        $normalized = strtolower(trim((string) $value));
        return in_array($normalized, ['1', 'true', 'on', 'yes', 'si'], true);
    }

    private function buildNormalizedComparableSql(string $expr): string
    {
        return "REPLACE(REPLACE(REPLACE(REPLACE(TRIM(UPPER(COALESCE({$expr}, ''))), ' ', ''), '.', ''), '-', ''), '/', '')";
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
            'denominazione' => array_key_exists('denominazione', $payload),
            'data_nascita' => array_key_exists('data_nascita', $payload),
            'codice_fiscale' => array_key_exists('cod_fis', $payload) || array_key_exists('codice_fiscale', $payload),
            'partita_iva' => array_key_exists('partita_iva', $payload),
            'comune_nascita' => array_key_exists('comune_nascita', $payload),
            'provincia_nascita' => array_key_exists('provincia_nascita', $payload),
            'indirizzo' => array_key_exists('indirizzo', $payload),
            'nr_civico' => array_key_exists('nr_civico', $payload),
            'citta' => array_key_exists('citta', $payload),
            'cap' => array_key_exists('cap', $payload),
            'provincia' => array_key_exists('provincia', $payload),
            'indirizzo_secondario' => array_key_exists('indirizzo_secondario', $payload),
            'nr_civico_secondario' => array_key_exists('nr_civico_secondario', $payload),
            'comune_secondario' => array_key_exists('comune_secondario', $payload),
            'cap_secondario' => array_key_exists('cap_secondario', $payload),
            'provincia_secondaria' => array_key_exists('provincia_secondaria', $payload),
            'residenza_indirizzo' => array_key_exists('residenza_indirizzo', $payload),
            'residenza_comune' => array_key_exists('residenza_comune', $payload),
            'residenza_cap' => array_key_exists('residenza_cap', $payload),
            'residenza_provincia' => array_key_exists('residenza_provincia', $payload),
            'telefono' => array_key_exists('telefono', $payload),
            'cellulare' => array_key_exists('cellulare', $payload),
            'email' => array_key_exists('email', $payload),
            'email_pec' => array_key_exists('email_pec', $payload),
            'banca' => array_key_exists('banca', $payload),
            'condizioni_pagamento' => array_key_exists('condizioni_pagamento', $payload),
            'codice_destinatario' => array_key_exists('codice_destinatario', $payload),
            'iva_differita' => array_key_exists('iva_differita', $payload),
            'note_cliente' => array_key_exists('note_cliente', $payload),
            'appointment_reminder_sms_enabled' => array_key_exists('appointment_reminder_sms_enabled', $payload),
            'visibile_in_anagrafica' => array_key_exists(self::REGISTRY_VISIBILITY_COLUMN, $payload),
            'bloccato' => array_key_exists('bloccato', $payload),
            'cliente_attivo' => array_key_exists('cliente_attivo', $payload),
            'paz_spec' => array_key_exists('paz_spec', $payload),
        ];
    }

    private function buildAdditionalPatientSelectSql(string $alias): string
    {
        $parts = [];

        foreach (self::EXTRA_PATIENT_FIELDS as $field => $meta) {
            $parts[] = $this->buildAdditionalPatientSelectFieldSql(
                $alias,
                $field,
                (bool)($meta['encrypted'] ?? false),
                (string)($meta['default'] ?? "''")
            );
        }

        return implode(",\n                ", $parts);
    }

    private function buildAssociateAllDoctorsSelectSql(string $alias): string
    {
        if (!$this->clientTableHasColumn(self::ASSOCIATE_ALL_DOCTORS_COLUMN)) {
            return 'CAST(0 AS SIGNED)';
        }

        return 'COALESCE(' . $alias . '.' . self::ASSOCIATE_ALL_DOCTORS_COLUMN . ', 0)';
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{provided: bool, enabled: bool}
     */
    private function resolveAllDoctorsAssociationMode(array $payload): array
    {
        if (!array_key_exists('associate_all_doctors', $payload)) {
            return [
                'provided' => false,
                'enabled' => false,
            ];
        }

        return [
            'provided' => true,
            'enabled' => $this->normalizeBooleanFlag($payload['associate_all_doctors'] ?? 0),
        ];
    }

    private function listAllDoctorAssociationPersonaleIds(int $selectedPersonaleId = 0): array
    {
        $doctorIds = (array) ($this->listSharedDoctorScopeIds()['personale_ids'] ?? []);
        if ($selectedPersonaleId > 0) {
            $doctorIds[] = $selectedPersonaleId;
        }

        return $this->normalizeIdList($doctorIds);
    }

    private function updateAssociateAllDoctorsFlag(int $idClient, bool $enabled): void
    {
        if ($idClient <= 0 || !$this->clientTableHasColumn(self::ASSOCIATE_ALL_DOCTORS_COLUMN)) {
            return;
        }

        $this->db->table(self::CLIENTS_TABLE)
            ->where('id_client', $idClient)
            ->update([self::ASSOCIATE_ALL_DOCTORS_COLUMN => $enabled ? 1 : 0]);
    }

    private function isPatientAssociatedToAllDoctors(int $idClient): bool
    {
        if ($idClient <= 0 || !$this->clientTableHasColumn(self::ASSOCIATE_ALL_DOCTORS_COLUMN)) {
            return false;
        }

        $row = $this->db->table(self::CLIENTS_TABLE)
            ->select('COALESCE(' . self::ASSOCIATE_ALL_DOCTORS_COLUMN . ', 0) AS associate_all_doctors')
            ->where('id_client', $idClient)
            ->get(1)
            ->getRowArray();

        return (int) ($row['associate_all_doctors'] ?? 0) === 1;
    }

    private function buildAdditionalPatientInsertFragments(array $data): array
    {
        $columns = [];
        $values = [];

        foreach (self::EXTRA_PATIENT_FIELDS as $field => $meta) {
            if (!$this->clientTableHasColumn($field)) {
                continue;
            }

            $columns[] = $field;
            $values[] = !empty($meta['encrypted'])
                ? $this->enc((string)($data[$field] ?? ''))
                : (string)((int)($data[$field] ?? (int)($meta['default'] ?? 0)));
        }

        return [
            'columns' => $columns === [] ? '' : ', ' . implode(",\n                ", $columns),
            'values' => $values === [] ? '' : ', ' . implode(",\n                ", $values),
        ];
    }

    private function buildAdditionalPatientUpdateMap(array $data): array
    {
        $map = [];

        foreach (self::EXTRA_PATIENT_FIELDS as $field => $meta) {
            if (!$this->clientTableHasColumn($field)) {
                continue;
            }

            $map[$field] = !empty($meta['encrypted'])
                ? $field . '=' . $this->encWithVector((string)($data[$field] ?? ''))
                : $field . '=' . (int)($data[$field] ?? (int)($meta['default'] ?? 0));
        }

        return $map;
    }

    private function buildAdditionalPatientSelectFieldSql(
        string $alias,
        string $field,
        bool $encrypted,
        string $defaultSql
    ): string {
        if (!$this->clientTableHasColumn($field)) {
            return $defaultSql . ' AS ' . $field;
        }

        if ($encrypted) {
            return $this->dec($alias . '.' . $field) . ' AS ' . $field;
        }

        return 'COALESCE(' . $alias . '.' . $field . ', ' . $defaultSql . ') AS ' . $field;
    }

    private function isAdditionalPatientEncryptedField(string $field): bool
    {
        return !empty(self::EXTRA_PATIENT_FIELDS[$field]['encrypted']);
    }

    private function patientReminderSmsSelectSql(string $alias): string
    {
        $alias = trim($alias);
        if ($alias === '' || !$this->clientTableHasAppointmentReminderSmsColumn()) {
            return '0';
        }

        return 'COALESCE(' . $alias . '.' . self::APPOINTMENT_REMINDER_SMS_COLUMN . ', 0)';
    }

    private function clientTableHasAppointmentReminderSmsColumn(): bool
    {
        if ($this->hasClientAppointmentReminderSmsColumn !== null) {
            return $this->hasClientAppointmentReminderSmsColumn;
        }

        return $this->hasClientAppointmentReminderSmsColumn = $this->clientTableHasColumn(
            self::APPOINTMENT_REMINDER_SMS_COLUMN
        );
    }

    private function clientTableHasColumn(string $column): bool
    {
        if (array_key_exists($column, $this->clientColumnExistsCache)) {
            return $this->clientColumnExistsCache[$column];
        }

        return $this->clientColumnExistsCache[$column] = $this->db->fieldExists($column, self::CLIENTS_TABLE);
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
