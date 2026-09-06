<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreatePlatformTenantAgendaFontPreferences extends Migration
{
    protected $DBGroup = 'platform';

    public function up()
    {
        if (!$this->db->tableExists('platform_tenants')
            || $this->db->tableExists('platform_tenant_agenda_font_preferences')) {
            return;
        }

        $this->forge->addField([
            'id_tenant' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
            ],
            'config_json' => [
                'type' => 'TEXT',
                'null' => false,
            ],
            'updated_by_platform_user' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
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

        $this->forge->addKey('id_tenant', true);
        $this->forge->addForeignKey(
            'id_tenant',
            'platform_tenants',
            'id_tenant',
            'CASCADE',
            'CASCADE',
            'fk_tenant_agenda_font_preferences_tenant'
        );
        $this->forge->createTable('platform_tenant_agenda_font_preferences', true);
    }

    public function down()
    {
        if ($this->db->tableExists('platform_tenant_agenda_font_preferences')) {
            $this->forge->dropTable('platform_tenant_agenda_font_preferences', true);
        }
    }
}
