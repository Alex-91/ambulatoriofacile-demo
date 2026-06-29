<?php

namespace App\Models;

use App\Services\AgendaVisitTypeSchemaService;
use CodeIgniter\Model;
use Exception;

class AgendaAppointmentModel extends Model
{
    protected $table = 'dap12_agenda_appuntamenti';
    protected $primaryKey = 'id_appuntamento';
    protected $db;

    /** @var array<string, bool> */
    private array $fieldExistsCache = [];
    private ?bool $hasAppointmentSlotLinkTable = null;
    private ?AgendaVisitTypeSchemaService $visitTypeSchemaService = null;

    public function __construct()
    {
        parent::__construct();
        $this->db = \Config\Database::connect();
    }

    public function saveAppointment(array $data): int
    {
        $idSlot = (int) ($data['id_slot'] ?? 0);
        $idDot = (int) ($data['id_dot'] ?? 0);
        $tokenLock = trim((string) ($data['token_lock'] ?? ''));
        $createdBy = !empty($data['created_by']) ? (int) $data['created_by'] : 0;
        $visitTypesFeatureEnabled = !empty($data['visit_types_feature_enabled']);

        if ($idSlot <= 0 || $idDot <= 0) {
            throw new Exception('Slot o dottore non valorizzati.');
        }

        if ($tokenLock === '') {
            throw new Exception('Lo slot non e piu disponibile. Riapri lo slot e riprova.');
        }

        (new AgendaLockModel())->cleanupExpiredLocks();

        $now = date('Y-m-d H:i:s');
        $lock = $this->loadActiveLock($tokenLock, $idSlot, $createdBy, $now);
        if (!$lock) {
            throw new Exception('Lo slot non e piu disponibile. Riapri lo slot e riprova.');
        }

        $slot = $this->loadSlotRow($idSlot);
        if (!$slot) {
            throw new Exception('Slot non trovato.');
        }

        $slotState = strtoupper(trim((string) ($slot['stato'] ?? '')));
        if ($slotState === 'PRENOTATO') {
            throw new Exception('Lo slot e gia prenotato.');
        }

        if ($slotState === 'CHIUSO') {
            throw new Exception('La giornata risulta bloccata.');
        }

        if ($this->slotHasActiveAppointment($idSlot)) {
            throw new Exception('Lo slot e gia prenotato.');
        }

        $slotDuration = $this->getSlotDurationMinutes($slot);
        $plan = $this->resolveVisitPlan($data, $slot, null, $visitTypesFeatureEnabled);
        $this->assertVisitTypeSchemaReady($plan, $slotDuration, $visitTypesFeatureEnabled);

        $coveredSlots = $this->resolveCoveredSlots(
            $slot,
            (int) $plan['duration_minutes'],
            0,
            $tokenLock
        );

        $insert = $this->buildAppointmentPayload($data, $plan, $coveredSlots, $createdBy, $now);

        if ($insert['cognome'] === '' || $insert['nome'] === '') {
            throw new Exception('Nome e cognome sono obbligatori.');
        }

        $coveredSlotIds = array_map(
            static fn(array $row): int => (int) ($row['id_slot'] ?? 0),
            $coveredSlots
        );

        $this->db->transStart();

        $this->db->table($this->table)->insert($insert);
        $idAppuntamento = (int) $this->db->insertID();

        $this->replaceAppointmentSlotLinks($idAppuntamento, $coveredSlotIds, $now);
        $this->setSlotsState($coveredSlotIds, 'PRENOTATO', $now);

        $this->db->table('dap14_agenda_lock')
            ->where('token_lock', $tokenLock)
            ->where('stato', 'ATTIVO')
            ->update([
                'stato' => 'RILASCIATO',
            ]);

        $this->db->transComplete();

        if (!$this->db->transStatus()) {
            throw new Exception('Errore durante il salvataggio della prenotazione.');
        }

        return $idAppuntamento;
    }

