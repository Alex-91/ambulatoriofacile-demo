<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateAgendaConfigFasce extends Migration
{
    private string $table = 'far10_agenda_config_fasce';

    public function up()
    {
        if (!$this->db->tableExists($this->table)) {
            $this->forge->addField([
                'id_config_fascia' => [
                    'type'           => 'BIGINT',
                    'auto_increment' => true,
                ],
                'id_config_giorno' => [
                    'type' => 'BIGINT',
                ],
                'ordine' => [
                    'type'       => 'SMALLINT',
                    'default'    => 1,
                ],
                'ora_inizio' => [
                    'type' => 'TIME',
                ],
                'ora_fine' => [
                    'type' => 'TIME',
                ],
                'durata_slot' => [
                    'type' => 'INT',
                ],
                'created_at' => [
                    'type' => 'DATETIME',
                    'null' => false,
                ],
                'updated_at' => [
                    'type' => 'DATETIME',
                    'null' => true,
                ],
            ]);

            $this->forge->addKey('id_config_fascia', true);
            $this->forge->addUniqueKey(['id_config_giorno', 'ordine'], 'uk_far10_agenda_config_fasce_giorno_ordine');
            $this->forge->addKey(['id_config_giorno', 'ora_inizio'], false, false, 'idx_far10_agenda_config_fasce_giorno_inizio');
            $this->forge->createTable($this->table, true);
        }

        $existingRows = $this->db->table($this->table)
            ->select('id_config_giorno')
            ->get()
            ->getResultArray();

        $existingDayIds = [];
        foreach ($existingRows as $row) {
            $existingDayIds[(int)($row['id_config_giorno'] ?? 0)] = true;
        }

        $giorni = $this->db->table('far10_agenda_config_giorni')
            ->orderBy('id_config_giorno', 'ASC')
            ->get()
            ->getResultArray();

        foreach ($giorni as $giorno) {
            $idConfigGiorno = (int)($giorno['id_config_giorno'] ?? 0);
            if ($idConfigGiorno <= 0 || isset($existingDayIds[$idConfigGiorno])) {
                continue;
            }

            $fasce = $this->buildLegacyRanges($giorno);
            if (empty($fasce)) {
                continue;
            }

            foreach ($fasce as $index => $fascia) {
                $this->db->table($this->table)->insert([
                    'id_config_giorno' => $idConfigGiorno,
                    'ordine'           => $index + 1,
                    'ora_inizio'       => $fascia['ora_inizio'],
                    'ora_fine'         => $fascia['ora_fine'],
                    'durata_slot'      => $fascia['durata_slot'],
                    'created_at'       => date('Y-m-d H:i:s'),
                ]);
            }
        }
    }

    public function down()
    {
        $this->forge->dropTable($this->table, true);
    }

    private function buildLegacyRanges(array $giorno): array
    {
        $fasce = [];

        if (
            (int)($giorno['mattina_attiva'] ?? 0) === 1
            && !empty($giorno['mattina_ora_inizio'])
            && !empty($giorno['mattina_ora_fine'])
            && (int)($giorno['mattina_durata_slot'] ?? 0) > 0
        ) {
            $fasce[] = [
                'ora_inizio'  => $giorno['mattina_ora_inizio'],
                'ora_fine'    => $giorno['mattina_ora_fine'],
                'durata_slot' => (int)$giorno['mattina_durata_slot'],
            ];
        }

        if (
            (int)($giorno['pomeriggio_attiva'] ?? 0) === 1
            && !empty($giorno['pomeriggio_ora_fine'])
            && (int)($giorno['pomeriggio_durata_slot'] ?? 0) > 0
        ) {
            $oraInizio = $this->resolveLegacyAfternoonStart($giorno);
            if ($oraInizio !== null) {
                $fasce[] = [
                    'ora_inizio'  => $oraInizio,
                    'ora_fine'    => $giorno['pomeriggio_ora_fine'],
                    'durata_slot' => (int)$giorno['pomeriggio_durata_slot'],
                ];
            }
        }

        usort($fasce, static function (array $left, array $right): int {
            return strcmp((string)$left['ora_inizio'], (string)$right['ora_inizio']);
        });

        return $fasce;
    }

    private function resolveLegacyAfternoonStart(array $giorno): ?string
    {
        $mode = (string)($giorno['pomeriggio_modalita_inizio'] ?? 'FINE_MATTINA');

        if ($mode === 'ORE_14') {
            return '14:00:00';
        }

        if ($mode === 'MANUALE') {
            return !empty($giorno['pomeriggio_ora_inizio']) ? (string)$giorno['pomeriggio_ora_inizio'] : null;
        }

        return !empty($giorno['mattina_ora_fine']) ? (string)$giorno['mattina_ora_fine'] : null;
    }
}
