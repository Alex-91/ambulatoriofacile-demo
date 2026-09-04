<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateWhatsappCampaignTables extends Migration
{
    protected $DBGroup = 'platform';

    public function up()
    {
        if (!$this->db->tableExists('platform_whatsapp_campaigns')) {
            $this->forge->addField([
                'id_whatsapp_campaign' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
                'id_tenant' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
                'audience_type' => ['type' => 'VARCHAR', 'constraint' => 32],
                'appointment_date' => ['type' => 'DATE', 'null' => true],
                'message_text' => ['type' => 'TEXT'],
                'status' => ['type' => 'VARCHAR', 'constraint' => 24, 'default' => 'queued'],
                'total_recipients' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
                'pending_recipients' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
                'sent_recipients' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
                'failed_recipients' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
                'created_by_platform_user_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
                'started_at' => ['type' => 'DATETIME', 'null' => true],
                'completed_at' => ['type' => 'DATETIME', 'null' => true],
                'created_at' => ['type' => 'DATETIME'],
                'updated_at' => ['type' => 'DATETIME'],
            ]);
            $this->forge->addKey('id_whatsapp_campaign', true);
            $this->forge->addKey(['id_tenant', 'status'], false, false, 'idx_wa_campaign_tenant_status');
            $this->forge->addForeignKey('id_tenant', 'platform_tenants', 'id_tenant', 'CASCADE', 'CASCADE');
            $this->forge->addForeignKey('created_by_platform_user_id', 'platform_users', 'id_platform_user', 'CASCADE', 'SET NULL');
            $this->forge->createTable('platform_whatsapp_campaigns', true);
        }

        if (!$this->db->tableExists('platform_whatsapp_campaign_recipients')) {
            $this->forge->addField([
                'id_whatsapp_campaign_recipient' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
                'id_whatsapp_campaign' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true],
                'id_tenant' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
                'id_client' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
                'id_appointment' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
                'patient_name' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
                'recipient_phone' => ['type' => 'VARCHAR', 'constraint' => 32],
                'status' => ['type' => 'VARCHAR', 'constraint' => 24, 'default' => 'pending'],
                'attempt_count' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'default' => 0],
                'provider_message_id' => ['type' => 'VARCHAR', 'constraint' => 128, 'null' => true],
                'error_text' => ['type' => 'TEXT', 'null' => true],
                'sent_at' => ['type' => 'DATETIME', 'null' => true],
                'created_at' => ['type' => 'DATETIME'],
                'updated_at' => ['type' => 'DATETIME'],
            ]);
            $this->forge->addKey('id_whatsapp_campaign_recipient', true);
            $this->forge->addKey(['id_whatsapp_campaign', 'status'], false, false, 'idx_wa_campaign_recipient_queue');
            $this->forge->addUniqueKey(['id_whatsapp_campaign', 'recipient_phone'], 'uq_wa_campaign_recipient_phone');
            $this->forge->addForeignKey('id_whatsapp_campaign', 'platform_whatsapp_campaigns', 'id_whatsapp_campaign', 'CASCADE', 'CASCADE');
            $this->forge->addForeignKey('id_tenant', 'platform_tenants', 'id_tenant', 'CASCADE', 'CASCADE');
            $this->forge->createTable('platform_whatsapp_campaign_recipients', true);
        }

        if (!$this->db->tableExists('platform_whatsapp_campaign_rate_limits')) {
            $this->forge->addField([
                'id_tenant' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
                'next_allowed_at' => ['type' => 'DATETIME', 'null' => true],
                'updated_at' => ['type' => 'DATETIME'],
            ]);
            $this->forge->addKey('id_tenant', true);
            $this->forge->addForeignKey('id_tenant', 'platform_tenants', 'id_tenant', 'CASCADE', 'CASCADE');
            $this->forge->createTable('platform_whatsapp_campaign_rate_limits', true);
        }
    }

    public function down()
    {
        foreach (['platform_whatsapp_campaign_rate_limits', 'platform_whatsapp_campaign_recipients', 'platform_whatsapp_campaigns'] as $table) {
            if ($this->db->tableExists($table)) {
                $this->forge->dropTable($table, true);
            }
        }
    }
}
