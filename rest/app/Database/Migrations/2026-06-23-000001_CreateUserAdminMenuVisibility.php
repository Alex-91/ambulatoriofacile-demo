<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateUserAdminMenuVisibility extends Migration
{
    public function up()
    {
        if ($this->db->tableExists('dap_user_admin_menu')) {
            return;
        }

        $this->forge->addField([
            'id_user' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
            ],
            'menu_key' => [
                'type' => 'VARCHAR',
                'constraint' => 120,
            ],
            'menu_link' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'default' => '',
            ],
            'can_view' => [
                'type' => 'TINYINT',
                'constraint' => 1,
                'default' => 1,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addKey(['id_user', 'menu_key'], true);
        $this->forge->addKey('menu_link');
        $this->forge->addForeignKey('id_user', 'dap01_users', 'id_user', 'CASCADE', 'CASCADE', 'fk_user_admin_menu_user');
        $this->forge->createTable('dap_user_admin_menu', true);
    }

    public function down()
    {
        if ($this->db->tableExists('dap_user_admin_menu')) {
            $this->forge->dropTable('dap_user_admin_menu', true);
        }
    }
}
