<?php

namespace App\Models;

use CodeIgniter\Model;
use Exception;

class AgendaBackupModel extends Model
{
    private const DETAIL_BATCH_SIZE = 250;
    private const DELETE_BATCH_SIZE = 500;

    protected $db;

    public function __construct()
    {
        parent::__construct();
        $this->db = \Config\Database::connect();
    }

    public function getSlotPeriodo(int $idDot, string $dataInizio, string $dataFine): array
    {
        return $this->buildSlotPeriodoQuery($idDot, $dataInizio, $dataFine)
            ->get()
            ->getResultArray();
    }

    public function getSlotPeriodoStats(int $idDot, string $dataInizio, string $dataFine): array
    {
        $row = $this->db->table('dap11_agenda_slot s')
            ->select(
                'COUNT(*) AS totale_righe, SUM(CASE WHEN a.id_appuntamento IS NULL THEN 0 ELSE 1 END) AS totale_appuntamenti',
                false
            )
            ->join('dap12_agenda_appuntamenti a', 'a.id_slot = s.id_slot', 'left')
            ->where('s.id_dot', $idDot)
            ->where('s.data_slot >=', $dataInizio)
            ->where('s.data_slot <=', $dataFine)
            ->get()
            ->getRowArray();

        return [
            'totale_righe'        => (int)($row['totale_righe'] ?? 0),
            'totale_appuntamenti' => (int)($row['totale_appuntamenti'] ?? 0),
        ];
    }

    public function processSlotPeriodoInChunks(
        int $idDot,
        string $dataInizio,
        string $dataFine,
        callable $consumer,
        int $chunkSize = self::DETAIL_BATCH_SIZE,
        ?callable $afterChunk = null
    ): void {
        $offset = 0;
        $processed = 0;

        while (true) {
            $rows = $this->buildSlotPeriodoQuery($idDot, $dataInizio, $dataFine)
                ->limit($chunkSize, $offset)
                ->get()
                ->getResultArray();

            if (empty($rows)) {
                break;
            }

            $consumer($rows);
            $processed += count($rows);
            if ($afterChunk !== null) {
                $afterChunk($processed);
            }
            $offset += count($rows);

            if (count($rows) < $chunkSize) {
                break;
            }
        }
    }

    public function saveBackupRecord(
        int $idDot,
        string $dataInizio,
        string $dataFine,
        string $nomeFile,
        string $percorsoFile,
        int $totaleSlot,
        int $totaleAppuntamenti,
        int $userId,
        array $rows
    ): int {
        $this->db->transStart();

        $this->db->table('dap19_agenda_backup')->insert([
            'id_dot'              => $idDot,
            'data_inizio'         => $dataInizio,
            'data_fine'           => $dataFine,
            'nome_file_pdf'       => $nomeFile,
            'percorso_file_pdf'   => $percorsoFile,
            'totale_slot'         => $totaleSlot,
            'totale_appuntamenti' => $totaleAppuntamenti,
            'created_by'          => $userId,
            'created_at'          => date('Y-m-d H:i:s'),
        ]);

        $idBackup = (int)$this->db->insertID();

        foreach ($rows as $r) {
            $this->db->table('dap20_agenda_backup_dettaglio')->insert([
                'id_backup'       => $idBackup,
                'id_slot'         => $r['id_slot'] ?? null,
                'id_appuntamento' => $r['id_appuntamento'] ?? null,
                'data_slot'       => $r['data_slot'] ?? null,
                'ora_inizio'      => $r['ora_inizio'] ?? null,
                'ora_fine'        => $r['ora_fine'] ?? null,
                'tipo_slot'       => $r['tipo_slot'] ?? null,
                'stato_slot'      => $r['stato_slot'] ?? null,
                'paziente'        => trim(($r['cognome'] ?? '') . ' ' . ($r['nome'] ?? '')),
                'motivo_visita'   => $r['motivo_visita'] ?? null,
                'note'            => $r['note'] ?? null,
            ]);
        }

        $this->db->transComplete();

        if (!$this->db->transStatus()) {
            throw new Exception('Errore nel salvataggio del backup.');
        }

        return $idBackup;
    }