    public function updateAppointment(array $data): bool
    {
        $idAppuntamento = (int) ($data['id_appuntamento'] ?? 0);
        $visitTypesFeatureEnabled = !empty($data['visit_types_feature_enabled']);

        if ($idAppuntamento <= 0) {
            throw new Exception('ID appuntamento mancante.');
        }

        $appointment = $this->loadAppointmentRow($idAppuntamento);
        if (!$appointment) {
            throw new Exception('Appuntamento non trovato.');
        }

        $slot = $this->loadSlotRow((int) ($appointment['id_slot'] ?? 0));
        if (!$slot) {
            throw new Exception('Slot principale appuntamento non trovato.');
        }

        $slotDuration = $this->getSlotDurationMinutes($slot);
        $plan = $this->resolveVisitPlan($data, $slot, $appointment, $visitTypesFeatureEnabled);
        $this->assertVisitTypeSchemaReady($plan, $slotDuration, $visitTypesFeatureEnabled);

        $coveredSlots = $this->resolveCoveredSlots(
            $slot,
            (int) $plan['duration_minutes'],
            $idAppuntamento
        );
        $coveredSlotIds = array_map(
            static fn(array $row): int => (int) ($row['id_slot'] ?? 0),
            $coveredSlots
        );
        $previousSlotIds = $this->getAppointmentCoveredSlotIds($idAppuntamento);

        $update = $this->buildAppointmentPayload($data, $plan, $coveredSlots, 0, date('Y-m-d H:i:s'));

        unset($update['id_slot'], $update['id_dot'], $update['stato'], $update['created_at'], $update['created_by']);

        $update['updated_at'] = date('Y-m-d H:i:s');

        $this->db->transStart();

        $this->db->table($this->table)
            ->where('id_appuntamento', $idAppuntamento)
            ->update($update);

        $this->replaceAppointmentSlotLinks($idAppuntamento, $coveredSlotIds, $update['updated_at']);
        $this->setSlotsState($coveredSlotIds, 'PRENOTATO', $update['updated_at']);

        $slotIdsToRestore = array_values(array_diff($previousSlotIds, $coveredSlotIds));
        foreach ($slotIdsToRestore as $slotIdToRestore) {
            $this->restoreSlotState($slotIdToRestore, $update['updated_at']);
        }

        $this->db->transComplete();

        if (!$this->db->transStatus()) {
            throw new Exception('Errore durante l\'aggiornamento della prenotazione.');
        }

        return true;
    }

    public function deleteAppointment(int $idAppuntamento, int $userId): bool
    {
        $row = $this->loadAppointmentRow($idAppuntamento);
        if (!$row) {
            throw new Exception('Appuntamento non trovato.');
        }

        $timestamp = date('Y-m-d H:i:s');
        $coveredSlotIds = $this->getAppointmentCoveredSlotIds($idAppuntamento);

        $this->db->transStart();

        $updatePayload = [
            'stato' => 'ANNULLATO',
            'updated_at' => $timestamp,
        ];

        if ($this->appointmentTableHasField('updated_by')) {
            $updatePayload['updated_by'] = $userId > 0 ? $userId : null;
        }

        $this->db->table($this->table)
            ->where('id_appuntamento', $idAppuntamento)
            ->update($updatePayload);

        if ($this->appointmentSlotLinkTableExists()) {
            $this->db->table('dap45_agenda_appuntamenti_slot')
                ->where('id_appuntamento', $idAppuntamento)
                ->delete();
        }

        foreach ($coveredSlotIds as $slotId) {
            $this->restoreSlotState($slotId, $timestamp);
        }

        $this->db->transComplete();

        if (!$this->db->transStatus()) {
            $dbError = $this->db->error();
            log_message('error', 'AgendaAppointmentModel::deleteAppointment failed for id_appuntamento={id} user_id={user} code={code} message={message}', [
                'id' => $idAppuntamento,
                'user' => $userId,
                'code' => (string) ($dbError['code'] ?? ''),
                'message' => (string) ($dbError['message'] ?? ''),
            ]);

            throw new Exception('Errore durante l\'annullamento della prenotazione.');
        }

        return true;
    }

