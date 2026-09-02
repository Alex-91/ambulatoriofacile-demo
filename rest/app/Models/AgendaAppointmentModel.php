<?php

namespace App\Models;

use App\Services\AgendaSlotFragmentService;
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
    private ?AgendaSlotFragmentService $slotFragmentService = null;

    public function __construct(?\CodeIgniter\Database\BaseConnection $db = null)
    {
        parent::__construct($db);
        $this->db = $db ?? \Config\Database::connect();
    }

    public function saveAppointment(array $data): int
    {
        $idSlot = (int) ($data['id_slot'] ?? 0);
        $idDot = (int) ($data['id_dot'] ?? 0);
        $tokenLock = trim((string) ($data['token_lock'] ?? ''));
        $createdBy = !empty($data['created_by']) ? (int) $data['created_by'] : 0;
        $visitTypesFeatureEnabled = !empty($data['visit_types_feature_enabled']);
        $visitTypeRequired = !array_key_exists('visit_type_required', $data) || !empty($data['visit_type_required']);
        $customTimeFeatureEnabled = !empty($data['custom_appointment_time_feature_enabled']);
        $residualSlotsFeatureEnabled = AgendaSlotFragmentService::isFeatureEnabled(
            $customTimeFeatureEnabled,
            !empty($data['custom_time_residual_slots_feature_enabled'])
        );
        $slotLockRequired = !array_key_exists('slot_lock_required', $data) || !empty($data['slot_lock_required']);

        if ($idSlot <= 0 || $idDot <= 0) {
            throw new Exception('Slot o dottore non valorizzati.');
        }

        if ($slotLockRequired && $tokenLock === '') {
            throw new Exception('Lo slot non è più disponibile. Riapri lo slot e riprova.');
        }

        if ($slotLockRequired) {
            (new AgendaLockModel())->cleanupExpiredLocks();
        }

        $now = date('Y-m-d H:i:s');
        if ($slotLockRequired) {
            $lock = $this->loadActiveLock($tokenLock, $idSlot, $createdBy, $now);
            if (!$lock) {
                throw new Exception('Lo slot non è più disponibile. Riapri lo slot e riprova.');
            }
        }

        $slot = $this->loadSlotRow($idSlot);
        if (!$slot) {
            throw new Exception('Slot non trovato.');
        }

        $slotState = strtoupper(trim((string) ($slot['stato'] ?? '')));
        if ($slotState === 'PRENOTATO') {
            throw new Exception('Lo slot e già prenotato.');
        }

        if ($slotState === 'CHIUSO') {
            throw new Exception('La giornata risulta bloccata.');
        }

        if ($this->slotHasActiveAppointment($idSlot)) {
            throw new Exception('Lo slot e già prenotato.');
        }

        $slotDuration = $this->getSlotDurationMinutes($slot);
        $plan = $this->resolveVisitPlan($data, $slot, null, $visitTypesFeatureEnabled, $visitTypeRequired);
        $this->assertVisitTypeSchemaReady($plan, $slotDuration, $visitTypesFeatureEnabled);

        $window = $this->resolveAppointmentWindow(
            $data,
            $slot,
            $plan,
            null,
            $customTimeFeatureEnabled,
            0,
            $tokenLock,
            $slotLockRequired
        );
        if (!empty($window['custom_start'])) {
            $plan['duration_minutes'] = (int) ($window['duration_minutes'] ?? 0);
        }
        $coveredSlots = $window['covered_slots'];
        $this->assertAppointmentSpanSchemaReady($coveredSlots);

        if (trim((string) ($data['cognome'] ?? '')) === '' || trim((string) ($data['nome'] ?? '')) === '') {
            throw new Exception('Nome e cognome sono obbligatori.');
        }

        $initialCoveredSlotIds = array_map(
            static fn(array $row): int => (int) ($row['id_slot'] ?? 0),
            $coveredSlots
        );

        $this->db->transBegin();

        try {
            $this->lockSlotRowsForUpdate($initialCoveredSlotIds);
            $this->assertCoveredSlotsAvailable($initialCoveredSlotIds, 0, $tokenLock, $slotLockRequired);

            if (
                !empty($window['custom_start'])
                && AgendaSlotFragmentService::shouldManageCustomWindow($residualSlotsFeatureEnabled)
            ) {
                $freshPrimarySlot = $this->loadSlotRow($idSlot);
                $freshCoveredSlots = $this->resolveCoveredSlotsForWindow(
                    $freshPrimarySlot,
                    (string) $window['custom_start'],
                    (string) $window['end'],
                    0,
                    $tokenLock,
                    $slotLockRequired
                );
                $this->assertCoveredSlotSetUnchanged($initialCoveredSlotIds, $freshCoveredSlots);
                $coveredSlots = $this->slotFragments()->splitForWindow(
                    $freshCoveredSlots,
                    (string) $window['custom_start'],
                    (string) $window['end'],
                    $now
                );
                $window['covered_slots'] = $coveredSlots;
            }

            $coveredSlotIds = array_map(
                static fn(array $row): int => (int) ($row['id_slot'] ?? 0),
                $coveredSlots
            );
            $this->assertAppointmentSpanSchemaReady($coveredSlots);
            $insert = $this->buildAppointmentPayload($data, $plan, $coveredSlots, $window, $createdBy, $now);

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

            if (!$this->db->transStatus()) {
                throw new Exception('Errore durante il salvataggio della prenotazione.');
            }

            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();
            throw $e;
        }

        return $idAppuntamento;
    }

    public function updateAppointment(array $data): bool
    {
        $idAppuntamento = (int) ($data['id_appuntamento'] ?? 0);
        $visitTypesFeatureEnabled = !empty($data['visit_types_feature_enabled']);
        $visitTypeRequired = !array_key_exists('visit_type_required', $data) || !empty($data['visit_type_required']);
        $customTimeFeatureEnabled = !empty($data['custom_appointment_time_feature_enabled']);
        $residualSlotsFeatureEnabled = AgendaSlotFragmentService::isFeatureEnabled(
            $customTimeFeatureEnabled,
            !empty($data['custom_time_residual_slots_feature_enabled'])
        );

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
        $plan = $this->resolveVisitPlan($data, $slot, $appointment, $visitTypesFeatureEnabled, $visitTypeRequired);
        $this->assertVisitTypeSchemaReady($plan, $slotDuration, $visitTypesFeatureEnabled);

        $window = $this->resolveAppointmentWindow(
            $data,
            $slot,
            $plan,
            $appointment,
            $customTimeFeatureEnabled,
            $idAppuntamento
        );
        if (!empty($window['custom_start'])) {
            $plan['duration_minutes'] = (int) ($window['duration_minutes'] ?? 0);
        }
        $coveredSlots = $window['covered_slots'];
        $this->assertAppointmentSpanSchemaReady($coveredSlots);
        $initialCoveredSlotIds = array_map(
            static fn(array $row): int => (int) ($row['id_slot'] ?? 0),
            $coveredSlots
        );
        $previousSlotIds = $this->getAppointmentCoveredSlotIds($idAppuntamento);
        $manageExistingFragments = $this->slotFragments()->hasFragmentsForSlots($previousSlotIds);

        $timestamp = date('Y-m-d H:i:s');
        $this->db->transBegin();

        try {
            $this->lockSlotRowsForUpdate(array_values(array_unique(array_merge($previousSlotIds, $initialCoveredSlotIds))));
            $this->assertCoveredSlotsAvailable($initialCoveredSlotIds, $idAppuntamento);

            if (
                !empty($window['custom_start'])
                && AgendaSlotFragmentService::shouldManageCustomWindow(
                    $residualSlotsFeatureEnabled,
                    $manageExistingFragments
                )
            ) {
                $freshPrimarySlot = $this->loadSlotRow((int) ($appointment['id_slot'] ?? 0));
                $freshCoveredSlots = $this->resolveCoveredSlotsForWindow(
                    $freshPrimarySlot,
                    (string) $window['custom_start'],
                    (string) $window['end'],
                    $idAppuntamento
                );
                $this->assertCoveredSlotSetUnchanged($initialCoveredSlotIds, $freshCoveredSlots);
                $coveredSlots = $this->slotFragments()->splitForWindow(
                    $freshCoveredSlots,
                    (string) $window['custom_start'],
                    (string) $window['end'],
                    $timestamp
                );
                $window['covered_slots'] = $coveredSlots;
            }

            $coveredSlotIds = array_map(
                static fn(array $row): int => (int) ($row['id_slot'] ?? 0),
                $coveredSlots
            );
            $this->assertAppointmentSpanSchemaReady($coveredSlots);
            $update = $this->buildAppointmentPayload($data, $plan, $coveredSlots, $window, 0, $timestamp);

            unset($update['id_dot'], $update['stato'], $update['created_at'], $update['created_by']);

            if ($this->appointmentTableHasField('updated_at')) {
                $update['updated_at'] = $timestamp;
            }

            $this->db->table($this->table)
                ->where('id_appuntamento', $idAppuntamento)
                ->update($update);

            $this->replaceAppointmentSlotLinks($idAppuntamento, $coveredSlotIds, $timestamp);
            $this->setSlotsState($coveredSlotIds, 'PRENOTATO', $timestamp);

            $slotIdsToRestore = array_values(array_diff($previousSlotIds, $coveredSlotIds));
            foreach ($slotIdsToRestore as $slotIdToRestore) {
                $this->restoreSlotState($slotIdToRestore, $timestamp);
            }
            $this->slotFragments()->compactGroupsForSlots($slotIdsToRestore, $timestamp);

            if (!$this->db->transStatus()) {
                throw new Exception('Errore durante l\'aggiornamento della prenotazione.');
            }

            $this->db->transCommit();
        } catch (\Throwable $e) {
            $this->db->transRollback();
            throw $e;
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
        ];

        if ($this->appointmentTableHasField('updated_at')) {
            $updatePayload['updated_at'] = $timestamp;
        }

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
        $this->slotFragments()->compactGroupsForSlots($coveredSlotIds, $timestamp);

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

    /**
     * @param array<int, int|string> $appointmentIds
     */
    public function deleteAppointments(array $appointmentIds, int $userId): int
    {
        $appointmentIds = array_values(array_unique(array_filter(
            array_map('intval', $appointmentIds),
            static fn(int $id): bool => $id > 0
        )));

        if ($appointmentIds === []) {
            throw new Exception('Seleziona almeno un appuntamento da eliminare.');
        }

        $timestamp = date('Y-m-d H:i:s');
        $updatePayload = ['stato' => 'ANNULLATO'];

        if ($this->appointmentTableHasField('updated_at')) {
            $updatePayload['updated_at'] = $timestamp;
        }

        if ($this->appointmentTableHasField('updated_by')) {
            $updatePayload['updated_by'] = $userId > 0 ? $userId : null;
        }

        $this->db->transBegin();

        try {
            $placeholders = implode(',', array_fill(0, count($appointmentIds), '?'));
            $rows = $this->db->query(
                "SELECT id_appuntamento, stato
                 FROM {$this->table}
                 WHERE id_appuntamento IN ({$placeholders})
                 FOR UPDATE",
                $appointmentIds
            )->getResultArray();

            if (count($rows) !== count($appointmentIds)) {
                throw new Exception(
                    'Uno o più appuntamenti selezionati non sono più disponibili.',
                    422
                );
            }

            foreach ($rows as $row) {
                if (strtoupper(trim((string) ($row['stato'] ?? ''))) === 'ANNULLATO') {
                    throw new Exception(
                        'Uno o più appuntamenti selezionati risultano già annullati.',
                        422
                    );
                }
            }

            $coveredSlotIds = [];
            foreach ($appointmentIds as $appointmentId) {
                $coveredSlotIds = array_merge(
                    $coveredSlotIds,
                    $this->getAppointmentCoveredSlotIds($appointmentId)
                );
            }
            $coveredSlotIds = array_values(array_unique(array_filter(array_map('intval', $coveredSlotIds))));

            $this->db->table($this->table)
                ->whereIn('id_appuntamento', $appointmentIds)
                ->update($updatePayload);

            if ($this->appointmentSlotLinkTableExists()) {
                $this->db->table('dap45_agenda_appuntamenti_slot')
                    ->whereIn('id_appuntamento', $appointmentIds)
                    ->delete();
            }

            foreach ($coveredSlotIds as $slotId) {
                $this->restoreSlotState($slotId, $timestamp);
            }
            $this->slotFragments()->compactGroupsForSlots($coveredSlotIds, $timestamp);

            if (!$this->db->transStatus() || !$this->db->transCommit()) {
                throw new \RuntimeException('Bulk appointment transaction failed.');
            }
        } catch (\Throwable $e) {
            $this->db->transRollback();

            if ($e instanceof Exception && $e->getCode() === 422) {
                throw $e;
            }

            $dbError = $this->db->error();
            log_message('error', 'AgendaAppointmentModel::deleteAppointments failed for count={count} user_id={user} code={code} message={message}', [
                'count' => count($appointmentIds),
                'user' => $userId,
                'code' => (string) ($dbError['code'] ?? ''),
                'message' => (string) ($dbError['message'] ?? ''),
            ]);

            throw new Exception('Errore durante l\'eliminazione degli appuntamenti selezionati.');
        }

        return count($appointmentIds);
    }

    private function buildAppointmentPayload(
        array $data,
        array $plan,
        array $coveredSlots,
        array $window,
        int $createdBy,
        string $timestamp
    ): array
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

        if ($this->appointmentTableHasField('ora_inizio_appuntamento')) {
            $payload['ora_inizio_appuntamento'] = !empty($window['custom_start'])
                ? (string) $window['custom_start']
                : null;
        }

        if ($this->appointmentTableHasField('ora_fine_appuntamento')) {
            $payload['ora_fine_appuntamento'] = !empty($window['end'])
                ? (string) $window['end']
                : (!empty($lastCoveredSlot['ora_fine']) ? (string) $lastCoveredSlot['ora_fine'] : null);
        }

        return $payload;
    }

    /**
     * @return array{
     *     covered_slots: array<int, array<string, mixed>>,
     *     custom_start: ?string,
     *     end: string,
     *     duration_minutes: int
     * }
     */
    private function resolveAppointmentWindow(
        array $data,
        array $primarySlot,
        array $plan,
        ?array $existingAppointment,
        bool $customTimeFeatureEnabled,
        int $ignoreAppointmentId = 0,
        string $allowedLockToken = '',
        bool $checkActiveLocks = true
    ): array {
        $durationMinutes = (int) ($plan['duration_minutes'] ?? 0);
        if ($durationMinutes <= 0) {
            throw new Exception('Durata appuntamento non valida.');
        }

        $storedCustomStart = $existingAppointment !== null
            ? trim((string) ($existingAppointment['ora_inizio_appuntamento'] ?? ''))
            : '';
        $storedCustomEnd = $existingAppointment !== null
            ? trim((string) ($existingAppointment['ora_fine_appuntamento'] ?? ''))
            : '';
        $toggleProvided = array_key_exists('custom_time_enabled', $data);
        $customRequested = $toggleProvided && !empty($data['custom_time_enabled']);

        if ($customRequested && !$customTimeFeatureEnabled) {
            throw new Exception('Gli orari personalizzati non sono attivi per questo spazio.');
        }

        $shouldPreserveStoredCustomTime = $storedCustomStart !== ''
            && (!$customTimeFeatureEnabled || !$toggleProvided);
        $customStart = null;

        if ($customRequested) {
            $customStart = $this->normalizeCustomAppointmentStart(
                (string) ($data['custom_start_time'] ?? ''),
                $primarySlot
            );
        } elseif ($shouldPreserveStoredCustomTime) {
            $customStart = $storedCustomStart;
        }

        if ($customStart !== null) {
            $this->ensureVisitTypeSchemaReady();

            if (
                !$this->appointmentTableHasField('ora_inizio_appuntamento')
                || !$this->appointmentTableHasField('ora_fine_appuntamento')
                || !$this->appointmentTableHasField('durata_minuti')
            ) {
                throw new Exception('La struttura del database non è aggiornata per gestire gli orari personalizzati.');
            }

            $startTimestamp = strtotime($customStart);
            if ($startTimestamp === false) {
                throw new Exception('Ora iniziale personalizzata non valida.');
            }

            $requestedCustomEnd = $customRequested
                ? trim((string) ($data['custom_end_time'] ?? ''))
                : '';
            $customEnd = null;

            if ($requestedCustomEnd !== '') {
                $customEnd = $this->normalizeCustomAppointmentEnd($requestedCustomEnd, $customStart);
            } elseif ($shouldPreserveStoredCustomTime && $storedCustomEnd !== '') {
                $storedEndTimestamp = strtotime($storedCustomEnd);
                if ($storedEndTimestamp !== false && $storedEndTimestamp > $startTimestamp) {
                    $customEnd = date('Y-m-d H:i:s', $storedEndTimestamp);
                }
            }

            $endTimestamp = $customEnd !== null
                ? strtotime($customEnd)
                : strtotime('+' . $durationMinutes . ' minutes', $startTimestamp);
            if ($endTimestamp === false || date('Y-m-d', $endTimestamp) !== date('Y-m-d', $startTimestamp)) {
                throw new Exception('L appuntamento personalizzato deve iniziare e terminare nello stesso giorno.');
            }

            $end = date('Y-m-d H:i:s', $endTimestamp);
            $durationMinutes = (int) round(($endTimestamp - $startTimestamp) / 60);
            if ($durationMinutes <= 0) {
                throw new Exception('L’ora finale personalizzata deve essere successiva all’ora iniziale.');
            }

            $coveredSlots = $this->resolveCoveredSlotsForWindow(
                $primarySlot,
                $customStart,
                $end,
                $ignoreAppointmentId,
                $allowedLockToken,
                $checkActiveLocks
            );

            return [
                'covered_slots' => $coveredSlots,
                'custom_start' => $customStart,
                'end' => $end,
                'duration_minutes' => $durationMinutes,
            ];
        }

        $coveredSlots = $this->resolveCoveredSlots(
            $primarySlot,
            $durationMinutes,
            $ignoreAppointmentId,
            $allowedLockToken,
            $checkActiveLocks
        );
        $lastCoveredSlot = end($coveredSlots);

        return [
            'covered_slots' => $coveredSlots,
            'custom_start' => null,
            'end' => !empty($lastCoveredSlot['ora_fine'])
                ? (string) $lastCoveredSlot['ora_fine']
                : null,
            'duration_minutes' => $durationMinutes,
        ];
    }

    private function resolveVisitPlan(
        array $data,
        array $slot,
        ?array $existingAppointment,
        bool $visitTypesFeatureEnabled,
        bool $visitTypeRequired = true
    ): array
    {
        $slotDuration = $this->getSlotDurationMinutes($slot);

        if (!empty($data['allow_custom_duration'])) {
            $customDuration = (int) ($data['durata_minuti'] ?? 0);
            if (
                $customDuration <= 0
                && $existingAppointment !== null
                && !array_key_exists('durata_minuti', $data)
            ) {
                $customDuration = $this->resolveStoredAppointmentDuration($existingAppointment, $slotDuration);
            }

            if ($customDuration <= 0) {
                throw new Exception('Seleziona un orario di fine valido per l’impegno personale.');
            }

            return [
                'visit_type_id' => 0,
                'type_label' => '',
                'duration_minutes' => $customDuration,
                'uses_custom_duration' => true,
            ];
        }

        if ($visitTypesFeatureEnabled) {
            $hasVisitTypeInput = array_key_exists('id_tipo_visita', $data);
            $selectedTypeId = (int) ($data['id_tipo_visita'] ?? 0);
            if (
                $selectedTypeId <= 0
                && $existingAppointment !== null
                && (!$hasVisitTypeInput || $visitTypeRequired)
            ) {
                $selectedTypeId = (int) ($existingAppointment['id_tipo_visita'] ?? 0);
            }

            if ($selectedTypeId <= 0) {
                if ($visitTypeRequired) {
                    throw new Exception('Seleziona il tipo visita.');
                }

                return [
                    'visit_type_id' => 0,
                    'type_label' => '',
                    'duration_minutes' => $slotDuration,
                ];
            }

            $typeRow = (new AgendaVisitTypeModel())->findType($selectedTypeId);
            if (!$typeRow) {
                throw new Exception('Tipo visita non trovato.');
            }

            if ($existingAppointment === null && (int) ($typeRow['attivo'] ?? 0) !== 1) {
                throw new Exception('Il tipo visita selezionato non è attivo.');
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

        $startTimestamp = strtotime((string) (
            $appointment['ora_inizio_appuntamento']
            ?? $appointment['slot_ora_inizio']
            ?? ''
        ));
        $endTimestamp = strtotime((string) ($appointment['ora_fine_appuntamento'] ?? ''));
        if ($startTimestamp !== false && $endTimestamp !== false && $endTimestamp > $startTimestamp) {
            return (int) round(($endTimestamp - $startTimestamp) / 60);
        }

        return max(1, $fallbackDuration);
    }

    private function normalizeCustomAppointmentStart(string $customTime, array $primarySlot): string
    {
        $customTime = trim($customTime);
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $customTime)) {
            throw new Exception('Inserisci un ora iniziale personalizzata valida.');
        }

        $date = trim((string) ($primarySlot['data_slot'] ?? ''));
        if ($date === '') {
            $slotStart = strtotime((string) ($primarySlot['ora_inizio'] ?? ''));
            $date = $slotStart !== false ? date('Y-m-d', $slotStart) : '';
        }

        if ($date === '') {
            throw new Exception('Data dello slot non valida.');
        }

        $customStart = $date . ' ' . $customTime . ':00';
        $customTimestamp = strtotime($customStart);
        $slotStartTimestamp = strtotime((string) (
            $primarySlot['slot_ora_inizio_originale']
            ?? $primarySlot['ora_inizio']
            ?? ''
        ));
        $slotEndTimestamp = strtotime((string) (
            $primarySlot['slot_ora_fine_originale']
            ?? $primarySlot['ora_fine']
            ?? ''
        ));

        if (
            $customTimestamp === false
            || $slotStartTimestamp === false
            || $slotEndTimestamp === false
            || $customTimestamp < $slotStartTimestamp
            || $customTimestamp >= $slotEndTimestamp
        ) {
            throw new Exception('L ora personalizzata deve rientrare nello slot iniziale selezionato.');
        }

        return date('Y-m-d H:i:s', $customTimestamp);
    }

    private function normalizeCustomAppointmentEnd(string $customTime, string $customStart): string
    {
        $customTime = trim($customTime);
        if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $customTime)) {
            throw new Exception('Inserisci un ora finale personalizzata valida.');
        }

        $startTimestamp = strtotime($customStart);
        if ($startTimestamp === false) {
            throw new Exception('Ora iniziale personalizzata non valida.');
        }

        $customEnd = date('Y-m-d', $startTimestamp) . ' ' . $customTime . ':00';
        $endTimestamp = strtotime($customEnd);
        if ($endTimestamp === false || $endTimestamp <= $startTimestamp) {
            throw new Exception('L’ora finale personalizzata deve essere successiva all’ora iniziale.');
        }

        return date('Y-m-d H:i:s', $endTimestamp);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function resolveCoveredSlotsForWindow(
        array $primarySlot,
        string $customStart,
        string $customEnd,
        int $ignoreAppointmentId = 0,
        string $allowedLockToken = '',
        bool $checkActiveLocks = true
    ): array {
        $startTimestamp = strtotime($customStart);
        $endTimestamp = strtotime($customEnd);
        if ($startTimestamp === false || $endTimestamp === false || $endTimestamp <= $startTimestamp) {
            throw new Exception('Intervallo personalizzato non valido.');
        }

        $idDot = (int) ($primarySlot['id_dot'] ?? 0);
        $dataSlot = (string) ($primarySlot['data_slot'] ?? '');
        $primarySlotId = (int) ($primarySlot['id_slot'] ?? 0);

        $rows = $this->db->table('dap11_agenda_slot')
            ->select('*')
            ->where('id_dot', $idDot)
            ->where('data_slot', $dataSlot)
            ->where('ora_inizio <', $customEnd)
            ->where('ora_fine >', $customStart)
            ->orderBy('ora_inizio', 'ASC')
            ->get()
            ->getResultArray();

        if ($rows === []) {
            throw new Exception('L ora personalizzata deve partire dallo slot selezionato.');
        }

        $coveredSlots = [];
        $primaryCoveredSlotFound = false;
        $coverageCursorTimestamp = $startTimestamp;
        $allowAdaptedPrimaryReplacement = $ignoreAppointmentId > 0
            && !empty($primarySlot['is_slot_adattato']);

        foreach ($rows as $row) {
            $slotId = (int) ($row['id_slot'] ?? 0);
            $rowStartTimestamp = strtotime((string) ($row['ora_inizio'] ?? ''));
            $rowEndTimestamp = strtotime((string) ($row['ora_fine'] ?? ''));

            if (
                $slotId <= 0
                || $rowStartTimestamp === false
                || $rowEndTimestamp === false
                || $rowEndTimestamp <= $rowStartTimestamp
            ) {
                throw new Exception('Durata slot non valida nella fascia selezionata.');
            }

            if ($rowStartTimestamp > $coverageCursorTimestamp) {
                throw new Exception('L’intervallo personalizzato attraversa una fascia senza slot disponibili.');
            }

            if ($coveredSlots !== [] && $rowStartTimestamp < $coverageCursorTimestamp) {
                throw new Exception('La fascia selezionata contiene slot sovrapposti e non può essere adattata in sicurezza.');
            }

            if (strtoupper(trim((string) ($row['stato'] ?? ''))) === 'CHIUSO') {
                throw new Exception('La fascia richiesta include uno slot in una giornata bloccata.');
            }

            if ($this->slotHasActiveAppointment($slotId, $ignoreAppointmentId)) {
                throw new Exception('Uno degli slot coinvolti e già occupato da un altro appuntamento.');
            }

            if ($checkActiveLocks && $this->slotHasActiveLock($slotId, $allowedLockToken)) {
                throw new Exception('Uno degli slot coinvolti è in modifica da un altro operatore.');
            }

            $primaryCoveredSlotFound = $primaryCoveredSlotFound || $slotId === $primarySlotId;
            $coveredSlots[] = $row;

            $coverageCursorTimestamp = max($coverageCursorTimestamp, $rowEndTimestamp);
        }

        if (!$primaryCoveredSlotFound && !$allowAdaptedPrimaryReplacement) {
            throw new Exception('L ora personalizzata deve partire dallo slot selezionato.');
        }

        if ($coverageCursorTimestamp < $endTimestamp) {
            throw new Exception('Non ci sono abbastanza slot disponibili per completare l’appuntamento.');
        }

        return $coveredSlots;
    }

    private function resolveCoveredSlots(
        array $primarySlot,
        int $requiredDurationMinutes,
        int $ignoreAppointmentId = 0,
        string $allowedLockToken = '',
        bool $checkActiveLocks = true
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

            if ($checkActiveLocks && $this->slotHasActiveLock($slotId, $allowedLockToken)) {
                throw new Exception('Uno degli slot consecutivi necessari è in modifica da un altro operatore.');
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
                throw new Exception('La durata del tipo visita non è compatibile con la griglia degli slot disponibili in questo punto dell’agenda.');
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

    /**
     * @param array<int, int> $slotIds
     */
    private function lockSlotRowsForUpdate(array $slotIds): void
    {
        $slotIds = array_values(array_unique(array_filter(array_map('intval', $slotIds))));
        if ($slotIds === []) {
            throw new Exception('Nessuno slot disponibile per l’appuntamento.');
        }

        sort($slotIds);
        $placeholders = implode(', ', array_fill(0, count($slotIds), '?'));
        $rows = $this->db->query(
            'SELECT id_slot FROM dap11_agenda_slot WHERE id_slot IN (' . $placeholders . ') ORDER BY id_slot FOR UPDATE',
            $slotIds
        )->getResultArray();

        if (count($rows) !== count($slotIds)) {
            throw new Exception('Uno degli slot coinvolti non è più disponibile.');
        }
    }

    /**
     * @param array<int, int> $slotIds
     */
    private function assertCoveredSlotsAvailable(
        array $slotIds,
        int $ignoreAppointmentId = 0,
        string $allowedLockToken = '',
        bool $checkActiveLocks = true
    ): void {
        foreach (array_values(array_unique(array_filter(array_map('intval', $slotIds)))) as $slotId) {
            $slot = $this->loadSlotRow($slotId);
            if (!$slot) {
                throw new Exception('Uno degli slot coinvolti non è più disponibile.');
            }

            if (strtoupper(trim((string) ($slot['stato'] ?? ''))) === 'CHIUSO') {
                throw new Exception('La fascia richiesta include uno slot in una giornata bloccata.');
            }

            if ($this->slotHasActiveAppointment($slotId, $ignoreAppointmentId)) {
                throw new Exception('Uno degli slot coinvolti e già occupato da un altro appuntamento.');
            }

            if ($checkActiveLocks && $this->slotHasActiveLock($slotId, $allowedLockToken)) {
                throw new Exception('Uno degli slot coinvolti è in modifica da un altro operatore.');
            }
        }
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

        $slot = $this->db->table('dap11_agenda_slot')
            ->where('id_slot', $idSlot)
            ->get()
            ->getRowArray() ?: [];

        return $slot === [] ? [] : $this->slotFragments()->decorateSlot($slot);
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
        $requiresExtendedAppointmentSchema = $visitTypesFeatureEnabled || !empty($plan['uses_custom_duration']);

        if ($requiresExtendedAppointmentSchema) {
            $this->ensureVisitTypeSchemaReady();

            foreach (['id_tipo_visita', 'tipo_visita_label', 'durata_minuti', 'ora_fine_appuntamento'] as $field) {
                if (!$this->appointmentTableHasField($field)) {
                    throw new Exception('La struttura del database non è aggiornata per gestire i tipi visita.');
                }
            }
        }

        if ($usesSpan && !$this->appointmentSlotLinkTableExists()) {
            throw new Exception('La struttura del database non è aggiornata per gestire appuntamenti su più slot.');
        }
    }

    /**
     * @param array<int, array<string, mixed>> $coveredSlots
     */
    private function assertAppointmentSpanSchemaReady(array $coveredSlots): void
    {
        if (count($coveredSlots) > 1 && !$this->appointmentSlotLinkTableExists()) {
            throw new Exception('La struttura del database non è aggiornata per gestire appuntamenti su più slot.');
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

    /**
     * @param array<int, int> $expectedIds
     * @param array<int, array<string, mixed>> $freshRows
     */
    private function assertCoveredSlotSetUnchanged(array $expectedIds, array $freshRows): void
    {
        $expectedIds = array_values(array_unique(array_filter(array_map('intval', $expectedIds))));
        $freshIds = array_values(array_unique(array_filter(array_map(
            static fn(array $row): int => (int) ($row['id_slot'] ?? 0),
            $freshRows
        ))));
        sort($expectedIds);
        sort($freshIds);

        if ($expectedIds !== $freshIds) {
            throw new Exception('La disponibilità degli slot è cambiata. Riapri l’appuntamento e riprova.');
        }
    }

    private function slotFragments(): AgendaSlotFragmentService
    {
        $this->slotFragmentService ??= new AgendaSlotFragmentService($this->db);

        return $this->slotFragmentService;
    }
}
