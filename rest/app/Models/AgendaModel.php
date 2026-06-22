<?php

namespace App\Models;

use CodeIgniter\Model;
use App\Libraries\Crypto_helper;
use App\Libraries\DatabaseConfig;

class AgendaModel extends Model
{
    protected $db;
    protected Crypto_helper $crypto;
    protected const RUOLO_ADMIN = 1;
    protected const RUOLO_SEGRETERIA = 2;
    protected const RUOLO_DOTTORE = 3;
    protected const RUOLO_INFERMIERE = 5;
    protected const AGENDA_VISIBILITY_TABLE = 'dap24_agenda_visibilita';
    private ?string $resolvedAgendaVisibilityTable = null;
    private array $agendaUserContextCache = [];
    private ?array $agendaRoleOptionsCache = null;
    private ?bool $memoBlockTableExists = null;
    private ?bool $domBlockTableExists = null;
    private ?string $blockedDayPrimaryKey = null;
    private array $personaleFieldExistsCache = [];

    private function normalizeLegacyString($value)
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }

        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        return mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
    }

    private function normalizeLegacyArrayRows(array $rows, array $keys): array
    {
        foreach ($rows as &$row) {
            if (!is_array($row)) {
                continue;
            }

            foreach ($keys as $key) {
                if (array_key_exists($key, $row)) {
                    $row[$key] = $this->normalizeLegacyString($row[$key]);
                }
            }
        }
        unset($row);

        return $rows;
    }

    private function normalizeLegacyObjectRows(array $rows, array $keys): array
    {
        foreach ($rows as $row) {
            if (!is_object($row)) {
                continue;
            }

            foreach ($keys as $key) {
                if (property_exists($row, $key)) {
                    $row->{$key} = $this->normalizeLegacyString($row->{$key});
                }
            }
        }

        return $rows;
    }

    public function __construct()
    {
        parent::__construct();
        $this->db = \Config\Database::connect();
        (new DatabaseConfig())->setEncryptionConfig($this->db);
        $this->crypto = new Crypto_helper();
    }

private function getAgendaVisibilityTable(): string
{
    if ($this->resolvedAgendaVisibilityTable !== null) {
        return $this->resolvedAgendaVisibilityTable;
    }

    $this->resolvedAgendaVisibilityTable = self::AGENDA_VISIBILITY_TABLE;
    return $this->resolvedAgendaVisibilityTable;
}

private function getAgendaUserContext(int $idUser): array
{
    if ($idUser <= 0) {
        return [];
    }

    if (isset($this->agendaUserContextCache[$idUser])) {
        return $this->agendaUserContextCache[$idUser];
    }

    $row = $this->db->table('dap03_personale p')
        ->select("
            p.id_personale,
            p.id_user,
            p.tipo,
            p.luogo,
            p.titolare,
            p.sostituto,
            p.is_dot,
            COALESCE(p.legacy_id_ope, 0) AS legacy_id_ope,
            COALESCE(p.legacy_id_dot, 0) AS legacy_id_dot,
            COALESCE(p.f_dom, 0) AS f_dom,
            u.username
        ", false)
        ->join('dap01_users u', 'u.id_user = p.id_user', 'left')
        ->where('p.id_user', $idUser)
        ->orderBy('p.id_personale', 'ASC')
        ->get()
        ->getRowArray();

    if (!$row) {
        $this->agendaUserContextCache[$idUser] = [];
        return [];
    }

    $row['id_personale'] = (int)($row['id_personale'] ?? 0);
    $row['id_user'] = (int)($row['id_user'] ?? 0);
    $row['tipo'] = (int)($row['tipo'] ?? 0);
    $row['luogo'] = (int)($row['luogo'] ?? 0);
    $row['titolare'] = (int)($row['titolare'] ?? 0);
    $row['sostituto'] = (int)($row['sostituto'] ?? 0);
    $row['is_dot'] = (int)($row['is_dot'] ?? 0);
    $row['legacy_id_ope'] = (int)($row['legacy_id_ope'] ?? 0);
    $row['legacy_id_dot'] = (int)($row['legacy_id_dot'] ?? 0);
    $row['f_dom'] = (int)($row['f_dom'] ?? 0);
    $row['agenda_ruolo'] = $this->mapDapTipoToAgendaRole((int)$row['tipo']);

    $this->agendaUserContextCache[$idUser] = $row;
    return $row;
}

private function getEffectiveAgendaRoleFromContext(array $context): int
{
    $idRuo = (int)($context['agenda_ruolo'] ?? 0);
    if ($idRuo <= 0) {
        $idRuo = $this->mapDapTipoToAgendaRole((int)($context['tipo'] ?? 0));
    }

    $username = strtolower(trim((string)($context['username'] ?? '')));
    if ($username === 'demo.segreteria') {
        return self::RUOLO_ADMIN;
    }

    return $idRuo;
}

private function mapDapTipoToAgendaRole(int $tipo): int
{
    return match ($tipo) {
        4 => self::RUOLO_ADMIN,
        3 => self::RUOLO_SEGRETERIA,
        1 => self::RUOLO_DOTTORE,
        2 => self::RUOLO_INFERMIERE,
        default => 0,
    };
}

private function mapAgendaRoleToDapTipo(int $idRuo): ?int
{
    return match ($idRuo) {
        self::RUOLO_ADMIN => 4,
        self::RUOLO_SEGRETERIA => 3,
        self::RUOLO_DOTTORE => 1,
        self::RUOLO_INFERMIERE => 2,
        default => null,
    };
}

private function getAgendaRoleOptions(): array
{
    if ($this->agendaRoleOptionsCache !== null) {
        return $this->agendaRoleOptionsCache;
    }

    $rows = $this->db->table('dap05_type_doctors')
        ->select('id_type_doctors, COALESCE(des_tipo, \'\') AS des_tipo')
        ->orderBy('id_type_doctors', 'ASC')
        ->get()
        ->getResultArray();

    $options = [];
    foreach ($rows as $row) {
        $tipoDap = (int)($row['id_type_doctors'] ?? 0);
        $idRuo = $this->mapDapTipoToAgendaRole($tipoDap);
        if ($idRuo <= 0) {
            continue;
        }

        $options[$idRuo] = [
            'id_ruo' => $idRuo,
            'des_ruo' => trim((string)($row['des_tipo'] ?? '')) !== ''
                ? (string)$row['des_tipo']
                : ('Ruolo #' . $idRuo),
            'tipo_dap' => $tipoDap,
        ];
    }

    $ordered = [];
    foreach ([self::RUOLO_ADMIN, self::RUOLO_SEGRETERIA, self::RUOLO_DOTTORE, self::RUOLO_INFERMIERE] as $idRuo) {
        if (isset($options[$idRuo])) {
            $ordered[] = $options[$idRuo];
        }
    }

    $this->agendaRoleOptionsCache = $ordered;
    return $ordered;
}

private function decryptCharExpr(string $fieldExpr): string
{
    return $this->crypto->decrypt_concat($fieldExpr);
}

private function hasPersonaleField(string $field): bool
{
    if (array_key_exists($field, $this->personaleFieldExistsCache)) {
        return $this->personaleFieldExistsCache[$field];
    }

    try {
        $exists = $this->db->fieldExists($field, 'dap03_personale');
    } catch (\Throwable $e) {
        $exists = false;
    }

    $this->personaleFieldExistsCache[$field] = $exists;
    return $exists;
}

private function getBlockedDayPrimaryKey(): string
{
    if ($this->blockedDayPrimaryKey !== null) {
        return $this->blockedDayPrimaryKey;
    }

    $this->blockedDayPrimaryKey = $this->db->fieldExists('id_giorno_bloccato', 'dap21_agenda_giorni_bloccati')
        ? 'id_giorno_bloccato'
        : 'id_blocco';

    return $this->blockedDayPrimaryKey;
}

private function isAgendaProfessionalVisible(int $legacyIdDot): bool
{
    if ($legacyIdDot <= 0) {
        return false;
    }

    $builder = $this->db->table('dap03_personale p')
        ->select('1')
        ->whereIn('p.tipo', [1, 2])
        ->where('p.legacy_id_dot', $legacyIdDot);

    if ($this->hasPersonaleField('show_in_agenda')) {
        $builder->where('COALESCE(p.show_in_agenda, 1) = 1', null, false);
    }

    return !empty($builder->get()->getRowArray());
}

private function getAgendaProfessionalsBuilder(): \CodeIgniter\Database\BaseBuilder
{
    $nomeExpr = $this->decryptCharExpr('p.nome');
    $cognomeExpr = $this->decryptCharExpr('p.cognome');

    $builder = $this->db->table('dap03_personale p')
        ->select("
            p.id_personale,
            p.id_user,
            p.tipo,
            COALESCE(p.legacy_id_ope, 0) AS legacy_id_ope,
            COALESCE(p.legacy_id_dot, 0) AS id_dot,
            COALESCE(p.f_dom, 0) AS f_dom,
            {$nomeExpr} AS nome,
            {$cognomeExpr} AS cognome,
            CASE p.tipo
                WHEN 4 THEN 1
                WHEN 3 THEN 2
                WHEN 1 THEN 3
                WHEN 2 THEN 5
                ELSE 0
            END AS id_ruo,
            CONCAT(TRIM({$cognomeExpr}), ' ', TRIM({$nomeExpr})) AS label
        ", false)
        ->whereIn('p.tipo', [1, 2])
        ->where('p.legacy_id_dot IS NOT NULL', null, false)
        ->where('p.legacy_id_dot >', 0);

    if ($this->hasPersonaleField('show_in_agenda')) {
        $builder->where('COALESCE(p.show_in_agenda, 1) = 1', null, false);
    }

    return $builder;
}

private function getAgendaOperatorIdsForUser(int $idUser): array
{
    $context = $this->getAgendaUserContext($idUser);
    if ($context === []) {
        return [];
    }

    $ids = [];
    $idPersonale = (int)($context['id_personale'] ?? 0);
    $legacyIdOpe = (int)($context['legacy_id_ope'] ?? 0);

    if ($idPersonale > 0) {
        $ids[] = $idPersonale;
    }
    if ($legacyIdOpe > 0) {
        $ids[] = $legacyIdOpe;
    }

    return array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0)));
}

