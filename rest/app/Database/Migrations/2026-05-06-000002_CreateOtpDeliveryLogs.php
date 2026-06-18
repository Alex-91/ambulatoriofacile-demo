<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateOtpDeliveryLogs extends Migration
{
    private string $table = 'otp_delivery_logs';

    public function up()
    {
        if ($this->db->tableExists($this->table)) {
            return;
        }

        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'purpose' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
            ],
            'channel' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
            ],
            'status' => [
                'type'       => 'VARCHAR',
                'constraint' => 16,
            ],
            'user_id' => [
                'type'     => 'INT',
                'null'     => true,
                'unsigned' => false,
            ],
            'user_type' => [
                'type'     => 'SMALLINT',
                'null'     => true,
                'unsigned' => false,
            ],
            'error_message' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'meta_json' => [
                'type' => 'MEDIUMTEXT',
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => false,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey('created_at', false, false, 'idx_otp_delivery_created_at');
        $this->forge->addKey(['channel', 'created_at'], false, false, 'idx_otp_delivery_channel_date');
        $this->forge->addKey(['purpose', 'created_at'], false, false, 'idx_otp_delivery_purpose_date');
        $this->forge->addKey(['status', 'created_at'], false, false, 'idx_otp_delivery_status_date');
        $this->forge->addKey('user_id', false, false, 'idx_otp_delivery_user_id');
        $this->forge->createTable($this->table, true);
    }

    public function down()
    {
        $this->forge->dropTable($this->table, true);
    }
}
