<?php

namespace App\Database\Migrations;

use App\Services\AgendaDoctorIdService;
use CodeIgniter\Database\Migration;

class BackfillAgendaDoctorIds extends Migration
{
    public function up()
    {
        if (!$this->db->tableExists('dap03_personale')) {
            return;
        }

        if (!$this->db->fieldExists('legacy_id_dot', 'dap03_personale')) {
            return;
        }

        $service = new AgendaDoctorIdService($this->db);
        $service->backfillMissing();
    }

    public function down()
    {
        // No-op: gli ID agenda sintetici assegnati ai nuovi professionisti
        // fanno ormai parte dei riferimenti usati dall'agenda.
    }
}
