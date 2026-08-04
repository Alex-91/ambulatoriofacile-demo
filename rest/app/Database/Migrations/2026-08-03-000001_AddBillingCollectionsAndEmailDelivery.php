<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

class AddBillingCollectionsAndEmailDelivery extends Migration
{
    public function up()
    {
        if (!$this->db->tableExists('billing_documents')) {
            return;
        }

        $existingFields = array_map('strtolower', $this->db->getFieldNames('billing_documents'));
        $fields = [
            'patient_email' => [
                'type' => 'VARCHAR',
                'constraint' => 190,
                'null' => true,
                'after' => 'patient_tax_code',
            ],
            'due_date' => [
                'type' => 'DATE',
                'null' => true,
                'after' => 'payment_date',
            ],
            'payment_status' => [
                'type' => 'VARCHAR',
                'constraint' => 20,
                'default' => 'unpaid',
                'after' => 'payment_method',
            ],
            'paid_at' => [
                'type' => 'DATETIME',
                'null' => true,
                'after' => 'payment_status',
            ],
            'invoice_email_sent_at' => [
                'type' => 'DATETIME',
                'null' => true,
                'after' => 'pdf_generated_at',
            ],
            'last_reminder_sent_at' => [
                'type' => 'DATETIME',
                'null' => true,
                'after' => 'invoice_email_sent_at',
            ],
            'reminder_count' => [
                'type' => 'INT',
                'constraint' => 11,
                'unsigned' => true,
                'default' => 0,
                'after' => 'last_reminder_sent_at',
            ],
            'email_last_recipient' => [
                'type' => 'VARCHAR',
                'constraint' => 190,
                'null' => true,
                'after' => 'reminder_count',
            ],
            'email_last_error' => [
                'type' => 'TEXT',
                'null' => true,
                'after' => 'email_last_recipient',
            ],
        ];

        foreach ($fields as $name => $definition) {
            if (in_array(strtolower($name), $existingFields, true)) {
                continue;
            }

            $this->forge->addColumn('billing_documents', [$name => $definition]);
        }

        // Mantiene coerenti anche i documenti creati prima dell'introduzione
        // dello scadenzario. Le nuove fatture useranno invece i valori scelti
        // nel form e i giorni di scadenza configurati dallo studio.
        $this->db->query("
            UPDATE billing_documents
            SET payment_status = 'paid',
                paid_at = COALESCE(paid_at, CONCAT(payment_date, ' 00:00:00'))
            WHERE payment_date IS NOT NULL
        ");
        $this->db->query("
            UPDATE billing_documents
            SET due_date = DATE_ADD(issue_date, INTERVAL 30 DAY)
            WHERE local_state = 'issued'
              AND due_date IS NULL
              AND issue_date IS NOT NULL
        ");

        if (!$this->db->tableExists('billing_document_email_log')) {
            $this->forge->addField([
                'id_billing_email_log' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'unsigned' => true,
                    'auto_increment' => true,
                ],
                'id_billing_document' => [
                    'type' => 'INT',
                    'constraint' => 11,
                    'unsigned' => true,
                ],
                'delivery_type' => [
                    'type' => 'VARCHAR',
                    'constraint' => 20,
                    'default' => 'invoice',
                ],
                'recipient' => [
                    'type' => 'VARCHAR',
                    'constraint' => 190,
                ],
                'subject' => [
                    'type' => 'VARCHAR',
                    'constraint' => 255,
                ],
                'message_body' => [
                    'type' => 'TEXT',
                    'null' => true,
                ],
                'delivery_status' => [
                    'type' => 'VARCHAR',
                    'constraint' => 20,
                    'default' => 'sent',
                ],
                'error_message' => [
                    'type' => 'TEXT',
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
            $this->forge->addKey('id_billing_email_log', true);
            $this->forge->addKey('id_billing_document');
            $this->forge->addKey('delivery_type');
            $this->forge->addKey('created_at');
            $this->forge->createTable('billing_document_email_log', true);
        }
    }

    public function down()
    {
        if ($this->db->tableExists('billing_document_email_log')) {
            $this->forge->dropTable('billing_document_email_log', true);
        }

        if (!$this->db->tableExists('billing_documents')) {
            return;
        }

        $existingFields = array_map('strtolower', $this->db->getFieldNames('billing_documents'));
        foreach ([
            'patient_email',
            'due_date',
            'payment_status',
            'paid_at',
            'invoice_email_sent_at',
            'last_reminder_sent_at',
            'reminder_count',
            'email_last_recipient',
            'email_last_error',
        ] as $field) {
            if (in_array($field, $existingFields, true)) {
                $this->forge->dropColumn('billing_documents', $field);
            }
        }
    }
}