    private function buildAppointmentPayload(array $data, array $plan, array $coveredSlots, int $createdBy, string $timestamp): array
    {
        $lastCoveredSlot = end($coveredSlots);

        $payload = [
            'id_slot' => (int) ($coveredSlots[0]['id_slot'] ?? 0),
            'id_dot' => (int) ($data['id_dot'] ?? 0),
            'id_paziente' => !empty($data['id_paziente']) ? (int) $data['id_paziente'] : null,
            'cognome' => trim((string) ($data['cognome'] ?? '')),
            'nome' => trim((string) ($data['nome'] ?? '')),
            'telefono' => trim((string) ($data['telefono'] ?? '')),
            'cellulare' => trim((string) ($data['cellulare'] ?? '')),
            'email' => trim((string) ($data['email'] ?? '')),
            'note' => trim((string) ($data['note'] ?? '')),
            'motivo_visita' => trim((string) ($data['motivo_visita'] ?? '')),
            'indirizzo_visita' => trim((string) ($data['indirizzo_visita'] ?? '')),
            'comune_visita' => trim((string) ($data['comune_visita'] ?? '')),
            'stato' => 'CONFERMATO',
            'created_at' => $timestamp,
        ];

        if ($this->appointmentTableHasField('created_by')) {
            $payload['created_by'] = $createdBy > 0 ? $createdBy : null;
        }

        if ($this->appointmentTableHasField('id_client')) {
            $payload['id_client'] = !empty($data['id_client'])
                ? (int) $data['id_client']
                : (!empty($data['id_paziente']) ? (int) $data['id_paziente'] : null);
        }

        if ($this->appointmentTableHasField('id_tipo_visita')) {
            $payload['id_tipo_visita'] = !empty($plan['visit_type_id']) ? (int) $plan['visit_type_id'] : null;
        }

        if ($this->appointmentTableHasField('tipo_visita_label')) {
            $payload['tipo_visita_label'] = trim((string) ($plan['type_label'] ?? '')) !== ''
                ? trim((string) ($plan['type_label'] ?? ''))
                : null;
        }

        if ($this->appointmentTableHasField('durata_minuti')) {
            $payload['durata_minuti'] = (int) ($plan['duration_minutes'] ?? 0) > 0
                ? (int) $plan['duration_minutes']
                : null;
        }

        if ($this->appointmentTableHasField('ora_fine_appuntamento')) {
            $payload['ora_fine_appuntamento'] = !empty($lastCoveredSlot['ora_fine'])
                ? (string) $lastCoveredSlot['ora_fine']
                : null;
        }

        return $payload;
    }

    private function resolveVisitPlan(array $data, array $slot, ?array $existingAppointment, bool $visitTypesFeatureEnabled): array
    {
        $slotDuration = $this->getSlotDurationMinutes($slot);

        if ($visitTypesFeatureEnabled) {
            $selectedTypeId = (int) ($data['id_tipo_visita'] ?? 0);
            if ($selectedTypeId <= 0 && $existingAppointment !== null) {
                $selectedTypeId = (int) ($existingAppointment['id_tipo_visita'] ?? 0);
            }

            if ($selectedTypeId <= 0) {
                throw new Exception('Seleziona il tipo visita.');
            }

            $typeRow = (new AgendaVisitTypeModel())->findType($selectedTypeId);
            if (!$typeRow) {
                throw new Exception('Tipo visita non trovato.');
            }

            if ($existingAppointment === null && (int) ($typeRow['attivo'] ?? 0) !== 1) {
                throw new Exception('Il tipo visita selezionato non e attivo.');
            }

            return [
                'visit_type_id' => (int) ($typeRow['id_tipo_visita'] ?? 0),
                'type_label' => trim((string) ($typeRow['nome'] ?? '')),
                'duration_minutes' => (int) ($typeRow['durata_minuti'] ?? 0),
            ];
        }

        if ($existingAppointment !== null) {
            return [
                'visit_type_id' => (int) ($existingAppointment['id_tipo_visita'] ?? 0),
                'type_label' => trim((string) ($existingAppointment['tipo_visita_label'] ?? '')),
                'duration_minutes' => $this->resolveStoredAppointmentDuration($existingAppointment, $slotDuration),
            ];
        }

        return [
            'visit_type_id' => 0,
            'type_label' => '',
            'duration_minutes' => $slotDuration,
        ];
    }

