<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateTsDocumentsTables extends Migration
{
    public function up()
    {
        $this->createDocumentsTable();
        $this->createDocumentEventsTable();
        $this->createDocumentReceiptsTable();
    }

    public function down()
    {
        foreach (['ts_document_receipts', 'ts_document_events', 'ts_documents'] as $table) {
            if ($this->db->tableExists($table)) {
                $this->forge->dropTable($table, true);
            }
        }
    }

    private function createDocumentsTable(): void
    {
        if ($this->db->tableExists('ts_documents')) {
            return;
        }

        $this->forge->addField([
            'id_ts_document' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'id_ts_profile' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
            ],
            'id_client' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
            ],
            'source_type' => [
                'type' => 'VARCHAR',
                'constraint' => 30,
                'default' => 'manual',
            ],
            'source_ref_id' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
            ],
            'document_identifier_hash' => [
                'type' => 'CHAR',
                'constraint' => 64,
            ],
            'sender_piva_snapshot' => [
                'type' => 'VARCHAR',
                'constraint' => 16,
            ],
            'sender_cf_snapshot_enc' => [
                'type' => 'LONGTEXT',
                'null' => true,
            ],
            'sender_type_snapshot' => [
                'type' => 'VARCHAR',
                'constraint' => 40,
                'null' => true,
            ],
            'patient_cf_enc' => [
                'type' => 'LONGTEXT',
            ],
            'patient_cf_hash' => [
                'type' => 'CHAR',
                'constraint' => 64,
            ],
            'patient_label_snapshot_enc' => [
                'type' => 'LONGTEXT',
                'null' => true,
            ],
            'document_number' => [
                'type' => 'VARCHAR',
                'constraint' => 32,
            ],
            'document_device' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
            ],
            'issue_date' => [
                'type' => 'DATE',
            ],
            'payment_date' => [
                'type' => 'DATE',
            ],
            'expense_type_code' => [
                'type' => 'VARCHAR',
                'constraint' => 8,
                'default' => 'SP',
            ],
            'payment_mode' => [
                'type' => 'VARCHAR',
                'constraint' => 16,
            ],
            'amount_total' => [
                'type' => 'DECIMAL',
                'constraint' => '10,2',
                'default' => 0,
            ],
            'opposition_flag' => [
                'type' => 'TINYINT',
                'constraint' => 1,
                'default' => 0,
            ],
            'notes' => [
                'type' => 'TEXT',
                'null' => true,
            ],
            'local_state' => [
                'type' => 'VARCHAR',
                'constraint' => 20,
                'default' => 'draft',
            ],
            'ts_state' => [
                'type' => 'VARCHAR',
                'constraint' => 20,
                'null' => true,
            ],
            'validation_json' => [
                'type' => 'LONGTEXT',
                'null' => true,
            ],
            'request_payload_json' => [
                'type' => 'LONGTEXT',
                'null' => true,
            ],
            'response_payload_json' => [
                'type' => 'LONGTEXT',
                'null' => true,
            ],
            'ts_protocol' => [
                'type' => 'VARCHAR',
                'constraint' => 64,
                'null' => true,
            ],
            'ts_sent_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
            'last_error_code' => [
                'type' => 'VARCHAR',
                'constraint' => 50,
                'null' => true,
            ],
            'last_error_message' => [
                'type' => 'TEXT',
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
        $this->forge->addKey('id_ts_document', true);
        $this->forge->addUniqueKey('document_identifier_hash', 'uq_ts_documents_identifier_hash');
        $this->forge->addKey('id_client');
        $this->forge->addKey('id_ts_profile');
        $this->forge->addKey('local_state');
        $this->forge->addKey('ts_state');
        $this->forge->addKey('issue_date');
        $this->forge->addKey('payment_date');
        $this->forge->addKey('ts_protocol');
        $this->forge->addKey(['source_type', 'source_ref_id'], false, false, 'idx_ts_documents_source');
        $this->forge->addKey(['patient_cf_hash', 'issue_date'], false, false, 'idx_ts_documents_patient_issue');
        $this->forge->createTable('ts_documents', true);
    }

    private function createDocumentEventsTable(): void
    {
        if ($this->db->tableExists('ts_document_events')) {
            return;
        }

        $this->forge->addField([
            'id_ts_event' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'id_ts_document' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
            ],
            'event_type' => [
                'type' => 'VARCHAR',
                'constraint' => 40,
            ],
            'event_level' => [
                'type' => 'VARCHAR',
                'constraint' => 16,
                'default' => 'info',
            ],
            'event_code' => [
                'type' => 'VARCHAR',
                'constraint' => 50,
                'null' => true,
            ],
            'message' => [
                'type' => 'TEXT',
            ],
            'context_json' => [
                'type' => 'LONGTEXT',
                'null' => true,
            ],
            'created_by' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id_ts_event', true);
        $this->forge->addKey(['id_ts_document', 'created_at'], false, false, 'idx_ts_document_events_document_created');
        $this->forge->addKey('event_type');
        $this->forge->addKey('event_level');
        $this->forge->addForeignKey('id_ts_document', 'ts_documents', 'id_ts_document', 'CASCADE', 'CASCADE');
        $this->forge->createTable('ts_document_events', true);
    }

    private function createDocumentReceiptsTable(): void
    {
        if ($this->db->tableExists('ts_document_receipts')) {
            return;
        }

        $this->forge->addField([
            'id_ts_receipt' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'auto_increment' => true,
            ],
            'id_ts_document' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
            ],
            'receipt_type' => [
                'type' => 'VARCHAR',
                'constraint' => 20,
            ],
            'ts_protocol' => [
                'type' => 'VARCHAR',
                'constraint' => 64,
                'null' => true,
            ],
            'storage_path' => [
                'type' => 'VARCHAR',
                'constraint' => 255,
            ],
            'mime_type' => [
                'type' => 'VARCHAR',
                'constraint' => 120,
                'null' => true,
            ],
            'file_size' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'null' => true,
            ],
            'checksum_sha256' => [
                'type' => 'CHAR',
                'constraint' => 64,
                'null' => true,
            ],
            'created_at' => [
                'type' => 'DATETIME',
                'null' => true,
            ],
        ]);
        $this->forge->addKey('id_ts_receipt', true);
        $this->forge->addKey('id_ts_document');
        $this->forge->addKey('receipt_type');
        $this->forge->addKey('ts_protocol');
        $this->forge->addForeignKey('id_ts_document', 'ts_documents', 'id_ts_document', 'CASCADE', 'CASCADE');
        $this->forge->createTable('ts_document_receipts', true);
    }
}
