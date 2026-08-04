<?php

namespace App\Models;

use CodeIgniter\Model;

class BillingDocumentModel extends Model
{
    protected $table = 'billing_documents';
    protected $primaryKey = 'id_billing_document';
    protected $returnType = 'array';
    protected $allowedFields = [
        'id_client',
        'document_number',
        'document_type',
        'issue_date',
        'payment_date',
        'patient_name',
        'patient_tax_code',
        'patient_email',
        'payment_method',
        'due_date',
        'payment_status',
        'paid_at',
        'line_items_json',
        'subtotal_amount',
        'stamp_duty_amount',
        'vat_rate',
        'vat_nature',
        'amount_total',
        'notes',
        'template_snapshot_json',
        'ts_sync_enabled',
        'ts_expense_type_code',
        'ts_opposition_flag',
        'linked_ts_document_id',
        'ts_sync_state',
        'local_state',
        'pdf_generated_at',
        'invoice_email_sent_at',
        'last_reminder_sent_at',
        'reminder_count',
        'email_last_recipient',
        'email_last_error',
        'created_by',
        'updated_by',
        'created_at',
        'updated_at',
    ];
    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    public function findByDocumentNumberAndDate(string $documentNumber, string $issueDate): ?array
    {
        $documentNumber = trim($documentNumber);
        $issueDate = trim($issueDate);
        if ($documentNumber === '' || $issueDate === '') {
            return null;
        }

        return $this->where('document_number', $documentNumber)
            ->where('issue_date', $issueDate)
            ->first();
    }
}
