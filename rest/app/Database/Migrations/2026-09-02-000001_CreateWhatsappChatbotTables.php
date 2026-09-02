<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateWhatsappChatbotTables extends Migration
{
    protected $DBGroup = 'platform';

    public function up()
    {
        $this->createChatbotsTable();
        $this->createInteractionsTable();
        $this->createInboundMessagesTable();
    }

    public function down()
    {
        foreach (['platform_whatsapp_chatbot_messages', 'platform_whatsapp_chatbot_interactions', 'platform_whatsapp_chatbots'] as $table) {
            if ($this->db->tableExists($table)) {
                $this->forge->dropTable($table, true);
            }
        }
    }

    private function createChatbotsTable(): void
    {
        if ($this->db->tableExists('platform_whatsapp_chatbots')) {
            return;
        }

        $this->forge->addField([
            'id_whatsapp_chatbot' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'id_tenant' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
            ],
            'is_enabled' => [
                'type' => 'TINYINT',
                'constraint' => 1,
                'default' => 0,
            ],
            'response_window_hours' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'default' => 168,
            ],
            'prompt_text' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'fallback_reply' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'open_on_json' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'rules_json' => [
                'type' => 'MEDIUMTEXT',
                'null' => true,
            ],
            'updated_by_platform_user_id' => [
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

        $this->forge->addKey('id_whatsapp_chatbot', true);
        $this->forge->addUniqueKey('id_tenant', 'uq_platform_whatsapp_chatbot_tenant');
        $this->forge->addForeignKey('id_tenant', 'platform_tenants', 'id_tenant', 'CASCADE', 'CASCADE');
        $this->forge->addForeignKey('updated_by_platform_user_id', 'platform_users', 'id_platform_user', 'CASCADE', 'SET NULL');
        $this->forge->createTable('platform_whatsapp_chatbots', true);
    }

    private function createInteractionsTable(): void
    {
        if ($this->db->tableExists('platform_whatsapp_chatbot_interactions')) {
            return;
        }

        $this->forge->addField([
            'id_whatsapp_chatbot_interaction' => [
                'type' => 'BIGINT',
                'constraint' => 20,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'id_tenant' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
            ],
            'appointment_id' => [
                'type' => 'BIGINT',
                'constraint' => 20,
                'unsigned' => true,
            ],
            'phone_key' => [
                'type' => 'VARCHAR',
                'constraint' => 32,
            ],
            'message_type' => [
                'type' => 'VARCHAR',
                'constraint' => 64,
            ],
            'outbound_message_id' => [
                'type' => 'VARCHAR',
                'constraint' => 191,
                'null' => true,
            ],
            'status' => [
                'type' => 'VARCHAR',
                'constraint' => 24,
                'default' => 'pending',
            ],
            'expires_at' => [
                'type' => 'DATETIME',
            ],
            'resolved_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'inbound_message_id' => [
                'type' => 'VARCHAR',
                'constraint' => 191,
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

        $this->forge->addKey('id_whatsapp_chatbot_interaction', true);
        $this->forge->addKey(['id_tenant', 'phone_key', 'status', 'expires_at'], false, false, 'idx_wa_bot_interactions_pending');
        $this->forge->addKey(['id_tenant', 'appointment_id'], false, false, 'idx_wa_bot_interactions_appointment');
        $this->forge->addForeignKey('id_tenant', 'platform_tenants', 'id_tenant', 'CASCADE', 'CASCADE');
        $this->forge->createTable('platform_whatsapp_chatbot_interactions', true);
    }

    private function createInboundMessagesTable(): void
    {
        if ($this->db->tableExists('platform_whatsapp_chatbot_messages')) {
            return;
        }

        $this->forge->addField([
            'id_whatsapp_chatbot_message' => [
                'type' => 'BIGINT',
                'constraint' => 20,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'id_tenant' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
            ],
            'message_key' => [
                'type' => 'CHAR',
                'constraint' => 64,
            ],
            'provider_message_id' => [
                'type' => 'VARCHAR',
                'constraint' => 191,
            ],
            'account_id' => [
                'type' => 'VARCHAR',
                'constraint' => 63,
            ],
            'phone_key' => [
                'type' => 'VARCHAR',
                'constraint' => 32,
            ],
            'sender_name' => [
                'type' => 'VARCHAR',
                'constraint' => 190,
                'null' => true,
            ],
            'message_text' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'matched_rule_id' => [
                'type' => 'VARCHAR',
                'constraint' => 80,
                'null' => true,
            ],
            'action_name' => [
                'type' => 'VARCHAR',
                'constraint' => 40,
                'null' => true,
            ],
            'appointment_id' => [
                'type' => 'BIGINT',
                'constraint' => 20,
                'unsigned' => true,
                'null' => true,
            ],
            'status' => [
                'type' => 'VARCHAR',
                'constraint' => 24,
                'default' => 'processing',
            ],
            'reply_text' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'error_text' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'received_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'processed_at' => [
                'type' => 'DATETIME',
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

        $this->forge->addKey('id_whatsapp_chatbot_message', true);
        $this->forge->addUniqueKey(['id_tenant', 'message_key'], 'uq_wa_bot_messages_tenant_message');
        $this->forge->addKey(['id_tenant', 'created_at'], false, false, 'idx_wa_bot_messages_tenant_created');
        $this->forge->addKey(['id_tenant', 'status'], false, false, 'idx_wa_bot_messages_tenant_status');
        $this->forge->addForeignKey('id_tenant', 'platform_tenants', 'id_tenant', 'CASCADE', 'CASCADE');
        $this->forge->createTable('platform_whatsapp_chatbot_messages', true);
    }
}
