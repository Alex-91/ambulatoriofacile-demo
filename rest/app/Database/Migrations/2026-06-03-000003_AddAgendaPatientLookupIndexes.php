<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddAgendaPatientLookupIndexes extends Migration
{
    public function up()
    {
        if ($this->db->tableExists('dap12_agenda_appuntamenti') && $this->db->fieldExists('id_client', 'dap12_agenda_appuntamenti')) {
            $this->createIndexIfMissing(
                'dap12_agenda_appuntamenti',
                'idx_dap12_agenda_app_dot_client_slot',
                'CREATE INDEX idx_dap12_agenda_app_dot_client_slot ON dap12_agenda_appuntamenti (id_dot, id_client, id_slot)'
            );
        }

        if ($this->db->tableExists('dap12_agenda_appuntamenti')) {
            $this->createIndexIfMissing(
                'dap12_agenda_appuntamenti',
                'idx_dap12_agenda_app_dot_paziente_slot',
                'CREATE INDEX idx_dap12_agenda_app_dot_paziente_slot ON dap12_agenda_appuntamenti (id_dot, id_paziente, id_slot)'
            );
        }
    }

    public function down()
    {
        $this->dropIndexIfExists('dap12_agenda_appuntamenti', 'idx_dap12_agenda_app_dot_client_slot');
        $this->dropIndexIfExists('dap12_agenda_appuntamenti', 'idx_dap12_agenda_app_dot_paziente_slot');
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
