<?php
namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class ProtectBillingNumbering extends Migration
{
    public function up()
    {
        if (!$this->db->tableExists('billing_documents')) {
            return;
        }
        if ($this->db->DBDriver === 'MySQLi') {
            $table = $this->db->query("SHOW TABLE STATUS WHERE Name = 'billing_documents'")->getRowArray();
            if (strtolower((string) ($table['Engine'] ?? '')) !== 'innodb') {
                throw new \RuntimeException('La numerazione protetta richiede billing_documents su InnoDB.');
            }
        }
        // Never silently renumber fiscal documents. Existing collisions require reconciliation.
        $duplicates = $this->db->query('SELECT document_number FROM billing_documents GROUP BY document_number HAVING COUNT(*) > 1 LIMIT 1')->getRowArray();
        if ($duplicates) {
            throw new \RuntimeException('Numeri fattura duplicati già presenti: riconciliare i documenti prima di attivare la numerazione protetta. Nessun documento è stato rinumerato.');
        }
        $indexes = $this->db->getIndexData('billing_documents');
        if (!isset($indexes['uq_billing_document_number'])) {
            $this->db->query('CREATE UNIQUE INDEX uq_billing_document_number ON billing_documents (document_number)');
        }
        if (!$this->db->tableExists('billing_numbering_lock')) {
            $this->forge->addField(['id' => ['type' => 'INT'], 'revision' => ['type' => 'BIGINT', 'default' => 0]]);
            $this->forge->addKey('id', true);
            $this->forge->createTable('billing_numbering_lock', true, $this->db->DBDriver === 'MySQLi' ? ['ENGINE' => 'InnoDB'] : []);
            $this->db->table('billing_numbering_lock')->insert(['id' => 1, 'revision' => 0]);
        }
        if (!$this->db->tableExists('billing_numbering_counters')) {
            $this->forge->addField(['scope' => ['type' => 'VARCHAR', 'constraint' => 16], 'last_number' => ['type' => 'INT']]);
            $this->forge->addKey('scope', true);
            $this->forge->createTable('billing_numbering_counters', true, $this->db->DBDriver === 'MySQLi' ? ['ENGINE' => 'InnoDB'] : []);
        }
    }

    public function down()
    {
        throw new \RuntimeException('Rollback automatico disabilitato: i contatori fiscali devono essere conservati.');
    }
}
