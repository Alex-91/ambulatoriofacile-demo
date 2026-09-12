<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;

class CreatePacsIntegration extends Migration
{
    public function up()
    {
        if (!$this->db->tableExists('dap02_clients')) return;
        $int=['type'=>'BIGINT'];
        $text=['type'=>'LONGTEXT'];
        $id=['type'=>'CHAR','constraint'=>32];
        $time=['type'=>'DATETIME'];
        $tables=[
            'pacs_patient_bindings'=>[
                'id'=>$id,'tenant_id'=>$int,'patient_id'=>$int,'profile_id'=>['type'=>'VARCHAR','constraint'=>40],
                'profile_hash'=>['type'=>'CHAR','constraint'=>64],
                'identity_hash'=>['type'=>'CHAR','constraint'=>64], 'identity_enc'=>$text,
                'owner_user_id'=>$int,'revision'=>['type'=>'INT','default'=>1],
                'active'=>['type'=>'INT','default'=>1], 'created_at'=>$time,'updated_at'=>$time,
            ],
            'pacs_study_links'=>[
                'id'=>$id,'tenant_id'=>$int,'patient_id'=>$int,'binding_id'=>$id,
                'binding_revision'=>['type'=>'INT'],'study_uid'=>['type'=>'VARCHAR','constraint'=>64],
                'metadata_enc'=>$text,'entry_id'=>$int+['null'=>true],
                'owner_user_id'=>$int,'active'=>['type'=>'INT','default'=>1],'created_at'=>$time,
            ],
            'pacs_audit'=>[
                'id'=>$id,'tenant_id'=>$int,'patient_id'=>$int,'actor_user_id'=>$int,
                'event'=>['type'=>'VARCHAR','constraint'=>40],'entity_id'=>$id,'recorded_at'=>$time,
            ],
        ];
        foreach ($tables as $table=>$fields) {
            if ($this->db->tableExists($table)) continue;
            $this->forge->addField($fields);
            $this->forge->addKey('id',true);
            $this->forge->addKey(['tenant_id','patient_id']);
            if ($table==='pacs_patient_bindings') {
                $this->forge->addUniqueKey(['tenant_id','profile_id','patient_id']);
                $this->forge->addUniqueKey(['tenant_id','profile_id','identity_hash']);
            }
            if ($table==='pacs_study_links') $this->forge->addUniqueKey(['tenant_id','binding_id','binding_revision','study_uid','owner_user_id']);
            $this->forge->createTable($table,true);
        }
        unset($this->db->dataCache['table_names']);
    }
    public function down()
    { throw new \RuntimeException('I collegamenti PACS e il registro accessi richiedono un piano esplicito di conservazione.'); }
}
