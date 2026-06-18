<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class RebuildPushInfrastructure extends Migration
{
    public function up()
    {
        $this->forge->dropTable('push_delivery_logs', true);
        $this->forge->dropTable('push_outbox', true);
        $this->forge->dropTable('push_subscriptions', true);
        $this->forge->dropTable('device_links', true);

        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 10,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'user_id' => [
                'type'       => 'INT',
                'constraint' => 10,
            ],
            'endpoint' => [
                'type'       => 'VARCHAR',
                'constraint' => 500,
            ],
            'endpoint_hash' => [
                'type'       => 'CHAR',
                'constraint' => 64,
            ],
            'p256dh' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'auth' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
            ],
            'device_id' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
            ],
            'device_name' => [
                'type'       => 'VARCHAR',
                'constraint' => 100,
                'null'       => true,
            ],
            'device_label' => [
                'type'       => 'VARCHAR',
                'constraint' => 120,
                'null'       => true,
            ],
            'device_brand' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
            ],
            'device_model' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
            ],
            'device_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
                'default'    => 'phone',
            ],
            'device_os' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
            ],
            'browser' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
                'null'       => true,
            ],
            'ua' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'is_mobile' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'is_active' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 1,
            ],
            'last_seen' => [
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

        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('endpoint_hash', 'uq_push_endpoint_hash');
        $this->forge->addKey(['user_id', 'is_active'], false, false, 'idx_push_user_active');
        $this->forge->addKey(['user_id', 'is_mobile', 'is_active'], false, false, 'idx_push_user_mobile_active');
        $this->forge->createTable('push_subscriptions', true);

        $this->forge->addField([
            'id' => [
                'type'           => 'INT',
                'constraint'     => 10,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'user_id' => [
                'type'       => 'INT',
                'constraint' => 10,
            ],
            'token' => [
                'type'       => 'VARCHAR',
                'constraint' => 64,
            ],
            'expires_at' => [
                'type' => 'DATETIME',
            ],
            'consumed' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
            ],
            'consumed_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addUniqueKey('token', 'uq_device_link_token');
        $this->forge->addKey(['user_id', 'expires_at'], false, false, 'idx_device_link_user_exp');
        $this->forge->createTable('device_links', true);

        $this->forge->addField([
            'id' => [
                'type'           => 'BIGINT',
                'constraint'     => 20,
                'unsigned'       => true,
                'auto_increment' => true,
            ],
            'event_type' => [
                'type'       => 'VARCHAR',
                'constraint' => 32,
            ],
            'user_id' => [
                'type'       => 'INT',
                'constraint' => 10,
                'null'       => true,
            ],
            'endpoint_hash' => [
                'type'       => 'CHAR',
                'constraint' => 64,
                'null'       => true,
            ],
            'success' => [
                'type'       => 'TINYINT',
                'constraint' => 1,
                'default'    => 0,
            ],
            'provider_status' => [
                'type'       => 'SMALLINT',
                'constraint' => 6,
                'null'       => true,
            ],
            'error_message' => [
                'type'       => 'VARCHAR',
                'constraint' => 255,
                'null'       => true,
            ],
            'payload_json' => [
                'type' => 'MEDIUMTEXT',
                'null' => true,
            ],
            'provider_response' => [
                'type' => 'MEDIUMTEXT',
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);

        $this->forge->addKey('id', true);
        $this->forge->addKey(['event_type', 'user_id', 'created_at'], false, false, 'idx_push_logs_evt_user');
        $this->forge->addKey('endpoint_hash', false, false, 'idx_push_logs_endpoint_hash');
        $this->forge->createTable('push_delivery_logs', true);
    }

    public function down()
    {
        $this->forge->dropTable('push_delivery_logs', true);
        $this->forge->dropTable('device_links', true);
        $this->forge->dropTable('push_subscriptions', true);
    }
}

