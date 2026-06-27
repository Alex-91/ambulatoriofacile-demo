<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddColorToAgendaVisitTypes extends Migration
{
    private const TABLE = 'dap44_agenda_tipi_visita';

    /** @var array<int, string> */
    private const DEFAULT_COLORS = [
        '#3C8DBC',
        '#16A085',
        '#5E72E4',
        '#EB6B56',
        '#8E44AD',
        '#F39C12',
        '#27AE60',
        '#C0392B',
        '#2C82C9',
        '#D35400',
    ];

    public function up()
    {
        if (!$this->db->tableExists(self::TABLE)) {
            return;
        }

        if (!$this->db->fieldExists('colore', self::TABLE)) {
            $this->forge->addColumn(self::TABLE, [
                'colore' => [
                    'type' => 'VARCHAR',
                    'constraint' => 7,
                    'null' => true,
                    'after' => 'durata_minuti',
                ],
            ]);
        }

        $this->backfillMissingColors();
    }

    public function down()
    {
        if ($this->db->tableExists(self::TABLE) && $this->db->fieldExists('colore', self::TABLE)) {
            $this->forge->dropColumn(self::TABLE, 'colore');
        }
    }

    private function backfillMissingColors(): void
    {
        if (
            !$this->db->tableExists(self::TABLE)
            || !$this->db->fieldExists('colore', self::TABLE)
        ) {
            return;
        }

        $rows = $this->db->table(self::TABLE)
            ->select('id_tipo_visita, nome, ordinamento, colore')
            ->orderBy('ordinamento', 'ASC')
            ->orderBy('nome', 'ASC')
            ->orderBy('id_tipo_visita', 'ASC')
            ->get()
            ->getResultArray();

        if ($rows === []) {
            return;
        }

        $updates = [];
        $paletteSize = count(self::DEFAULT_COLORS);

        foreach (array_values($rows) as $index => $row) {
            $idTipoVisita = (int) ($row['id_tipo_visita'] ?? 0);
            if ($idTipoVisita <= 0) {
                continue;
            }

            $currentColor = strtoupper(trim((string) ($row['colore'] ?? '')));
            if ($this->isValidHexColor($currentColor)) {
                continue;
            }

            $updates[] = [
                'id_tipo_visita' => $idTipoVisita,
                'colore' => self::DEFAULT_COLORS[$index % $paletteSize],
            ];
        }

        if ($updates !== []) {
            $this->db->table(self::TABLE)->updateBatch($updates, 'id_tipo_visita');
        }
    }

    private function isValidHexColor(string $value): bool
    {
        return preg_match('/^#[0-9A-F]{6}$/', $value) === 1;
    }
}
