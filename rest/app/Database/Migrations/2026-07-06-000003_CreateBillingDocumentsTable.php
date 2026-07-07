<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateBillingDocumentsTable extends Migration
{
    public function up()
    {
        if ($this->db->tableExists('billing_documents')) {
            return;
        }

        $this->forge->addField([
            'id_billing_document' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'id_client' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
            ],
            'document_number' => [
                'type' => 'VARCHAR',
                'constraint' => 32,
            ],
            'document_type' => [
                'type' => 'VARCHAR',
                'constraint' => 24,
                'default' => 'invoice',
            ],
            'issue_date' => [
                'type' => 'DATE',
            ],
            'payment_date' => [
                'type' => 'DATE',
                'null' => true,
            ],
            'patient_name' => [
                'type' => 'VARCHAR',
                'constraint' => 190,
            ],
            'patient_tax_code' => [
                'type' => 'VARCHAR',
                'constraint' => 16,
                'null' => true,
            ],
            'payment_method' => [
                'type' => 'VARCHAR',
                'constraint' => 24,
                'default' => 'bank_transfer',
            ],
            'line_items_json' => [
                'type' => 'LONGTEXT',
                'null' => true,
            ],
            'subtotal_amount' => [
                'type' => 'DECIMAL',
                'constraint' => '10,2',
                'default' => 0,
            ],
            'stamp_duty_amount' => [
                'type' => 'DECIMAL',
                'constraint' => '10,2',
                'default' => 0,
            ],
            'vat_rate' => [
                'type' => 'DECIMAL',
                'constraint' => '5,2',
                'default' => 0,
            ],
            'vat_nature' => [
                'type' => 'VARCHAR',
                'constraint' => 16,
                'null' => true,
            ],
            'amount_total' => [
                'type' => 'DECIMAL',
                'constraint' => '10,2',
                'default' => 0,
            ],
            'notes' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'template_snapshot_json' => [
                'type' => 'LONGTEXT',
                'null' => true,
            ],
            'ts_sync_enabled' => [
                'type' => 'TINYINT',
                'constraint' => 1,
                'default' => 0,
            ],
            'ts_expense_type_code' => [
                'type' => 'VARCHAR',
                'constraint' => 8,
                'null' => true,
            ],
            'ts_opposition_flag' => [
                'type' => 'TINYINT',
                'constraint' => 1,
                'default' => 0,
            ],
            'linked_ts_document_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
            ],
            'ts_sync_state' => [
                'type' => 'VARCHAR',
                'constraint' => 24,
                'null' => true,
            ],
            'local_state' => [
                'type' => 'VARCHAR',
                'constraint' => 20,
                'default' => 'draft',
            ],
            'pdf_generated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'created_by' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
            ],
            'updated_by' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'updated_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id_billing_document', true);
        $this->forge->addKey('id_client');
        $this->forge->addKey('document_number');
        $this->forge->addKey('issue_date');
        $this->forge->addKey('payment_date');
        $this->forge->addKey('local_state');
        $this->forge->addKey('ts_sync_state');
        $this->forge->addKey('linked_ts_document_id');
        $this->forge->addKey(['document_number', 'issue_date'], false, false, 'idx_billing_document_number_issue');
        $this->forge->createTable('billing_documents', true);
    }

    public function down()
    {
        if ($this->db->tableExists('billing_documents')) {
            $this->forge->dropTable('billing_documents', true);
        }
    }
}
