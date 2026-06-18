<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class ExpandEncryptedPersonaleColumns extends Migration
{
    private string $table = 'dap03_personale';

    public function up()
    {
        if (!$this->db->tableExists($this->table)) {
            return;
        }

        $this->forge->modifyColumn($this->table, [
            'nome' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => false,
            ],
            'cognome' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => false,
            ],
            'qualifica' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => false,
            ],
            'cellulare' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => false,
            ],
        ]);
    }

    public function down()
    {
        if (!$this->db->tableExists($this->table)) {
            return;
        }

        $this->forge->modifyColumn($this->table, [
            'nome' => [
                'type' => 'VARCHAR',
                'constraint' => 45,
                'null' => false,
            ],
            'cognome' => [
                'type' => 'VARCHAR',
                'constraint' => 45,
                'null' => false,
            ],
            'qualifica' => [
                'type' => 'VARCHAR',
                'constraint' => 45,
                'null' => false,
            ],
            'cellulare' => [
                'type' => 'VARCHAR',
                'constraint' => 45,
                'null' => false,
            ],
        ]);
    }
}
