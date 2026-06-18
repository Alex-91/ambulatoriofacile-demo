<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddClientReferenceToAgendaAppointments extends Migration
{
    private string $table = 'far12_agenda_appuntamenti';

    public function up()
    {
        if (!$this->db->tableExists($this->table)) {
            return;
        }

        if (!$this->db->fieldExists('id_client', $this->table)) {
            $this->forge->addColumn($this->table, [
                'id_client' => [
                    'type' => 'INT',
                    'null' => true,
                    'after' => 'id_paziente',
                ],
            ]);
        }

        $indexes = $this->db->getIndexData($this->table);
        if (!isset($indexes['idx_far12_id_client'])) {
            $this->db->query("CREATE INDEX `idx_far12_id_client` ON `{$this->table}` (`id_client`)");
        }
    }

    public function down()
    {
        if (!$this->db->tableExists($this->table)) {
            return;
        }

        $indexes = $this->db->getIndexData($this->table);
        if (isset($indexes['idx_far12_id_client'])) {
            $this->db->query("DROP INDEX `idx_far12_id_client` ON `{$this->table}`");
        }

        if ($this->db->fieldExists('id_client', $this->table)) {
            $this->forge->dropColumn($this->table, 'id_client');
        }
    }
}
