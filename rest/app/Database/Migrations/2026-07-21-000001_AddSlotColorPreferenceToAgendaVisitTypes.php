<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddSlotColorPreferenceToAgendaVisitTypes extends Migration
{
    private const TABLE = 'dap44_agenda_tipi_visita';
    private const COLUMN = 'usa_colore_tipo_visita_slot';

    public function up()
    {
        if (!$this->db->tableExists(self::TABLE)) {
            return;
        }

        if (!$this->db->fieldExists(self::COLUMN, self::TABLE)) {
            $this->forge->addColumn(self::TABLE, [
                self::COLUMN => [
                    'type' => 'TINYINT',
                    'constraint' => 1,
                    'default' => 1,
                    'null' => false,
                    'after' => 'colore',
                ],
            ]);
        }

        $this->db->query(
            'UPDATE ' . self::TABLE . ' SET ' . self::COLUMN . ' = 1 WHERE ' . self::COLUMN . ' IS NULL'
        );
    }

    public function down()
    {
        if ($this->db->tableExists(self::TABLE) && $this->db->fieldExists(self::COLUMN, self::TABLE)) {
            $this->forge->dropColumn(self::TABLE, self::COLUMN);
        }
    }
}
