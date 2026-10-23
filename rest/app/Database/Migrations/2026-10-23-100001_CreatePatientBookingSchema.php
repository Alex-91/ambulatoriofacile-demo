<?php
namespace App\Database\Migrations;

use App\Services\PatientBookingSchema;
use CodeIgniter\Database\Migration;

/** Included by the normal provisioning of every new tenant. Never enables it. */
class CreatePatientBookingSchema extends Migration
{
    public function up()
    {
        if ($this->db->tableExists('dap12_agenda_appuntamenti')) {
            PatientBookingSchema::tenant($this->db);
        }
    }

    public function down()
    {
        // Preserve appointments, encrypted contacts and delivery history on rollback.
    }
}
