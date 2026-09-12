<?php
namespace App\Database\Migrations;
use CodeIgniter\Database\Migration;
class AddFseProfileSnapshot extends Migration
{
    public function up()
    {
        if ($this->db->tableExists('fse_documents') && !$this->db->fieldExists('profile_snapshot_json', 'fse_documents')) {
            $this->forge->addColumn('fse_documents', ['profile_snapshot_json'=>['type'=>'LONGTEXT', 'null'=>true]]);
            unset($this->db->dataCache['field_names'][$this->db->DBPrefix.'fse_documents']);
        }
    }
    public function down() { throw new \RuntimeException('Conservare le configurazioni storiche associate ai referti.'); }
}