    public function saveBackupRecordFromPeriodo(
        int $idDot,
        string $dataInizio,
        string $dataFine,
        string $nomeFile,
        string $percorsoFile,
        int $totaleSlot,
        int $totaleAppuntamenti,
        int $userId,
        ?callable $progressCallback = null
    ): int {
        $this->db->transStart();

        $this->db->table('dap19_agenda_backup')->insert([
            'id_dot'              => $idDot,
            'data_inizio'         => $dataInizio,
            'data_fine'           => $dataFine,
            'nome_file_pdf'       => $nomeFile,
            'percorso_file_pdf'   => $percorsoFile,
            'totale_slot'         => $totaleSlot,
            'totale_appuntamenti' => $totaleAppuntamenti,
            'created_by'          => $userId,
            'created_at'          => date('Y-m-d H:i:s'),
        ]);

        $idBackup = (int)$this->db->insertID();

        $this->processSlotPeriodoInChunks(
            $idDot,
            $dataInizio,
            $dataFine,
            function (array $rows) use ($idBackup): void {
            $batch = [];

            foreach ($rows as $r) {
                $batch[] = [
                    'id_backup'       => $idBackup,
                    'id_slot'         => $r['id_slot'] ?? null,
                    'id_appuntamento' => $r['id_appuntamento'] ?? null,
                    'data_slot'       => $r['data_slot'] ?? null,
                    'ora_inizio'      => $r['ora_inizio'] ?? null,
                    'ora_fine'        => $r['ora_fine'] ?? null,
                    'tipo_slot'       => $r['tipo_slot'] ?? null,
                    'stato_slot'      => $r['stato_slot'] ?? null,
                    'paziente'        => trim(($r['cognome'] ?? '') . ' ' . ($r['nome'] ?? '')),
                    'motivo_visita'   => $r['motivo_visita'] ?? null,
                    'note'            => $r['note'] ?? null,
                ];
            }

            if (!empty($batch)) {
                $this->db->table('dap20_agenda_backup_dettaglio')->insertBatch($batch);
            }
            },
            self::DETAIL_BATCH_SIZE,
            $progressCallback !== null
                ? static function (int $processed) use ($progressCallback, $totaleSlot): void {
                    $progressCallback($processed, $totaleSlot);
                }
                : null
        );

        $this->db->transComplete();

        if (!$this->db->transStatus()) {
            throw new Exception('Errore nel salvataggio del backup.');
        }

        return $idBackup;
    }

    public function deletePeriodoAgenda(int $idDot, string $dataInizio, string $dataFine, ?callable $progressCallback = null): void
    {
        $this->db->transStart();
        $processed = 0;

        while (true) {
            $slotIds = $this->db->table('dap11_agenda_slot')
                ->select('id_slot')
                ->where('id_dot', $idDot)
                ->where('data_slot >=', $dataInizio)
                ->where('data_slot <=', $dataFine)
                ->orderBy('id_slot', 'ASC')
                ->limit(self::DELETE_BATCH_SIZE)
                ->get()
                ->getResultArray();

            if (empty($slotIds)) {
                break;
            }

            $ids = array_map(static fn($r) => (int)$r['id_slot'], $slotIds);

            $this->db->table('dap12_agenda_appuntamenti')->whereIn('id_slot', $ids)->delete();
            $this->db->table('dap14_agenda_lock')->whereIn('id_slot', $ids)->delete();
            $this->db->table('dap11_agenda_slot')->whereIn('id_slot', $ids)->delete();

            $processed += count($ids);
            if ($progressCallback !== null) {
                $progressCallback($processed);
            }
        }

        $this->db->transComplete();

        if (!$this->db->transStatus()) {
            throw new Exception('Errore durante la cancellazione dei vecchi slot.');
        }
    }

    protected function buildSlotPeriodoQuery(int $idDot, string $dataInizio, string $dataFine)
    {
        return $this->db->table('dap11_agenda_slot s')
            ->select("
                s.id_slot,
                s.data_slot,
                s.ora_inizio,
                s.ora_fine,
                s.tipo_slot,
                s.stato AS stato_slot,
                a.id_appuntamento,
                a.cognome,
                a.nome,
                a.motivo_visita,
                a.note
            ")
            ->join('dap12_agenda_appuntamenti a', 'a.id_slot = s.id_slot', 'left')
            ->where('s.id_dot', $idDot)
            ->where('s.data_slot >=', $dataInizio)
            ->where('s.data_slot <=', $dataFine)
            ->orderBy('s.data_slot', 'ASC')
            ->orderBy('s.ora_inizio', 'ASC')
            ->orderBy('s.id_slot', 'ASC');
    }
}
