<?php

namespace App\Database\Migrations;

use App\Services\AgendaSlotFragmentSchemaService;
use CodeIgniter\Database\Migration;

class CreateAgendaSlotFragments extends Migration
{
    public function up()
    {
        AgendaSlotFragmentSchemaService::create($this->db);
    }

    public function down()
    {
        if ($this->db->tableExists(AgendaSlotFragmentSchemaService::TABLE)) {
            $this->forge->dropTable(AgendaSlotFragmentSchemaService::TABLE, true);
        }
    }
}
