<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddLegacyPatientBridgeToDap02Clients extends Migration
{
    private string $table = 'dap02_clients';

    public function up()
    {
        if (!$this->db->tableExists($this->table)) {
            return;
        }

        if (!$this->db->fieldExists('legacy_id_paziente', $this->table)) {
            $this->forge->addColumn($this->table, [
                'legacy_id_paziente' => [
                    'type'       => 'INT',
                    'constraint' => 11,
                    'null'       => true,
                    'after'      => 'id_personale',
                ],
            ]);
        }

        if (!$this->indexExists($this->table, 'idx_dap02_legacy_id_paziente')) {
            $this->db->query('CREATE INDEX idx_dap02_legacy_id_paziente ON ' . $this->table . ' (legacy_id_paziente)');
        }
    }

    public function down()
    {
        if (!$this->db->tableExists($this->table)) {
            return;
        }

        if ($this->indexExists($this->table, 'idx_dap02_legacy_id_paziente')) {
            $this->db->query('DROP INDEX idx_dap02_legacy_id_paziente ON ' . $this->table);
        }

        if ($this->db->fieldExists('legacy_id_paziente', $this->table)) {
            $this->forge->dropColumn($this->table, 'legacy_id_paziente');
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
