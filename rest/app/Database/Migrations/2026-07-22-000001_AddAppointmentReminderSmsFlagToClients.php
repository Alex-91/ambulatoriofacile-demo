<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddAppointmentReminderSmsFlagToClients extends Migration
{
    private const TABLE = 'dap02_clients';
    private const COLUMN = 'appointment_reminder_sms_enabled';

    public function up()
    {
        if (
            !$this->db->tableExists(self::TABLE)
            || $this->db->fieldExists(self::COLUMN, self::TABLE)
        ) {
            return;
        }

        $this->forge->addColumn(self::TABLE, [
            self::COLUMN => [
                'type' => 'TINYINT',
                'constraint' => 1,
                'null' => false,
                'default' => 0,
                'after' => 'avviso_mail',
            ],
        ]);
    }

    public function down()
    {
        if (
            !$this->db->tableExists(self::TABLE)
            || !$this->db->fieldExists(self::COLUMN, self::TABLE)
        ) {
            return;
        }

        $this->forge->dropColumn(self::TABLE, self::COLUMN);
    }
}
