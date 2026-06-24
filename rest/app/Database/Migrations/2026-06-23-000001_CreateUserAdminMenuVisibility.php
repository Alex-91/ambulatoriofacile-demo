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
            'id_user' => $this->resolveUserIdFieldDefinition(),
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

    /**
     * Match the existing dap01_users.id_user column definition so the FK stays compatible
     * across local databases that were created from older dumps.
     *
     * @return array<string, mixed>
     */
    private function resolveUserIdFieldDefinition(): array
    {
        $definition = [
            'type' => 'INT',
            'constraint' => 11,
        ];

        $row = $this->db
            ->query("SHOW COLUMNS FROM `dap01_users` LIKE 'id_user'")
            ->getFirstRow('array');

        $type = strtolower((string) ($row['Type'] ?? ''));

        if ($type !== '' && preg_match('/^([a-z]+)(?:\((\d+)\))?/', $type, $matches)) {
            $definition['type'] = strtoupper((string) ($matches[1] ?? 'INT'));

            if (!empty($matches[2])) {
                $definition['constraint'] = (int) $matches[2];
            }
        }

        if (str_contains($type, 'unsigned')) {
            $definition['unsigned'] = true;
        }

        return $definition;
    }
}
