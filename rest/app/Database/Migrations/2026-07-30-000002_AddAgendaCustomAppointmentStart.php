<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddAgendaCustomAppointmentStart extends Migration
{
    public function up()
    {
        if (
            !$this->db->tableExists('dap12_agenda_appuntamenti')
            || $this->db->fieldExists('ora_inizio_appuntamento', 'dap12_agenda_appuntamenti')
        ) {
            return;
        }

        $this->forge->addColumn('dap12_agenda_appuntamenti', [
            'ora_inizio_appuntamento' => [
                'type' => 'DATETIME',
                'null' => true,
                'after' => $this->db->fieldExists('durata_minuti', 'dap12_agenda_appuntamenti')
                    ? 'durata_minuti'
                    : 'id_slot',
            ],
        ]);
    }

    public function down()
    {
        if (
            $this->db->tableExists('dap12_agenda_appuntamenti')
            && $this->db->fieldExists('ora_inizio_appuntamento', 'dap12_agenda_appuntamenti')
        ) {
            $this->forge->dropColumn('dap12_agenda_appuntamenti', 'ora_inizio_appuntamento');
        }
    }
}
