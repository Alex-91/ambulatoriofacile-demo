<?php
namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddFseRadiologyDetails extends Migration
{
    public function up()
    {
        unset($this->db->dataCache['field_names'][$this->db->DBPrefix . 'fse_documents']);
        if ($this->db->tableExists('fse_documents') && !$this->db->fieldExists('radiology_details_enc', 'fse_documents')) {
            $this->forge->addColumn('fse_documents', ['radiology_details_enc' => ['type' => 'LONGTEXT', 'null' => true]]);
            unset($this->db->dataCache['field_names'][$this->db->DBPrefix . 'fse_documents']);
        }
    }

    public function down()
    {
        throw new \RuntimeException('Rollback distruttivo dei dati RAD non consentito.');
    }
}
