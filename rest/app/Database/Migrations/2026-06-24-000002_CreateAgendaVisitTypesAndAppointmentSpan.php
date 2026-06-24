<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateAgendaVisitTypesAndAppointmentSpan extends Migration
{
    public function up()
    {
        $this->createVisitTypesTable();
        $this->extendAppointmentsTable();
        $this->createAppointmentSlotLinkTable();
        $this->backfillAppointmentSpanMetadata();
    }

    public function down()
    {
        if ($this->db->tableExists('dap45_agenda_appuntamenti_slot')) {
            $this->forge->dropTable('dap45_agenda_appuntamenti_slot', true);
        }

        if ($this->db->tableExists('dap12_agenda_appuntamenti')) {
            foreach (['ora_fine_appuntamento', 'durata_minuti', 'tipo_visita_label', 'id_tipo_visita'] as $column) {
                if ($this->db->fieldExists($column, 'dap12_agenda_appuntamenti')) {
                    $this->forge->dropColumn('dap12_agenda_appuntamenti', $column);
                }
            }
        }

        if ($this->db->tableExists('dap44_agenda_tipi_visita')) {
            $this->forge->dropTable('dap44_agenda_tipi_visita', true);
        }
    }

    private function createVisitTypesTable(): void
    {
        if ($this->db->tableExists('dap44_agenda_tipi_visita')) {
            return;
        }

        $this->forge->addField([
            'id_tipo_visita' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'nome' => [
                'type' => 'VARCHAR',
                'constraint' => 160,
            ],
            'durata_minuti' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
            ],
            'attivo' => [
                'type' => 'TINYINT',
                'constraint' => 1,
                'default' => 1,
            ],
            'ordinamento' => [
                'type' => 'INT',
                'constraint' => 11,
                'default' => 0,
            ],
            'created_by' => [
                'type' => 'INT',
                'constraint' => 11,
                'null' => true,
            ],
            'updated_by' => [
                'type' => 'INT',
                'constraint' => 11,
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id_tipo_visita', true);
        $this->forge->addKey(['attivo', 'ordinamento']);
        $this->forge->createTable('dap44_agenda_tipi_visita', true);
    }

    private function extendAppointmentsTable(): void
    {
        if (!$this->db->tableExists('dap12_agenda_appuntamenti')) {
            return;
        }

        $definitions = [];

        if (!$this->db->fieldExists('id_tipo_visita', 'dap12_agenda_appuntamenti')) {
            $definitions['id_tipo_visita'] = [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
                'after' => $this->db->fieldExists('id_client', 'dap12_agenda_appuntamenti') ? 'id_client' : 'id_paziente',
            ];
        }

        if (!$this->db->fieldExists('tipo_visita_label', 'dap12_agenda_appuntamenti')) {
            $definitions['tipo_visita_label'] = [
                'type' => 'VARCHAR',
                'constraint' => 160,
                'null' => true,
                'after' => 'id_tipo_visita',
            ];
        }

        if (!$this->db->fieldExists('durata_minuti', 'dap12_agenda_appuntamenti')) {
            $definitions['durata_minuti'] = [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
                'after' => 'tipo_visita_label',
            ];
        }

        if (!$this->db->fieldExists('ora_fine_appuntamento', 'dap12_agenda_appuntamenti')) {
            $definitions['ora_fine_appuntamento'] = [
                'type' => 'DATETIME',
                'null' => true,
                'after' => 'durata_minuti',
            ];
        }

        if ($definitions !== []) {
            $this->forge->addColumn('dap12_agenda_appuntamenti', $definitions);
        }

        $this->createIndexIfMissing(
            'dap12_agenda_appuntamenti',
            'idx_dap12_agenda_app_tipo_visita',
            'CREATE INDEX idx_dap12_agenda_app_tipo_visita ON dap12_agenda_appuntamenti (id_tipo_visita)'
        );
    }

    private function createAppointmentSlotLinkTable(): void
    {
        if ($this->db->tableExists('dap45_agenda_appuntamenti_slot')) {
            return;
        }

        $this->forge->addField([
            'id_appuntamento_slot' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'id_appuntamento' => $this->resolveColumnDefinition(
                'dap12_agenda_appuntamenti',
                'id_appuntamento',
                ['type' => 'INT', 'constraint' => 11, 'unsigned' => true]
            ),
            'id_slot' => $this->resolveColumnDefinition(
                'dap11_agenda_slot',
                'id_slot',
                ['type' => 'INT', 'constraint' => 11, 'unsigned' => true]
            ),
            'posizione' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'default' => 1,
            ],
            'is_primario' => [
                'type' => 'TINYINT',
                'constraint' => 1,
                'default' => 0,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id_appuntamento_slot', true);
        $this->forge->addUniqueKey('id_slot', 'uq_dap45_agenda_slot');
        $this->forge->addUniqueKey(['id_appuntamento', 'id_slot'], 'uq_dap45_agenda_app_slot');
        $this->forge->addKey(['id_appuntamento', 'posizione'], false, false, 'idx_dap45_agenda_app_pos');

        if ($this->db->tableExists('dap12_agenda_appuntamenti')) {
            $this->forge->addForeignKey(
                'id_appuntamento',
                'dap12_agenda_appuntamenti',
                'id_appuntamento',
                'CASCADE',
                'CASCADE',
                'fk_dap45_app'
            );
        }

        if ($this->db->tableExists('dap11_agenda_slot')) {
            $this->forge->addForeignKey(
                'id_slot',
                'dap11_agenda_slot',
                'id_slot',
                'CASCADE',
                'CASCADE',
                'fk_dap45_slot'
            );
        }

        $this->forge->createTable('dap45_agenda_appuntamenti_slot', true);
    }

    private function backfillAppointmentSpanMetadata(): void
    {
        if (
            !$this->db->tableExists('dap12_agenda_appuntamenti')
            || !$this->db->tableExists('dap11_agenda_slot')
        ) {
            return;
        }

        if (
            $this->db->fieldExists('durata_minuti', 'dap12_agenda_appuntamenti')
            && $this->db->fieldExists('ora_fine_appuntamento', 'dap12_agenda_appuntamenti')
        ) {
            $this->db->query(
                "
                UPDATE dap12_agenda_appuntamenti a
                INNER JOIN dap11_agenda_slot s
                    ON s.id_slot = a.id_slot
                SET
                    a.durata_minuti = COALESCE(
                        a.durata_minuti,
                        TIMESTAMPDIFF(MINUTE, s.ora_inizio, s.ora_fine)
                    ),
                    a.ora_fine_appuntamento = COALESCE(
                        a.ora_fine_appuntamento,
                        s.ora_fine
                    )
                WHERE a.stato <> 'ANNULLATO'
                "
            );
        }

        if (!$this->db->tableExists('dap45_agenda_appuntamenti_slot')) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        $rows = $this->db->query(
            "
            SELECT a.id_appuntamento, a.id_slot
            FROM dap12_agenda_appuntamenti a
            LEFT JOIN dap45_agenda_appuntamenti_slot rel
                ON rel.id_appuntamento = a.id_appuntamento
            WHERE a.stato <> 'ANNULLATO'
              AND rel.id_appuntamento IS NULL
            "
        )->getResultArray();

        if ($rows === []) {
            return;
        }

        $insert = [];
        foreach ($rows as $row) {
            $appointmentId = (int) ($row['id_appuntamento'] ?? 0);
            $slotId = (int) ($row['id_slot'] ?? 0);
            if ($appointmentId <= 0 || $slotId <= 0) {
                continue;
            }

            $insert[] = [
                'id_appuntamento' => $appointmentId,
                'id_slot' => $slotId,
                'posizione' => 1,
                'is_primario' => 1,
                'created_at' => $now,
            ];
        }

        if ($insert !== []) {
            $this->db->table('dap45_agenda_appuntamenti_slot')->insertBatch($insert);
        }
    }

    private function createIndexIfMissing(string $table, string $indexName, string $sql): void
    {
        if (!$this->db->tableExists($table) || $this->indexExists($table, $indexName)) {
            return;
        }

        $this->db->query($sql);
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $row = $this->db->query(
            "
            SELECT COUNT(*) AS c
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = ?
              AND index_name = ?
            ",
            [$table, $indexName]
        )->getRowArray();

        return (int) ($row['c'] ?? 0) > 0;
    }

    /**
     * Build a field definition that stays compatible with an existing referenced column.
     *
     * @param array<string, mixed> $fallback
     * @return array<string, mixed>
     */
    private function resolveColumnDefinition(string $table, string $column, array $fallback): array
    {
        if (!$this->db->tableExists($table) || !$this->db->fieldExists($column, $table)) {
            return $fallback;
        }

        $row = $this->db
            ->query(sprintf("SHOW COLUMNS FROM `%s` LIKE ?", $table), [$column])
            ->getFirstRow('array');

        $type = strtolower((string) ($row['Type'] ?? ''));
        if ($type === '') {
            return $fallback;
        }

        $definition = $fallback;
        unset($definition['unsigned']);

        if (preg_match('/^([a-z]+)(?:\((\d+)\))?/', $type, $matches)) {
            $definition['type'] = strtoupper((string) ($matches[1] ?? $fallback['type']));

            if (!empty($matches[2])) {
                $definition['constraint'] = (int) $matches[2];
            }
        }

        if (str_contains($type, 'unsigned')) {
            $definition['unsigned'] = true;
        }

        return $definition;
    }
}