    private function resolveStoredAppointmentDuration(array $appointment, int $fallbackDuration): int
    {
        $duration = (int) ($appointment['durata_minuti'] ?? 0);
        if ($duration > 0) {
            return $duration;
        }

        $startTimestamp = strtotime((string) ($appointment['slot_ora_inizio'] ?? ''));
        $endTimestamp = strtotime((string) ($appointment['ora_fine_appuntamento'] ?? ''));
        if ($startTimestamp !== false && $endTimestamp !== false && $endTimestamp > $startTimestamp) {
            return (int) round(($endTimestamp - $startTimestamp) / 60);
        }

        return max(1, $fallbackDuration);
    }

    private function resolveCoveredSlots(
        array $primarySlot,
        int $requiredDurationMinutes,
        int $ignoreAppointmentId = 0,
        string $allowedLockToken = ''
    ): array {
        if ($requiredDurationMinutes <= 0) {
            throw new Exception('Durata appuntamento non valida.');
        }

        $idDot = (int) ($primarySlot['id_dot'] ?? 0);
        $dataSlot = (string) ($primarySlot['data_slot'] ?? '');
        $primarySlotId = (int) ($primarySlot['id_slot'] ?? 0);
        $primaryStart = (string) ($primarySlot['ora_inizio'] ?? '');

        $rows = $this->db->table('dap11_agenda_slot')
            ->select('id_slot, id_dot, data_slot, ora_inizio, ora_fine, stato')
            ->where('id_dot', $idDot)
            ->where('data_slot', $dataSlot)
            ->where('ora_inizio >=', $primaryStart)
            ->orderBy('ora_inizio', 'ASC')
            ->get()
            ->getResultArray();

        $coveredSlots = [];
        $coveredDuration = 0;
        $expectedStart = $primaryStart;

        foreach ($rows as $row) {
            $currentStart = (string) ($row['ora_inizio'] ?? '');

            if ($coveredSlots === [] && (int) ($row['id_slot'] ?? 0) !== $primarySlotId) {
                continue;
            }

            if ($coveredSlots !== [] && $currentStart !== $expectedStart) {
                break;
            }

            $slotState = strtoupper(trim((string) ($row['stato'] ?? '')));
            if ($slotState === 'CHIUSO') {
                throw new Exception('La fascia richiesta include uno slot in una giornata bloccata.');
            }

            $slotId = (int) ($row['id_slot'] ?? 0);
            if ($slotId <= 0) {
                continue;
            }

            if ($this->slotHasActiveAppointment($slotId, $ignoreAppointmentId)) {
                throw new Exception('Non ci sono abbastanza slot consecutivi liberi per il tipo visita selezionato.');
            }

            if ($this->slotHasActiveLock($slotId, $allowedLockToken)) {
                throw new Exception('Uno degli slot consecutivi necessari e in modifica da un altro operatore.');
            }

            $slotDuration = $this->getSlotDurationMinutes($row);
            if ($slotDuration <= 0) {
                throw new Exception('Durata slot non valida nella fascia selezionata.');
            }

            $coveredSlots[] = $row;
            $coveredDuration += $slotDuration;
            $expectedStart = (string) ($row['ora_fine'] ?? '');

            if ($coveredDuration === $requiredDurationMinutes) {
                return $coveredSlots;
            }

            if ($coveredDuration > $requiredDurationMinutes) {
                throw new Exception('La durata del tipo visita non e compatibile con la griglia degli slot disponibili in questo punto dell agenda.');
            }
        }

        throw new Exception('Non ci sono abbastanza slot consecutivi liberi per il tipo visita selezionato.');
    }

    private function replaceAppointmentSlotLinks(int $idAppuntamento, array $slotIds, string $timestamp): void
    {
        if (!$this->appointmentSlotLinkTableExists()) {
            return;
        }

        $slotIds = array_values(array_unique(array_filter(array_map('intval', $slotIds))));

        $this->db->table('dap45_agenda_appuntamenti_slot')
            ->where('id_appuntamento', $idAppuntamento)
            ->delete();

        if ($slotIds === []) {
            return;
        }

        $insert = [];
        foreach ($slotIds as $index => $slotId) {
            $insert[] = [
                'id_appuntamento' => $idAppuntamento,
                'id_slot' => $slotId,
                'posizione' => $index + 1,
                'is_primario' => $index === 0 ? 1 : 0,
                'created_at' => $timestamp,
            ];
        }

        $this->db->table('dap45_agenda_appuntamenti_slot')->insertBatch($insert);
    }

