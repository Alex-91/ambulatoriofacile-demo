<?php
namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/** Tenant migration; never creates patient records in the platform database. */
class CreateClinicalRecords extends Migration
{
    public function up()
    {
        if (!$this->db->tableExists('dap02_clients')) return;
        $id = ['type'=>'INT','unsigned'=>true,'auto_increment'=>true];
        $int = ['type'=>'INT','unsigned'=>true];
        $nullableId = $int + ['null'=>true];
        $time = ['type'=>'DATETIME'];
        $text = ['type'=>'LONGTEXT'];
        $patientKey=$int;
        if ($this->db->DBDriver === 'MySQLi') {
            $column=$this->db->query("SHOW COLUMNS FROM dap02_clients WHERE Field = 'id_client'")->getRowArray();
            if (!$column || !preg_match('/^(tinyint|smallint|mediumint|int|bigint)(?:\([0-9]+\))?( unsigned)?$/i',$column['Type'],$parts)) throw new \RuntimeException('Tipo della chiave paziente non supportato.');
            $patientKey=['type'=>strtoupper($parts[1]),'unsigned'=>!empty($parts[2])];
        }
        $tables = [
            'clinical_entries' => [
                'id'=>$id,'id_client'=>$int,'author_user_id'=>$int,
                'kind'=>['type'=>'VARCHAR','constraint'=>24], 'occurred_at'=>$time,
                'payload_enc'=>$text,'revision'=>$int + ['default'=>1],
                'previous_entry_id'=>$nullableId,'appointment_id'=>$nullableId,
                'state'=>['type'=>'VARCHAR','constraint'=>20,'default'=>'draft'],
                'finalized_at'=>$time + ['null'=>true],
                'pdf_object_id'=>['type'=>'CHAR','constraint'=>32,'null'=>true],
                'signed_object_id'=>['type'=>'CHAR','constraint'=>32,'null'=>true],
                'signature_evidence_json'=>$text + ['null'=>true],
                'created_at'=>$time,'updated_at'=>$time,
            ],
            'clinical_objects' => [
                'id'=>['type'=>'CHAR','constraint'=>32],'id_client'=>$int,'entry_id'=>$nullableId,
                'category'=>['type'=>'VARCHAR','constraint'=>20],
                'mime'=>['type'=>'VARCHAR','constraint'=>100], 'name_enc'=>$text,
                'sha256'=>['type'=>'CHAR','constraint'=>64],'size_bytes'=>$int,
                'created_by'=>$int,'created_at'=>$time,
            ],
            'clinical_consent_templates' => [
                'id'=>$id,'kind'=>['type'=>'VARCHAR','constraint'=>32],
                'title'=>['type'=>'VARCHAR','constraint'=>160],
                'version'=>['type'=>'VARCHAR','constraint'=>32],
                'content_enc'=>$text,'sha256'=>['type'=>'CHAR','constraint'=>64],
                'created_by'=>$int,'created_at'=>$time,
            ],
            'clinical_consents' => [
                'id'=>$id,'id_client'=>$int,'template_id'=>$int,
                'kind'=>['type'=>'VARCHAR','constraint'=>32],
                'decision'=>['type'=>'VARCHAR','constraint'=>20],
                'signer_enc'=>$text,'evidence_object_id'=>['type'=>'CHAR','constraint'=>32,'null'=>true],
                'previous_id'=>$nullableId,'recorded_by'=>$int,'recorded_at'=>$time,
            ],
            'clinical_patient_state' => ['id_client'=>$int,'revision'=>$int + ['default'=>0]],
            'clinical_audit' => [
                'id'=>$id,'id_client'=>$nullableId,'actor_user_id'=>$int,
                'event'=>['type'=>'VARCHAR','constraint'=>40],
                'entity_id'=>['type'=>'VARCHAR','constraint'=>64], 'recorded_at'=>$time,
            ],
        ];
        foreach ($tables as $name=>$fields) {
            if ($this->db->tableExists($name)) continue;
            if (isset($fields['id_client'])) $fields['id_client']=$patientKey + (isset($fields['id_client']['null']) ? ['null'=>true] : []);
            $this->forge->addField($fields);
            $this->forge->addKey($name === 'clinical_patient_state' ? 'id_client' : 'id', true);
            if (isset($fields['id_client']) && $name !== 'clinical_patient_state') $this->forge->addKey('id_client');
            if ($name === 'clinical_entries') $this->forge->addUniqueKey('previous_entry_id');
            if ($name === 'clinical_consent_templates') $this->forge->addUniqueKey(['kind','version']);
            if (isset($fields['id_client']) && $name !== 'clinical_audit') $this->forge->addForeignKey('id_client','dap02_clients','id_client','RESTRICT','RESTRICT');
            $this->forge->createTable($name, true);
        }
        unset($this->db->dataCache['table_names']);
    }
    public function down() { throw new \RuntimeException('Le cartelle cliniche e i consensi richiedono un piano di conservazione e ripristino; rollback distruttivo non ammesso.'); }
}
