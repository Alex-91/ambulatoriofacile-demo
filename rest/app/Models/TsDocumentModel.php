<?php

namespace App\Models;

use CodeIgniter\Model;

class TsDocumentModel extends Model
{
    protected $table = 'ts_documents';
    protected $primaryKey = 'id_ts_document';
    protected $returnType = 'array';
    protected $allowedFields = [
        'id_ts_profile',
        'id_client',
        'source_type',
        'source_ref_id',
        'document_identifier_hash',
        'sender_piva_snapshot',
        'sender_cf_snapshot_enc',
        'sender_type_snapshot',
        'patient_cf_enc',
        'patient_cf_hash',
        'patient_label_snapshot_enc',
        'document_number',
        'document_device',
        'issue_date',
        'payment_date',
        'document_type',
        'expense_type_code',
        'payment_mode',
        'amount_total',
        'vat_rate',
        'vat_nature',
        'opposition_flag',
        'notes',
        'local_state',
        'ts_state',
        'validation_json',
        'request_payload_json',
        'response_payload_json',
        'ts_protocol',
        'ts_sent_at',
        'last_error_code',
        'last_error_message',
        'created_by',
        'updated_by',
        'created_at',
        'updated_at',
    ];
    protected $useTimestamps = true;
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    public function findByIdentifierHash(string $identifierHash): ?array
    {
        $identifierHash = trim(strtolower($identifierHash));
        if ($identifierHash === '') {
            return null;
        }

        return $this->where('LOWER(document_identifier_hash)', $identifierHash)->first();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listByLocalState(string $localState, int $limit = 100): array
    {
        $localState = trim(strtolower($localState));
        if ($localState === '') {
            return [];
        }

        return $this->where('LOWER(local_state)', $localState)
            ->orderBy('issue_date', 'DESC')
            ->orderBy('id_ts_document', 'DESC')
            ->findAll(max(1, $limit));
    }
}