private function getAgendaOperatorRecord(int $operatorId): ?array
{
    if ($operatorId <= 0) {
        return null;
    }

    $row = $this->db->query("
        SELECT
            id_personale,
            COALESCE(legacy_id_ope, 0) AS legacy_id_ope,
            COALESCE(legacy_id_dot, 0) AS legacy_id_dot,
            tipo
        FROM dap03_personale
        WHERE id_personale = ?
           OR legacy_id_ope = ?
        ORDER BY CASE WHEN id_personale = ? THEN 0 ELSE 1 END, id_personale ASC
        LIMIT 1
    ", [$operatorId, $operatorId, $operatorId])->getRowArray();

    if (!$row) {
        return null;
    }

    $row['id_personale'] = (int)($row['id_personale'] ?? 0);
    $row['legacy_id_ope'] = (int)($row['legacy_id_ope'] ?? 0);
    $row['legacy_id_dot'] = (int)($row['legacy_id_dot'] ?? 0);
    $row['tipo'] = (int)($row['tipo'] ?? 0);

    return $row;
}

private function getLegacyOperatorIdentifiersForPersonaleId(int $idPersonale): array
{
    if ($idPersonale <= 0) {
        return [];
    }

    $row = $this->getAgendaOperatorRecord($idPersonale);

    if (!$row) {
        return [];
    }

    $ids = [(int)$row['id_personale']];
    $legacyIdOpe = (int)($row['legacy_id_ope'] ?? 0);
    if ($legacyIdOpe > 0) {
        $ids[] = $legacyIdOpe;
    }

    $ids = array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0)));
    sort($ids);

    return $ids;
}

public function getAgendaProfessionalByLegacyId(int $idDot): ?array
{
    if ($idDot <= 0) {
        return null;
    }

    $row = $this->getAgendaProfessionalsBuilder()
        ->where('p.legacy_id_dot', $idDot)
        ->get()
        ->getRowArray();

    if ($row) {
        $row = $this->normalizeLegacyArrayRows([$row], ['nome', 'cognome', 'label'])[0];
    }

    return $row ?: null;
}

public function getAllAgendaProfessionals(): array
{
    $rows = $this->getAgendaProfessionalsBuilder()
        ->orderBy($this->decryptCharExpr('p.cognome'), '', false)
        ->orderBy($this->decryptCharExpr('p.nome'), '', false)
        ->get()
        ->getResult();

    return $this->normalizeLegacyObjectRows($rows, ['nome', 'cognome', 'label']);
}

/**
 * @param array<int, int|string> $idDots
 * @return array<int, array<string, mixed>>
 */
public function getAgendaProfessionalMapByLegacyIds(array $idDots): array
{
    $normalizedIds = array_values(array_unique(array_filter(array_map(
        static fn($value): int => (int) $value,
        $idDots
    ), static fn(int $id): bool => $id > 0)));

    if ($normalizedIds === []) {
        return [];
    }

    sort($normalizedIds);

    $rows = $this->getAgendaProfessionalsBuilder()
        ->whereIn('p.legacy_id_dot', $normalizedIds)
        ->orderBy($this->decryptCharExpr('p.cognome'), '', false)
        ->orderBy($this->decryptCharExpr('p.nome'), '', false)
        ->get()
        ->getResultArray();

    $rows = $this->normalizeLegacyArrayRows($rows, ['nome', 'cognome', 'label']);
    $map = [];

    foreach ($rows as $row) {
        $idDot = (int) ($row['id_dot'] ?? 0);
        if ($idDot <= 0) {
            continue;
        }

        $map[$idDot] = $row;
    }

    return $map;
}

