<?php

namespace App\Models;

use CodeIgniter\Model;
use Exception;

class AgendaNoteModel extends Model
{
    protected $table = 'dap15_agenda_note';
    protected $primaryKey = 'id_nota';
    protected $db;
    protected AgendaConfigModel $agendaConfigModel;
    protected AgendaLocationModel $locationModel;
    protected array $appointmentLocationCandidatesByDay = [];
    protected array $slotLocationCandidatesByDay = [];

    public function __construct()
    {
        parent::__construct();
        $this->db = \Config\Database::connect();
        $this->agendaConfigModel = new AgendaConfigModel();
        $this->locationModel = new AgendaLocationModel();
    }

    public function getNoteFatteByDoctor(int $idDot): array
    {
        return $this->db->table('dap15_agenda_note')
            ->select("
                id_nota,
                id_dot,
                data_inizio_validita,
                cliente,
                id_paziente,
                telefono,
                cellulare,
                indirizzo,
                citta,
                note,
                fatta,
                data_fatta,
                attiva,
                COALESCE(u_created.username, '') AS created_by_username,
                created_at,
                updated_at
            ")
            ->join('dap01_users u_created', 'u_created.id_user = dap15_agenda_note.created_by', 'left')
            ->where('id_dot', $idDot)
            ->where('attiva', 1)
            ->where('fatta', 1)
            ->orderBy('created_at', 'ASC')
            ->orderBy('id_nota', 'ASC')
            ->get()
            ->getResultArray();
    }

    public function getDurataSlotConfigByDoctorDateTime(int $idDot, string $data, string $oraInizio): array
    {
        return $this->agendaConfigModel->resolveSlotConfigByDoctorDateTime($idDot, $data, $oraInizio);
    }

    public function hasSlotCollision(int $idDot, string $data, string $oraInizioDt, string $oraFineDt): bool
    {
        $count = $this->db->table('dap11_agenda_slot')
            ->where('id_dot', $idDot)
            ->where('data_slot', $data)
            ->groupStart()
                ->where('ora_inizio <', $oraFineDt)
                ->where('ora_fine >', $oraInizioDt)
            ->groupEnd()
            ->countAllResults();

        return $count > 0;
    }

    public function hasExactSlot(int $idDot, string $data, string $oraInizioDt, string $oraFineDt): bool
    {
        return $this->findExactSlot($idDot, $data, $oraInizioDt, $oraFineDt) !== null;
    }

    protected function findExactSlot(int $idDot, string $data, string $oraInizioDt, string $oraFineDt): ?array
    {
        $row = $this->db->table('dap11_agenda_slot')
            ->select('id_slot, id_config, origine_slot, stato, titolo_libero, note_interne')
            ->where('id_dot', $idDot)
            ->where('data_slot', $data)
            ->where('ora_inizio', $oraInizioDt)
            ->where('ora_fine', $oraFineDt)
            ->get()
            ->getRowArray();

        return $row ?: null;
    }

    public function insertExtraSlotsInPeriod(array $payload, int $userId): array
    {
        $idDot        = (int)($payload['id_dot'] ?? 0);
        $dataInizio   = (string)($payload['data_inizio'] ?? '');
        $dataFine     = (string)($payload['data_fine'] ?? '');
        $location = $this->locationModel->resolveSelection($payload);
        $giorni       = $payload['giorni'] ?? [];

        $giorni = is_array($giorni) ? array_values(array_unique(array_map('intval', $giorni))) : [];

        if ($idDot <= 0 || $dataInizio === '' || $dataFine === '') {
            throw new \Exception('Dati mancanti.');
        }

        if ($dataInizio > $dataFine) {
            throw new \Exception('La data fine deve essere uguale o successiva alla data inizio.');
        }

        if (empty($giorni)) {
            throw new \Exception('Seleziona almeno un giorno della settimana.');
        }

        $inizioTs = strtotime($dataInizio);
        $fineTs   = strtotime($dataFine);

        $inserted = 0;
        $processedDays = 0;
        $giorniBloccati = [];
        $giorniSenzaConfig = [];
        $collisioni = [];
        $inseriti = [];

        $this->db->transStart();

        for ($ts = $inizioTs; $ts <= $fineTs; $ts = strtotime('+1 day', $ts)) {
            $data = date('Y-m-d', $ts);
            $giornoSettimana = (int)date('N', $ts);

            if (!in_array($giornoSettimana, $giorni, true)) {
                continue;
            }

            $processedDays++;

            if ($this->isGiornoBloccato($idDot, $data)) {
                $giorniBloccati[] = $data;
                continue;
            }

            try {
                $rangeConfig = $this->resolveExtraSlotRangeForDate($payload, $idDot, $data);
            } catch (\Exception $e) {
                $giorniSenzaConfig[] = [
                    'data'   => $data,
                    'motivo' => $e->getMessage(),
                ];
                continue;
            }

            $dayResult = $this->insertExtraSlotsForDateInternal(
                $idDot,
                $data,
                $rangeConfig,
                $location,
                'Inserito da gestione slot extra'
            );

            $inserted += (int)($dayResult['inserted'] ?? 0);
            $collisioni = array_merge($collisioni, $dayResult['collisioni'] ?? []);
            $inseriti = array_merge($inseriti, $dayResult['inseriti'] ?? []);
        }

        $this->db->transComplete();

        if (!$this->db->transStatus()) {
            throw new \Exception('Errore durante l\'inserimento degli slot extra.');
        }

        return [
            'processed_days'      => $processedDays,
            'inserted'            => $inserted,
            'giorni_bloccati'     => $giorniBloccati,
            'giorni_senza_config' => $giorniSenzaConfig,
            'collisioni'          => $collisioni,
            'inseriti'            => $inseriti,
        ];
    }

    public function insertExtraSlotsForSingleDay(array $payload): array
    {
        $idDot = (int)($payload['id_dot'] ?? 0);
        $data = (string)($payload['data'] ?? '');
        $location = $this->locationModel->resolveSelection($payload);

        if ($idDot <= 0 || $data === '') {
            throw new \Exception('Dati mancanti.');
        }

        if ($this->isGiornoBloccato($idDot, $data)) {
            throw new \Exception('La giornata è bloccata. Non puoi aggiungere slot extra.');
        }

        $rangeConfig = $this->resolveExtraSlotRangeForDate($payload, $idDot, $data);

        $this->db->transStart();
        $result = $this->insertExtraSlotsForDateInternal(
            $idDot,
            $data,
            $rangeConfig,
            $location,
            'Inserito da agenda - slot extra singolo'
        );
        $this->db->transComplete();

        if (!$this->db->transStatus()) {
            throw new \Exception('Errore durante l\'inserimento degli slot extra.');
        }

        return $result;
    }

    public function getNoteFatteByDoctorPaginate(int $idDot, int $perPage = 20, int $page = 1, string $searchTerm = ''): array
    {
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;
        $searchTerm = trim((string)(preg_replace('/\s+/', ' ', $searchTerm) ?? ''));
        $searchTokens = array_values(array_filter(preg_split('/\s+/', $searchTerm) ?: []));

        $builder = $this->db->table('dap15_agenda_note')
            ->select("
                id_nota,
                id_dot,
                data_inizio_validita,
                cliente,
                id_paziente,
                telefono,
                cellulare,
                indirizzo,
                citta,
                note,
                fatta,
                data_fatta,
                attiva,
                COALESCE(u_created.username, '') AS created_by_username,
                created_at,
                updated_at
            ")
            ->join('dap01_users u_created', 'u_created.id_user = dap15_agenda_note.created_by', 'left')
            ->where('id_dot', $idDot)
            ->where('attiva', 1)
            ->where('fatta', 1);

        if (!empty($searchTokens)) {
            $builder->groupStart();
            foreach ($searchTokens as $token) {
                $builder->like('cliente', $token);
            }
            $builder->groupEnd();
        }

        $builder
            ->orderBy('created_at', 'ASC')
            ->orderBy('id_nota', 'ASC');

        $rows = $builder
            ->limit($perPage, $offset)
            ->get()
            ->getResultArray();

        $countBuilder = $this->db->table('dap15_agenda_note')
            ->where('id_dot', $idDot)
            ->where('attiva', 1)
            ->where('fatta', 1);

        if (!empty($searchTokens)) {
            $countBuilder->groupStart();
            foreach ($searchTokens as $token) {
                $countBuilder->like('cliente', $token);
            }
            $countBuilder->groupEnd();
        }

        $total = $countBuilder->countAllResults();

        return [
            'rows'      => $rows,
            'total'     => $total,
            'page'      => $page,
            'perPage'   => $perPage,
            'lastPage'  => max(1, (int) ceil($total / $perPage)),
        ];
    }

    public function getNoteByDoctor(int $idDot): array
    {
        return $this->db->table('dap15_agenda_note')
            ->select("
                dap15_agenda_note.*,
                COALESCE(u_created.username, '') AS created_by_username
            ")
            ->join('dap01_users u_created', 'u_created.id_user = dap15_agenda_note.created_by', 'left')
            ->where('id_dot', $idDot)
            ->where('attiva', 1)
            ->where('fatta', 0)
            ->orderBy('created_at', 'ASC')
            ->orderBy('id_nota', 'ASC')
            ->get()
            ->getResultArray();
    }

    public function getNota(int $idNota): ?array
    {
        $row = $this->db->table('dap15_agenda_note')
            ->select("
                dap15_agenda_note.*,
                COALESCE(u_created.username, '') AS created_by_username
            ")
            ->join('dap01_users u_created', 'u_created.id_user = dap15_agenda_note.created_by', 'left')
            ->where('id_nota', $idNota)
            ->get()
            ->getRowArray();

        return $row ?: null;
    }

    public function saveNota(array $data): int
    {
        $idNota = (int)($data['id_nota'] ?? 0);

        $insert = [
            'id_dot'               => (int)($data['id_dot'] ?? 0),
            'data_inizio_validita' => trim((string)($data['data_inizio_validita'] ?? '')),
            'cliente'              => trim((string)($data['cliente'] ?? '')),
            'id_paziente'          => !empty($data['id_paziente']) ? (int)$data['id_paziente'] : null,
            'telefono'             => trim((string)($data['telefono'] ?? '')),
            'cellulare'            => trim((string)($data['cellulare'] ?? '')),
            'indirizzo'            => trim((string)($data['indirizzo'] ?? '')),
            'citta'                => trim((string)($data['citta'] ?? '')),
            'note'                 => trim((string)($data['note'] ?? '')),
            'fatta'                => !empty($data['fatta']) ? 1 : 0,
            'attiva'               => 1,
        ];

        if ($insert['id_dot'] <= 0) {
            throw new Exception('Dottore non valido.');
        }

        if ($insert['data_inizio_validita'] === '') {
            throw new Exception('La data inizio validità è obbligatoria.');
        }

        if ($insert['cliente'] === '') {
            throw new Exception('Il cliente è obbligatorio.');
        }

        if ($insert['fatta'] === 1) {
            $insert['data_fatta'] = date('Y-m-d H:i:s');
        } else {
            $insert['data_fatta'] = null;
        }

        if ($idNota > 0) {
            $insert['updated_by'] = !empty($data['created_by']) ? (int)$data['created_by'] : null;
            $insert['updated_at'] = date('Y-m-d H:i:s');

            $this->db->table('dap15_agenda_note')
                ->where('id_nota', $idNota)
                ->update($insert);

            return $idNota;
        }

        $insert['created_by'] = !empty($data['created_by']) ? (int)$data['created_by'] : null;
        $insert['created_at'] = date('Y-m-d H:i:s');

        $this->db->table('dap15_agenda_note')->insert($insert);

        return (int)$this->db->insertID();
    }

    public function setFatta(int $idNota, int $fatta, int $userId): bool
    {
        return (bool)$this->db->table('dap15_agenda_note')
            ->where('id_nota', $idNota)
            ->update([
                'fatta'      => $fatta ? 1 : 0,
                'data_fatta' => $fatta ? date('Y-m-d H:i:s') : null,
                'updated_by' => $userId,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
    }

    public function deleteNota(int $idNota): bool
    {
        return (bool)$this->db->table('dap15_agenda_note')
            ->where('id_nota', $idNota)
            ->delete();
    }

    protected function resolveExtraSlotRangeForDate(array $payload, int $idDot, string $data): array
    {
        $oraInizio = trim((string)($payload['ora_inizio'] ?? ''));
        $oraFine = trim((string)($payload['ora_fine'] ?? ''));
        $durataCustom = (int)($payload['durata_slot'] ?? 0);

        if (!$this->isValidHourMinute($oraInizio)) {
            throw new \Exception('Orario inizio non valido. Usa il formato HH:mm.');
        }

        $hasCustomRange = $oraFine !== '' || $durataCustom > 0;

        if ($hasCustomRange) {
            if (!$this->isValidHourMinute($oraFine)) {
                throw new \Exception('Orario fine non valido. Usa il formato HH:mm.');
            }

            if ($oraFine <= $oraInizio) {
                throw new \Exception('L\'ora fine deve essere successiva all\'ora inizio.');
            }

            if ($durataCustom <= 0) {
                $durataCustom = $this->calculateMinutesBetweenHours($oraInizio, $oraFine);
            }

            if ($durataCustom <= 0) {
                throw new \Exception('Durata slot non valida.');
            }

            return [
                'ora_inizio'   => $oraInizio,
                'ora_fine'     => $oraFine,
                'durata'       => $durataCustom,
                'id_config'    => null,
                'slot_windows' => $this->buildExtraSlotWindows($data, $oraInizio, $oraFine, $durataCustom),
            ];
        }

        $durataInfo = $this->getDurataSlotConfigByDoctorDateTime($idDot, $data, $oraInizio);
        if (empty($durataInfo['status'])) {
            throw new \Exception((string)($durataInfo['message'] ?? 'Configurazione non valida'));
        }

        $durata = (int)($durataInfo['durata'] ?? 0);
        if ($durata <= 0) {
            throw new \Exception('Durata slot non valida.');
        }

        $oraFine = date('H:i', strtotime($data . ' ' . $oraInizio . ':00 +' . $durata . ' minutes'));

        return [
            'ora_inizio'   => $oraInizio,
            'ora_fine'     => $oraFine,
            'durata'       => $durata,
            'id_config'    => (int)($durataInfo['id_config'] ?? 0),
            'slot_windows' => $this->buildExtraSlotWindows($data, $oraInizio, $oraFine, $durata),
        ];
    }

    protected function insertExtraSlotsForDateInternal(
        int $idDot,
        string $data,
        array $rangeConfig,
        array $location,
        string $noteInterne
    ): array {
        $inserted = 0;
        $collisioni = [];
        $inseriti = [];

        foreach (($rangeConfig['slot_windows'] ?? []) as $window) {
            $oraInizioDt = (string)$window['ora_inizio_dt'];
            $oraFineDt = (string)$window['ora_fine_dt'];

            $exactSlot = $this->findExactSlot($idDot, $data, $oraInizioDt, $oraFineDt);
            if ($exactSlot !== null) {
                $collisioni[] = [
                    'data'       => $data,
                    'ora_inizio' => (string)$window['ora_inizio'],
                    'ora_fine'   => (string)$window['ora_fine'],
                    'motivo'     => $this->buildExactSlotCollisionReason($exactSlot),
                    'id_slot'    => (int)($exactSlot['id_slot'] ?? 0),
                    'id_config'  => !empty($exactSlot['id_config']) ? (int)$exactSlot['id_config'] : null,
                    'origine_slot' => (string)($exactSlot['origine_slot'] ?? ''),
                ];
                continue;
            }

            $effectiveLocation = $this->resolveAutoLocationForExtraSlot(
                $idDot,
                $data,
                $oraInizioDt,
                $oraFineDt,
                $location
            );

            $this->db->table('dap11_agenda_slot')->insert([
                'id_dot'        => $idDot,
                'id_config'     => !empty($rangeConfig['id_config']) ? (int)$rangeConfig['id_config'] : null,
                'data_slot'     => $data,
                'ora_inizio'    => $oraInizioDt,
                'ora_fine'      => $oraFineDt,
                'tipo_slot'     => 'AMBULATORIO',
                'stato'         => 'LIBERO',
                'titolo_libero' => 'EXTRA',
                'id_amb_legacy' => $effectiveLocation['id_amb_legacy'],
                'ambulatorio'   => $effectiveLocation['ambulatorio'] !== '' ? $effectiveLocation['ambulatorio'] : null,
                'stanza'        => $effectiveLocation['stanza'] !== '' ? $effectiveLocation['stanza'] : null,
                'origine_slot'  => 'EXTRA',
                'note_interne'  => $noteInterne,
                'created_at'    => date('Y-m-d H:i:s'),
            ] + ($this->locationModel->slotTableHasRoomColumn()
                ? ['id_stanza' => $effectiveLocation['id_stanza']]
                : []));

            $inserted++;
            $inseriti[] = [
                'data'       => $data,
                'ora_inizio' => (string)$window['ora_inizio'],
                'ora_fine'   => (string)$window['ora_fine'],
            ];
        }

        return [
            'inserted'   => $inserted,
            'collisioni' => $collisioni,
            'inseriti'   => $inseriti,
        ];
    }

    protected function buildExactSlotCollisionReason(array $slot): string
    {
        $origine = strtoupper(trim((string)($slot['origine_slot'] ?? '')));

        if ($origine === 'CONFIG') {
            return 'Slot gia presente in agenda come slot configurato';
        }

        if ($origine === 'EXTRA') {
            return 'Slot extra gia presente con lo stesso orario';
        }

        return 'Slot gia presente con lo stesso orario';
    }

    protected function resolveAutoLocationForExtraSlot(
        int $idDot,
        string $data,
        string $oraInizioDt,
        string $oraFineDt,
        array $baseLocation
    ): array {
        if ($this->locationHasValue($baseLocation)) {
            return $baseLocation;
        }

        $nearestAppointmentLocation = $this->findNearestAppointmentLocation(
            $idDot,
            $data,
            $oraInizioDt,
            $oraFineDt
        );
        if ($nearestAppointmentLocation !== null) {
            return $nearestAppointmentLocation;
        }

        $nearestSlotLocation = $this->findNearestSlotLocation(
            $idDot,
            $data,
            $oraInizioDt,
            $oraFineDt
        );
        if ($nearestSlotLocation !== null) {
            return $nearestSlotLocation;
        }

        return $baseLocation + [
            'id_amb_legacy' => null,
            'id_stanza'     => null,
            'ambulatorio'   => '',
            'stanza'        => '',
        ];
    }

    protected function findNearestAppointmentLocation(
        int $idDot,
        string $data,
        string $oraInizioDt,
        string $oraFineDt
    ): ?array {
        return $this->pickNearestLocationCandidate(
            $this->getAppointmentLocationCandidatesForDay($idDot, $data),
            $oraInizioDt,
            $oraFineDt
        );
    }

    protected function findNearestSlotLocation(
        int $idDot,
        string $data,
        string $oraInizioDt,
        string $oraFineDt
    ): ?array {
        return $this->pickNearestLocationCandidate(
            $this->getSlotLocationCandidatesForDay($idDot, $data),
            $oraInizioDt,
            $oraFineDt
        );
    }

    protected function getAppointmentLocationCandidatesForDay(int $idDot, string $data): array
    {
        $cacheKey = $idDot . '|' . $data;
        if (array_key_exists($cacheKey, $this->appointmentLocationCandidatesByDay)) {
            return $this->appointmentLocationCandidatesByDay[$cacheKey];
        }

        $roomSelect = $this->locationModel->slotTableHasRoomColumn()
            ? 's.id_stanza'
            : 'NULL AS id_stanza';

        $rows = $this->db->table('dap12_agenda_appuntamenti a')
            ->select("
                s.id_slot,
                s.ora_inizio,
                s.ora_fine,
                s.id_amb_legacy,
                {$roomSelect},
                s.ambulatorio,
                s.stanza
            ", false)
            ->join('dap11_agenda_slot s', 's.id_slot = a.id_slot', 'inner')
            ->where('s.id_dot', $idDot)
            ->where('s.data_slot', $data)
            ->where('a.stato <>', 'ANNULLATO')
            ->orderBy('s.ora_inizio', 'ASC')
            ->get()
            ->getResultArray();

        return $this->appointmentLocationCandidatesByDay[$cacheKey] = $this->buildLocationCandidates($rows);
    }

    protected function getSlotLocationCandidatesForDay(int $idDot, string $data): array
    {
        $cacheKey = $idDot . '|' . $data;
        if (array_key_exists($cacheKey, $this->slotLocationCandidatesByDay)) {
            return $this->slotLocationCandidatesByDay[$cacheKey];
        }

        $roomSelect = $this->locationModel->slotTableHasRoomColumn()
            ? 'id_stanza'
            : 'NULL AS id_stanza';

        $rows = $this->db->table('dap11_agenda_slot')
            ->select("
                id_slot,
                ora_inizio,
                ora_fine,
                id_amb_legacy,
                {$roomSelect},
                ambulatorio,
                stanza
            ", false)
            ->where('id_dot', $idDot)
            ->where('data_slot', $data)
            ->orderBy('ora_inizio', 'ASC')
            ->get()
            ->getResultArray();

        return $this->slotLocationCandidatesByDay[$cacheKey] = $this->buildLocationCandidates($rows);
    }

    protected function buildLocationCandidates(array $rows): array
    {
        $candidates = [];

        foreach ($rows as $row) {
            $location = $this->normalizeSlotLocation($row);
            if ($location === null) {
                continue;
            }

            $startTs = strtotime((string)($row['ora_inizio'] ?? ''));
            $endTs = strtotime((string)($row['ora_fine'] ?? ''));
            if ($startTs === false || $endTs === false) {
                continue;
            }

            $candidates[] = [
                'id_slot'   => (int)($row['id_slot'] ?? 0),
                'start_ts'  => $startTs,
                'end_ts'    => $endTs,
                'location'  => $location,
            ];
        }

        return $candidates;
    }

    protected function pickNearestLocationCandidate(array $candidates, string $oraInizioDt, string $oraFineDt): ?array
    {
        if ($candidates === []) {
            return null;
        }

        $targetStartTs = strtotime($oraInizioDt);
        $targetEndTs = strtotime($oraFineDt);
        if ($targetStartTs === false || $targetEndTs === false) {
            return null;
        }

        $targetMidTs = $targetStartTs + (int)(($targetEndTs - $targetStartTs) / 2);
        $nearest = null;
        $nearestDistance = null;
        $nearestStartDistance = null;

        foreach ($candidates as $candidate) {
            $candidateStartTs = (int)($candidate['start_ts'] ?? 0);
            $candidateEndTs = (int)($candidate['end_ts'] ?? 0);
            if ($candidateStartTs <= 0 || $candidateEndTs <= 0) {
                continue;
            }

            $candidateMidTs = $candidateStartTs + (int)(($candidateEndTs - $candidateStartTs) / 2);
            $distance = abs($candidateMidTs - $targetMidTs);
            $startDistance = abs($candidateStartTs - $targetStartTs);

            if (
                $nearest === null
                || $distance < $nearestDistance
                || ($distance === $nearestDistance && $startDistance < $nearestStartDistance)
            ) {
                $nearest = $candidate['location'];
                $nearestDistance = $distance;
                $nearestStartDistance = $startDistance;
            }
        }

        return $nearest;
    }

    protected function normalizeSlotLocation(array $slot): ?array
    {
        $location = [
            'id_amb_legacy' => !empty($slot['id_amb_legacy']) ? (int)$slot['id_amb_legacy'] : null,
            'id_stanza'     => !empty($slot['id_stanza']) ? (int)$slot['id_stanza'] : null,
            'ambulatorio'   => trim((string)($slot['ambulatorio'] ?? '')),
            'stanza'        => trim((string)($slot['stanza'] ?? '')),
        ];

        if (!$this->locationHasValue($location)) {
            return null;
        }

        try {
            return $this->locationModel->resolveSelection($location);
        } catch (\Throwable $e) {
            return $location;
        }
    }

    protected function locationHasValue(array $location): bool
    {
        return !empty($location['id_amb_legacy'])
            || !empty($location['id_stanza'])
            || trim((string)($location['ambulatorio'] ?? '')) !== ''
            || trim((string)($location['stanza'] ?? '')) !== '';
    }

    protected function isGiornoBloccato(int $idDot, string $dataAgenda): bool
    {
        return $this->db->table('dap21_agenda_giorni_bloccati')
            ->where('id_dot', $idDot)
            ->where('data_agenda', $dataAgenda)
            ->countAllResults() > 0;
    }

    protected function buildExtraSlotWindows(string $data, string $oraInizio, string $oraFine, int $durataMinuti): array
    {
        $cursor = new \DateTime($data . ' ' . $oraInizio . ':00');
        $limit = new \DateTime($data . ' ' . $oraFine . ':00');
        $windows = [];

        while ($cursor < $limit) {
            $slotStart = clone $cursor;
            $slotEnd = (clone $cursor)->modify('+' . $durataMinuti . ' minutes');

            if ($slotEnd > $limit) {
                break;
            }

            $windows[] = [
                'ora_inizio_dt' => $slotStart->format('Y-m-d H:i:s'),
                'ora_fine_dt'   => $slotEnd->format('Y-m-d H:i:s'),
                'ora_inizio'    => $slotStart->format('H:i'),
                'ora_fine'      => $slotEnd->format('H:i'),
            ];

            $cursor = $slotEnd;
        }

        if (empty($windows)) {
            throw new \Exception('L\'intervallo selezionato non contiene slot completi con la durata indicata.');
        }

        return $windows;
    }

    protected function isValidHourMinute(string $value): bool
    {
        return (bool)preg_match('/^\d{2}:\d{2}$/', $value);
    }

    protected function calculateMinutesBetweenHours(string $oraInizio, string $oraFine): int
    {
        $inizio = \DateTime::createFromFormat('H:i', $oraInizio);
        $fine = \DateTime::createFromFormat('H:i', $oraFine);

        if (!$inizio || !$fine) {
            return 0;
        }

        $inizioMinuti = ((int)$inizio->format('H') * 60) + (int)$inizio->format('i');
        $fineMinuti = ((int)$fine->format('H') * 60) + (int)$fine->format('i');

        return max(0, $fineMinuti - $inizioMinuti);
    }
}
