<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;

class AddPacsOrderWorkflow extends Migration
{
    public function up()
    {
        if (!$this->db->tableExists('pacs_orders')) return;
        $fields=[
            'appointment_id'=>['type'=>'BIGINT','null'=>true],
            'appointment_hash'=>['type'=>'CHAR','constraint'=>64,'null'=>true],
            'scheduled_date'=>['type'=>'DATE','null'=>true],
            'workflow_stage'=>['type'=>'VARCHAR','constraint'=>20,'default'=>'awaiting'],
            'report_entry_id'=>['type'=>'BIGINT','null'=>true],
            'study_link_id'=>['type'=>'CHAR','constraint'=>32,'null'=>true],
        ];
        foreach ($fields as $name=>$definition) {
            if (!$this->db->fieldExists($name,'pacs_orders')) $this->forge->addColumn('pacs_orders',[$name=>$definition]);
            unset($this->db->dataCache['field_names']['pacs_orders']);
        }
        if ($this->db->tableExists('pacs_audit') && !$this->db->fieldExists('order_revision','pacs_audit')) {
            $this->forge->addColumn('pacs_audit',['order_revision'=>['type'=>'INT','null'=>true]]);
            unset($this->db->dataCache['field_names']['pacs_audit']);
        }
        $indexes=$this->db->getIndexData('pacs_orders');
        if (!isset($indexes['pacs_operational_queue'])) {
            $this->forge->addKey(['tenant_id','owner_user_id','state','scheduled_date'],false,false,'pacs_operational_queue');
            $this->forge->processIndexes('pacs_orders');
        }
        if (!isset($indexes['pacs_report_entry'])) {
            $this->forge->addKey(['tenant_id','report_entry_id'],false,false,'pacs_report_entry');
            $this->forge->processIndexes('pacs_orders');
        }
    }
    public function down() { throw new \RuntimeException('Il percorso diagnostico richiede un piano esplicito di conservazione.'); }
}
