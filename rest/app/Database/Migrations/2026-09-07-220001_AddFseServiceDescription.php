<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddFseServiceDescription extends Migration
{
    public function up()
    {
        unset($this->db->dataCache['field_names'][$this->db->DBPrefix . 'fse_documents']);
        if ($this->db->tableExists('fse_documents') && !$this->db->fieldExists('service_description_enc', 'fse_documents')) {
            $this->forge->addColumn('fse_documents', ['service_description_enc' => ['type' => 'LONGTEXT', 'null' => true]]);
            unset($this->db->dataCache['field_names'][$this->db->DBPrefix . 'fse_documents']);
        }
    }

    public function down()
    {
        // Clinical text must not be silently deleted by a rollback.
        throw new \RuntimeException('Rollback FSE non distruttivo: conservare service_description_enc.');
    }
}
