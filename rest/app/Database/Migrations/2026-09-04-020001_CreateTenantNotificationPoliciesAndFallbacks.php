<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateTenantNotificationPoliciesAndFallbacks extends Migration
{
    protected $DBGroup = 'platform';

    public function up()
    {
        $this->createPolicies();
        $this->createRateLimits();
        $this->createFallbacks();
    }

    public function down()
    {
        foreach (['platform_notification_fallbacks', 'platform_notification_rate_limits', 'platform_tenant_notification_policies'] as $table) {
            if ($this->db->tableExists($table)) {
                $this->forge->dropTable($table, true);
            }
        }
    }

    private function createRateLimits(): void
    {
        if ($this->db->tableExists('platform_notification_rate_limits')) {
            return;
        }

        $this->forge->addField([
            'id_notification_rate_limit' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'id_tenant' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'channel' => ['type' => 'VARCHAR', 'constraint' => 16],
            'next_allowed_at' => ['type' => 'DATETIME', 'null' => true],
            'counter_date' => ['type' => 'DATE', 'null' => true],
            'sent_today' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
            'updated_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id_notification_rate_limit', true);
        $this->forge->addUniqueKey(['id_tenant', 'channel'], 'uq_notification_rate_limit_tenant_channel');
        $this->forge->addForeignKey('id_tenant', 'platform_tenants', 'id_tenant', 'CASCADE', 'CASCADE');
        $this->forge->createTable('platform_notification_rate_limits', true);
    }

    private function createPolicies(): void
    {
        if ($this->db->tableExists('platform_tenant_notification_policies')) {
            return;
        }

        $this->forge->addField([
            'id_tenant_notification_policy' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'id_tenant' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'config_json' => ['type' => 'LONGTEXT'],
            'updated_by_platform_user_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME'],
            'updated_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id_tenant_notification_policy', true);
        $this->forge->addUniqueKey('id_tenant', 'uq_tenant_notification_policy');
        $this->forge->addForeignKey('id_tenant', 'platform_tenants', 'id_tenant', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('updated_by_platform_user_id', 'platform_users', 'id_platform_user', 'CASCADE', 'SET NULL');
        $this->forge->createTable('platform_tenant_notification_policies', true);
    }

    private function createFallbacks(): void
    {
        if ($this->db->tableExists('platform_notification_fallbacks')) {
            return;
        }

        $this->forge->addField([
            'id_notification_fallback' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'id_tenant' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'fallback_key' => ['type' => 'CHAR', 'constraint' => 64],
            'source_type' => ['type' => 'VARCHAR', 'constraint' => 40],
            'source_id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'null' => true],
            'message_type' => ['type' => 'VARCHAR', 'constraint' => 64],
            'recipient_phone' => ['type' => 'VARCHAR', 'constraint' => 32],
            'message_text' => ['type' => 'TEXT'],
            'whatsapp_provider_id' => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'whatsapp_status' => ['type' => 'VARCHAR', 'constraint' => 24, 'default' => 'sent'],
            'status' => ['type' => 'VARCHAR', 'constraint' => 24, 'default' => 'pending'],
            'due_at' => ['type' => 'DATETIME'],
            'checked_at' => ['type' => 'DATETIME', 'null' => true],
            'sms_provider_id' => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'sms_sent_at' => ['type' => 'DATETIME', 'null' => true],
            'error_text' => ['type' => 'TEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME'],
            'updated_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id_notification_fallback', true);
        $this->forge->addUniqueKey('fallback_key', 'uq_notification_fallback_key');
        $this->forge->addKey(['id_tenant', 'status', 'due_at'], false, false, 'idx_notification_fallback_due');
        $this->forge->addForeignKey('id_tenant', 'platform_tenants', 'id_tenant', 'CASCADE', 'CASCADE');
        $this->forge->createTable('platform_notification_fallbacks', true);
    }
}
