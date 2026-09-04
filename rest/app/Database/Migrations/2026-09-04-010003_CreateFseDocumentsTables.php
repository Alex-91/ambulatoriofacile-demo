<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class CreateFseDocumentsTables extends Migration
{
    public function up()
    {
        if (!$this->db->tableExists('fse_documents')) {
            $this->createDocuments();
        }
        if (!$this->db->tableExists('fse_document_events')) {
            $this->createEvents();
        }
    }

    public function down()
    {
        foreach (['fse_document_events', 'fse_documents'] as $table) {
            if ($this->db->tableExists($table)) {
                $this->forge->dropTable($table, true);
            }
        }
    }

    private function createDocuments(): void
    {
        $this->forge->addField([
            'id_fse_document' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'id_fse_profile' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'id_client' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'source_type' => ['type' => 'VARCHAR', 'constraint' => 30, 'default' => 'manual'],
            'source_ref_id' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'local_state' => ['type' => 'VARCHAR', 'constraint' => 24, 'default' => 'draft'],
            'document_type' => ['type' => 'VARCHAR', 'constraint' => 12, 'default' => 'RSA'],
            'document_title' => ['type' => 'VARCHAR', 'constraint' => 200],
            'loinc_code' => ['type' => 'VARCHAR', 'constraint' => 30, 'default' => '11488-4'],
            'loinc_display_name' => ['type' => 'VARCHAR', 'constraint' => 160, 'default' => 'Nota di consulto'],
            'document_unique_id' => ['type' => 'VARCHAR', 'constraint' => 120],
            'set_id' => ['type' => 'VARCHAR', 'constraint' => 120],
            'submission_id' => ['type' => 'VARCHAR', 'constraint' => 120],
            'version_number' => ['type' => 'INT', 'constraint' => 5, 'unsigned' => true, 'default' => 1],
            'patient_cf_enc' => ['type' => 'LONGTEXT'],
            'patient_cf_hash' => ['type' => 'CHAR', 'constraint' => 64],
            'patient_first_name_enc' => ['type' => 'LONGTEXT'],
            'patient_last_name_enc' => ['type' => 'LONGTEXT'],
            'patient_birth_date_enc' => ['type' => 'LONGTEXT'],
            'patient_gender' => ['type' => 'VARCHAR', 'constraint' => 2],
            'patient_email_enc' => ['type' => 'LONGTEXT', 'null' => true],
            'patient_address_enc' => ['type' => 'LONGTEXT', 'null' => true],
            'patient_city_enc' => ['type' => 'LONGTEXT', 'null' => true],
            'author_cf_enc' => ['type' => 'LONGTEXT'],
            'author_cf_hash' => ['type' => 'CHAR', 'constraint' => 64],
            'author_first_name_enc' => ['type' => 'LONGTEXT'],
            'author_last_name_enc' => ['type' => 'LONGTEXT'],
            'service_start' => ['type' => 'DATETIME'],
            'service_end' => ['type' => 'DATETIME', 'null' => true],
            'reason_text_enc' => ['type' => 'LONGTEXT', 'null' => true],
            'history_text_enc' => ['type' => 'LONGTEXT', 'null' => true],
            'findings_text_enc' => ['type' => 'LONGTEXT', 'null' => true],
            'report_text_enc' => ['type' => 'LONGTEXT'],
            'diagnosis_text_enc' => ['type' => 'LONGTEXT', 'null' => true],
            'conclusions_text_enc' => ['type' => 'LONGTEXT', 'null' => true],
            'patient_consent' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 1],
            'access_rules_json' => ['type' => 'TEXT', 'null' => true],
            'administrative_request' => ['type' => 'VARCHAR', 'constraint' => 20, 'default' => 'NOSSN'],
            'cda_path' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'cda_sha256' => ['type' => 'CHAR', 'constraint' => 64, 'null' => true],
            'unsigned_pdf_path' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'unsigned_pdf_sha256' => ['type' => 'CHAR', 'constraint' => 64, 'null' => true],
            'signed_pdf_path' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'signed_pdf_sha256' => ['type' => 'CHAR', 'constraint' => 64, 'null' => true],
            'trace_id' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'span_id' => ['type' => 'VARCHAR', 'constraint' => 100, 'null' => true],
            'workflow_instance_id' => ['type' => 'VARCHAR', 'constraint' => 500, 'null' => true],
            'gateway_state' => ['type' => 'VARCHAR', 'constraint' => 60, 'null' => true],
            'gateway_http_status' => ['type' => 'INT', 'constraint' => 5, 'null' => true],
            'last_gateway_message' => ['type' => 'TEXT', 'null' => true],
            'last_response_json' => ['type' => 'LONGTEXT', 'null' => true],
            'validated_at' => ['type' => 'DATETIME', 'null' => true],
            'published_at' => ['type' => 'DATETIME', 'null' => true],
            'deleted_at' => ['type' => 'DATETIME', 'null' => true],
            'created_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'updated_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
            'updated_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id_fse_document', true);
        $this->forge->addUniqueKey('document_unique_id', 'uq_fse_document_unique_id');
        $this->forge->addKey('local_state');
        $this->forge->addKey('patient_cf_hash');
        $this->forge->addKey('workflow_instance_id');
        $this->forge->addKey(['source_type', 'source_ref_id'], false, false, 'idx_fse_document_source');
        $this->forge->createTable('fse_documents', true);
    }

    private function createEvents(): void
    {
        $this->forge->addField([
            'id_fse_event' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'auto_increment' => true],
            'id_fse_document' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true],
            'event_type' => ['type' => 'VARCHAR', 'constraint' => 50],
            'event_level' => ['type' => 'VARCHAR', 'constraint' => 16, 'default' => 'info'],
            'message' => ['type' => 'TEXT'],
            'context_json' => ['type' => 'LONGTEXT', 'null' => true],
            'created_by' => ['type' => 'INT', 'constraint' => 11, 'unsigned' => true, 'null' => true],
            'created_at' => ['type' => 'DATETIME', 'null' => true],
        ]);
        $this->forge->addKey('id_fse_event', true);
        $this->forge->addKey(['id_fse_document', 'created_at'], false, false, 'idx_fse_event_document_created');
        $this->forge->addForeignKey('id_fse_document', 'fse_documents', 'id_fse_document', 'CASCADE', 'CASCADE');
        $this->forge->createTable('fse_document_events', true);
    }
}