    /**
     * @return array<int, int>
     */
    private function getAppointmentCoveredSlotIds(int $idAppuntamento): array
    {
        if ($idAppuntamento <= 0) {
            return [];
        }

        $row = $this->db->table($this->table)
            ->select('id_slot')
            ->where('id_appuntamento', $idAppuntamento)
            ->get()
            ->getRowArray();

        $baseSlotId = (int) ($row['id_slot'] ?? 0);

        if ($this->appointmentSlotLinkTableExists()) {
            $rows = $this->db->table('dap45_agenda_appuntamenti_slot')
                ->select('id_slot')
                ->where('id_appuntamento', $idAppuntamento)
                ->orderBy('posizione', 'ASC')
                ->orderBy('id_appuntamento_slot', 'ASC')
                ->get()
                ->getResultArray();

            if ($rows !== []) {
                $slotIds = array_values(array_filter(array_map(
                    static fn(array $row): int => (int) ($row['id_slot'] ?? 0),
                    $rows
                )));

                if ($baseSlotId > 0) {
                    array_unshift($slotIds, $baseSlotId);
                }

                return array_values(array_unique(array_filter($slotIds)));
            }
        }

        return $baseSlotId > 0 ? [$baseSlotId] : [];
    }

    private function restoreSlotState(int $idSlot, string $timestamp): void
    {
        if ($idSlot <= 0) {
            return;
        }

        $slot = $this->loadSlotRow($idSlot);
        if (!$slot) {
            return;
        }

        if ($this->slotHasActiveAppointment($idSlot)) {
            $targetState = 'PRENOTATO';
        } elseif ($this->slotHasActiveLock($idSlot)) {
            $targetState = 'BLOCCATO';
        } else {
            $isDayBlocked = $this->db->table('dap21_agenda_giorni_bloccati')
                ->where('id_dot', (int) ($slot['id_dot'] ?? 0))
                ->where('data_agenda', (string) ($slot['data_slot'] ?? ''))
                ->countAllResults() > 0;

            $targetState = $isDayBlocked ? 'CHIUSO' : 'LIBERO';
        }

        $this->db->table('dap11_agenda_slot')
            ->where('id_slot', $idSlot)
            ->update([
                'stato' => $targetState,
                'updated_at' => $timestamp,
            ]);
    }

    /**
     * @param array<int, int> $slotIds
     */
    private function setSlotsState(array $slotIds, string $state, string $timestamp): void
    {
        $slotIds = array_values(array_unique(array_filter(array_map('intval', $slotIds))));
        if ($slotIds === []) {
            return;
        }

        $this->db->table('dap11_agenda_slot')
            ->whereIn('id_slot', $slotIds)
            ->update([
                'stato' => $state,
                'updated_at' => $timestamp,
            ]);
    }

    private function slotHasActiveAppointment(int $idSlot, int $ignoreAppointmentId = 0): bool
    {
        if ($idSlot <= 0) {
            return false;
        }

        if ($this->appointmentSlotLinkTableExists()) {
            $sql = "
                SELECT a.id_appuntamento
                FROM {$this->table} a
                WHERE a.stato <> 'ANNULLATO'
                  AND (
                        a.id_slot = ?
                        OR EXISTS (
                            SELECT 1
                            FROM dap45_agenda_appuntamenti_slot rel
                            WHERE rel.id_appuntamento = a.id_appuntamento
                              AND rel.id_slot = ?
                        )
                  )
            ";
            $params = [$idSlot, $idSlot];

            if ($ignoreAppointmentId > 0) {
                $sql .= ' AND a.id_appuntamento <> ?';
                $params[] = $ignoreAppointmentId;
            }

            $sql .= ' LIMIT 1';

            return $this->db->query($sql, $params)->getRowArray() !== null;
        }

        $builder = $this->db->table($this->table)
            ->select('id_appuntamento')
            ->where('id_slot', $idSlot)
            ->where('stato <>', 'ANNULLATO');

        if ($ignoreAppointmentId > 0) {
            $builder->where('id_appuntamento <>', $ignoreAppointmentId);
        }

        return $builder->get(1)->getRowArray() !== null;
    }

