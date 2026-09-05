<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateSmsFactorDeliveryReceipts extends Migration
{
    protected $DBGroup = 'platform';

    public function up()
    {
        if ($this->db->tableExists('platform_sms_delivery_receipts')) {
            return;
        }

        $this->forge->addField([
            'id_sms_delivery_receipt' => [
                'type' => 'BIGINT',
                'constraint' => 20,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'receipt_key' => ['type' => 'CHAR', 'constraint' => 64],
            'id_tenant' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'provider' => ['type' => 'VARCHAR', 'constraint' => 32, 'default' => 'smsfactor'],
            'client_message_id' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'campaign_id' => ['type' => 'VARCHAR', 'constraint' => 64],
            'destination' => ['type' => 'VARCHAR', 'constraint' => 32],
            'country_code' => ['type' => 'CHAR', 'constraint' => 2, 'null' => true],
            'status_code' => ['type' => 'SMALLINT', 'constraint' => 6],
            'delivery_status' => ['type' => 'VARCHAR', 'constraint' => 32],
            'occurred_at' => ['type' => 'DATETIME', 'null' => true],
            'payload_json' => ['type' => 'TEXT'],
            'created_at' => ['type' => 'DATETIME'],
            'updated_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id_sms_delivery_receipt', true);
        $this->forge->addUniqueKey('receipt_key', 'uq_smsfactor_delivery_receipt');
        $this->forge->addKey(['id_tenant', 'updated_at'], false, false, 'idx_smsfactor_receipt_tenant_date');
        $this->forge->addKey('campaign_id', false, false, 'idx_smsfactor_receipt_campaign');
        $this->forge->addKey('client_message_id', false, false, 'idx_smsfactor_receipt_client_message');
        $this->forge->addForeignKey('id_tenant', 'platform_tenants', 'id_tenant', 'CASCADE', 'SET NULL');
        $this->forge->createTable('platform_sms_delivery_receipts', true);
    }

    public function down()
    {
        if ($this->db->tableExists('platform_sms_delivery_receipts')) {
            $this->forge->dropTable('platform_sms_delivery_receipts', true);
        }
    }
}
