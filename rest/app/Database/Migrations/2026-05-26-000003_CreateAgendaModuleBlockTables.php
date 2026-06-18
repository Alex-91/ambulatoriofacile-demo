<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateAgendaModuleBlockTables extends Migration
{
    public function up()
    {
        $this->createBlockTable(
            'dap37_block_memo',
            'id_block_memo',
            'ux_dap37_block_memo_dot_data',
            'ux_dap37_block_memo_legacy',
            'idx_dap37_block_memo_data'
        );

        $this->createBlockTable(
            'dap31_block_dom',
            'id_block_dom',
            'ux_dap31_block_dom_dot_data',
            'ux_dap31_block_dom_legacy',
            'idx_dap31_block_dom_data'
        );
    }

    public function down()
    {
        foreach (['dap37_block_memo', 'dap31_block_dom'] as $table) {
            if ($this->db->tableExists($table)) {
                $this->forge->dropTable($table, true);
            }
        }
    }

    private function createBlockTable(
        string $table,
        string $primaryKey,
        string $uniqueDotDate,
        string $uniqueLegacy,
        string $idxDate
    ): void {
        if (!$this->db->tableExists($table)) {
            $this->forge->addField([
                $primaryKey => [
                    'type'           => 'INT',
                    'constraint'     => 11,
                    'unsigned'       => true,
                    'auto_increment' => true,
                ],
                'id_dot' => [
                    'type'       => 'INT',
                    'constraint' => 11,
                    'null'       => false,
                ],
                'data_agenda' => [
                    'type' => 'DATE',
                    'null' => false,
                ],
                'legacy_id_stampa' => [
                    'type'       => 'INT',
                    'constraint' => 11,
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
            $this->forge->addKey($primaryKey, true);
            $this->forge->createTable($table, true);
        }

        if (!$this->indexExists($table, $uniqueDotDate)) {
            $this->db->query("CREATE UNIQUE INDEX {$uniqueDotDate} ON {$table} (id_dot, data_agenda)");
        }

        if (!$this->indexExists($table, $uniqueLegacy)) {
            $this->db->query("CREATE UNIQUE INDEX {$uniqueLegacy} ON {$table} (legacy_id_stampa)");
        }

        if (!$this->indexExists($table, $idxDate)) {
            $this->db->query("CREATE INDEX {$idxDate} ON {$table} (data_agenda)");
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $row = $this->db->query("
            SELECT COUNT(*) AS c
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = ?
              AND index_name = ?
        ", [$table, $indexName])->getRowArray();

        return (int)($row['c'] ?? 0) > 0;
    }
}
