<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateSmsProviderSettings extends Migration
{
    protected $DBGroup = 'platform';

    public function up()
    {
        if ($this->db->tableExists('platform_sms_provider_settings')) {
            return;
        }

        $this->forge->addField([
            'id_sms_provider_setting' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'scope_key' => ['type' => 'VARCHAR', 'constraint' => 64],
            'id_tenant' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'inherit_global' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'provider' => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'smsfactor'],
            'default_sender' => ['type' => 'VARCHAR', 'constraint' => 11, 'default' => 'AmbFacile'],
            'smsfactor_base_url' => ['type' => 'VARCHAR', 'constraint' => 255, 'default' => 'https://api.smsfactor.com'],
            'smsfactor_timeout_seconds' => ['type' => 'SMALLINT', 'constraint' => 5, 'unsigned' => true, 'default' => 30],
            'smsfactor_push_type' => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'alert'],
            'smsfactor_api_token_encrypted' => ['type' => 'LONGTEXT', 'null' => true],
            'smsfactor_webhook_signature_encrypted' => ['type' => 'LONGTEXT', 'null' => true],
            'aruba_username_encrypted' => ['type' => 'LONGTEXT', 'null' => true],
            'aruba_password_encrypted' => ['type' => 'LONGTEXT', 'null' => true],
            'updated_by_platform_user_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME'],
            'updated_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id_sms_provider_setting', true);
        $this->forge->addUniqueKey('scope_key', 'uq_sms_provider_scope');
        $this->forge->addKey('id_tenant', false, false, 'idx_sms_provider_tenant');
        $this->forge->addForeignKey('id_tenant', 'platform_tenants', 'id_tenant', 'CASCADE', 'CASCADE', 'fk_sms_provider_tenant');
        $this->forge->addForeignKey('updated_by_platform_user_id', 'platform_users', 'id_platform_user', 'CASCADE', 'SET NULL', 'fk_sms_provider_user');
        $this->forge->createTable('platform_sms_provider_settings', true);
    }

    public function down()
    {
        if ($this->db->tableExists('platform_sms_provider_settings')) {
            $this->forge->dropTable('platform_sms_provider_settings', true);
        }
    }
}