    private function slotHasActiveLock(int $idSlot, string $allowedToken = ''): bool
    {
        if ($idSlot <= 0) {
            return false;
        }

        $builder = $this->db->table('dap14_agenda_lock')
            ->select('id_lock')
            ->where('id_slot', $idSlot)
            ->where('stato', 'ATTIVO')
            ->where('expires_at >=', date('Y-m-d H:i:s'));

        if ($allowedToken !== '') {
            $builder->where('token_lock <>', $allowedToken);
        }

        return $builder->get(1)->getRowArray() !== null;
    }

    private function loadActiveLock(string $tokenLock, int $idSlot, int $createdBy, string $timestamp): array
    {
        $builder = $this->db->table('dap14_agenda_lock')
            ->where('token_lock', $tokenLock)
            ->where('id_slot', $idSlot)
            ->where('stato', 'ATTIVO')
            ->where('expires_at >=', $timestamp);

        if ($createdBy > 0) {
            $builder->where('id_ope', $createdBy);
        }

        return $builder->get()->getRowArray() ?: [];
    }

    private function loadSlotRow(int $idSlot): array
    {
        if ($idSlot <= 0) {
            return [];
        }

        return $this->db->table('dap11_agenda_slot')
            ->where('id_slot', $idSlot)
            ->get()
            ->getRowArray() ?: [];
    }

    private function loadAppointmentRow(int $idAppuntamento): array
    {
        if ($idAppuntamento <= 0) {
            return [];
        }

        $select = 'a.*, s.data_slot, s.ora_inizio AS slot_ora_inizio, s.ora_fine AS slot_ora_fine';

        return $this->db->table($this->table . ' a')
            ->select($select)
            ->join('dap11_agenda_slot s', 's.id_slot = a.id_slot', 'left')
            ->where('a.id_appuntamento', $idAppuntamento)
            ->get()
            ->getRowArray() ?: [];
    }

    private function getSlotDurationMinutes(array $slot): int
    {
        $start = strtotime((string) ($slot['ora_inizio'] ?? ''));
        $end = strtotime((string) ($slot['ora_fine'] ?? ''));

        if ($start === false || $end === false || $end <= $start) {
            return 0;
        }

        return (int) round(($end - $start) / 60);
    }

    private function assertVisitTypeSchemaReady(array $plan, int $slotDuration, bool $visitTypesFeatureEnabled): void
    {
        $usesSpan = (int) ($plan['duration_minutes'] ?? 0) > $slotDuration;

        if ($visitTypesFeatureEnabled) {
            $this->ensureVisitTypeSchemaReady();

            foreach (['id_tipo_visita', 'tipo_visita_label', 'durata_minuti', 'ora_fine_appuntamento'] as $field) {
                if (!$this->appointmentTableHasField($field)) {
                    throw new Exception('La struttura del database non e aggiornata per gestire i tipi visita.');
                }
            }
        }

        if ($usesSpan && !$this->appointmentSlotLinkTableExists()) {
            throw new Exception('La struttura del database non e aggiornata per gestire appuntamenti su piu slot.');
        }
    }

    private function appointmentSlotLinkTableExists(): bool
    {
        if ($this->hasAppointmentSlotLinkTable === null) {
            $this->hasAppointmentSlotLinkTable = $this->db->tableExists('dap45_agenda_appuntamenti_slot');
        }

        return $this->hasAppointmentSlotLinkTable;
    }

    private function appointmentTableHasField(string $field): bool
    {
        if (!array_key_exists($field, $this->fieldExistsCache)) {
            $this->fieldExistsCache[$field] = $this->db->fieldExists($field, $this->table);
        }

        return $this->fieldExistsCache[$field];
    }

    private function ensureVisitTypeSchemaReady(): void
    {
        $this->visitTypeSchemaService ??= new AgendaVisitTypeSchemaService($this->db);
        $this->visitTypeSchemaService->ensureReady();
        $this->fieldExistsCache = [];
        $this->hasAppointmentSlotLinkTable = null;
    }
}
