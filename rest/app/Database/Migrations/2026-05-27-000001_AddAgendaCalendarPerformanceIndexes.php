<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddAgendaCalendarPerformanceIndexes extends Migration
{
    public function up()
    {
        $this->createIndexIfMissing(
            'dap12_agenda_appuntamenti',
            'idx_dap12_agenda_app_slot_state',
            'CREATE INDEX idx_dap12_agenda_app_slot_state ON dap12_agenda_appuntamenti (id_slot, stato)'
        );

        $this->createIndexIfMissing(
            'dap10_agenda_config',
            'idx_dap10_agenda_config_dot_attiva_periodo',
            'CREATE INDEX idx_dap10_agenda_config_dot_attiva_periodo ON dap10_agenda_config (id_dot, attiva, data_inizio, data_fine, id_config)'
        );
    }

    public function down()
    {
        $this->dropIndexIfExists('dap12_agenda_appuntamenti', 'idx_dap12_agenda_app_slot_state');
        $this->dropIndexIfExists('dap10_agenda_config', 'idx_dap10_agenda_config_dot_attiva_periodo');
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
            "
            SELECT COUNT(*) AS c
            FROM information_schema.statistics
            WHERE table_schema = DATABASE()
              AND table_name = ?
              AND index_name = ?
            ",
            [$table, $indexName]
        )->getRowArray();

        return (int)($row['c'] ?? 0) > 0;
    }
}
