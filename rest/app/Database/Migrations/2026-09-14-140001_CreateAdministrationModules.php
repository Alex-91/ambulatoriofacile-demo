<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;

class CreateAdministrationModules extends Migration
{
    public function up()
    {
        if (!$this->db->tableExists('dap02_clients')) return;
        $id=['type'=>'CHAR','constraint'=>32]; $int=['type'=>'BIGINT']; $text=['type'=>'LONGTEXT'];
        $base=['id'=>$id,'tenant_id'=>$int,'revision'=>$int,'payload_enc'=>$text,'created_by'=>$int,'updated_at'=>['type'=>'DATETIME']];
        $tables=[
            'administration_catalog'=>$base+['kind'=>['type'=>'VARCHAR','constraint'=>20],'active'=>['type'=>'INT']],
            'administration_quotes'=>$base+['request_token'=>$id,'patient_id'=>$int,'state'=>['type'=>'VARCHAR','constraint'=>20],'total_cents'=>$int,'expires_on'=>['type'=>'DATE']],
            'administration_cases'=>$base+['patient_id'=>$int,'quote_id'=>$id,'regime'=>['type'=>'VARCHAR','constraint'=>20],'state'=>['type'=>'VARCHAR','constraint'=>20],'total_cents'=>$int,'payer_cents'=>$int,'patient_cents'=>$int,'received_cents'=>$int],
            'administration_receipts'=>$base+['case_id'=>$id,'amount_cents'=>$int,'party'=>['type'=>'VARCHAR','constraint'=>12],'paid_on'=>['type'=>'DATE'],'active'=>['type'=>'INT']],
            'administration_compensation'=>$base+['billing_id'=>$int,'staff_id'=>$int,'state'=>['type'=>'VARCHAR','constraint'=>20],'amount_cents'=>$int],
            'administration_audit'=>['id'=>['type'=>'BIGINT','auto_increment'=>true],'tenant_id'=>$int,'actor_id'=>$int,'entity_id'=>$id,'event'=>['type'=>'VARCHAR','constraint'=>40],'revision'=>$int,'created_at'=>['type'=>'DATETIME']],
        ];
        foreach ($tables as $name=>$fields) {
            if ($this->db->tableExists($name)) continue;
            $this->forge->addField($fields); $this->forge->addKey('id',true); $this->forge->addKey('tenant_id');
            if ($name==='administration_cases') $this->forge->addUniqueKey(['tenant_id','quote_id']);
            if ($name==='administration_quotes') $this->forge->addUniqueKey(['tenant_id','request_token']);
            if ($name==='administration_compensation') $this->forge->addUniqueKey(['tenant_id','billing_id','staff_id']);
            if ($name==='administration_receipts') $this->forge->addKey(['tenant_id','case_id']);
            $this->forge->createTable($name,true); unset($this->db->dataCache['table_names']);
        }
    }
    public function down() { throw new \RuntimeException('Archivi amministrativi: usare una procedura di ripristino concordata.'); }
}
