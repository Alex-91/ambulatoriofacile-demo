<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class OptimizeAgendaSavePaths extends Migration
{
    public function up()
    {
        $this->createIndexIfMissing(
            'dap11_agenda_slot',
            'idx_dap11_agenda_slot_state_id',
            'CREATE INDEX idx_dap11_agenda_slot_state_id ON dap11_agenda_slot (stato, id_slot)'
        );

        $this->createIndexIfMissing(
            'dap12_agenda_appuntamenti',
            'idx_dap12_agenda_app_slot_state',
            'CREATE INDEX idx_dap12_agenda_app_slot_state ON dap12_agenda_appuntamenti (id_slot, stato)'
        );

        if ($this->db->tableExists('dap12_agenda_appuntamenti') && $this->db->fieldExists('id_client', 'dap12_agenda_appuntamenti')) {
            $this->createIndexIfMissing(
                'dap12_agenda_appuntamenti',
                'idx_dap12_agenda_app_dot_client_slot',
                'CREATE INDEX idx_dap12_agenda_app_dot_client_slot ON dap12_agenda_appuntamenti (id_dot, id_client, id_slot)'
            );
        }

        $this->createIndexIfMissing(
            'dap12_agenda_appuntamenti',
            'idx_dap12_agenda_app_dot_paziente_slot',
            'CREATE INDEX idx_dap12_agenda_app_dot_paziente_slot ON dap12_agenda_appuntamenti (id_dot, id_paziente, id_slot)'
        );

        $this->createIndexIfMissing(
            'dap14_agenda_lock',
            'ux_dap14_agenda_lock_token',
            'CREATE UNIQUE INDEX ux_dap14_agenda_lock_token ON dap14_agenda_lock (token_lock)'
        );

        $this->createIndexIfMissing(
            'dap14_agenda_lock',
            'idx_dap14_agenda_lock_slot_state_exp',
            'CREATE INDEX idx_dap14_agenda_lock_slot_state_exp ON dap14_agenda_lock (id_slot, stato, expires_at)'
        );

        $this->createIndexIfMissing(
            'dap14_agenda_lock',
            'idx_dap14_agenda_lock_state_exp_slot',
            'CREATE INDEX idx_dap14_agenda_lock_state_exp_slot ON dap14_agenda_lock (stato, expires_at, id_slot)'
        );

        $this->createIndexIfMissing(
            'dap13_visite_domiciliari',
            'idx_dap13_visite_dot_day_state_name',
            'CREATE INDEX idx_dap13_visite_dot_day_state_name ON dap13_visite_domiciliari (id_dot, giorno_visita, stato, cognome, nome)'
        );
    }

    public function down()
    {
        $this->dropIndexIfExists('dap13_visite_domiciliari', 'idx_dap13_visite_dot_day_state_name');
        $this->dropIndexIfExists('dap14_agenda_lock', 'idx_dap14_agenda_lock_state_exp_slot');
        $this->dropIndexIfExists('dap14_agenda_lock', 'idx_dap14_agenda_lock_slot_state_exp');
        $this->dropIndexIfExists('dap14_agenda_lock', 'ux_dap14_agenda_lock_token');
        $this->dropIndexIfExists('dap12_agenda_appuntamenti', 'idx_dap12_agenda_app_dot_paziente_slot');
        $this->dropIndexIfExists('dap12_agenda_appuntamenti', 'idx_dap12_agenda_app_dot_client_slot');
        $this->dropIndexIfExists('dap12_agenda_appuntamenti', 'idx_dap12_agenda_app_slot_state');
        $this->dropIndexIfExists('dap11_agenda_slot', 'idx_dap11_agenda_slot_state_id');
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
