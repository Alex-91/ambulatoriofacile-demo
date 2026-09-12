<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddFseDocumentRevisions extends Migration
{
    public function up()
    {
        if (!$this->db->tableExists('fse_documents')) return;
        unset($this->db->dataCache['field_names'][$this->db->DBPrefix . 'fse_documents']);
        $columns = [
            'previous_document_id' => ['type' => 'INT', 'unsigned' => true, 'null' => true],
            'document_oid_root' => ['type' => 'VARCHAR', 'constraint' => 120, 'null' => true],
            'revision_reason_enc' => ['type' => 'LONGTEXT', 'null' => true],
            'edit_token' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
        ];
        foreach ($columns as $name => $definition) {
            if (!$this->db->fieldExists($name, 'fse_documents')) {
                $this->forge->addColumn('fse_documents', [$name => $definition]);
                unset($this->db->dataCache['field_names'][$this->db->DBPrefix . 'fse_documents']);
            }
        }
        // One successor per immutable original, including concurrent double clicks.
        $indexes = $this->db->getIndexData('fse_documents');
        if (!isset($indexes['uq_fse_previous_document'])) {
            $this->forge->addUniqueKey('previous_document_id', 'uq_fse_previous_document');
            $this->forge->processIndexes('fse_documents');
        }
    }

    public function down()
    {
        throw new \RuntimeException('Rollback FSE non distruttivo: conservare storico e motivi delle revisioni.');
    }
}
