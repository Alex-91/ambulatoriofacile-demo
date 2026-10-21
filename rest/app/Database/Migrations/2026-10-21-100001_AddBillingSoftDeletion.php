<?php
namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddBillingSoftDeletion extends Migration
{
    public function up()
    {
        $this->db->resetDataCache();
        if ($this->db->tableExists('billing_documents') && !$this->db->fieldExists('deleted_at', 'billing_documents')) {
            $this->forge->addColumn('billing_documents', ['deleted_at' => ['type' => 'DATETIME', 'null' => true]]);
            $this->db->resetDataCache();
        }
    }

    public function down()
    {
        throw new \RuntimeException('Conservare le fatture cancellate e i loro numeri: rollback automatico disabilitato.');
    }
}
