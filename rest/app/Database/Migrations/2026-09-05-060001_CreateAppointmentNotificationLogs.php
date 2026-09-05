<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateAppointmentNotificationLogs extends Migration
{
    protected $DBGroup = 'platform';

    public function up()
    {
        if ($this->db->tableExists('platform_appointment_notification_logs')) {
            return;
        }

        $this->forge->addField([
            'id_notification_log' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'auto_increment' => true],
            'event_id' => ['type' => 'VARCHAR', 'constraint' => 64],
            'id_tenant' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'tenant_key' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'tenant_name' => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            'message_type' => ['type' => 'VARCHAR', 'constraint' => 64, 'default' => 'notification'],
            'status' => ['type' => 'VARCHAR', 'constraint' => 32, 'default' => 'sent'],
            'channel' => ['type' => 'VARCHAR', 'constraint' => 24, 'default' => ''],
            'provider' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'provider_id' => ['type' => 'VARCHAR', 'constraint' => 191, 'null' => true],
            'recipient' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'recipient_role' => ['type' => 'VARCHAR', 'constraint' => 32, 'null' => true],
            'appointment_id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'null' => true],
            'doctor_id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'null' => true],
            'doctor_label' => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            'actor_user_id' => ['type' => 'BIGINT', 'constraint' => 20, 'unsigned' => true, 'null' => true],
            'actor_label' => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            'patient_label' => ['type' => 'VARCHAR', 'constraint' => 190, 'null' => true],
            'scheduled_for' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'notes' => ['type' => 'TEXT', 'null' => true],
            'source' => ['type' => 'VARCHAR', 'constraint' => 80, 'default' => 'runtime'],
            'error_message' => ['type' => 'LONGTEXT', 'null' => true],
            'response_json' => ['type' => 'LONGTEXT', 'null' => true],
            'created_at' => ['type' => 'DATETIME'],
        ]);
        $this->forge->addKey('id_notification_log', true);
        $this->forge->addUniqueKey('event_id', 'uq_notification_log_event');
        $this->forge->addKey(['id_tenant', 'created_at'], false, false, 'idx_notification_log_tenant_date');
        $this->forge->addKey(['channel', 'status', 'created_at'], false, false, 'idx_notification_log_channel_status');
        $this->forge->addKey('provider_id', false, false, 'idx_notification_log_provider');
        $this->forge->addForeignKey('id_tenant', 'platform_tenants', 'id_tenant', 'CASCADE', 'SET NULL', 'fk_notification_log_tenant');
        $this->forge->createTable('platform_appointment_notification_logs', true);
    }

    public function down()
    {
        if ($this->db->tableExists('platform_appointment_notification_logs')) {
            $this->forge->dropTable('platform_appointment_notification_logs', true);
        }
    }
}
