<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateAgendaAmbulatorioRooms extends Migration
{
    public function up()
    {
        $this->addColumnIfMissing('dap42_ambulatori', 'attiva', [
            'type'       => 'TINYINT',
            'constraint' => 1,
            'default'    => 1,
            'null'       => false,
            'after'      => 'telefono',
        ]);

        $this->addColumnIfMissing('dap42_ambulatori', 'ordinamento', [
            'type'       => 'INT',
            'constraint' => 11,
            'unsigned'   => true,
            'default'    => 0,
            'null'       => false,
            'after'      => 'attiva',
        ]);

        if (!$this->db->tableExists('dap43_ambulatori_stanze')) {
            $this->forge->addField([
                'id_stanza' => [
                    'type'           => 'INT',
                    'constraint'     => 11,
                    'unsigned'       => true,
                    'auto_increment' => true,
                ],
                'id_amb_legacy' => [
                    'type'       => 'INT',
                    'constraint' => 11,
                    'unsigned'   => true,
                    'null'       => false,
                ],
                'nome' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 100,
                    'null'       => false,
                ],
                'ordinamento' => [
                    'type'       => 'INT',
                    'constraint' => 11,
                    'unsigned'   => true,
                    'default'    => 0,
                    'null'       => false,
                ],
                'attiva' => [
                    'type'       => 'TINYINT',
                    'constraint' => 1,
                    'default'    => 1,
                    'null'       => false,
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
            $this->forge->addKey('id_stanza', true);
            $this->forge->createTable('dap43_ambulatori_stanze');
        }

        $this->addColumnIfMissing('dap10_agenda_config_fasce', 'id_stanza', [
            'type'       => 'INT',
            'constraint' => 11,
            'unsigned'   => true,
            'null'       => true,
            'after'      => 'id_amb_legacy',
        ]);

        $this->addColumnIfMissing('dap11_agenda_slot', 'id_stanza', [
            'type'       => 'INT',
            'constraint' => 11,
            'unsigned'   => true,
            'null'       => true,
            'after'      => 'id_amb_legacy',
        ]);

        $this->createIndexIfMissing(
            'dap42_ambulatori',
            'idx_dap42_attiva_ordinamento',
            'CREATE INDEX idx_dap42_attiva_ordinamento ON dap42_ambulatori (attiva, ordinamento, nome)'
        );
        $this->createIndexIfMissing(
            'dap43_ambulatori_stanze',
            'idx_dap43_amb_attiva_ordinamento',
            'CREATE INDEX idx_dap43_amb_attiva_ordinamento ON dap43_ambulatori_stanze (id_amb_legacy, attiva, ordinamento, nome)'
        );
        $this->createIndexIfMissing(
            'dap43_ambulatori_stanze',
            'uq_dap43_amb_nome',
            'CREATE UNIQUE INDEX uq_dap43_amb_nome ON dap43_ambulatori_stanze (id_amb_legacy, nome)'
        );
        $this->createIndexIfMissing(
            'dap10_agenda_config_fasce',
            'idx_dap10_fasce_id_stanza',
            'CREATE INDEX idx_dap10_fasce_id_stanza ON dap10_agenda_config_fasce (id_stanza)'
        );
        $this->createIndexIfMissing(
            'dap11_agenda_slot',
            'idx_dap11_slot_id_stanza',
            'CREATE INDEX idx_dap11_slot_id_stanza ON dap11_agenda_slot (id_stanza)'
        );
    }

    public function down()
    {
        $this->dropIndexIfExists('dap11_agenda_slot', 'idx_dap11_slot_id_stanza');
        $this->dropIndexIfExists('dap10_agenda_config_fasce', 'idx_dap10_fasce_id_stanza');
        $this->dropIndexIfExists('dap43_ambulatori_stanze', 'uq_dap43_amb_nome');
        $this->dropIndexIfExists('dap43_ambulatori_stanze', 'idx_dap43_amb_attiva_ordinamento');
        $this->dropIndexIfExists('dap42_ambulatori', 'idx_dap42_attiva_ordinamento');

        $this->dropColumnIfExists('dap11_agenda_slot', 'id_stanza');
        $this->dropColumnIfExists('dap10_agenda_config_fasce', 'id_stanza');
        $this->dropColumnIfExists('dap42_ambulatori', 'ordinamento');
        $this->dropColumnIfExists('dap42_ambulatori', 'attiva');

        if ($this->db->tableExists('dap43_ambulatori_stanze')) {
            $this->forge->dropTable('dap43_ambulatori_stanze');
        }
    }

    private function addColumnIfMissing(string $table, string $column, array $definition): void
    {
        if (!$this->db->tableExists($table) || $this->db->fieldExists($column, $table)) {
            return;
        }

        $this->forge->addColumn($table, [$column => $definition]);
    }

    private function dropColumnIfExists(string $table, string $column): void
    {
        if (!$this->db->tableExists($table) || !$this->db->fieldExists($column, $table)) {
            return;
        }

        $this->forge->dropColumn($table, $column);
    }

    private function createIndexIfMissing(string $table, string $indexName, string $sql): void
    {
        if (!$this->db->tableExists($table) || $this->indexExists($table, $indexName)) {
            return;
        }

        $this->db->query($sql);
    }

    private function dropIndexIfExists(string $table, string $indexName): void
    {
        if (!$this->db->tableExists($table) || !$this->indexExists($table, $indexName)) {
            return;
        }

        $this->db->query("DROP INDEX {$indexName} ON {$table}");
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $row = $this->db->query(
            '
            SELECT COUNT(*) AS c
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = ?
              AND index_name = ?
            ',
            [$table, $indexName]
        )->getRowArray();

        return (int)($row['c'] ?? 0) > 0;
    }
}
