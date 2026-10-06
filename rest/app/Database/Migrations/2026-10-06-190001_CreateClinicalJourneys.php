<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;

/** Additive tenant schema. No existing appointments, clinical entries or test data are copied. */
final class CreateClinicalJourneys extends Migration
{
    public function up()
    {
        if (!$this->db->tableExists('dap02_clients') || !$this->db->tableExists('clinical_entries')) return;
        $this->forge->addField([
            'tenant_id'=>['type'=>'INT','unsigned'=>true],
            'appointment_id'=>['type'=>'BIGINT','unsigned'=>true],
            'patient_id'=>['type'=>'BIGINT','unsigned'=>true],
            'doctor_id'=>['type'=>'BIGINT','unsigned'=>true],
            'stage'=>['type'=>'VARCHAR','constraint'=>24,'default'=>'booked'],
            'revision'=>['type'=>'INT','default'=>0],
            'report_id'=>['type'=>'INT','unsigned'=>true,'null'=>true],
            'updated_at'=>['type'=>'DATETIME','null'=>true],
        ]);
        $this->forge->addKey(['tenant_id','appointment_id'],true);
        $this->forge->createTable('clinical_journeys',true);
        $this->forge->addField([
            'id'=>['type'=>'BIGINT','unsigned'=>true,'auto_increment'=>true],
            'tenant_id'=>['type'=>'INT','unsigned'=>true],
            'appointment_id'=>['type'=>'BIGINT','unsigned'=>true],
            'actor_user_id'=>['type'=>'INT','unsigned'=>true],
            'action'=>['type'=>'VARCHAR','constraint'=>40],
            'recorded_at'=>['type'=>'DATETIME'],
        ]);
        $this->forge->addKey('id',true);
        $this->forge->addKey(['tenant_id','appointment_id']);
        $this->forge->createTable('clinical_journeys_events',true);
    }
    public function down() { /* Preserve clinical workflow history on application rollback. */ }
}
