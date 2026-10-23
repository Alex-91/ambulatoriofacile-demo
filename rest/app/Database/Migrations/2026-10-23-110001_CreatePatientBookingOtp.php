<?php
namespace App\Database\Migrations;

use App\Services\PatientBookingSchema;
use CodeIgniter\Database\Migration;

/** Provision SMS verification for existing and future spaces without enabling it. */
class CreatePatientBookingOtp extends Migration
{
    public function up()
    {
        if($this->db->tableExists('dap12_agenda_appuntamenti'))PatientBookingSchema::tenant($this->db);
    }
    public function down()
    {
        // Keep verification/audit records when rolling the application back.
    }
}
