<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;
final class CreateClinicalJourneyTest extends Migration
{
    public function up()
    {
        if ((string)env('AF_CLINICAL_JOURNEY_TEST')!=='1') return;
        \App\Services\ClinicalJourneyService::assertTest();
        $this->forge->addField([
            'tenant_id'=>['type'=>'INT','unsigned'=>true], 'appointment_id'=>['type'=>'INT','unsigned'=>true],
            'stage'=>['type'=>'VARCHAR','constraint'=>24,'default'=>'booked'], 'revision'=>['type'=>'INT','default'=>0],
            'report_id'=>['type'=>'INT','unsigned'=>true,'null'=>true],
            'signature_simulated_at'=>['type'=>'DATETIME','null'=>true], 'delivery_simulated_at'=>['type'=>'DATETIME','null'=>true], 'updated_at'=>['type'=>'DATETIME','null'=>true],
        ]);$this->forge->addKey(['tenant_id','appointment_id'],true);$this->forge->createTable('clinical_journey_test',true);
        $this->forge->addField(['id'=>['type'=>'INT','unsigned'=>true,'auto_increment'=>true], 'tenant_id'=>['type'=>'INT','unsigned'=>true], 'appointment_id'=>['type'=>'INT','unsigned'=>true], 'actor_user_id'=>['type'=>'INT','unsigned'=>true], 'action'=>['type'=>'VARCHAR','constraint'=>40], 'recorded_at'=>['type'=>'DATETIME']]);
        $this->forge->addKey('id',true);$this->forge->addKey(['tenant_id','appointment_id']);$this->forge->createTable('clinical_journey_test_events',true);
    }
    public function down() { /* Test data removal is a separate explicit operation. */ }
}
