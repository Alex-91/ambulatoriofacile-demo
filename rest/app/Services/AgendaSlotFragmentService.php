<?php

namespace App\Services;

use App\Database\Migrations\CreateAgendaSlotFragments;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Exception;

class AgendaSlotFragmentService
{
    public const TABLE = 'dap46_agenda_slot_frammenti';
    public const FEATURE_KEY = 'agenda_custom_time_residual_slots';
    public const PARENT_FEATURE_KEY = 'agenda_custom_appointment_time';

    private BaseConnection $db;
    private ?bool $tableReady = null;
    /** @var array<int, string>|null */
    private ?array $slotFields = null;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? \Config\Database::connect();
    }

    public function isReady(): bool
    {
        if ($this->tableReady === null) {
            $this->tableReady = $this->db->tableExists(self::TABLE);
        }

        return $this->tableReady;
    }

    public function ensureReady(): void
    {
        if ($this->isReady()) {
            return;
        }

        try {
            $migration = new CreateAgendaSlotFragments(Database::forge($this->db));
            $migration->up();
        } catch (\Throwable $e) {
            log_message('error', 'AgendaSlotFragmentService runtime migration failed: {message}', [
                'message' => $e->getMessage(),
            ]);
        }

        $this->tableReady = null;
        if (!$this->isReady()) {
            throw new Exception('La struttura del database non è aggiornata per creare gli slot adattati.');
        }
    }

    public static function isFeatureEnabled(
        bool $customAppointmentTimeEnabled,
        bool $residualSlotsEnabled
    ): bool {
        return $customAppointmentTimeEnabled && $residualSlotsEnabled;
    }

    public static function shouldManageCustomWindow(
        bool $featureEnabled,
        bool $appointmentAlreadyUsesFragments = false
    ): bool {
        return $featureEnabled || $appointmentAlreadyUsesFragments;
    }

    /** @param array<int, string> $enabledFeatureKeys */
    public static function assertFeatureDependencies(array $enabledFeatureKeys): void
    {
        if (
            in_array(self::FEATURE_KEY, $enabledFeatureKeys, true)
            && !in_array(self::PARENT_FEATURE_KEY, $enabledFeatureKeys, true)
        ) {
            throw new \InvalidArgumentException(
                'Gli slot residui possono essere attivati solo se sono attivi anche gli Orari personalizzati appuntamenti.'
            );
        }
    }

    /** @param array<int, int> $slotIds */
    public function hasFragmentsForSlots(array $slotIds): bool
    {
        $slotIds = array_values(array_unique(array_filter(array_map('intval', $slotIds))));
        if ($slotIds === [] || !$this->isReady()) {
            return false;
        }

        return $this->db->table(self::TABLE)
            ->select('id_frammento')
            ->whereIn('id_slot', $slotIds)
            ->get(1)
            ->getRowArray() !== null;
    }

    /**
     * @return array{before: ?array{start: string, end: string}, inside: array{start: string, end: string}, after: ?array{start: string, end: string}}
     */
    public static function calculatePartition(
        string $slotStart,
        string $slotEnd,
        string $windowStart,
        string $windowEnd
    ): array {
        $slotStartTs = strtotime($slotStart);
        $slotEndTs = strtotime($slotEnd);
        $windowStartTs = strtotime($windowStart);
        $windowEndTs = strtotime($windowEnd);

        if (
            $slotStartTs === false
            || $slotEndTs === false
            || $windowStartTs === false
            || $windowEndTs === false
            || $slotEndTs <= $slotStartTs
            || $windowEndTs <= $windowStartTs
        ) {
            throw new Exception('Intervallo slot non valido per la suddivisione.');
        }

        $insideStartTs = max($slotStartTs, $windowStartTs);
        $insideEndTs = min($slotEndTs, $windowEndTs);
        if ($insideEndTs <= $insideStartTs) {
            throw new Exception('Lo slot non interseca l’intervallo personalizzato.');
        }

        $format = static fn (int $timestamp): string => date('Y-m-d H:i:s', $timestamp);

        return [
            'before' => $slotStartTs < $insideStartTs
                ? ['start' => $format($slotStartTs), 'end' => $format($insideStartTs)]
                : null,
            'inside' => ['start' => $format($insideStartTs), 'end' => $format($insideEndTs)],
            'after' => $insideEndTs < $slotEndTs
                ? ['start' => $format($insideEndTs), 'end' => $format($slotEndTs)]
                : null,
        ];
    }

    /**
     * Suddivide soltanto i record di confine e conserva l'id dello slot originale
     * per la porzione occupata dall'appuntamento.
     *
     * @param array<int, array<string, mixed>> $coveredSlots
     * @return array<int, array<string, mixed>>
     */
    public function splitForWindow(
        array $coveredSlots,
        string $windowStart,
        string $windowEnd,
        string $timestamp
    ): array {
        $result = [];

        foreach ($coveredSlots as $slot) {
            $slotId = (int) ($slot['id_slot'] ?? 0);
            $slotStart = (string) ($slot['ora_inizio'] ?? '');
            $slotEnd = (string) ($slot['ora_fine'] ?? '');
            $partition = self::calculatePartition($slotStart, $slotEnd, $windowStart, $windowEnd);
            $requiresSplit = $partition['before'] !== null || $partition['after'] !== null;

            if ($slotId <= 0) {
                throw new Exception('Slot non valido durante la suddivisione.');
            }

            if (!$requiresSplit) {
                $result[] = $slot;
                continue;
            }

            $this->ensureReady();

            $metadata = $this->ensureFragmentMetadata($slot, $timestamp);
            foreach (['before', 'after'] as $position) {
                $range = $partition[$position];
                if ($range === null) {
                    continue;
                }

                $residualSlotId = $this->insertResidualSlot(
                    $slot,
                    $range['start'],
                    $range['end'],
                    $timestamp
                );
                $this->insertFragmentMetadata($residualSlotId, $metadata, $timestamp);
            }

            $inside = $partition['inside'];
            $this->db->table('dap11_agenda_slot')
                ->where('id_slot', $slotId)
                ->update([
                    'ora_inizio' => $inside['start'],
                    'ora_fine' => $inside['end'],
                    'updated_at' => $timestamp,
                ]);

            $slot['ora_inizio'] = $inside['start'];
            $slot['ora_fine'] = $inside['end'];
            $slot['is_slot_adattato'] = 1;
            $slot['slot_ora_inizio_originale'] = $metadata['ora_inizio_originale'];
            $slot['slot_ora_fine_originale'] = $metadata['ora_fine_originale'];
            $result[] = $slot;
        }

        usort($result, static function (array $left, array $right): int {
            $timeComparison = strcmp(
                (string) ($left['ora_inizio'] ?? ''),
                (string) ($right['ora_inizio'] ?? '')
            );

            return $timeComparison !== 0
                ? $timeComparison
                : ((int) ($left['id_slot'] ?? 0) <=> (int) ($right['id_slot'] ?? 0));
        });

        return $result;
    }

    public function decorateSlot(array $slot): array
    {
        $slot['is_slot_adattato'] = 0;
        if (!$this->isReady() || (int) ($slot['id_slot'] ?? 0) <= 0) {
            return $slot;
        }

        $metadata = $this->db->table(self::TABLE)
            ->where('id_slot', (int) $slot['id_slot'])
            ->get(1)
            ->getRowArray();
        if (!$metadata) {
            return $slot;
        }

        $slot['is_slot_adattato'] = 1;
        $slot['slot_gruppo_frammenti'] = (string) ($metadata['gruppo_token'] ?? '');
        $slot['slot_ora_inizio_originale'] = (string) ($metadata['ora_inizio_originale'] ?? '');
        $slot['slot_ora_fine_originale'] = (string) ($metadata['ora_fine_originale'] ?? '');

        return $slot;
    }

    /**
     * Ricompone un gruppo solo quando nessun frammento è occupato o bloccato.
     * Prima della ricomposizione conserva gli orari effettivi e riallinea i
     * riferimenti degli appuntamenti annullati allo slot canonico.
     *
     * @param array<int, int> $slotIds
     */
    public function compactGroupsForSlots(array $slotIds, string $timestamp): void
    {
        $slotIds = array_values(array_unique(array_filter(array_map('intval', $slotIds))));
        if ($slotIds === [] || !$this->isReady()) {
            return;
        }

        $groupRows = $this->db->table(self::TABLE)
            ->select('gruppo_token')
            ->whereIn('id_slot', $slotIds)
            ->groupBy('gruppo_token')
            ->get()
            ->getResultArray();

        foreach ($groupRows as $groupRow) {
            $groupToken = trim((string) ($groupRow['gruppo_token'] ?? ''));
            if ($groupToken !== '') {
                $this->compactGroup($groupToken, $timestamp);
            }
        }
    }

    /** @return array<string, mixed> */
    private function ensureFragmentMetadata(array $slot, string $timestamp): array
    {
        $slotId = (int) ($slot['id_slot'] ?? 0);
        $existing = $this->db->table(self::TABLE)
            ->where('id_slot', $slotId)
            ->get(1)
            ->getRowArray();
        if ($existing) {
            return $existing;
        }

        $payload = [
            'id_slot' => $slotId,
            'gruppo_token' => bin2hex(random_bytes(16)),
            'id_slot_origine' => $slotId,
            'ora_inizio_originale' => (string) ($slot['ora_inizio'] ?? ''),
            'ora_fine_originale' => (string) ($slot['ora_fine'] ?? ''),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ];
        $this->db->table(self::TABLE)->insert($payload);

        return $payload;
    }

    /** @param array<string, mixed> $metadata */
    private function insertFragmentMetadata(int $slotId, array $metadata, string $timestamp): void
    {
        $this->db->table(self::TABLE)->insert([
            'id_slot' => $slotId,
            'gruppo_token' => (string) ($metadata['gruppo_token'] ?? ''),
            'id_slot_origine' => (int) ($metadata['id_slot_origine'] ?? 0),
            'ora_inizio_originale' => (string) ($metadata['ora_inizio_originale'] ?? ''),
            'ora_fine_originale' => (string) ($metadata['ora_fine_originale'] ?? ''),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    private function insertResidualSlot(
        array $source,
        string $start,
        string $end,
        string $timestamp
    ): int {
        $payload = [];
        foreach ($this->getSlotFields() as $field) {
            if (
                $field === 'id_slot'
                || $field === 'ora_inizio'
                || $field === 'ora_fine'
                || $field === 'stato'
                || $field === 'created_at'
                || $field === 'updated_at'
            ) {
                continue;
            }

            if (array_key_exists($field, $source)) {
                $payload[$field] = $source[$field];
            }
        }

        $payload['ora_inizio'] = $start;
        $payload['ora_fine'] = $end;
        $payload['stato'] = 'LIBERO';
        if (in_array('created_at', $this->getSlotFields(), true)) {
            $payload['created_at'] = $timestamp;
        }
        if (in_array('updated_at', $this->getSlotFields(), true)) {
            $payload['updated_at'] = $timestamp;
        }

        $this->db->table('dap11_agenda_slot')->insert($payload);
        $slotId = (int) $this->db->insertID();
        if ($slotId <= 0) {
            throw new Exception('Impossibile creare lo slot adattato residuo.');
        }

        return $slotId;
    }

    /** @return array<int, string> */
    private function getSlotFields(): array
    {
        if ($this->slotFields === null) {
            $this->slotFields = $this->db->getFieldNames('dap11_agenda_slot');
        }

        return $this->slotFields;
    }

    private function compactGroup(string $groupToken, string $timestamp): void
    {
        $rows = $this->db->query(
            'SELECT f.gruppo_token, f.id_slot_origine, f.ora_inizio_originale, f.ora_fine_originale,
                    s.*
             FROM ' . self::TABLE . ' f
             INNER JOIN dap11_agenda_slot s ON s.id_slot = f.id_slot
             WHERE f.gruppo_token = ?
             ORDER BY s.ora_inizio, s.id_slot
             FOR UPDATE',
            [$groupToken]
        )->getResultArray();
        if (count($rows) < 2 || !$this->isGroupContiguous($rows)) {
            return;
        }

        $fragmentIds = array_values(array_map(
            static fn (array $row): int => (int) ($row['id_slot'] ?? 0),
            $rows
        ));
        if ($this->groupHasActiveAppointments($fragmentIds) || $this->groupHasActiveLocks($fragmentIds, $timestamp)) {
            return;
        }

        $preferredId = (int) ($rows[0]['id_slot_origine'] ?? 0);
        $canonicalId = in_array($preferredId, $fragmentIds, true) ? $preferredId : $fragmentIds[0];
        $idsToDelete = array_values(array_diff($fragmentIds, [$canonicalId]));
        $originalStart = (string) ($rows[0]['ora_inizio_originale'] ?? '');
        $originalEnd = (string) ($rows[0]['ora_fine_originale'] ?? '');

        $this->repointCancelledAppointments($fragmentIds, $canonicalId, $timestamp);

        if ($idsToDelete !== []) {
            $this->db->table('dap14_agenda_lock')
                ->whereIn('id_slot', $idsToDelete)
                ->where('stato <>', 'ATTIVO')
                ->delete();
            $this->db->table('dap11_agenda_slot')
                ->whereIn('id_slot', $idsToDelete)
                ->delete();
        }

        $isDayBlocked = $this->db->table('dap21_agenda_giorni_bloccati')
            ->where('id_dot', (int) ($rows[0]['id_dot'] ?? 0))
            ->where('data_agenda', (string) ($rows[0]['data_slot'] ?? ''))
            ->countAllResults() > 0;

        $this->db->table('dap11_agenda_slot')
            ->where('id_slot', $canonicalId)
            ->update([
                'ora_inizio' => $originalStart,
                'ora_fine' => $originalEnd,
                'stato' => $isDayBlocked ? 'CHIUSO' : 'LIBERO',
                'updated_at' => $timestamp,
            ]);
        $this->db->table(self::TABLE)
            ->where('id_slot', $canonicalId)
            ->delete();
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function isGroupContiguous(array $rows): bool
    {
        $originalStart = (string) ($rows[0]['ora_inizio_originale'] ?? '');
        $originalEnd = (string) ($rows[0]['ora_fine_originale'] ?? '');
        $expectedStart = $originalStart;

        foreach ($rows as $row) {
            if (
                (string) ($row['ora_inizio_originale'] ?? '') !== $originalStart
                || (string) ($row['ora_fine_originale'] ?? '') !== $originalEnd
                || (string) ($row['ora_inizio'] ?? '') !== $expectedStart
            ) {
                return false;
            }

            $expectedStart = (string) ($row['ora_fine'] ?? '');
        }

        return $expectedStart === $originalEnd;
    }

    /** @param array<int, int> $slotIds */
    private function groupHasActiveAppointments(array $slotIds): bool
    {
        $placeholders = implode(', ', array_fill(0, count($slotIds), '?'));
        $params = array_merge($slotIds, $slotIds);
        $linkCondition = $this->db->tableExists('dap45_agenda_appuntamenti_slot')
            ? ' OR EXISTS (
                    SELECT 1 FROM dap45_agenda_appuntamenti_slot rel
                    WHERE rel.id_appuntamento = a.id_appuntamento
                      AND rel.id_slot IN (' . $placeholders . ')
                )'
            : '';
        if ($linkCondition === '') {
            $params = $slotIds;
        }

        return $this->db->query(
            'SELECT a.id_appuntamento
             FROM dap12_agenda_appuntamenti a
             WHERE a.stato <> \'ANNULLATO\'
               AND (a.id_slot IN (' . $placeholders . ')' . $linkCondition . ')
             LIMIT 1',
            $params
        )->getRowArray() !== null;
    }

    /** @param array<int, int> $slotIds */
    private function groupHasActiveLocks(array $slotIds, string $timestamp): bool
    {
        return $this->db->table('dap14_agenda_lock')
            ->select('id_lock')
            ->whereIn('id_slot', $slotIds)
            ->where('stato', 'ATTIVO')
            ->where('expires_at >=', $timestamp)
            ->get(1)
            ->getRowArray() !== null;
    }

    /** @param array<int, int> $fragmentIds */
    private function repointCancelledAppointments(array $fragmentIds, int $canonicalId, string $timestamp): void
    {
        $hasCustomStart = $this->db->fieldExists('ora_inizio_appuntamento', 'dap12_agenda_appuntamenti');
        $hasCustomEnd = $this->db->fieldExists('ora_fine_appuntamento', 'dap12_agenda_appuntamenti');
        $select = 'a.id_appuntamento, s.ora_inizio, s.ora_fine, '
            . ($hasCustomStart ? 'a.ora_inizio_appuntamento' : 'NULL AS ora_inizio_appuntamento')
            . ', '
            . ($hasCustomEnd ? 'a.ora_fine_appuntamento' : 'NULL AS ora_fine_appuntamento');
        $rows = $this->db->table('dap12_agenda_appuntamenti a')
            ->select($select, false)
            ->join('dap11_agenda_slot s', 's.id_slot = a.id_slot', 'inner')
            ->whereIn('a.id_slot', $fragmentIds)
            ->where('a.stato', 'ANNULLATO')
            ->get()
            ->getResultArray();

        foreach ($rows as $row) {
            $payload = ['id_slot' => $canonicalId];
            if (
                $hasCustomStart
                && empty($row['ora_inizio_appuntamento'])
            ) {
                $payload['ora_inizio_appuntamento'] = (string) ($row['ora_inizio'] ?? '');
            }
            if (
                $hasCustomEnd
                && empty($row['ora_fine_appuntamento'])
            ) {
                $payload['ora_fine_appuntamento'] = (string) ($row['ora_fine'] ?? '');
            }
            if ($this->db->fieldExists('updated_at', 'dap12_agenda_appuntamenti')) {
                $payload['updated_at'] = $timestamp;
            }

            $this->db->table('dap12_agenda_appuntamenti')
                ->where('id_appuntamento', (int) ($row['id_appuntamento'] ?? 0))
                ->update($payload);
        }
    }
}