public function getMenuVisibleByUser(int $idUser): array
{
    $context = $this->getAgendaUserContext($idUser);
    if ($context === []) {
        return [];
    }

    $idOpe = (int)($context['legacy_id_ope'] ?? 0);
    if ($idOpe <= 0) {
        $idOpe = (int)($context['id_personale'] ?? 0);
    }
    $idRuo = $this->getEffectiveAgendaRoleFromContext($context);

    $rows = $this->db->query("
        SELECT DISTINCT
            m.id_menu,
            m.id_menu_padre,
            m.codice,
            m.tipo_voce,
            m.label_menu,
            m.icona,
            m.rotta,
            m.ordinamento,
            m.attivo
        FROM dap17_agenda_menu m
        LEFT JOIN dap18_agenda_menu_permessi p_ope
            ON p_ope.id_menu = m.id_menu
           AND p_ope.id_ope = ?
        LEFT JOIN dap18_agenda_menu_permessi p_ruo
            ON p_ruo.id_menu = m.id_menu
           AND p_ruo.id_ruo = ?
           AND p_ruo.id_ope IS NULL
        WHERE m.attivo = 1
          AND (
                (p_ope.id_perm IS NOT NULL AND p_ope.visibile = 1)
                OR
                (p_ope.id_perm IS NULL AND p_ruo.id_perm IS NOT NULL AND p_ruo.visibile = 1)
               /*   OR
              (p_ope.id_perm IS NULL AND p_ruo.id_perm IS NULL)*/
              )
        ORDER BY
            COALESCE(m.id_menu_padre, m.id_menu),
            m.ordinamento ASC,
            m.label_menu ASC
    ", [$idOpe, $idRuo])->getResultArray();

    $byParent = [];

    foreach ($rows as $row) {
        if (!$this->isMenuRowAllowedForUser($row, $idUser)) {
            continue;
        }

        $parentId = (int)($row['id_menu_padre'] ?? 0);
        $row['children'] = [];
        $byParent[$parentId][] = $row;
    }

    $buildTree = function (int $parentId) use (&$buildTree, $byParent): array {
        $items = $byParent[$parentId] ?? [];

        foreach ($items as &$item) {
            $item['children'] = $buildTree((int)$item['id_menu']);
        }
        unset($item);

        return $items;
    };

    return $buildTree(0);
}

private function isMenuRowAllowedForUser(array $row, int $idUser): bool
{
    $route = trim((string)($row['rotta'] ?? ''));
    if ($route === '') {
        return true;
    }

    if ($route === 'agenda/visibilita-operatori') {
        return $this->canManageVisibility($idUser);
    }

    if ($route === 'agenda/menu-ruoli') {
        return $this->canManageMenuRoles($idUser);
    }

    if ($route === 'agenda/slot-bloccati') {
        return $this->canBloccareGiorno($idUser);
    }

    return true;
}

public function getSlotExtraByDoctorPaginate(
    int $idDot,
    ?string $dataInizio = null,
    ?string $dataFine = null,
    int $page = 1,
    int $perPage = 20
): array {
    $page = max(1, $page);
    $perPage = max(1, $perPage);
    $offset = ($page - 1) * $perPage;

    $builder = $this->db->table('dap11_agenda_slot s')
        ->select("
            s.id_slot,
            s.id_dot,
            s.data_slot,
            s.ora_inizio,
            s.ora_fine,
            s.tipo_slot,
            s.stato,
            s.titolo_libero,
            s.ambulatorio,
            s.stanza,
            s.origine_slot,
            s.note_interne,
            s.created_at,
            (
                SELECT COUNT(*)
                FROM dap12_agenda_appuntamenti a
                WHERE a.id_slot = s.id_slot
                  AND a.stato <> 'ANNULLATO'
            ) AS appuntamenti_attivi
        ")
        ->where('s.id_dot', $idDot)
        ->where('s.origine_slot', 'EXTRA');

    if (!empty($dataInizio)) {
        $builder->where('s.data_slot >=', $dataInizio);
    }

    if (!empty($dataFine)) {
        $builder->where('s.data_slot <=', $dataFine);
    }

    $countBuilder = clone $builder;
    $total = $countBuilder->countAllResults();

    $rows = $builder
        ->orderBy('s.data_slot', 'DESC')
        ->orderBy('s.ora_inizio', 'DESC')
        ->limit($perPage, $offset)
        ->get()
        ->getResultArray();

    return [
        'rows'     => $rows,
        'total'    => $total,
        'page'     => $page,
        'perPage'  => $perPage,
        'lastPage' => max(1, (int)ceil($total / $perPage)),
    ];
}
public function getExtraSlotsByIds(array $ids, int $idDot): array
{
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (empty($ids)) {
        return [];
    }

    return $this->db->table('dap11_agenda_slot')
        ->select('id_slot, id_dot, data_slot, ora_inizio, ora_fine, origine_slot, titolo_libero')
        ->where('id_dot', $idDot)
        ->where('origine_slot', 'EXTRA')
        ->whereIn('id_slot', $ids)
        ->get()
        ->getResultArray();
}
public function countActiveAppointmentsBySlotIds(array $slotIds): int
{
    $slotIds = array_values(array_filter(array_map('intval', $slotIds)));
    if (empty($slotIds)) {
        return 0;
    }

    return $this->db->table('dap12_agenda_appuntamenti')
        ->whereIn('id_slot', $slotIds)
        ->where('stato <>', 'ANNULLATO')
        ->countAllResults();
}
public function getActiveAppointmentsBySlotIds(array $slotIds): array
{
    $slotIds = array_values(array_filter(array_map('intval', $slotIds)));
    if (empty($slotIds)) {
        return [];
    }

    return $this->db->table('dap12_agenda_appuntamenti a')
        ->select("
            a.id_appuntamento,
            a.id_slot,
            a.cognome,
            a.nome,
            a.stato,
            s.data_slot,
            s.ora_inizio,
            s.ora_fine
        ")
        ->join('dap11_agenda_slot s', 's.id_slot = a.id_slot', 'inner')
        ->whereIn('a.id_slot', $slotIds)
        ->where('a.stato <>', 'ANNULLATO')
        ->orderBy('s.data_slot', 'ASC')
        ->orderBy('s.ora_inizio', 'ASC')
        ->get()
        ->getResultArray();
}
public function deleteExtraSlotsByIds(array $slotIds, int $idDot, bool $forceDelete = false): array
{
    $slotIds = array_values(array_filter(array_map('intval', $slotIds)));
    if (empty($slotIds)) {
        throw new \Exception('Nessuno slot selezionato.');
    }

    $slots = $this->getExtraSlotsByIds($slotIds, $idDot);
    if (empty($slots)) {
        throw new \Exception('Nessuno slot extra valido trovato.');
    }

    $validIds = array_map(static fn($r) => (int)$r['id_slot'], $slots);

    $appointments = $this->getActiveAppointmentsBySlotIds($validIds);

    if (!$forceDelete && !empty($appointments)) {
        return [
            'status' => false,
            'hasAppointments' => true,
            'message' => 'Sono presenti appuntamenti attivi sugli slot selezionati.',
            'appointments' => $appointments,
            'deletable_slot_ids' => $validIds,
        ];
    }

    $this->db->transStart();

    if (!empty($appointments)) {
        $this->db->table('dap12_agenda_appuntamenti')
            ->whereIn('id_slot', $validIds)
            ->where('stato <>', 'ANNULLATO')
            ->delete();
    }

    $this->db->table('dap11_agenda_slot')
        ->where('id_dot', $idDot)
        ->where('origine_slot', 'EXTRA')
        ->whereIn('id_slot', $validIds)
        ->delete();

    $this->db->transComplete();

    if (!$this->db->transStatus()) {
        throw new \Exception('Errore durante l\'eliminazione degli slot extra.');
    }

    return [
        'status' => true,
        'deleted_slots' => count($validIds),
        'deleted_appointments' => count($appointments),
        'message' => 'Eliminazione completata con successo.',
    ];
}
public function copyPatientOnSameSlotForPeriod(array $payload, int $userId): array
{
    $idDot          = (int)($payload['id_dot'] ?? 0);
    $giornoRif      = (string)($payload['giorno_riferimento'] ?? '');
    $slotOraInizio  = trim((string)($payload['slot_ora_inizio'] ?? ''));
    $dataInizio     = (string)($payload['data_inizio'] ?? '');
    $dataFine       = (string)($payload['data_fine'] ?? '');
    $idPaziente     = (int)($payload['id_paziente'] ?? 0);

    if ($idDot <= 0) {
        throw new \Exception('Dottore non valido.');
    }

    if ($giornoRif === '' || $slotOraInizio === '' || $dataInizio === '' || $dataFine === '') {
        throw new \Exception('Parametri mancanti.');
    }

    if ($idPaziente <= 0) {
        throw new \Exception('Paziente non valido.');
    }

    $paziente = $this->getAgendaPatientRow($idPaziente, $idDot, $userId);

    if (!$paziente) {
        throw new \Exception('Paziente non trovato per il medico selezionato.');
    }

    $slotOraInizioSql = $this->normalizeTime($slotOraInizio);
    $giornoRiferimentoDate = new \DateTime($giornoRif);
    $giornoSettimanaTarget = (int)$giornoRiferimentoDate->format('N');
    $start = new \DateTime($dataInizio);
    $end   = new \DateTime($dataFine);
    $end->modify('+1 day');

    $creati = 0;
    $giorniBloccati = [];
    $giaPieni = [];
    $slotNonTrovati = [];

    $this->db->transStart();

    for ($dt = clone $start; $dt < $end; $dt->modify('+1 day')) {
        $dataCorrente = $dt->format('Y-m-d');

        if ((int)$dt->format('N') !== $giornoSettimanaTarget) {
            continue;
        }

        if ($this->isGiornoBloccato($idDot, $dataCorrente)) {
            $giorniBloccati[] = $dataCorrente;
            continue;
        }

        $slot = $this->db->table('dap11_agenda_slot')
            ->select('id_slot, id_dot, data_slot, ora_inizio, ora_fine, stato')
            ->where('id_dot', $idDot)
            ->where('data_slot', $dataCorrente)
            ->where('TIME(ora_inizio)', $slotOraInizioSql)
            ->orderBy('ora_inizio', 'ASC')
            ->get()
            ->getRowArray();

        if (!$slot) {
            $slotNonTrovati[] = [
                'data' => $dataCorrente,
                'ora'  => $slotOraInizio,
            ];
            continue;
        }

        $giaOccupato = $this->db->table('dap12_agenda_appuntamenti')
            ->where('id_slot', (int)$slot['id_slot'])
            ->where('stato <>', 'ANNULLATO')
            ->countAllResults();

        if ($giaOccupato > 0 || ($slot['stato'] ?? '') !== 'LIBERO') {
            $giaPieni[] = [
                'data'    => $dataCorrente,
                'ora'     => $slotOraInizio,
                'id_slot' => (int)$slot['id_slot'],
            ];
            continue;
        }

        $this->db->table('dap12_agenda_appuntamenti')->insert([
            'id_slot'          => (int)$slot['id_slot'],
            'id_dot'           => $idDot,
            'id_paziente'      => $idPaziente,
            'id_client'        => $idPaziente,
            'cognome'          => (string)($paziente['cognome'] ?? ''),
            'nome'             => (string)($paziente['nome'] ?? ''),
            'telefono'         => (string)($paziente['telefono'] ?? ''),
            'cellulare'        => (string)($paziente['cellulare'] ?? ''),
            'email'            => (string)($paziente['email'] ?? ''),
            'note'             => null,
            'motivo_visita'    => null,
            'indirizzo_visita' => null,
            'comune_visita'    => null,
            'stato'            => 'CONFERMATO',
            'created_by'       => $userId,
            'created_at'       => date('Y-m-d H:i:s'),
        ]);

        $this->db->table('dap11_agenda_slot')
            ->where('id_slot', (int)$slot['id_slot'])
            ->update([
                'stato'      => 'PRENOTATO',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        $creati++;
    }

    $this->db->transComplete();

    if (!$this->db->transStatus()) {
        throw new \Exception('Errore durante la copia degli appuntamenti sul periodo.');
    }

    return [
        'creati'           => $creati,
        'giorni_bloccati'  => $giorniBloccati,
        'gia_pieni'        => $giaPieni,
        'slot_non_trovati' => $slotNonTrovati,
    ];
}
public function copyWeeklyAppointmentsFromDayRange(array $filters, int $userId): array
{
    $idDot        = (int)($filters['id_dot'] ?? 0);
    $dataSorgente = (string)($filters['data_sorgente'] ?? '');
    $oraInizio    = trim((string)($filters['ora_inizio'] ?? ''));
    $oraFine      = trim((string)($filters['ora_fine'] ?? ''));
    $dataFine     = (string)($filters['data_fine'] ?? '');

    if ($idDot <= 0 || $dataSorgente === '' || $oraInizio === '' || $oraFine === '' || $dataFine === '') {
        throw new \Exception('Parametri mancanti.');
    }

    $hasAppointmentClientColumn = $this->db->fieldExists('id_client', 'dap12_agenda_appuntamenti');
    $appointmentClientSelect = $hasAppointmentClientColumn
        ? 'a.id_client,'
        : 'NULL AS id_client,';

    $sourceAppointments = $this->db->table('dap12_agenda_appuntamenti a')
        ->select("
            a.id_appuntamento,
            a.id_slot,
            a.id_dot,
            a.id_paziente,
            {$appointmentClientSelect}
            a.cognome,
            a.nome,
            a.telefono,
            a.cellulare,
            a.email,
            a.note,
            a.motivo_visita,
            a.indirizzo_visita,
            a.comune_visita,
            a.stato,
            s.data_slot,
            TIME_FORMAT(s.ora_inizio, '%H:%i') AS ora_inizio_label,
            TIME_FORMAT(s.ora_fine, '%H:%i') AS ora_fine_label,
            s.ora_inizio,
            s.ora_fine
        ")
        ->join('dap11_agenda_slot s', 's.id_slot = a.id_slot', 'inner')
        ->where('a.id_dot', $idDot)
        ->where('s.data_slot', $dataSorgente)
        ->where('a.stato <>', 'ANNULLATO')
        ->where('TIME(s.ora_inizio) >=', $this->normalizeTime($oraInizio))
        ->where('TIME(s.ora_fine) <=', $this->normalizeTime($oraFine))
        ->orderBy('s.ora_inizio', 'ASC')
        ->get()
        ->getResultArray();

    if (empty($sourceAppointments)) {
        throw new \Exception('Nessun appuntamento trovato nella fascia oraria del giorno selezionato.');
    }

    $startDate = new \DateTime($dataSorgente);
    $limitDate = new \DateTime($dataFine);

    $creati = 0;
    $giorniBloccatiMap = [];
    $giaPieni = [];
    $slotNonTrovati = [];

    $this->db->transStart();

    $currentDate = clone $startDate;
    $currentDate->modify('+7 days');

    while ($currentDate <= $limitDate) {
        $dataDest = $currentDate->format('Y-m-d');

        if ($this->isGiornoBloccato($idDot, $dataDest)) {
            $giorniBloccatiMap[$dataDest] = $dataDest;
            $currentDate->modify('+7 days');
            continue;
        }

        foreach ($sourceAppointments as $app) {
            $oraInizioSrc = date('H:i:s', strtotime((string)$app['ora_inizio']));
            $oraFineSrc   = date('H:i:s', strtotime((string)$app['ora_fine']));

            $slotDest = $this->db->table('dap11_agenda_slot')
                ->select('id_slot, stato, data_slot, ora_inizio, ora_fine')
                ->where('id_dot', $idDot)
                ->where('data_slot', $dataDest)
                ->where('TIME(ora_inizio)', $oraInizioSrc)
                ->where('TIME(ora_fine)', $oraFineSrc)
                ->orderBy('ora_inizio', 'ASC')
                ->get()
                ->getRowArray();

            if (!$slotDest) {
                $slotNonTrovati[] = [
                    'data'     => $dataDest,
                    'ora_inizio'=> substr($oraInizioSrc, 0, 5),
                    'ora_fine' => substr($oraFineSrc, 0, 5),
                    'paziente' => trim(($app['cognome'] ?? '') . ' ' . ($app['nome'] ?? '')),
                ];
                continue;
            }

            $giaOccupato = $this->db->table('dap12_agenda_appuntamenti')
                ->where('id_slot', (int)$slotDest['id_slot'])
                ->where('stato <>', 'ANNULLATO')
                ->countAllResults();

            if ($giaOccupato > 0 || ($slotDest['stato'] ?? '') !== 'LIBERO') {
                $giaPieni[] = [
                    'data'      => $dataDest,
                    'ora_inizio'=> substr($oraInizioSrc, 0, 5),
                    'ora_fine'  => substr($oraFineSrc, 0, 5),
                    'id_slot'   => (int)$slotDest['id_slot'],
                    'paziente'  => trim(($app['cognome'] ?? '') . ' ' . ($app['nome'] ?? '')),
                ];
                continue;
            }

            $insert = [
                'id_slot'          => (int)$slotDest['id_slot'],
                'id_dot'           => $idDot,
                'id_paziente'      => !empty($app['id_paziente']) ? (int)$app['id_paziente'] : null,
                'cognome'          => (string)($app['cognome'] ?? ''),
                'nome'             => (string)($app['nome'] ?? ''),
                'telefono'         => (string)($app['telefono'] ?? ''),
                'cellulare'        => (string)($app['cellulare'] ?? ''),
                'email'            => (string)($app['email'] ?? ''),
                'note'             => $app['note'] ?? null,
                'motivo_visita'    => $app['motivo_visita'] ?? null,
                'indirizzo_visita' => $app['indirizzo_visita'] ?? null,
                'comune_visita'    => $app['comune_visita'] ?? null,
                'stato'            => 'CONFERMATO',
                'created_by'       => $userId,
                'created_at'       => date('Y-m-d H:i:s'),
            ];
            if ($hasAppointmentClientColumn) {
                $insert['id_client'] = !empty($app['id_client']) ? (int)$app['id_client'] : null;
            }

            $this->db->table('dap12_agenda_appuntamenti')->insert($insert);

            $this->db->table('dap11_agenda_slot')
                ->where('id_slot', (int)$slotDest['id_slot'])
                ->update([
                    'stato'      => 'PRENOTATO',
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);

            $creati++;
        }

        $currentDate->modify('+7 days');
    }

    $this->db->transComplete();

    if (!$this->db->transStatus()) {
        throw new \Exception('Errore durante la copia settimanale degli appuntamenti.');
    }

    return [
        'appuntamenti_sorgente' => count($sourceAppointments),
        'creati'                => $creati,
        'giorni_bloccati'       => array_values($giorniBloccatiMap),
        'slot_non_trovati'      => $slotNonTrovati,
        'gia_pieni'             => $giaPieni,
    ];
}
public function getSmsAppointmentsEnabledByDoctor(?int $idDot = null): array
{
    $cognomeExpr = $this->decryptCharExpr('d.cognome');
    $nomeExpr = $this->decryptCharExpr('d.nome');

    $select = "
        s.id_sms,
        s.id_dot,
        s.conferma,
        {$cognomeExpr} AS cognome,
        {$nomeExpr} AS nome
    ";

    $sql = "
        SELECT $select
        FROM dap39_sms_dot s
        LEFT JOIN dap03_personale d
          ON d.legacy_id_dot = s.id_dot
         AND d.tipo IN (1, 2)
    ";

    if (!empty($idDot)) {
        $idDot = (int)$idDot;
        $sql .= " WHERE s.id_dot = $idDot ";
    }

    $sql .= " ORDER BY cognome ASC, nome ASC ";

    // 🔥 stampa query
   // echo $sql;
    //exit;

    return $this->db->query($sql)->getResultArray();
}
public function getSmsAppointmentConfigByDoctor(int $idDot): ?array
{
    $row = $this->db->table('dap39_sms_dot')
        ->where('id_dot', $idDot)
        ->get()
        ->getRowArray();

    return $row ?: null;
}
public function saveSmsAppointmentConfig(int $idDot, int $conferma): bool
{
    $existing = $this->db->table('dap39_sms_dot')
        ->where('id_dot', $idDot)
        ->get()
        ->getRowArray();

    if ($existing) {
        return (bool)$this->db->table('dap39_sms_dot')
            ->where('id_dot', $idDot)
            ->update([
                'conferma' => $conferma ? 1 : 0,
            ]);
    }

    return (bool)$this->db->table('dap39_sms_dot')->insert([
        'id_dot'    => $idDot,
        'conferma'  => $conferma ? 1 : 0,
    ]);
}
public function disableSmsAppointmentConfig(int $idSms): bool
{
    return (bool)$this->db->table('dap39_sms_dot')
        ->where('id_sms', $idSms)
        ->delete();
}
public function getSmsAppointmentConfigById(int $idSms): ?array
{
    $row = $this->db->table('dap39_sms_dot')
        ->where('id_sms', $idSms)
        ->get()
        ->getRowArray();

    return $row ?: null;
}
public function getFerieByDoctorPaginate(int $idDot, int $perPage = 20, int $page = 1): array
{
    $page = max(1, $page);
    $perPage = max(1, $perPage);
    $offset = ($page - 1) * $perPage;
    $primaryKey = $this->getBlockedDayPrimaryKey();

    $builder = $this->db->table('dap21_agenda_giorni_bloccati gb')
->select("gb.{$primaryKey} AS id_giorno_bloccato, gb.id_dot, gb.data_agenda, gb.motivo, gb.created_by, gb.created_at", false)
        ->where('gb.id_dot', $idDot);

    $total = (clone $builder)->countAllResults(false);

    $rows = $builder
        ->orderBy('gb.data_agenda', 'DESC')
        ->limit($perPage, $offset)
        ->get()
        ->getResultArray();

    $lastPage = max(1, (int)ceil($total / $perPage));

    return [
        'rows' => $rows,
        'total' => $total,
        'page' => min($page, $lastPage),
        'perPage' => $perPage,
        'lastPage' => $lastPage,
    ];
}

public function findGiornoFerieById(int $idGiornoBloccato): ?array
{
    $primaryKey = $this->getBlockedDayPrimaryKey();

    $row = $this->db->table('dap21_agenda_giorni_bloccati')
        ->select("{$primaryKey} AS id_giorno_bloccato, id_dot, data_agenda, motivo, created_by, created_at", false)
        ->where($primaryKey, $idGiornoBloccato)
        ->get()
        ->getRowArray();

    return $row ?: null;
}

public function findGiorniFerieByIds(array $ids): array
{
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (empty($ids)) {
        return [];
    }

    $primaryKey = $this->getBlockedDayPrimaryKey();

    return $this->db->table('dap21_agenda_giorni_bloccati')
        ->select("{$primaryKey} AS id_giorno_bloccato, id_dot, data_agenda, motivo, created_by, created_at", false)
        ->whereIn($primaryKey, $ids)
        ->get()
        ->getResultArray();
}
public function deleteGiornoFerie(int $idGiornoBloccato): bool
{
    $row = $this->findGiornoFerieById($idGiornoBloccato);

    if (!$row) {
        throw new \Exception('Giorno ferie non trovato.');
    }

    $idDot = (int)$row['id_dot'];
    $dataAgenda = (string)$row['data_agenda'];
    $primaryKey = $this->getBlockedDayPrimaryKey();

    $this->db->transStart();

    $this->db->table('dap21_agenda_giorni_bloccati')
        ->where($primaryKey, $idGiornoBloccato)
        ->delete();

    $this->db->table('dap11_agenda_slot')
        ->where('id_dot', $idDot)
        ->where('data_slot', $dataAgenda)
        ->where('stato', 'CHIUSO')
        ->update([
            'stato'      => 'LIBERO',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

    $this->db->transComplete();

    if (!$this->db->transStatus()) {
        throw new \Exception('Errore durante l\'eliminazione del giorno ferie.');
    }

    return true;
}
public function deleteGiorniFerieByIds(array $ids): array
{
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (empty($ids)) {
        throw new \Exception('Nessun giorno ferie selezionato.');
    }

    $rows = $this->findGiorniFerieByIds($ids);

    if (empty($rows)) {
        throw new \Exception('Nessun record trovato.');
    }

    $primaryKey = $this->getBlockedDayPrimaryKey();

    $this->db->transStart();

    foreach ($rows as $row) {
        $this->db->table('dap21_agenda_giorni_bloccati')
            ->where($primaryKey, (int)$row['id_giorno_bloccato'])
            ->delete();

        $this->db->table('dap11_agenda_slot')
            ->where('id_dot', (int)$row['id_dot'])
            ->where('data_slot', (string)$row['data_agenda'])
            ->where('stato', 'CHIUSO')
            ->update([
                'stato'      => 'LIBERO',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }

    $this->db->transComplete();

    if (!$this->db->transStatus()) {
        throw new \Exception('Errore durante l\'eliminazione multipla delle ferie.');
    }

    return [
        'eliminati' => count($rows),
    ];
}
public function sbloccaFeriePeriodo(array $payload): array
{
    $idDot      = (int)($payload['id_dot'] ?? 0);
    $dataInizio = trim((string)($payload['data_inizio'] ?? ''));
    $dataFine   = trim((string)($payload['data_fine'] ?? ''));

    if ($idDot <= 0) {
        throw new \Exception('Medico non valido.');
    }

    if ($dataInizio === '' || $dataFine === '') {
        throw new \Exception('Seleziona data inizio e data fine.');
    }

    if ($dataFine < $dataInizio) {
        throw new \Exception('La data fine deve essere uguale o successiva alla data inizio.');
    }

    $inizioTs = strtotime($dataInizio);
    $fineTs   = strtotime($dataFine);

    $totali = 0;
    $sbloccati = 0;
    $nonBloccati = [];

    $this->db->transStart();

    for ($ts = $inizioTs; $ts <= $fineTs; $ts = strtotime('+1 day', $ts)) {
        $data = date('Y-m-d', $ts);
        $totali++;

        if (!$this->isGiornoBloccato($idDot, $data)) {
            $nonBloccati[] = $data;
            continue;
        }

        $this->db->table('dap21_agenda_giorni_bloccati')
            ->where('id_dot', $idDot)
            ->where('data_agenda', $data)
            ->delete();

        $this->db->table('dap11_agenda_slot')
            ->where('id_dot', $idDot)
            ->where('data_slot', $data)
            ->where('stato', 'CHIUSO')
            ->update([
                'stato'      => 'LIBERO',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        $sbloccati++;
    }

    $this->db->transComplete();

    if (!$this->db->transStatus()) {
        throw new \Exception('Errore durante lo sblocco ferie.');
    }

    return [
        'totali'       => $totali,
        'sbloccati'    => $sbloccati,
        'non_bloccati' => $nonBloccati,
    ];
}
public function countPrenotazioniAttiveByDoctorDate(int $idDot, string $dataAgenda): int
{
    return $this->db->table('dap11_agenda_slot s')
        ->join(
            'dap12_agenda_appuntamenti a',
            'a.id_slot = s.id_slot AND a.stato <> "ANNULLATO"',
            'inner'
        )
        ->where('s.id_dot', $idDot)
        ->where('s.data_slot', $dataAgenda)
        ->countAllResults();
}
   public function isAdmin(int $idUser): bool
{
    return $this->getRuoloOperatore($idUser) === self::RUOLO_ADMIN;
}

   public function isSegreteria(int $idUser): bool
{
    return $this->getRuoloOperatore($idUser) === self::RUOLO_SEGRETERIA;
}

public function isDottore(int $idUser): bool
{
    return $this->getRuoloOperatore($idUser) === self::RUOLO_DOTTORE;
}

public function hasFullAgendaVisibility(int $idUser): bool
{
    $context = $this->getAgendaUserContext($idUser);
    $idRuo = $this->getEffectiveAgendaRoleFromContext($context);

    return in_array($idRuo, [self::RUOLO_ADMIN, self::RUOLO_SEGRETERIA], true);
}

private function hasFullAgendaDoctorVisibility(int $idUser): bool
{
    return $this->hasFullAgendaVisibility($idUser);
}

private function getFarDoctorIdsFromLegacyVisibility(int $idUser): array
{
    $operatorIds = $this->getAgendaOperatorIdsForUser($idUser);
    if (empty($operatorIds)) {
        return [];
    }

    $builder = $this->db->table($this->getAgendaVisibilityTable())
        ->select('id_dot');

    if (count($operatorIds) === 1) {
        $builder->where('id_ope', $operatorIds[0]);
    } else {
        $builder->whereIn('id_ope', $operatorIds);
    }

    $rows = $builder->get()->getResultArray();

    $ids = array_map(static fn(array $row): int => (int)($row['id_dot'] ?? 0), $rows);
    $ids = array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0)));
    sort($ids);

    return $ids;
}

public function bloccaFeriePeriodo(array $payload, int $idUser): array
{
    $idDot      = (int)($payload['id_dot'] ?? 0);
    $dataInizio = trim((string)($payload['data_inizio'] ?? ''));
    $dataFine   = trim((string)($payload['data_fine'] ?? ''));
    $motivo     = trim((string)($payload['motivo'] ?? 'Ferie'));

    if ($idDot <= 0) {
        throw new \Exception('Medico non valido.');
    }

    if ($dataInizio === '' || $dataFine === '') {
        throw new \Exception('Seleziona data inizio e data fine.');
    }

    if ($dataFine < $dataInizio) {
        throw new \Exception('La data fine deve essere uguale o successiva alla data inizio.');
    }

    $inizioTs = strtotime($dataInizio);
    $fineTs   = strtotime($dataFine);

    if ($inizioTs === false || $fineTs === false) {
        throw new \Exception('Date non valide.');
    }

    $totali = 0;
    $bloccati = 0;
    $giaBloccati = [];
    $conPrenotazioni = [];
    $giorniInseriti = [];

    $this->db->transStart();

    for ($ts = $inizioTs; $ts <= $fineTs; $ts = strtotime('+1 day', $ts)) {
        $data = date('Y-m-d', $ts);
        $totali++;

        if ($this->isGiornoBloccato($idDot, $data)) {
            $giaBloccati[] = $data;
            continue;
        }

        $prenotazioniAttive = $this->countPrenotazioniAttiveByDoctorDate($idDot, $data);
        if ($prenotazioniAttive > 0) {
            $conPrenotazioni[] = [
                'data' => $data,
                'prenotazioni' => $prenotazioniAttive,
            ];
            continue;
        }

        $this->db->table('dap21_agenda_giorni_bloccati')->insert([
            'id_dot'      => $idDot,
            'data_agenda' => $data,
            'motivo'      => $motivo !== '' ? $motivo : 'Ferie',
            'created_by'  => $idUser,
        ]);

        $this->db->table('dap11_agenda_slot')
            ->where('id_dot', $idDot)
            ->where('data_slot', $data)
            ->whereIn('stato', ['LIBERO', 'BLOCCATO'])
            ->update([
                'stato'      => 'CHIUSO',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        $bloccati++;
        $giorniInseriti[] = $data;
    }

    $this->db->transComplete();

    if (!$this->db->transStatus()) {
        throw new \Exception('Errore durante il salvataggio delle ferie.');
    }

    return [
        'totali'            => $totali,
        'bloccati'          => $bloccati,
        'inseriti'          => $giorniInseriti,
        'gia_bloccati'      => $giaBloccati,
        'con_prenotazioni'  => $conPrenotazioni,
    ];
}
public function copyPatientOnFreeSlots(array $filters, int $userId): array
{
    $idDot      = (int)($filters['id_dot'] ?? 0);
    $data       = (string)($filters['data'] ?? '');
    $oraInizio  = trim((string)($filters['ora_inizio'] ?? ''));
    $oraFine    = trim((string)($filters['ora_fine'] ?? ''));
    $idPaziente = (int)($filters['id_paziente'] ?? 0);

    if ($idDot <= 0 || $data === '' || $oraInizio === '' || $oraFine === '' || $idPaziente <= 0) {
        throw new \Exception('Parametri mancanti.');
    }

    $paziente = $this->getAgendaPatientRow($idPaziente, $idDot, $userId);

    if (!$paziente) {
        throw new \Exception('Paziente non trovato per il medico selezionato.');
    }

    $slots = $this->db->table('dap11_agenda_slot')
        ->where('id_dot', $idDot)
        ->where('data_slot', $data)
        ->where('TIME(ora_inizio) >=', $this->normalizeTime($oraInizio))
        ->where('TIME(ora_fine) <=', $this->normalizeTime($oraFine))
        ->where('stato', 'LIBERO')
        ->orderBy('ora_inizio', 'ASC')
        ->get()
        ->getResultArray();

    if (empty($slots)) {
        throw new \Exception('Nessuno slot libero trovato nella fascia selezionata.');
    }

    $creati = 0;
    $saltati = 0;

    $this->db->transStart();

    foreach ($slots as $slot) {
        $giaOccupato = $this->db->table('dap12_agenda_appuntamenti')
            ->where('id_slot', (int)$slot['id_slot'])
            ->where('stato <>', 'ANNULLATO')
            ->countAllResults();

        if ($giaOccupato > 0) {
            $saltati++;
            continue;
        }

        $this->db->table('dap12_agenda_appuntamenti')->insert([
            'id_slot'          => (int)$slot['id_slot'],
            'id_dot'           => $idDot,
            'id_paziente'      => $idPaziente,
            'id_client'        => $idPaziente,
            'cognome'          => (string)($paziente['cognome'] ?? ''),
            'nome'             => (string)($paziente['nome'] ?? ''),
            'telefono'         => (string)($paziente['telefono'] ?? ''),
            'cellulare'        => (string)($paziente['cellulare'] ?? ''),
            'email'            => (string)($paziente['email'] ?? ''),
            'note'             => null,
            'motivo_visita'    => null,
            'indirizzo_visita' => null,
            'comune_visita'    => null,
            'stato'            => 'CONFERMATO',
            'created_by'       => $userId,
            'created_at'       => date('Y-m-d H:i:s'),
        ]);

        $this->db->table('dap11_agenda_slot')
            ->where('id_slot', (int)$slot['id_slot'])
            ->update([
                'stato'      => 'PRENOTATO',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        $creati++;
    }

    $this->db->transComplete();

    if (!$this->db->transStatus()) {
        throw new \Exception('Errore durante la creazione degli appuntamenti.');
    }

    return [
        'totale_slot_liberi' => count($slots),
        'creati'             => $creati,
        'saltati'            => $saltati,
    ];
}

public function getOrariAgendaByDoctorAndDate(int $idDot, string $data): array
{
    $rows = $this->db->table('dap11_agenda_slot')
        ->select("
            id_slot,
            TIME_FORMAT(ora_inizio, '%H:%i') AS ora_inizio_label,
            TIME_FORMAT(ora_fine, '%H:%i')   AS ora_fine_label,
            ora_inizio,
            ora_fine,
            stato
        ")
        ->where('id_dot', $idDot)
        ->where('data_slot', $data)
        ->where($this->buildConfiguredOrBookedSlotSql('dap11_agenda_slot'), null, false)
        ->orderBy('ora_inizio', 'ASC')
        ->get()
        ->getResultArray();

    return $rows;
}
protected function buildConfiguredDayExistsSql(string $slotTableAlias): string
{
    $hasFasceTable = $this->db->tableExists('dap10_agenda_config_fasce');
    $fasceCondition = $hasFasceTable
        ? '(EXISTS (SELECT 1 FROM dap10_agenda_config_fasce cf WHERE cf.id_config_giorno = cg.id_config_giorno) OR cg.mattina_attiva = 1 OR cg.pomeriggio_attiva = 1)'
        : '(cg.mattina_attiva = 1 OR cg.pomeriggio_attiva = 1)';

    return "EXISTS (
        SELECT 1
        FROM dap10_agenda_config c
        INNER JOIN dap10_agenda_config_giorni cg
            ON cg.id_config = c.id_config
        WHERE c.id_dot = {$slotTableAlias}.id_dot
          AND c.attiva = 1
          AND c.data_inizio <= {$slotTableAlias}.data_slot
          AND (c.data_fine IS NULL OR c.data_fine >= {$slotTableAlias}.data_slot)
          AND cg.giorno_settimana = ((DAYOFWEEK({$slotTableAlias}.data_slot) + 5) % 7) + 1
          AND c.id_config = (
              SELECT MAX(c2.id_config)
              FROM dap10_agenda_config c2
              WHERE c2.id_dot = {$slotTableAlias}.id_dot
                AND c2.attiva = 1
                AND c2.data_inizio <= {$slotTableAlias}.data_slot
                AND (c2.data_fine IS NULL OR c2.data_fine >= {$slotTableAlias}.data_slot)
          )
          AND cg.giorno_libero = 0
          AND {$fasceCondition}
    )";
}

protected function buildConfiguredOrBookedSlotSql(string $slotTableAlias): string
{
    $configuredSql = $this->buildConfiguredDayExistsSql($slotTableAlias);

    return "(
        {$configuredSql}
        OR EXISTS (
            SELECT 1
            FROM dap12_agenda_appuntamenti a_vis
            WHERE a_vis.id_slot = {$slotTableAlias}.id_slot
              AND a_vis.stato <> 'ANNULLATO'
        )
    )";
}

public function canManageMenuRoles(int $idOpe): bool
{
    return $this->getRuoloOperatore($idOpe) === self::RUOLO_ADMIN;
}

public function getRuoliAgenda(): array
{
    return array_map(static function (array $row): array {
        return [
            'id_ruo' => (int)($row['id_ruo'] ?? 0),
            'des_ruo' => (string)($row['des_ruo'] ?? ''),
        ];
    }, $this->getAgendaRoleOptions());
}

public function getAgendaMenuTreeWithRolePermissions(int $idRuo): array
{
    $rows = $this->db->table('dap17_agenda_menu m')
        ->select("
            m.id_menu,
            m.id_menu_padre,
            m.codice,
            m.tipo_voce,
            m.label_menu,
            m.icona,
            m.rotta,
            m.ordinamento,
            m.attivo,
            CASE
                WHEN p.id_perm IS NOT NULL AND p.visibile = 1 THEN 1
                ELSE 0
            END AS checked
        ", false)
        ->join(
            'dap18_agenda_menu_permessi p',
            'p.id_menu = m.id_menu AND p.id_ruo = ' . (int)$idRuo . ' AND p.id_ope IS NULL',
            'left'
        )
        ->where('m.attivo', 1)
        ->orderBy('m.id_menu_padre', 'ASC')
        ->orderBy('m.ordinamento', 'ASC')
        ->orderBy('m.label_menu', 'ASC')
        ->get()
        ->getResultArray();

    $byParent = [];

    foreach ($rows as $row) {
        $parentId = empty($row['id_menu_padre']) ? 0 : (int)$row['id_menu_padre'];
        $row['children'] = [];
        $byParent[$parentId][] = $row;
    }

    $buildTree = function ($parentId) use (&$buildTree, $byParent) {
        $items = $byParent[$parentId] ?? [];

        foreach ($items as &$item) {
            $item['children'] = $buildTree((int)$item['id_menu']);
        }
        unset($item);

        return $items;
    };

    return $buildTree(0);
}

public function saveAgendaMenuPermissionsByRole(int $idRuo, array $idMenu): bool
{
    $idMenu = array_values(array_unique(array_filter(array_map('intval', $idMenu), static fn($v) => $v > 0)));

    $this->db->transStart();

    $this->db->table('dap18_agenda_menu_permessi')
        ->where('id_ruo', $idRuo)
        ->where('id_ope IS NULL', null, false)
        ->delete();

    if (!empty($idMenu)) {
        $insert = [];

        foreach ($idMenu as $id) {
            $insert[] = [
                'id_menu'   => $id,
                'id_ruo'    => $idRuo,
                'id_ope'    => null,
                'visibile'  => 1,
            ];
        }

        $this->db->table('dap18_agenda_menu_permessi')->insertBatch($insert);
    }

    $this->db->transComplete();

    return $this->db->transStatus();
}



private function normalizeTime(string $time): string
{
    $time = trim($time);

    if ($time === '') {
        return '';
    }

    if (preg_match('/^\d{2}:\d{2}$/', $time)) {
        return $time . ':00';
    }

    return $time;
}

private function getAgendaPatientRow(int $idPaziente, int $idDot, int $actingUserId = 0): ?array
{
    if ($idPaziente <= 0 || $idDot <= 0) {
        return null;
    }

    $row = (new PazientiModel())->getPazienteByDoctor($idPaziente, $idDot, $actingUserId);
    if (!$row) {
        return null;
    }

    return [
        'id_paziente' => (int)($row['id_paziente'] ?? 0),
        'id_client' => (int)($row['id_paziente'] ?? 0),
        'nome' => (string)($row['nome'] ?? ''),
        'cognome' => (string)($row['cognome'] ?? ''),
        'telefono' => (string)($row['telefono'] ?? ''),
        'cellulare' => (string)($row['cellulare'] ?? ''),
        'email' => (string)($row['email'] ?? ''),
        'paz_spec' => (string)($row['paz_spec'] ?? ''),
    ];
}
public function canManageVisibility(int $idUser): bool
{
    return $this->isAdmin($idUser);
}

public function getIdDotByOperatore(int $idUser): int
{
    $context = $this->getAgendaUserContext($idUser);
    return (int)($context['legacy_id_dot'] ?? 0);
}

public function getDotIdsVisibili(int $idUser): array
{
    if ($idUser <= 0) {
        return [];
    }

    if ($this->hasFullAgendaDoctorVisibility($idUser)) {
        $rows = $this->getAgendaProfessionalsBuilder()
            ->get()
            ->getResultArray();

        $ids = array_map(static fn(array $row): int => (int)($row['id_dot'] ?? 0), $rows);
        $ids = array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0)));
        sort($ids);

        return $ids;
    }

    $ids = [];

    $idDotSelf = $this->getIdDotByOperatore($idUser);
    if ($idDotSelf > 0) {
        $ids[] = $idDotSelf;
    }

    $ids = array_merge($ids, $this->getFarDoctorIdsFromLegacyVisibility($idUser));

    $ids = array_values(array_unique(array_filter($ids)));

    sort($ids);

    return $ids;
}

public function getMediciVisibili(int $idUser): array
{
    if ($idUser <= 0) {
        return [];
    }

    $builder = $this->getAgendaProfessionalsBuilder()
        ->orderBy($this->decryptCharExpr('p.cognome'), '', false)
        ->orderBy($this->decryptCharExpr('p.nome'), '', false);

    if (!$this->hasFullAgendaDoctorVisibility($idUser)) {
        $ids = $this->getDotIdsVisibili($idUser);
        if (empty($ids)) {
            return [];
        }

        $builder->whereIn('p.legacy_id_dot', $ids);
    }

    $rows = $builder->get()->getResult();

    return $this->normalizeLegacyObjectRows($rows, ['nome', 'cognome', 'label']);



    // vede sempre se stesso
    $dotSelf = $this->db->table('dap03_personale')
        ->select('id_dot')
        ->where('id_ope', $idUser)
        ->get()
        ->getRowArray();

    $myDot = (int)($dotSelf['id_dot'] ?? 0);

    if ($myDot > 0) {
        $ids[] = $myDot;
    }

    // vede quelli assegnati nella tabella visibilità
    $rows = $this->db->table($this->getAgendaVisibilityTable())
        ->select('id_dot')
        ->where('id_ope', $idUser)
        ->get()
        ->getResultArray();

    foreach ($rows as $row) {
        $ids[] = (int)$row['id_dot'];
    }

    $ids = array_values(array_unique(array_filter($ids)));

    if (empty($ids)) {
        return [];
    }

    return $this->db->table('dap03_personale')
        ->select("
            id_dot,
            nome,
            cognome,
            f_dom,
            CONCAT(cognome, ' ', nome) AS label
        ")
        ->whereIn('id_dot', $ids)
        ->orderBy('cognome', 'ASC')
        ->orderBy('nome', 'ASC')
        ->get()
        ->getResult();
}

public function canUserAccessDoctor(int $idUser, int $idDot): bool
{
    if ($idUser <= 0 || $idDot <= 0) {
        return false;
    }

    if (!$this->isAgendaProfessionalVisible($idDot)) {
        return false;
    }

    if ($this->hasFullAgendaDoctorVisibility($idUser)) {
        return true;
    }

    return in_array($idDot, $this->getDotIdsVisibili($idUser), true);

    $utente = $this->db->table('dap01_users')
        ->select('id_ruo')
        ->where('id_ope', $idUser)
        ->get()
        ->getRowArray();

    $idRuo = (int)($utente['id_ruo'] ?? 0);

    // Admin e segreteria vedono tutto
    if (in_array($idRuo, [1, 2], true)) {
        return true;
    }

    // Se il professionista collegato all'utente è lui stesso
    $dotSelf = $this->db->table('dap03_personale')
        ->select('id_dot')
        ->where('id_ope', $idUser)
        ->get()
        ->getRowArray();

    $myDot = (int)($dotSelf['id_dot'] ?? 0);

    if ($myDot > 0 && $myDot === $idDot) {
        return true;
    }

    // Controllo tabella visibilità
    $count = $this->db->table($this->getAgendaVisibilityTable())
        ->where('id_ope', $idUser)
        ->where('id_dot', $idDot)
        ->countAllResults();

    return $count > 0;
}

public function getOperatoriGestibiliPerVisibilita(): array
{
    $nomeExpr = $this->decryptCharExpr('p.nome');
    $cognomeExpr = $this->decryptCharExpr('p.cognome');

    $rows = $this->db->table('dap03_personale p')
        ->select("
            p.id_personale AS id_ope,
            CASE p.tipo
                WHEN 1 THEN 3
                WHEN 2 THEN 5
                ELSE 0
            END AS id_ruo,
            {$nomeExpr} AS nome,
            {$cognomeExpr} AS cognome,
            COALESCE(p.legacy_id_dot, 0) AS id_dot,
            CONCAT(
                TRIM(COALESCE({$cognomeExpr}, '')),
                ' ',
                TRIM(COALESCE({$nomeExpr}, ''))
            ) AS label_operatore
        ", false)
        ->whereIn('p.tipo', [1, 2])
        ->where('p.legacy_id_dot >', 0)
        ->orderBy($cognomeExpr, '', false)
        ->orderBy($nomeExpr, '', false)
        ->get()
        ->getResultArray();

    return $this->normalizeLegacyArrayRows($rows, ['nome', 'cognome', 'label_operatore']);
}

public function isOperatoreGestibilePerVisibilita(int $idOpe): bool
{
    if ($idOpe <= 0) {
        return false;
    }

    $row = $this->getAgendaOperatorRecord($idOpe);
    if ($row === null) {
        return false;
    }

    return in_array((int)$row['tipo'], [1, 2], true)
        && (int)($row['legacy_id_dot'] ?? 0) > 0;
}

public function getDottoriInfermieriAgenda(): array
{
    $nomeExpr = $this->decryptCharExpr('p.nome');
    $cognomeExpr = $this->decryptCharExpr('p.cognome');

    return $this->db->table('dap03_personale p')
        ->select("
            COALESCE(p.legacy_id_dot, 0) AS id_dot,
            p.id_personale AS id_ope,
            {$nomeExpr} AS nome,
            {$cognomeExpr} AS cognome,
            CASE p.tipo
                WHEN 1 THEN 3
                WHEN 2 THEN 5
                ELSE 0
            END AS id_ruo,
            CONCAT(TRIM({$cognomeExpr}), ' ', TRIM({$nomeExpr})) AS label
        ", false)
        ->whereIn('p.tipo', [1, 2])
        ->where('p.legacy_id_dot >', 0)
        ->orderBy($cognomeExpr, '', false)
        ->orderBy($nomeExpr, '', false)
        ->get()
        ->getResultArray();
}

public function getDotIdsAssegnatiAOpe(int $idOpe): array
{
    if ($idOpe <= 0) {
        return [];
    }

    $operatorIds = $this->getLegacyOperatorIdentifiersForPersonaleId($idOpe);
    if (empty($operatorIds)) {
        return [];
    }

    $builder = $this->db->table($this->getAgendaVisibilityTable())
        ->select('id_dot');

    if (count($operatorIds) === 1) {
        $builder->where('id_ope', $operatorIds[0]);
    } else {
        $builder->whereIn('id_ope', $operatorIds);
    }

    $rows = $builder->get()->getResultArray();
    $ids = array_map(static fn(array $row): int => (int)($row['id_dot'] ?? 0), $rows);
    $ids = array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0)));
    sort($ids);

    return $ids;
}

public function salvaVisibilitaOperatore(int $idOpe, array $idDots): bool
{
    if ($idOpe <= 0) {
        return false;
    }

    $operator = $this->getAgendaOperatorRecord($idOpe);
    if ($operator === null) {
        return false;
    }

    $canonicalOperatorId = (int)($operator['id_personale'] ?? 0);
    if ($canonicalOperatorId <= 0) {
        return false;
    }

    $idDots = array_values(array_unique(array_filter(array_map('intval', $idDots), static fn($v) => $v > 0)));
    $operatorIds = $this->getLegacyOperatorIdentifiersForPersonaleId($canonicalOperatorId);

    $this->db->transStart();

    $deleteBuilder = $this->db->table($this->getAgendaVisibilityTable());
    if (count($operatorIds) === 1) {
        $deleteBuilder->where('id_ope', $operatorIds[0]);
    } else {
        $deleteBuilder->whereIn('id_ope', $operatorIds);
    }
    $deleteBuilder->delete();

    foreach ($idDots as $idDot) {
        $this->db->table($this->getAgendaVisibilityTable())->insert([
            'id_ope' => $canonicalOperatorId,
            'id_dot' => $idDot,
        ]);
    }

    $this->db->transComplete();

    return $this->db->transStatus();
}

    public function getMenuVisible(): array
    {
        return $this->db->table('dap17_agenda_menu')
            ->where('attivo', 1)
            ->orderBy('ordinamento', 'ASC')
            ->get()
            ->getResult();
    }

    public function getRuoloOperatore(int $idUser): int
{
    $context = $this->getAgendaUserContext($idUser);
    return $this->getEffectiveAgendaRoleFromContext($context);
}
public function sbloccaGiorno(int $idDot, string $dataAgenda): bool
{
    $this->db->transStart();

    $this->db->table('dap21_agenda_giorni_bloccati')
        ->where('id_dot', $idDot)
        ->where('data_agenda', $dataAgenda)
        ->delete();

    $this->db->table('dap11_agenda_slot')
        ->where('id_dot', $idDot)
        ->where('data_slot', $dataAgenda)
        ->where('stato', 'CHIUSO')
        ->update([
            'stato'      => 'LIBERO',
            'updated_at' => date('Y-m-d H:i:s')
        ]);

    $this->db->transComplete();

    return $this->db->transStatus();
}
public function getNotaGiorno(int $idDot, string $dataAgenda): array
{
    $row = $this->db->table('dap23_agenda_nota_giorno')
        ->where('id_dot', $idDot)
        ->where('data_agenda', $dataAgenda)
        ->get()
        ->getRowArray();

    return $row ?: [];
}

public function saveNotaGiorno(int $idDot, string $dataAgenda, string $nota, int $userId): int
{
    $nota = trim($nota);

    $row = $this->db->table('dap23_agenda_nota_giorno')
        ->where('id_dot', $idDot)
        ->where('data_agenda', $dataAgenda)
        ->get()
        ->getRowArray();

    if ($row) {
        $this->db->table('dap23_agenda_nota_giorno')
            ->where('id_nota_giorno', (int)$row['id_nota_giorno'])
            ->update([
                'nota'       => $nota,
                'updated_by' => $userId,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

        return (int)$row['id_nota_giorno'];
    }

    $this->db->table('dap23_agenda_nota_giorno')->insert([
        'id_dot'      => $idDot,
        'data_agenda' => $dataAgenda,
        'nota'        => $nota,
        'created_by'  => $userId,
        'updated_by'  => $userId,
        'created_at'  => date('Y-m-d H:i:s'),
        'updated_at'  => date('Y-m-d H:i:s'),
    ]);

    return (int)$this->db->insertID();
}

public function canBloccareGiorno(int $idUser): bool
{
    $idRuo = $this->getRuoloOperatore($idUser);

    if ($idRuo <= 0) {
        return false;
    }

    $row = $this->db->table('dap22_agenda_permessi_azioni')
        ->select('puo_bloccare_giorno')
        ->where('id_ruo', $idRuo)
        ->get()
        ->getRowArray();

    return (int)($row['puo_bloccare_giorno'] ?? 0) === 1;
}

public function isGiornoBloccato(int $idDot, string $dataAgenda): bool
{
    return $this->db->table('dap21_agenda_giorni_bloccati')
        ->where('id_dot', $idDot)
        ->where('data_agenda', $dataAgenda)
        ->countAllResults() > 0;
}

public function getGiorniBloccatiMapForRange(int $idDot, string $fromDate, string $toDate): array
{
    return $this->getBlockedDayMapForRange('dap21_agenda_giorni_bloccati', $idDot, $fromDate, $toDate);
}

public function isMemoGiornoBloccato(int $idDot, string $dataAgenda): bool
{
    return $this->isModuleDayBlocked('dap37_block_memo', $this->memoBlockTableExists, $idDot, $dataAgenda);
}

public function getMemoGiorniBloccatiMapForRange(int $idDot, string $fromDate, string $toDate): array
{
    return $this->getOptionalBlockedDayMapForRange('dap37_block_memo', $this->memoBlockTableExists, $idDot, $fromDate, $toDate);
}

public function isDomiciliareGiornoBloccato(int $idDot, string $dataAgenda): bool
{
    return $this->isModuleDayBlocked('dap31_block_dom', $this->domBlockTableExists, $idDot, $dataAgenda);
}

public function getDomiciliareGiorniBloccatiMapForRange(int $idDot, string $fromDate, string $toDate): array
{
    return $this->getOptionalBlockedDayMapForRange('dap31_block_dom', $this->domBlockTableExists, $idDot, $fromDate, $toDate);
}

public function bloccaDomiciliareGiorno(int $idDot, string $dataAgenda): bool
{
    $this->ensureDomiciliaryBlockTableExists();

    if ($this->isDomiciliareGiornoBloccato($idDot, $dataAgenda)) {
        throw new \Exception('Le domiciliari risultano gia bloccate per questo giorno.');
    }

    $now = date('Y-m-d H:i:s');

    return (bool)$this->db->table('dap31_block_dom')->insert([
        'id_dot'      => $idDot,
        'data_agenda' => $dataAgenda,
        'created_at'  => $now,
        'updated_at'  => $now,
    ]);
}

public function sbloccaDomiciliareGiorno(int $idDot, string $dataAgenda): bool
{
    $this->ensureDomiciliaryBlockTableExists();

    $this->db->table('dap31_block_dom')
        ->where('id_dot', $idDot)
        ->where('data_agenda', $dataAgenda)
        ->delete();

    return true;
}

public function bloccaGiorno(int $idDot, string $dataAgenda, int $idUser, ?string $motivo = null): bool
{
    if ($this->isGiornoBloccato($idDot, $dataAgenda)) {
        throw new \Exception('La giornata risulta già bloccata.');
    }

    $hasPrenotati = $this->db->table('dap11_agenda_slot s')
        ->join('dap12_agenda_appuntamenti a', 'a.id_slot = s.id_slot', 'inner')
        ->where('s.id_dot', $idDot)
        ->where('s.data_slot', $dataAgenda)
        ->where('s.stato', 'PRENOTATO')
        ->countAllResults();

    // Existing appointments must not prevent blocking the whole day.
    if (false && $hasPrenotati > 0) {
        throw new \Exception('Non puoi bloccare il giorno perché ci sono appuntamenti già prenotati.');
    }

    $this->db->transStart();

    $this->db->table('dap21_agenda_giorni_bloccati')->insert([
        'id_dot'      => $idDot,
        'data_agenda' => $dataAgenda,
        'motivo'      => $motivo,
        'created_by'  => $idUser,
    ]);

    $this->db->table('dap11_agenda_slot')
        ->where('id_dot', $idDot)
        ->where('data_slot', $dataAgenda)
        ->whereIn('stato', ['LIBERO', 'BLOCCATO'])
        ->update([
            'stato'      => 'CHIUSO',
            'updated_at' => date('Y-m-d H:i:s')
        ]);

    $this->db->transComplete();

    return $this->db->transStatus();
}

private function isModuleDayBlocked(string $table, ?bool &$existsCache, int $idDot, string $dataAgenda): bool
{
    if ($idDot <= 0 || trim($dataAgenda) === '') {
        return false;
    }

    if ($existsCache === null) {
        $existsCache = $this->db->tableExists($table);
    }

    if ($existsCache !== true) {
        return false;
    }

    return $this->db->table($table)
        ->where('id_dot', $idDot)
        ->where('data_agenda', $dataAgenda)
        ->countAllResults() > 0;
}

private function getBlockedDayMapForRange(string $table, int $idDot, string $fromDate, string $toDate): array
{
    if ($idDot <= 0 || trim($fromDate) === '' || trim($toDate) === '') {
        return [];
    }

    $rows = $this->db->table($table)
        ->select('data_agenda')
        ->where('id_dot', $idDot)
        ->where('data_agenda >=', $fromDate)
        ->where('data_agenda <=', $toDate)
        ->get()
        ->getResultArray();

    $map = [];

    foreach ($rows as $row) {
        $dateKey = trim((string)($row['data_agenda'] ?? ''));
        if ($dateKey === '') {
            continue;
        }

        $map[$dateKey] = true;
    }

    return $map;
}

private function getOptionalBlockedDayMapForRange(
    string $table,
    ?bool &$existsCache,
    int $idDot,
    string $fromDate,
    string $toDate
): array {
    if ($idDot <= 0 || trim($fromDate) === '' || trim($toDate) === '') {
        return [];
    }

    if ($existsCache === null) {
        $existsCache = $this->db->tableExists($table);
    }

    if ($existsCache !== true) {
        return [];
    }

    return $this->getBlockedDayMapForRange($table, $idDot, $fromDate, $toDate);
}

private function ensureDomiciliaryBlockTableExists(): void
{
    if ($this->domBlockTableExists === null) {
        $this->domBlockTableExists = $this->db->tableExists('dap31_block_dom');
    }

    if ($this->domBlockTableExists !== true) {
        throw new \Exception('La tabella per il blocco delle domiciliari non e disponibile.');
    }
}
}
