<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AllowNullUserInDap02Clients extends Migration
{
    private string $table = 'dap02_clients';

    public function up()
    {
        if (!$this->db->tableExists($this->table)) {
            return;
        }

        $this->db->query("UPDATE `{$this->table}` SET id_user = NULL WHERE id_user = 0");

        $this->forge->modifyColumn($this->table, [
            'id_user' => [
                'type' => 'INT',
                'null' => true,
            ],
        ]);
    }

    public function down()
    {
        if (!$this->db->tableExists($this->table)) {
            return;
        }

        $this->db->query("UPDATE `{$this->table}` SET id_user = 0 WHERE id_user IS NULL");

        $this->forge->modifyColumn($this->table, [
            'id_user' => [
                'type' => 'INT',
                'null' => false,
            ],
        ]);
    }
}
