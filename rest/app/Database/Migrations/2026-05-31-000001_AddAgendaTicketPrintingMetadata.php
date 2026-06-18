<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddAgendaTicketPrintingMetadata extends Migration
{
    public function up()
    {
        if (!$this->db->tableExists('dap42_ambulatori')) {
            $this->forge->addField([
                'id_amb_legacy' => [
                    'type'       => 'INT',
                    'constraint' => 11,
                    'unsigned'   => true,
                ],
                'nome' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 100,
                    'null'       => false,
                ],
                'logo' => [
                    'type' => 'LONGBLOB',
                    'null' => true,
                ],
                'indirizzo' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 150,
                    'null'       => true,
                ],
                'citta' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 150,
                    'null'       => true,
                ],
                'telefono' => [
                    'type'       => 'VARCHAR',
                    'constraint' => 60,
                    'null'       => true,
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
            $this->forge->addKey('id_amb_legacy', true);
            $this->forge->createTable('dap42_ambulatori');
        }

        $this->addColumnIfMissing('dap10_agenda_config_fasce', 'id_amb_legacy', [
            'type'       => 'INT',
            'constraint' => 11,
            'unsigned'   => true,
            'null'       => true,
            'after'      => 'durata_slot',
        ]);
        $this->addColumnIfMissing('dap10_agenda_config_fasce', 'ambulatorio', [
            'type'       => 'VARCHAR',
            'constraint' => 150,
            'null'       => true,
            'after'      => 'id_amb_legacy',
        ]);
        $this->addColumnIfMissing('dap10_agenda_config_fasce', 'stanza', [
            'type'       => 'VARCHAR',
            'constraint' => 100,
            'null'       => true,
            'after'      => 'ambulatorio',
        ]);

        $this->addColumnIfMissing('dap11_agenda_slot', 'id_amb_legacy', [
            'type'       => 'INT',
            'constraint' => 11,
            'unsigned'   => true,
            'null'       => true,
            'after'      => 'titolo_libero',
        ]);

        $this->createIndexIfMissing(
            'dap10_agenda_config_fasce',
            'idx_dap10_fasce_amb_legacy',
            'CREATE INDEX idx_dap10_fasce_amb_legacy ON dap10_agenda_config_fasce (id_amb_legacy)'
        );
        $this->createIndexIfMissing(
            'dap11_agenda_slot',
            'idx_dap11_slot_amb_legacy',
            'CREATE INDEX idx_dap11_slot_amb_legacy ON dap11_agenda_slot (id_amb_legacy)'
        );
    }

    public function down()
    {
        $this->dropIndexIfExists('dap11_agenda_slot', 'idx_dap11_slot_amb_legacy');
        $this->dropIndexIfExists('dap10_agenda_config_fasce', 'idx_dap10_fasce_amb_legacy');

        $this->dropColumnIfExists('dap11_agenda_slot', 'id_amb_legacy');
        $this->dropColumnIfExists('dap10_agenda_config_fasce', 'stanza');
        $this->dropColumnIfExists('dap10_agenda_config_fasce', 'ambulatorio');
        $this->dropColumnIfExists('dap10_agenda_config_fasce', 'id_amb_legacy');

        if ($this->db->tableExists('dap42_ambulatori')) {
            $this->forge->dropTable('dap42_ambulatori');
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
