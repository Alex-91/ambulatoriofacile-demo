<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;

class CreatePacsOrders extends Migration
{
    public function up()
    {
        if (!$this->db->tableExists('dap02_clients') || $this->db->tableExists('pacs_orders')) return;
        $id=['type'=>'CHAR','constraint'=>32]; $int=['type'=>'BIGINT'];
        $this->forge->addField([
            'id'=>$id,'tenant_id'=>$int,'patient_id'=>$int,'binding_id'=>$id,'binding_revision'=>['type'=>'INT'],
            'owner_user_id'=>$int,'request_key'=>$id,'request_hash'=>['type'=>'CHAR','constraint'=>64],
            'accession'=>['type'=>'VARCHAR','constraint'=>16],'study_uid'=>['type'=>'VARCHAR','constraint'=>64],
            'state'=>['type'=>'VARCHAR','constraint'=>20,'default'=>'draft'],'revision'=>['type'=>'INT','default'=>1],
            'payload_enc'=>['type'=>'LONGTEXT'],'created_at'=>['type'=>'DATETIME'],'updated_at'=>['type'=>'DATETIME'],
            'last_exported_at'=>['type'=>'DATETIME','null'=>true],'last_export_sha256'=>['type'=>'CHAR','constraint'=>64,'null'=>true],
        ]);
        $this->forge->addKey('id',true);
        $this->forge->addKey(['tenant_id','patient_id']);
        $this->forge->addUniqueKey(['tenant_id','owner_user_id','request_key']);
        $this->forge->addUniqueKey(['tenant_id','accession']);
        $this->forge->addUniqueKey('study_uid');
        $this->forge->createTable('pacs_orders',true);
        unset($this->db->dataCache['table_names']);
    }
    public function down() { throw new \RuntimeException('Richieste diagnostiche e audit richiedono un piano esplicito di conservazione.'); }
}
