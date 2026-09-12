<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;

/** Keeps adapted fragments covered by an extra slot in the same appointment. */
final class AgendaExtraSlotCoverageService
{
    private ?bool $hasFragments = null;
    private ?bool $hasLinks = null;
    private array $appointmentFields = [];

    public function __construct(private BaseConnection $db)
    {
    }

    /**
     * Normal configured slots keep their existing semantics. Only fragments
     * intersecting an EXTRA in this appointment need additional coverage.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAdditionalResiduals(array $coveredSlots, string $start, string $end): array
    {
        $extras = array_values(array_filter($coveredSlots, static fn(array $slot): bool =>
            strtoupper((string) ($slot['origine_slot'] ?? '')) === 'EXTRA'
        ));
        if ($extras === [] || !$this->hasFragments()) {
            return [];
        }

        $ids = array_map(static fn(array $slot): int => (int) $slot['id_slot'], $coveredSlots);
        $rows = $this->db->table('dap11_agenda_slot s')
            ->select('s.*')
            ->join(AgendaSlotFragmentService::TABLE . ' f', 'f.id_slot = s.id_slot')
            ->where('s.id_dot', (int) $extras[0]['id_dot'])
            ->where('s.data_slot', (string) $extras[0]['data_slot'])
            ->where('s.ora_inizio <', $end)
            ->where('s.ora_fine >', $start)
            ->whereNotIn('s.id_slot', $ids)
            ->orderBy('s.ora_inizio')->orderBy('s.id_slot')
            ->get()->getResultArray();

        return array_values(array_filter($rows, static function (array $row) use ($extras): bool {
            foreach ($extras as $extra) {
                if ($row['ora_inizio'] < $extra['ora_fine'] && $row['ora_fine'] > $extra['ora_inizio']) {
                    return true;
                }
            }
            return false;
        }));
    }

    /**
     * Compatibility for appointments saved before residual links were recorded.
     * Used by both calendar and availability queries; no runtime data repair.
     */
    public function coveredResidualExistsSql(string $slotAlias, int $ignoreAppointmentId = 0, bool $fullyCovered = true): string
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $slotAlias)) {
            throw new \InvalidArgumentException('Alias slot non valido.');
        }
        if (!$this->hasFragments()) {
            return '1 = 0';
        }

        $this->hasLinks ??= $this->db->tableExists('dap45_agenda_appuntamenti_slot');
        $start = $this->appointmentTimeSql('ora_inizio_appuntamento', 'ora_inizio');
        $end = $this->appointmentTimeSql('ora_fine_appuntamento', 'ora_fine');
        $timeCondition = $fullyCovered
            ? "{$start} <= {$slotAlias}.ora_inizio AND {$end} >= {$slotAlias}.ora_fine"
            : "{$start} < {$slotAlias}.ora_fine AND {$end} > {$slotAlias}.ora_inizio";
        $extraLink = $this->hasLinks
            ? ' OR EXISTS (SELECT 1 FROM dap45_agenda_appuntamenti_slot extra_rel
                           WHERE extra_rel.id_appuntamento = extra_a.id_appuntamento
                             AND extra_rel.id_slot = extra_s.id_slot)'
            : '';
        $ignore = $ignoreAppointmentId > 0 ? ' AND extra_a.id_appuntamento <> ' . $ignoreAppointmentId : '';

        return "(EXISTS (SELECT 1 FROM " . AgendaSlotFragmentService::TABLE . " extra_f
                         WHERE extra_f.id_slot = {$slotAlias}.id_slot)
            AND EXISTS (
                SELECT 1 FROM dap12_agenda_appuntamenti extra_a
                INNER JOIN dap11_agenda_slot extra_primary ON extra_primary.id_slot = extra_a.id_slot
                WHERE extra_a.id_dot = {$slotAlias}.id_dot
                  AND extra_primary.data_slot = {$slotAlias}.data_slot
                  AND extra_a.stato <> 'ANNULLATO' {$ignore}
                  AND {$timeCondition}
                  AND EXISTS (
                      SELECT 1 FROM dap11_agenda_slot extra_s
                      WHERE extra_s.id_dot = {$slotAlias}.id_dot
                        AND extra_s.data_slot = {$slotAlias}.data_slot
                        AND extra_s.origine_slot = 'EXTRA'
                        AND extra_s.ora_inizio < {$slotAlias}.ora_fine
                        AND extra_s.ora_fine > {$slotAlias}.ora_inizio
                        AND (extra_s.id_slot = extra_a.id_slot {$extraLink})
                  )
            ))";
    }

    public function hasOverlappingExtraAppointment(int $slotId, int $ignoreAppointmentId = 0): bool
    {
        if (!$this->hasFragments()) {
            return false;
        }
        return $this->db->table('dap11_agenda_slot s')->select('s.id_slot')
            ->where('s.id_slot', $slotId)
            ->where($this->coveredResidualExistsSql('s', $ignoreAppointmentId, false), null, false)
            ->get(1)->getRowArray() !== null;
    }

    private function hasFragments(): bool
    {
        return $this->hasFragments ??= $this->db->tableExists(AgendaSlotFragmentService::TABLE);
    }

    private function appointmentTimeSql(string $field, string $slotField): string
    {
        $this->appointmentFields[$field] ??= $this->db->fieldExists($field, 'dap12_agenda_appuntamenti');
        return $this->appointmentFields[$field]
            ? 'COALESCE(extra_a.' . $field . ', extra_primary.' . $slotField . ')'
            : 'extra_primary.' . $slotField;
    }
}
