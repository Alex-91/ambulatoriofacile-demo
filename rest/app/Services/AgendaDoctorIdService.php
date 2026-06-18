<?php

namespace App\Services;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

class AgendaDoctorIdService
{
    public const LOCAL_ID_BASE = 1000000000;

    private BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function ensureForPersonale(int $idPersonale, ?int $tipo = null): int
    {
        if ($idPersonale <= 0) {
            return 0;
        }

        $row = $this->db->table('dap03_personale')
            ->select('id_personale, tipo, COALESCE(legacy_id_dot, 0) AS legacy_id_dot')
            ->where('id_personale', $idPersonale)
            ->get(1)
            ->getRowArray();

        if (!$row) {
            return 0;
        }

        $tipo = $tipo ?? (int)($row['tipo'] ?? 0);
        if (!$this->isAgendaProfessionalType($tipo)) {
            return 0;
        }

        $currentId = (int)($row['legacy_id_dot'] ?? 0);
        if ($currentId > 0) {
            return $currentId;
        }

        $agendaId = $this->reserveAgendaDoctorId($idPersonale);

        $this->db->table('dap03_personale')
            ->where('id_personale', $idPersonale)
            ->groupStart()
                ->where('legacy_id_dot IS NULL', null, false)
                ->orWhere('legacy_id_dot <=', 0)
            ->groupEnd()
            ->update(['legacy_id_dot' => $agendaId]);

        $freshRow = $this->db->table('dap03_personale')
            ->select('COALESCE(legacy_id_dot, 0) AS legacy_id_dot')
            ->where('id_personale', $idPersonale)
            ->get(1)
            ->getRowArray();

        return (int)($freshRow['legacy_id_dot'] ?? 0);
    }

    public function backfillMissing(): int
    {
        $rows = $this->db->table('dap03_personale')
            ->select('id_personale, tipo')
            ->whereIn('tipo', [1, 2])
            ->groupStart()
                ->where('legacy_id_dot IS NULL', null, false)
                ->orWhere('legacy_id_dot <=', 0)
            ->groupEnd()
            ->orderBy('id_personale', 'ASC')
            ->get()
            ->getResultArray();

        $updated = 0;

        foreach ($rows as $row) {
            $agendaId = $this->ensureForPersonale(
                (int)($row['id_personale'] ?? 0),
                (int)($row['tipo'] ?? 0)
            );

            if ($agendaId > 0) {
                $updated++;
            }
        }

        return $updated;
    }

    private function isAgendaProfessionalType(int $tipo): bool
    {
        return in_array($tipo, [1, 2], true);
    }

    private function reserveAgendaDoctorId(int $idPersonale): int
    {
        $preferredId = self::LOCAL_ID_BASE + $idPersonale;
        if ($this->isAgendaDoctorIdAvailable($preferredId, $idPersonale)) {
            return $preferredId;
        }

        $row = $this->db->table('dap03_personale')
            ->select('MAX(COALESCE(legacy_id_dot, 0)) AS max_id', false)
            ->where('legacy_id_dot >=', self::LOCAL_ID_BASE)
            ->get(1)
            ->getRowArray();

        $candidate = max(self::LOCAL_ID_BASE, (int)($row['max_id'] ?? 0)) + 1;
        while (!$this->isAgendaDoctorIdAvailable($candidate, $idPersonale)) {
            $candidate++;
        }

        return $candidate;
    }

    private function isAgendaDoctorIdAvailable(int $agendaId, int $ignorePersonaleId): bool
    {
        if ($agendaId <= 0) {
            return false;
        }

        $count = $this->db->table('dap03_personale')
            ->where('legacy_id_dot', $agendaId)
            ->where('id_personale <>', $ignorePersonaleId)
            ->countAllResults();

        return $count === 0;
    }
}
