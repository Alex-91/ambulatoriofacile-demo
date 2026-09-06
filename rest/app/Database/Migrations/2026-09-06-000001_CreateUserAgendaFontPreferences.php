<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateUserAgendaFontPreferences extends Migration
{
    public function up()
    {
        if ($this->db->tableExists('dap_user_agenda_font_preferences')) {
            return;
        }

        $this->forge->addField([
            'id_user' => $this->resolveUserIdFieldDefinition(),
            'config_json' => [
                'type' => 'TEXT',
                'null' => false,
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

        $this->forge->addKey('id_user', true);
        $this->forge->addForeignKey(
            'id_user',
            'dap01_users',
            'id_user',
            'CASCADE',
            'CASCADE',
            'fk_user_agenda_font_preferences_user'
        );
        $this->forge->createTable('dap_user_agenda_font_preferences', true);
    }

    public function down()
    {
        if ($this->db->tableExists('dap_user_agenda_font_preferences')) {
            $this->forge->dropTable('dap_user_agenda_font_preferences', true);
        }
    }

    /**
     * Keep the foreign key compatible with old tenant databases, whose user id
     * can differ in integer width or signedness.
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
