<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreatePlatformImpersonationLogs extends Migration
{
    protected $DBGroup = 'platform';

    public function up()
    {
        if ($this->db->tableExists('platform_impersonation_logs')) {
            return;
        }

        $this->forge->addField([
            'id_platform_impersonation_log' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'id_platform_user' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
            ],
            'id_tenant' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
            ],
            'app_user_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
            ],
            'target_username' => [
                'type' => 'VARCHAR',
                'constraint' => 190,
                'null' => true,
            ],
            'target_display_name' => [
                'type' => 'VARCHAR',
                'constraint' => 190,
                'null' => true,
            ],
            'target_tenant_role' => [
                'type' => 'VARCHAR',
                'constraint' => 30,
                'null' => true,
            ],
            'target_tipo_user' => [
                'type' => 'INT',
                'constraint' => 11,
                'default' => 0,
            ],
            'reason_text' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'session_token' => [
                'type' => 'VARCHAR',
                'constraint' => 80,
            ],
            'origin_login_source' => [
                'type' => 'VARCHAR',
                'constraint' => 40,
                'null' => true,
            ],
            'origin_path' => [
                'type' => 'VARCHAR',
                'constraint' => 190,
                'null' => true,
            ],
            'origin_ip' => [
                'type' => 'VARCHAR',
                'constraint' => 64,
                'null' => true,
            ],
            'origin_user_agent' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
                'null' => true,
            ],
            'started_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'expires_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
            'ended_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'end_reason' => [
                'type' => 'VARCHAR',
                'constraint' => 30,
                'null' => true,
            ],
            'metadata_json' => [
                'type' => 'MEDIUMTEXT',
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

        $this->forge->addKey('id_platform_impersonation_log', true);
        $this->forge->addKey('id_platform_user');
        $this->forge->addKey('id_tenant');
        $this->forge->addKey('app_user_id');
        $this->forge->addKey('session_token');
        $this->forge->addKey(['started_at', 'ended_at'], false, false, 'idx_platform_imp_started');
        $this->forge->addForeignKey('id_platform_user', 'platform_users', 'id_platform_user', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('id_tenant', 'platform_tenants', 'id_tenant', 'CASCADE', 'CASCADE');
        $this->forge->createTable('platform_impersonation_logs', true);
    }

    public function down()
    {
        if ($this->db->tableExists('platform_impersonation_logs')) {
            $this->forge->dropTable('platform_impersonation_logs', true);
        }
    }
}
