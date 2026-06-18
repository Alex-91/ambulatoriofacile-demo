<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class ExpandAgendaNotaGiornoText extends Migration
{
    public function up()
    {
        $this->updateNotaColumn('MEDIUMTEXT');
    }

    public function down()
    {
        $this->updateNotaColumn('TEXT');
    }

    private function updateNotaColumn(string $type): void
    {
        if (
            !$this->db->tableExists('dap23_agenda_nota_giorno')
            || !$this->db->fieldExists('nota', 'dap23_agenda_nota_giorno')
        ) {
            return;
        }

        $this->forge->modifyColumn('dap23_agenda_nota_giorno', [
            'nota' => [
                'type' => $type,
                'null' => true,
            ],
        ]);
    }
}
